<?php

namespace App\Services;

interface SpeechToText
{
    /**
     * Transcribe one caller-supplied local audio file without owning any workflow authority.
     */
    public function transcribe(string $audioPath): SpeechToTextResult;
}
