<?php

namespace App\Services;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Throwable;

final class WhisperCppSpeechToText implements SpeechToText
{
    public function __construct(
        private SanitizedExecutionEnvironment $environment,
    ) {}

    /**
     * Transcribe one bounded local audio file through the explicitly configured whisper.cpp binary.
     */
    public function transcribe(string $audioPath): SpeechToTextResult
    {
        if (! (bool) config('aios.voice_stt_enabled', false)) {
            return $this->failure(
                'disabled',
                'Local speech transcription is disabled.',
            );
        }

        $timeoutSeconds = (int) config('aios.voice_stt_timeout_seconds', 0);
        $maxAudioBytes = (int) config('aios.voice_stt_max_audio_bytes', 0);
        $maxDurationSeconds = (int) config('aios.voice_stt_max_duration_seconds', 0);

        if ($timeoutSeconds < 1 || $maxAudioBytes < 1 || $maxDurationSeconds < 1) {
            return $this->failure(
                'invalid_configuration',
                'Local speech transcription limits are invalid.',
            );
        }

        $binaryPath = $this->resolveExecutable(
            (string) config('aios.voice_stt_binary_path', ''),
        );

        if ($binaryPath === null) {
            return $this->failure(
                'binary_unavailable',
                'The configured local speech transcription binary is unavailable.',
            );
        }

        $modelPath = $this->resolveReadableFile(
            (string) config('aios.voice_stt_model_path', ''),
        );

        if ($modelPath === null) {
            return $this->failure(
                'model_unavailable',
                'The configured local speech transcription model is unavailable.',
            );
        }

        $resolvedAudioPath = $this->resolveReadableFile($audioPath);

        if ($resolvedAudioPath === null) {
            return $this->failure(
                'audio_unavailable',
                'The local audio file is unavailable.',
            );
        }

        $audioBytes = filesize($resolvedAudioPath);

        if ($audioBytes === false) {
            return $this->failure(
                'audio_unavailable',
                'The local audio file could not be inspected.',
            );
        }

        if ($audioBytes > $maxAudioBytes) {
            return $this->failure(
                'audio_too_large',
                'The local audio file exceeds the configured size limit.',
            );
        }

        $command = $this->environment->wrap([
            $binaryPath,
            '--model',
            $modelPath,
            '--file',
            $resolvedAudioPath,
            '--no-prints',
            '--no-timestamps',
            '--duration',
            (string) ($maxDurationSeconds * 1000),
        ]);

        try {
            $result = Process::timeout($timeoutSeconds)->run($command);
        } catch (ProcessTimedOutException) {
            return $this->failure(
                'timeout',
                'Local speech transcription exceeded the configured timeout.',
            );
        } catch (Throwable) {
            return $this->failure(
                'process_failure',
                'Local speech transcription could not be started.',
            );
        }

        if (! $result->successful()) {
            return $this->failure(
                'process_failure',
                'Local speech transcription failed.',
            );
        }

        return new SpeechToTextResult(
            successful: true,
            transcript: trim($result->output()),
        );
    }

    /**
     * Resolve one explicitly configured absolute executable path without searching PATH.
     */
    private function resolveExecutable(string $path): ?string
    {
        if ($path === '' || ! str_starts_with($path, '/')) {
            return null;
        }

        $resolved = realpath($path);

        if ($resolved === false || ! is_file($resolved) || ! is_executable($resolved)) {
            return null;
        }

        return $resolved;
    }

    /**
     * Resolve one readable regular file to its canonical local path.
     */
    private function resolveReadableFile(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $resolved = realpath($path);

        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            return null;
        }

        return $resolved;
    }

    /**
     * Build one bounded failure result that never exposes raw provider stderr or host paths.
     */
    private function failure(string $type, string $message): SpeechToTextResult
    {
        return new SpeechToTextResult(
            successful: false,
            failureType: $type,
            failureMessage: $message,
        );
    }
}
