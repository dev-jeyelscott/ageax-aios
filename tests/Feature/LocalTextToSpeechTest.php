<?php

use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\PiperTextToSpeech;
use App\Services\TextToSpeech;
use App\Services\TextToSpeechResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Process;

/**
 * Create isolated executable and model fixtures for local text-to-speech tests.
 *
 * @return array{directory: string, binary: string, model: string}
 */
function localTextToSpeechFixture(): array
{
    $directory = sys_get_temp_dir().'/ageax-piper-'.fake()->uuid();
    mkdir($directory, 0700, true);

    $binary = $directory.'/piper';
    $model = $directory.'/voice.onnx';

    file_put_contents($binary, "#!/bin/sh\nexit 0\n");
    file_put_contents($model, 'test-model');
    chmod($binary, 0700);
    chmod($model, 0600);

    return compact('directory', 'binary', 'model');
}

/**
 * Apply one valid local TTS configuration with optional per-test overrides.
 *
 * @param  array{directory: string, binary: string, model: string}  $fixture
 * @param  array<string, mixed>  $overrides
 */
function configureLocalTextToSpeech(
    array $fixture,
    array $overrides = [],
): void {
    $configuration = array_replace([
        'aios.voice_tts_enabled' => true,
        'aios.voice_tts_binary_path' => $fixture['binary'],
        'aios.voice_tts_model_path' => $fixture['model'],
        'aios.voice_tts_timeout_seconds' => 17,
        'aios.voice_tts_max_text_characters' => 1000,
        'aios.voice_tts_max_audio_bytes' => 1024 * 1024,
    ], $overrides);

    foreach ($configuration as $key => $value) {
        config()->set($key, $value);
    }
}

/**
 * Build a minimal RIFF/WAVE byte sequence for fake local synthesis output.
 */
function fakeLocalTextToSpeechWave(int $payloadBytes = 32): string
{
    return 'RIFF'
        .pack('V', 4 + $payloadBytes)
        .'WAVE'
        .str_repeat("\0", $payloadBytes);
}

/**
 * Remove all local TTS fixture files after each test path completes.
 *
 * @param  array{directory: string, binary: string, model: string}  $fixture
 */
function removeLocalTextToSpeechFixture(array $fixture): void
{
    @unlink($fixture['model']);
    @unlink($fixture['binary']);
    @rmdir($fixture['directory']);
}

/**
 * Create one verified authenticated operator for protected local speech routes.
 */
function localTextToSpeechUser(): User
{
    return User::factory()->create([
        'email_verified_at' => now(),
    ]);
}

/**
 * Replace the local TTS adapter with an exact binary response for HTTP transport tests.
 */
function fakeLocalTextToSpeechBoundary(string $audio): void
{
    app()->instance(
        TextToSpeech::class,
        new class($audio) implements TextToSpeech
        {
            /**
             * Store the exact binary audio fixture returned by this test boundary.
             */
            public function __construct(
                private readonly string $audio,
            ) {}

            /**
             * Return the configured WAV bytes without Process fake normalization.
             */
            public function synthesize(string $text): TextToSpeechResult
            {
                return new TextToSpeechResult(
                    successful: true,
                    audio: $this->audio,
                    mimeType: 'audio/wav',
                );
            }
        },
    );
}

test('resolves the explicit local tts boundary and launches no process while disabled', function (): void {
    Process::fake();
    config()->set('aios.voice_tts_enabled', false);

    $service = app(TextToSpeech::class);
    $result = $service->synthesize('AGEAX response');

    expect($service)->toBeInstanceOf(PiperTextToSpeech::class)
        ->and($result->successful)->toBeFalse()
        ->and($result->audio)->toBe('')
        ->and($result->failureType)->toBe('disabled')
        ->and($result->failureMessage)->toBe('Local text-to-speech is disabled.');

    Process::assertNotRan(fn (): bool => true);
});

test('rejects missing non executable and non allowlisted binaries before process execution', function (): void {
    $fixture = localTextToSpeechFixture();
    Process::fake();

    $nonAllowlistedBinary = $fixture['directory'].'/custom-synth';
    file_put_contents($nonAllowlistedBinary, "#!/bin/sh\nexit 0\n");
    chmod($nonAllowlistedBinary, 0700);

    try {
        configureLocalTextToSpeech($fixture, [
            'aios.voice_tts_binary_path' => $fixture['directory'].'/missing-piper',
        ]);
        $missing = app(TextToSpeech::class)->synthesize('AGEAX response');

        chmod($fixture['binary'], 0600);
        configureLocalTextToSpeech($fixture);
        $nonExecutable = app(TextToSpeech::class)->synthesize('AGEAX response');

        chmod($fixture['binary'], 0700);
        configureLocalTextToSpeech($fixture, [
            'aios.voice_tts_binary_path' => $nonAllowlistedBinary,
        ]);
        $nonAllowlisted = app(TextToSpeech::class)->synthesize('AGEAX response');

        expect($missing->failureType)->toBe('binary_unavailable')
            ->and($nonExecutable->failureType)->toBe('binary_unavailable')
            ->and($nonAllowlisted->failureType)->toBe('binary_unavailable');

        Process::assertNotRan(fn (): bool => true);
    } finally {
        @unlink($nonAllowlistedBinary);
        removeLocalTextToSpeechFixture($fixture);
    }
});

