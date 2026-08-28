<?php

namespace App\Http\Controllers;

use App\Http\Requests\SynthesizeVoiceSpeechRequest;
use App\Services\TextToSpeech;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Throwable;

final class VoiceSpeechController extends Controller
{
    /**
     * Synthesize one authenticated text response without creating durable AIOS state.
     */
    public function __invoke(
        SynthesizeVoiceSpeechRequest $request,
        TextToSpeech $textToSpeech,
    ): JsonResponse|Response {
        $text = trim((string) $request->validated('text'));

        try {
            $result = $textToSpeech->synthesize($text);
        } catch (Throwable) {
            return response()->json(
                [
                    'message' => 'Local text-to-speech could not be completed.',
                    'failure_type' => 'synthesis_failure',
                ],
                503,
            );
        }

        if (! $result->successful) {
            return response()->json(
                [
                    'message' => $result->failureMessage
                        ?? 'Local text-to-speech failed.',
                    'failure_type' => $result->failureType
                        ?? 'synthesis_failure',
                ],
                $this->failureStatus($result->failureType),
            );
        }

        return response(
            $result->audio,
            200,
            [
                'Content-Type' => $result->mimeType ?? 'audio/wav',
                'Cache-Control' => 'no-store, private',
                'Content-Disposition' => 'inline; filename="ageax-speech.wav"',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * Map caller-correctable text failures to 422 and local capability failures to 503.
     */
    private function failureStatus(?string $failureType): int
    {
        return in_array(
            $failureType,
            ['invalid_text', 'text_too_large'],
            true,
        ) ? 422 : 503;
    }
}
