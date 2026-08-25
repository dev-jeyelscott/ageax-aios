<?php

use App\Models\User;
use App\Services\SpeechToText;
use App\Services\SpeechToTextResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class VoiceAudioUploadSpeechToTextFake implements SpeechToText
{
    public int $calls = 0;

    public ?string $observedPath = null;

    public bool $observedFileExists = false;

    public bool $observedExecutable = true;

    public bool $throwUnexpectedly = false;

    public SpeechToTextResult $result;

    /**
     * Initialize one successful local transcription result for focused upload tests.
     */
    public function __construct()
    {
        $this->result = new SpeechToTextResult(
            successful: true,
            transcript: 'transcribed locally',
        );
    }

    /**
     * Capture the temporary file state visible only during local transcription.
     */
    public function transcribe(
        string $audioPath,
    ): SpeechToTextResult {
        $this->calls++;
        $this->observedPath = realpath($audioPath)
            ?: $audioPath;
        $this->observedFileExists = is_file(
            $audioPath,
        );

        $permissions = fileperms(
            $audioPath,
        );

        $this->observedExecutable = $permissions === false
            || ($permissions & 0111) !== 0;

        if ($this->throwUnexpectedly) {
            throw new RuntimeException(
                'test-only unexpected transcription failure',
            );
        }

        return $this->result;
    }
}

/**
 * Build deterministic uncompressed PCM WAV bytes for server MIME detection.
 */
function voiceAudioWavContents(
    int $dataBytes = 160,
): string {
    $dataBytes = max(
        2,
        $dataBytes,
    );

    if ($dataBytes % 2 !== 0) {
        $dataBytes++;
    }

    $channels = 1;
    $sampleRate = 8000;
    $bitsPerSample = 16;
    $blockAlign = $channels
        * intdiv(
            $bitsPerSample,
            8,
        );
    $byteRate = $sampleRate
        * $blockAlign;
    $data = str_repeat(
        "\0",
        $dataBytes,
    );

    return 'RIFF'
        .pack(
            'V',
            36 + strlen($data),
        )
        .'WAVE'
        .'fmt '
        .pack('V', 16)
        .pack('v', 1)
        .pack('v', $channels)
        .pack('V', $sampleRate)
        .pack('V', $byteRate)
        .pack('v', $blockAlign)
        .pack('v', $bitsPerSample)
        .'data'
        .pack(
            'V',
            strlen($data),
        )
        .$data;
}

/**
 * Create one real uploaded-file fixture whose server MIME is derived from actual bytes.
 *
 * @return array{file: UploadedFile, path: string}
 */
function voiceAudioUploadFixture(
    string $contents,
    string $originalName = 'voice.wav',
    string $clientMimeType = 'application/octet-stream',
): array {
    $path = tempnam(
        sys_get_temp_dir(),
        'ageax-voice-upload-',
    );

    if ($path === false) {
        throw new RuntimeException(
            'Voice upload fixture could not be created.',
        );
    }

    if (
        file_put_contents(
            $path,
            $contents,
        ) === false
    ) {
        @unlink($path);

        throw new RuntimeException(
            'Voice upload fixture could not be written.',
        );
    }

    return [
        'file' => new UploadedFile(
            $path,
            $originalName,
            $clientMimeType,
            null,
            true,
        ),
        'path' => $path,
    ];
}

/**
 * Create an isolated managed-workspace fixture that is a sibling of voice temporary storage.
 */
function voiceAudioWorkspaceFixture(): string
{
    $path = sys_get_temp_dir()
        .DIRECTORY_SEPARATOR
        .'ageax-voice-workspace-'
        .bin2hex(random_bytes(8));

    if (! mkdir($path, 0700)) {
        throw new RuntimeException(
            'Voice workspace fixture could not be created.',
        );
    }

    config()->set(
        'aios.workspace_root',
        $path,
    );

    return $path;
}

/**
 * Remove one uploaded-file source fixture after the request completes.
 */
function removeVoiceAudioUploadFixture(
    array $fixture,
): void {
    @unlink(
        $fixture['path'],
    );
}

/**
 * Remove one isolated managed-workspace fixture.
 */
function removeVoiceAudioWorkspaceFixture(
    string $workspace,
): void {
    @rmdir(
        $workspace,
    );
}