test('rejects missing non onnx models and invalid tts limits before process execution', function (): void {
    $fixture = localTextToSpeechFixture();
    Process::fake();

    $invalidModel = $fixture['directory'].'/voice.txt';
    file_put_contents($invalidModel, 'not-onnx');
    chmod($invalidModel, 0600);

    try {
        configureLocalTextToSpeech($fixture, [
            'aios.voice_tts_model_path' => $fixture['directory'].'/missing.onnx',
        ]);
        $missingModel = app(TextToSpeech::class)->synthesize('AGEAX response');

        configureLocalTextToSpeech($fixture, [
            'aios.voice_tts_model_path' => $invalidModel,
        ]);
        $invalidModelResult = app(TextToSpeech::class)->synthesize('AGEAX response');

        expect($missingModel->failureType)->toBe('model_unavailable')
            ->and($invalidModelResult->failureType)->toBe('model_unavailable');

        foreach ([
            'aios.voice_tts_timeout_seconds',
            'aios.voice_tts_max_text_characters',
            'aios.voice_tts_max_audio_bytes',
        ] as $key) {
            configureLocalTextToSpeech($fixture, [$key => 0]);

            $result = app(TextToSpeech::class)->synthesize('AGEAX response');

            expect($result->failureType)->toBe('invalid_configuration');
        }

        Process::assertNotRan(fn (): bool => true);
    } finally {
        @unlink($invalidModel);
        removeLocalTextToSpeechFixture($fixture);
    }
});

test('rejects invalid and oversized text before local tts process execution', function (): void {
    $fixture = localTextToSpeechFixture();
    Process::fake();

    try {
        configureLocalTextToSpeech($fixture, [
            'aios.voice_tts_max_text_characters' => 5,
        ]);

        $oversized = app(TextToSpeech::class)->synthesize('AGEAX response');

        configureLocalTextToSpeech($fixture);
        $invalid = app(TextToSpeech::class)->synthesize("unsafe\0text");

        expect($oversized->failureType)->toBe('text_too_large')
            ->and($invalid->failureType)->toBe('invalid_text');

        Process::assertNotRan(fn (): bool => true);
    } finally {
        removeLocalTextToSpeechFixture($fixture);
    }
});

test('uses stdin safe argv configured timeout and the sanitized execution environment', function (): void {
    $fixture = localTextToSpeechFixture();
    $originalDbPassword = getenv('DB_PASSWORD');
    $originalApiToken = getenv('AIOS_TEST_API_TOKEN');
    putenv('DB_PASSWORD=must-not-reach-piper');
    putenv('AIOS_TEST_API_TOKEN=must-not-reach-piper');
    Process::fake([
        '*' => Process::result(output: fakeLocalTextToSpeechWave()),
    ]);

    try {
        configureLocalTextToSpeech($fixture);

        $result = app(TextToSpeech::class)->synthesize('AGEAX local response.');

        Process::assertRan(function (PendingProcess $process) use ($fixture): bool {
            $command = (new ReflectionProperty($process, 'command'))->getValue($process);
            $timeout = (new ReflectionProperty($process, 'timeout'))->getValue($process);
            $input = (new ReflectionProperty($process, 'input'))->getValue($process);
            $binaryIndex = array_search($fixture['binary'], $command, true);

            if (! is_int($binaryIndex)) {
                return false;
            }

            $providerArguments = array_slice($command, $binaryIndex);

            return $command[0] === '/usr/bin/env'
                && $command[1] === '-i'
                && $timeout === 17
                && $input === "AGEAX local response.\n"
                && ! collect($command)->contains(
                    fn (string $argument): bool => str_starts_with($argument, 'DB_'),
                )
                && ! collect($command)->contains(
                    fn (string $argument): bool => str_starts_with(
                        $argument,
                        'AIOS_TEST_API_TOKEN=',
                    ),
                )
                && $providerArguments === [
                    $fixture['binary'],
                    '--model',
                    $fixture['model'],
                    '--output-file',
                    '-',
                ];
        });

        expect($result->successful)->toBeTrue()
            ->and($result->mimeType)->toBe('audio/wav')
            ->and($result->audio)->toStartWith('RIFF')
            ->and(substr($result->audio, 8, 4))->toBe('WAVE')
            ->and($result->failureType)->toBeNull()
            ->and($result->failureMessage)->toBeNull();
    } finally {
        $originalDbPassword === false
            ? putenv('DB_PASSWORD')
            : putenv('DB_PASSWORD='.$originalDbPassword);
        $originalApiToken === false
            ? putenv('AIOS_TEST_API_TOKEN')
            : putenv('AIOS_TEST_API_TOKEN='.$originalApiToken);
        removeLocalTextToSpeechFixture($fixture);
    }
});

