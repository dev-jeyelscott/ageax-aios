<?php

namespace App\Services;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

final class PiperTextToSpeech implements TextToSpeech
{
    /** @var list<string> */
    private const array ALLOWED_EXECUTABLE_NAMES = ['piper'];

    /**
     * Create the local Piper adapter with the shared sanitized process environment.
     */
    public function __construct(
        private SanitizedExecutionEnvironment $environment,
    ) {}

    /**
     * Synthesize one bounded text value into an in-memory WAV response through local Piper.
     */
    public function synthesize(string $text): TextToSpeechResult
    {
        if (! (bool) config('aios.voice_tts_enabled', false)) {
            return $this->failure(
                'disabled',
                'Local text-to-speech is disabled.',
            );
        }

        $timeoutSeconds = (int) config('aios.voice_tts_timeout_seconds', 0);
        $maxTextCharacters = (int) config('aios.voice_tts_max_text_characters', 0);
        $maxAudioBytes = (int) config('aios.voice_tts_max_audio_bytes', 0);

        if ($timeoutSeconds < 1 || $maxTextCharacters < 1 || $maxAudioBytes < 1) {
            return $this->failure(
                'invalid_configuration',
                'Local text-to-speech limits are invalid.',
            );
        }

        $normalizedText = trim($text);

        if (! $this->validText($normalizedText)) {
            return $this->failure(
                'invalid_text',
                'The text-to-speech input is invalid.',
            );
        }

        $textCharacters = Str::length($normalizedText);

        if ($textCharacters > $maxTextCharacters) {
            return $this->failure(
                'text_too_large',
                'The text-to-speech input exceeds the configured character limit.',
            );
        }

        $binaryPath = $this->resolveExecutable(
            (string) config('aios.voice_tts_binary_path', ''),
        );

        if ($binaryPath === null) {
            return $this->failure(
                'binary_unavailable',
                'The configured local text-to-speech binary is unavailable or not allowlisted.',
            );
        }

        $modelPath = $this->resolveModel(
            (string) config('aios.voice_tts_model_path', ''),
        );

        if ($modelPath === null) {
            return $this->failure(
                'model_unavailable',
                'The configured local text-to-speech model is unavailable.',
            );
        }

        $command = $this->environment->wrap([
            $binaryPath,
            '--model',
            $modelPath,
            '--output-file',
            '-',
        ]);

        $startedAt = hrtime(true);

        try {
            $result = Process::input($normalizedText."\n")
                ->timeout($timeoutSeconds)
                ->run($command);
        } catch (ProcessTimedOutException) {
            return $this->observedFailure(
                'timeout',
                'Local text-to-speech exceeded the configured timeout.',
                $textCharacters,
                $startedAt,
            );
        } catch (Throwable) {
            return $this->observedFailure(
                'process_failure',
                'Local text-to-speech could not be started.',
                $textCharacters,
                $startedAt,
            );
        }

        if (! $result->successful()) {
            return $this->observedFailure(
                'process_failure',
                'Local text-to-speech failed.',
                $textCharacters,
                $startedAt,
            );
        }

        $audio = $result->output();

        if (strlen($audio) > $maxAudioBytes) {
            return $this->observedFailure(
                'audio_too_large',
                'Local text-to-speech output exceeds the configured size limit.',
                $textCharacters,
                $startedAt,
            );
        }

        if (! $this->isWaveAudio($audio)) {
            return $this->observedFailure(
                'invalid_audio_output',
                'Local text-to-speech returned invalid audio output.',
                $textCharacters,
                $startedAt,
            );
        }

        return new TextToSpeechResult(
            successful: true,
            audio: $audio,
            mimeType: 'audio/wav',
        );
    }

    /**
     * Validate UTF-8 text and reject control characters that have no legitimate speech purpose.
     */
    private function validText(string $text): bool
    {
        if ($text === '' || preg_match('//u', $text) !== 1) {
            return false;
        }

        return preg_match(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',
            $text,
        ) !== 1;
    }

    /**
     * Resolve only an explicitly configured absolute executable whose canonical name is allowlisted.
     */
    private function resolveExecutable(string $path): ?string
    {
        if ($path === '' || ! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return null;
        }

        $resolved = realpath($path);

        if (
            $resolved === false
            || ! is_file($resolved)
            || ! is_executable($resolved)
            || ! in_array(
                basename($resolved),
                self::ALLOWED_EXECUTABLE_NAMES,
                true,
            )
        ) {
            return null;
        }

        return $resolved;
    }

    /**
     * Resolve only an explicitly configured absolute readable ONNX voice model.
     */
    private function resolveModel(string $path): ?string
    {
        if ($path === '' || ! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return null;
        }

        $resolved = realpath($path);

        if (
            $resolved === false
            || ! is_file($resolved)
            || ! is_readable($resolved)
            || strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) !== 'onnx'
        ) {
            return null;
        }

        return $resolved;
    }

    /**
     * Validate the minimum RIFF/WAVE signature before returning provider output to the browser.
     */
    private function isWaveAudio(string $audio): bool
    {
        return strlen($audio) >= 12
            && substr($audio, 0, 4) === 'RIFF'
            && substr($audio, 8, 4) === 'WAVE';
    }

    /**
     * Record bounded operational failure metadata without logging the spoken text or provider stderr.
     */
    private function observedFailure(
        string $type,
        string $message,
        int $textCharacters,
        int $startedAt,
    ): TextToSpeechResult {
        Log::warning(
            'Local text-to-speech synthesis failed.',
            [
                'failure_type' => $type,
                'text_characters' => $textCharacters,
                'duration_ms' => $this->elapsedMilliseconds($startedAt),
            ],
        );

        return $this->failure($type, $message);
    }

    /**
     * Calculate bounded process duration metadata for presentation-layer observability.
     */
    private function elapsedMilliseconds(int $startedAt): int
    {
        return max(
            0,
            (int) round((hrtime(true) - $startedAt) / 1_000_000),
        );
    }

    /**
     * Build one normalized failure result without raw provider output, host paths, or spoken text.
     */
    private function failure(string $type, string $message): TextToSpeechResult
    {
        return new TextToSpeechResult(
            successful: false,
            failureType: $type,
            failureMessage: $message,
        );
    }
}
