<?php

namespace App\Services;

final readonly class TextToSpeechResult
{
    /**
     * Represent one normalized local speech synthesis outcome without provider diagnostics.
     */
    public function __construct(
        public bool $successful,
        public string $audio = '',
        public ?string $mimeType = null,
        public ?string $failureType = null,
        public ?string $failureMessage = null,
    ) {}
}