test('temporary voice transcription requires authentication', function () {
    $fixture = voiceAudioUploadFixture(
        voiceAudioWavContents(),
    );

    try {
        $this
            ->post(
                route(
                    'voice.transcriptions.store',
                ),
                [
                    'audio' => $fixture['file'],
                ],
                [
                    'Accept' => 'application/json',
                ],
            )
            ->assertUnauthorized();
    } finally {
        removeVoiceAudioUploadFixture(
            $fixture,
        );
    }
});

test('supported server detected audio is staged privately transcribed and deleted', function () {
    Storage::fake('local');
    Storage::fake('public');

    $workspace = voiceAudioWorkspaceFixture();
    $fixture = voiceAudioUploadFixture(
        voiceAudioWavContents(),
        '../../client-controlled-name.wav',
        'application/x-php',
    );
    $speechToText = new VoiceAudioUploadSpeechToTextFake;

    app()->instance(
        SpeechToText::class,
        $speechToText,
    );

    try {
        $this
            ->actingAs(
                User::factory()->create([
                    'email_verified_at' => now(),
                ]),
            )
            ->post(
                route(
                    'voice.transcriptions.store',
                ),
                [
                    'audio' => $fixture['file'],
                ],
                [
                    'Accept' => 'application/json',
                ],
            )
            ->assertOk()
            ->assertExactJson([
                'transcript' => 'transcribed locally',
            ]);

        expect($speechToText->calls)
            ->toBe(1)
            ->and($speechToText->observedFileExists)
            ->toBeTrue()
            ->and($speechToText->observedExecutable)
            ->toBeFalse();

        if (! is_string($speechToText->observedPath)) {
            throw new RuntimeException(
                'The temporary voice path was not observed.',
            );
        }

        $temporaryPath = $speechToText->observedPath;
        $temporaryDirectory = dirname(
            $temporaryPath,
        );
        $installationRoot = realpath(
            base_path(),
        );
        $workspaceRoot = realpath(
            $workspace,
        );

        if (
            $installationRoot === false
            || $workspaceRoot === false
        ) {
            throw new RuntimeException(
                'Protected test paths could not be resolved.',
            );
        }

        expect(
            preg_match(
                '/^[a-f0-9]{32}\.wav$/',
                basename($temporaryPath),
            ),
        )->toBe(1)
            ->and(
                basename($temporaryPath),
            )->not->toContain(
                'client-controlled-name',
            )
            ->and(
                str_starts_with(
                    $temporaryPath,
                    rtrim(
                        $installationRoot,
                        DIRECTORY_SEPARATOR,
                    ).DIRECTORY_SEPARATOR,
                ),
            )->toBeFalse()
            ->and(
                str_starts_with(
                    $temporaryPath,
                    rtrim(
                        $workspaceRoot,
                        DIRECTORY_SEPARATOR,
                    ).DIRECTORY_SEPARATOR,
                ),
            )->toBeFalse()
            ->and(
                file_exists(
                    $temporaryPath,
                ),
            )->toBeFalse()
            ->and(
                is_dir(
                    $temporaryDirectory,
                ),
            )->toBeFalse()
            ->and(
                Storage::disk('local')->allFiles(),
            )->toBe([])
            ->and(
                Storage::disk('public')->allFiles(),
            )->toBe([]);
    } finally {
        removeVoiceAudioUploadFixture(
            $fixture,
        );
        removeVoiceAudioWorkspaceFixture(
            $workspace,
        );
    }
});

