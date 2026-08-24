<?php

namespace App\Services;

final readonly class SpeechToTextResult
{
    /**
     * Represent one normalized local speech transcription outcome without exposing provider stderr.
     */
    public function __construct(
        public bool $successful,
        public string $transcript = '',
        public ?string $failureType = null,
        public ?string $failureMessage = null,
    ) {}
}
