<?php

use App\Services\SpeechToText;
use App\Services\WhisperCppSpeechToText;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/**
 * Create isolated executable, model, and audio fixture files for local speech tests.
 *
 * @return array{directory: string, binary: string, model: string, audio: string}
 */
function whisperCppSpeechFixture(): array
{
    $directory = sys_get_temp_dir().'/ageax-whisper-'.fake()->uuid();
    mkdir($directory, 0700, true);

    $binary = $directory.'/whisper-cli';
    $model = $directory.'/model.bin';
    $audio = $directory.'/audio.wav';

    file_put_contents($binary, "#!/bin/sh\nexit 0\n");
    file_put_contents($model, 'test-model');
    file_put_contents($audio, 'test-audio');
    chmod($binary, 0700);
    chmod($model, 0600);
    chmod($audio, 0600);

    return compact('directory', 'binary', 'model', 'audio');
}

/**
 * Apply one valid local speech configuration, optionally overriding individual values.
 *
 * @param  array{binary: string, model: string, audio: string}  $fixture
 * @param  array<string, mixed>  $overrides
 */
function configureWhisperCppSpeech(array $fixture, array $overrides = []): void
{
    $configuration = array_replace([
        'aios.voice_stt_enabled' => true,
        'aios.voice_stt_binary_path' => $fixture['binary'],
        'aios.voice_stt_model_path' => $fixture['model'],
        'aios.voice_stt_timeout_seconds' => 17,
        'aios.voice_stt_max_audio_bytes' => 1024,
        'aios.voice_stt_max_duration_seconds' => 30,
    ], $overrides);

    foreach ($configuration as $key => $value) {
        config()->set($key, $value);
    }
}

/**
 * Remove all temporary local speech fixture files after a test completes.
 *
 * @param  array{directory: string, binary: string, model: string, audio: string}  $fixture
 */
function removeWhisperCppSpeechFixture(array $fixture): void
{
    @unlink($fixture['audio']);
    @unlink($fixture['model']);
    @unlink($fixture['binary']);
    @rmdir($fixture['directory']);
}

test('resolves the explicit local speech boundary and launches no process while disabled', function () {
    Process::fake();
    config()->set('aios.voice_stt_enabled', false);

    $service = app(SpeechToText::class);
    $result = $service->transcribe('/tmp/not-used.wav');

    expect($service)->toBeInstanceOf(WhisperCppSpeechToText::class)
        ->and($result->successful)->toBeFalse()
        ->and($result->transcript)->toBe('')
        ->and($result->failureType)->toBe('disabled')
        ->and($result->failureMessage)->toBe('Local speech transcription is disabled.');

    Process::assertNotRan(fn (): bool => true);
});

test('fails safely for missing and non executable configured binaries without fallback', function () {
    $fixture = whisperCppSpeechFixture();
    Process::fake();

    try {
        configureWhisperCppSpeech($fixture, [
            'aios.voice_stt_binary_path' => $fixture['directory'].'/missing-whisper-cli',
        ]);

        $missing = app(SpeechToText::class)->transcribe($fixture['audio']);

        chmod($fixture['binary'], 0600);
        configureWhisperCppSpeech($fixture);

        $nonExecutable = app(SpeechToText::class)->transcribe($fixture['audio']);

        expect($missing->successful)->toBeFalse()
            ->and($missing->failureType)->toBe('binary_unavailable')
            ->and($nonExecutable->successful)->toBeFalse()
            ->and($nonExecutable->failureType)->toBe('binary_unavailable');

        Process::assertNotRan(fn (): bool => true);
    } finally {
        removeWhisperCppSpeechFixture($fixture);
    }
});

test('fails safely for missing model and audio files before process execution', function () {
    $fixture = whisperCppSpeechFixture();
    Process::fake();

    try {
        configureWhisperCppSpeech($fixture, [
            'aios.voice_stt_model_path' => $fixture['directory'].'/missing-model.bin',
        ]);

        $missingModel = app(SpeechToText::class)->transcribe($fixture['audio']);

        configureWhisperCppSpeech($fixture);
        $missingAudio = app(SpeechToText::class)->transcribe(
            $fixture['directory'].'/missing-audio.wav',
        );

        expect($missingModel->successful)->toBeFalse()
            ->and($missingModel->failureType)->toBe('model_unavailable')
            ->and($missingAudio->successful)->toBeFalse()
            ->and($missingAudio->failureType)->toBe('audio_unavailable');

        Process::assertNotRan(fn (): bool => true);
    } finally {
        removeWhisperCppSpeechFixture($fixture);
    }
});

