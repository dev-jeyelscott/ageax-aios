<?php

namespace App\Http\Requests;

use App\Services\VoiceAudioTranscriber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;
use Throwable;

final class TranscribeVoiceAudioRequest extends FormRequest
{
    /**
     * Restrict temporary voice ingestion to authenticated users.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Require exactly one successfully uploaded audio file.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'audio' => [
                'required',
                'file',
            ],
        ];
    }

    /**
     * Attach exact byte and server-detected MIME validation after normal file validation.
     */
    public function withValidator(
        Validator $validator,
    ): void {
        $validator->after([
            $this,
            'validateVoiceAudio',
        ]);
    }

    /**
     * Reject oversized or unsupported audio before the transcription service is invoked.
     */
    public function validateVoiceAudio(
        Validator $validator,
    ): void {
        if ($validator->errors()->has('audio')) {
            return;
        }

        $audio = $this->file('audio');

        if (! $audio instanceof UploadedFile) {
            $validator->errors()->add(
                'audio',
                'A valid audio sample is required.',
            );

            return;
        }

        $maxAudioBytes = (int) config(
            'aios.voice_stt_max_audio_bytes',
            0,
        );

        $size = $audio->getSize();

        if (
            $maxAudioBytes < 1
            || ! is_int($size)
            || $size < 1
        ) {
            $validator->errors()->add(
                'audio',
                'The audio sample could not be inspected.',
            );

            return;
        }

        if ($size > $maxAudioBytes) {
            $validator->errors()->add(
                'audio',
                'The audio sample exceeds the configured size limit.',
            );

            return;
        }

        try {
            $mimeType = $audio->getMimeType();
        } catch (Throwable) {
            $mimeType = null;
        }

        if (! VoiceAudioTranscriber::supportsMimeType($mimeType)) {
            $validator->errors()->add(
                'audio',
                'The audio sample type is not supported.',
            );
        }
    }
}
