<?php

namespace App\Services;

interface TextToSpeech
{
    /**
     * Synthesize one bounded text response through the configured local presentation adapter.
     */
    public function synthesize(string $text): TextToSpeechResult;
}
