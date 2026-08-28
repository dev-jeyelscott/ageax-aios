<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class SynthesizeVoiceSpeechRequest extends FormRequest
{
    /**
     * Require the existing authenticated Laravel boundary for local speech presentation.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validate one bounded text value before invoking the local presentation adapter.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $maxCharacters = max(
            1,
            (int) config('aios.voice_tts_max_text_characters', 1000),
        );

        return [
            'text' => [
                'required',
                'string',
                "max:{$maxCharacters}",
            ],
        ];
    }
}