test('rejects invalid speech limits before process execution', function () {
    $fixture = whisperCppSpeechFixture();
    Process::fake();

    try {
        foreach ([
            'aios.voice_stt_timeout_seconds',
            'aios.voice_stt_max_audio_bytes',
            'aios.voice_stt_max_duration_seconds',
        ] as $key) {
            configureWhisperCppSpeech($fixture, [$key => 0]);

            $result = app(SpeechToText::class)->transcribe($fixture['audio']);

            expect($result->successful)->toBeFalse()
                ->and($result->failureType)->toBe('invalid_configuration');
        }

        Process::assertNotRan(fn (): bool => true);
    } finally {
        removeWhisperCppSpeechFixture($fixture);
    }
});

test('rejects oversized audio before local speech process execution', function () {
    $fixture = whisperCppSpeechFixture();
    Process::fake();

    try {
        file_put_contents($fixture['audio'], str_repeat('a', 64));
        configureWhisperCppSpeech($fixture, [
            'aios.voice_stt_max_audio_bytes' => 16,
        ]);

        $result = app(SpeechToText::class)->transcribe($fixture['audio']);

        expect($result->successful)->toBeFalse()
            ->and($result->failureType)->toBe('audio_too_large')
            ->and($result->transcript)->toBe('');

        Process::assertNotRan(fn (): bool => true);
    } finally {
        removeWhisperCppSpeechFixture($fixture);
    }
});

test('uses safe argv invocation configured timeout and the sanitized execution environment', function () {
    $fixture = whisperCppSpeechFixture();
    $originalDbPassword = getenv('DB_PASSWORD');
    $originalApiToken = getenv('AIOS_TEST_API_TOKEN');
    putenv('DB_PASSWORD=must-not-reach-whisper');
    putenv('AIOS_TEST_API_TOKEN=must-not-reach-whisper');
    Process::fake(['*' => Process::result(output: "  transcribed locally  \n")]);

    try {
        configureWhisperCppSpeech($fixture);

        $result = app(SpeechToText::class)->transcribe($fixture['audio']);

        Process::assertRan(function (PendingProcess $process) use ($fixture): bool {
            $command = (new ReflectionProperty($process, 'command'))->getValue($process);
            $timeout = (new ReflectionProperty($process, 'timeout'))->getValue($process);
            $binaryIndex = array_search($fixture['binary'], $command, true);

            if (! is_int($binaryIndex)) {
                return false;
            }

            $providerArguments = array_slice($command, $binaryIndex);

            return $command[0] === '/usr/bin/env'
                && $command[1] === '-i'
                && $timeout === 17
                && ! collect($command)->contains(fn (string $argument): bool => str_starts_with($argument, 'DB_'))
                && ! collect($command)->contains(fn (string $argument): bool => str_starts_with($argument, 'AIOS_TEST_API_TOKEN='))
                && $providerArguments === [
                    $fixture['binary'],
                    '--model',
                    $fixture['model'],
                    '--file',
                    $fixture['audio'],
                    '--no-prints',
                    '--no-timestamps',
                    '--duration',
                    '30000',
                ];
        });

        expect($result->successful)->toBeTrue()
            ->and($result->transcript)->toBe('transcribed locally')
            ->and($result->failureType)->toBeNull()
            ->and($result->failureMessage)->toBeNull();
    } finally {
        $originalDbPassword === false
            ? putenv('DB_PASSWORD')
            : putenv('DB_PASSWORD='.$originalDbPassword);
        $originalApiToken === false
            ? putenv('AIOS_TEST_API_TOKEN')
            : putenv('AIOS_TEST_API_TOKEN='.$originalApiToken);
        removeWhisperCppSpeechFixture($fixture);
    }
});

test('does not expose provider stderr or output when local transcription fails', function () {
    $fixture = whisperCppSpeechFixture();
    Process::fake([
        '*' => Process::result(
            output: 'must-not-become-transcript',
            errorOutput: 'provider-secret-stderr',
            exitCode: 2,
        ),
    ]);

    try {
        configureWhisperCppSpeech($fixture);

        $result = app(SpeechToText::class)->transcribe($fixture['audio']);

        expect($result->successful)->toBeFalse()
            ->and($result->failureType)->toBe('process_failure')
            ->and($result->transcript)->toBe('')
            ->and($result->failureMessage)->toBe('Local speech transcription failed.')
            ->and($result->failureMessage)->not->toContain('provider-secret-stderr');
    } finally {
        removeWhisperCppSpeechFixture($fixture);
    }
});

test('normalizes a real local process timeout without requiring whisper or a model in CI', function () {
    $fixture = whisperCppSpeechFixture();

    try {
        file_put_contents($fixture['binary'], "#!/bin/sh\nsleep 2\n");
        chmod($fixture['binary'], 0700);
        configureWhisperCppSpeech($fixture, [
            'aios.voice_stt_timeout_seconds' => 1,
        ]);

        $result = app(SpeechToText::class)->transcribe($fixture['audio']);

        expect($result->successful)->toBeFalse()
            ->and($result->failureType)->toBe('timeout')
            ->and($result->transcript)->toBe('')
            ->and($result->failureMessage)->toContain('configured timeout');
    } finally {
        removeWhisperCppSpeechFixture($fixture);
    }
});