test('unsupported MIME and oversized audio are rejected before transcription', function () {
    Storage::fake('local');
    Storage::fake('public');

    $speechToText = new VoiceAudioUploadSpeechToTextFake;

    app()->instance(
        SpeechToText::class,
        $speechToText,
    );

    $unsupported = voiceAudioUploadFixture(
        "<?php echo 'not audio';",
        'claimed-audio.wav',
        'audio/wav',
    );

    try {
        $this
            ->actingAs(
                User::factory()->create([
                    'email_verified_at' => now(),
                ]),
            )
            ->post(
                route(
                    'voice.transcriptions.store',
                ),
                [
                    'audio' => $unsupported['file'],
                ],
                [
                    'Accept' => 'application/json',
                ],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'audio',
            );
    } finally {
        removeVoiceAudioUploadFixture(
            $unsupported,
        );
    }

    config()->set(
        'aios.voice_stt_max_audio_bytes',
        128,
    );

    $oversized = voiceAudioUploadFixture(
        voiceAudioWavContents(
            512,
        ),
    );

    try {
        $this
            ->actingAs(
                User::factory()->create([
                    'email_verified_at' => now(),
                ]),
            )
            ->post(
                route(
                    'voice.transcriptions.store',
                ),
                [
                    'audio' => $oversized['file'],
                ],
                [
                    'Accept' => 'application/json',
                ],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'audio',
            );

        expect($speechToText->calls)
            ->toBe(0)
            ->and(
                Storage::disk('local')->allFiles(),
            )->toBe([])
            ->and(
                Storage::disk('public')->allFiles(),
            )->toBe([]);
    } finally {
        removeVoiceAudioUploadFixture(
            $oversized,
        );
    }
});

test('temporary audio is deleted when speech transcription returns a normal failure', function () {
    $workspace = voiceAudioWorkspaceFixture();
    $fixture = voiceAudioUploadFixture(
        voiceAudioWavContents(),
    );
    $speechToText = new VoiceAudioUploadSpeechToTextFake;
    $speechToText->result = new SpeechToTextResult(
        successful: false,
        failureType: 'process_failure',
        failureMessage: 'Local speech transcription failed.',
    );

    app()->instance(
        SpeechToText::class,
        $speechToText,
    );

    try {
        $this
            ->actingAs(
                User::factory()->create([
                    'email_verified_at' => now(),
                ]),
            )
            ->post(
                route(
                    'voice.transcriptions.store',
                ),
                [
                    'audio' => $fixture['file'],
                ],
                [
                    'Accept' => 'application/json',
                ],
            )
            ->assertStatus(503)
            ->assertExactJson([
                'message' => 'Local speech transcription failed.',
                'failure_type' => 'process_failure',
            ]);

        if (! is_string($speechToText->observedPath)) {
            throw new RuntimeException(
                'The temporary voice path was not observed.',
            );
        }

        expect($speechToText->observedFileExists)
            ->toBeTrue()
            ->and(
                file_exists(
                    $speechToText->observedPath,
                ),
            )->toBeFalse()
            ->and(
                is_dir(
                    dirname(
                        $speechToText->observedPath,
                    ),
                ),
            )->toBeFalse();
    } finally {
        removeVoiceAudioUploadFixture(
            $fixture,
        );
        removeVoiceAudioWorkspaceFixture(
            $workspace,
        );
    }
});

test('temporary audio is deleted and unexpected transcription errors stay sanitized', function () {
    $workspace = voiceAudioWorkspaceFixture();
    $fixture = voiceAudioUploadFixture(
        voiceAudioWavContents(),
    );
    $speechToText = new VoiceAudioUploadSpeechToTextFake;
    $speechToText->throwUnexpectedly = true;

    app()->instance(
        SpeechToText::class,
        $speechToText,
    );

    try {
        $response = $this
            ->actingAs(
                User::factory()->create([
                    'email_verified_at' => now(),
                ]),
            )
            ->post(
                route(
                    'voice.transcriptions.store',
                ),
                [
                    'audio' => $fixture['file'],
                ],
                [
                    'Accept' => 'application/json',
                ],
            );

        $response
            ->assertStatus(500)
            ->assertExactJson([
                'message' => 'Local speech transcription could not be completed.',
                'failure_type' => 'transcription_failure',
            ]);

        if (! is_string($speechToText->observedPath)) {
            throw new RuntimeException(
                'The temporary voice path was not observed.',
            );
        }

        expect($speechToText->observedFileExists)
            ->toBeTrue()
            ->and(
                file_exists(
                    $speechToText->observedPath,
                ),
            )->toBeFalse()
            ->and(
                is_dir(
                    dirname(
                        $speechToText->observedPath,
                    ),
                ),
            )->toBeFalse()
            ->and(
                $response->getContent(),
            )->not->toContain(
                'test-only unexpected transcription failure',
            );
    } finally {
        removeVoiceAudioUploadFixture(
            $fixture,
        );
        removeVoiceAudioWorkspaceFixture(
            $workspace,
        );
    }
});