test('rejects oversized invalid and failed provider output without exposing stderr', function (): void {
    $fixture = localTextToSpeechFixture();

    try {
        configureLocalTextToSpeech($fixture, [
            'aios.voice_tts_max_audio_bytes' => 16,
        ]);
        Process::fake([
            '*' => Process::result(output: fakeLocalTextToSpeechWave(64)),
        ]);

        $oversized = app(TextToSpeech::class)->synthesize('AGEAX response');

        configureLocalTextToSpeech($fixture);
        Process::fake([
            '*' => Process::result(output: 'not-wave-audio'),
        ]);

        $invalid = app(TextToSpeech::class)->synthesize('AGEAX response');

        Process::fake([
            '*' => Process::result(
                output: 'must-not-become-audio',
                errorOutput: 'provider-secret-stderr',
                exitCode: 2,
            ),
        ]);

        $failed = app(TextToSpeech::class)->synthesize('AGEAX response');

        expect($oversized->failureType)->toBe('audio_too_large')
            ->and($invalid->failureType)->toBe('invalid_audio_output')
            ->and($failed->successful)->toBeFalse()
            ->and($failed->audio)->toBe('')
            ->and($failed->failureType)->toBe('process_failure')
            ->and($failed->failureMessage)->toBe('Local text-to-speech failed.')
            ->and($failed->failureMessage)->not->toContain('provider-secret-stderr');
    } finally {
        removeLocalTextToSpeechFixture($fixture);
    }
});

test('local tts route remains behind authenticated and verified middleware', function (): void {
    $route = app('router')
        ->getRoutes()
        ->getByName('voice.speech.store');

    expect($route)
        ->toBeInstanceOf(Route::class)
        ->and($route?->methods())
        ->toContain('POST')
        ->and($route?->gatherMiddleware())
        ->toContain('auth')
        ->toContain('verified');
});

test('authenticated route returns non cacheable wav output without durable aios execution state', function (): void {
    $wave = fakeLocalTextToSpeechWave();

    fakeLocalTextToSpeechBoundary($wave);

    $this
        ->actingAs(localTextToSpeechUser())
        ->postJson(
            route('voice.speech.store'),
            ['text' => 'AGEAX local response.'],
        )
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/wav')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertContent($wave);

    expect(AgentRun::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
});

test('tts provider failure is isolated to presentation and creates no durable aios execution state', function (): void {
    $fixture = localTextToSpeechFixture();
    Process::fake([
        '*' => Process::result(
            errorOutput: 'private-provider-detail',
            exitCode: 2,
        ),
    ]);

    try {
        configureLocalTextToSpeech($fixture);

        $this
            ->actingAs(localTextToSpeechUser())
            ->postJson(
                route('voice.speech.store'),
                ['text' => 'AGEAX local response.'],
            )
            ->assertServiceUnavailable()
            ->assertJsonPath('failure_type', 'process_failure')
            ->assertJsonMissing(['private-provider-detail']);

        expect(AgentRun::query()->count())->toBe(0)
            ->and(AuditEvent::query()->count())->toBe(0);
    } finally {
        removeLocalTextToSpeechFixture($fixture);
    }
});

test('normalizes a real local process timeout without requiring piper or a voice model in ci', function (): void {
    $fixture = localTextToSpeechFixture();

    try {
        file_put_contents($fixture['binary'], "#!/bin/sh\nsleep 2\n");
        chmod($fixture['binary'], 0700);
        configureLocalTextToSpeech($fixture, [
            'aios.voice_tts_timeout_seconds' => 1,
        ]);

        $result = app(TextToSpeech::class)->synthesize('AGEAX response');

        expect($result->successful)->toBeFalse()
            ->and($result->failureType)->toBe('timeout')
            ->and($result->audio)->toBe('')
            ->and($result->failureMessage)
            ->toContain('configured timeout');
    } finally {
        removeLocalTextToSpeechFixture($fixture);
    }
});
