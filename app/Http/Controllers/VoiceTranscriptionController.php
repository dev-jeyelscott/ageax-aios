<?php

namespace App\Http\Controllers;

use App\Http\Requests\TranscribeVoiceAudioRequest;
use App\Services\VoiceAudioTranscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Throwable;

final class VoiceTranscriptionController extends Controller
{
    /**
     * Transcribe one authenticated temporary audio upload without persisting audio or workflow state.
     */
    public function __invoke(
        TranscribeVoiceAudioRequest $request,
        VoiceAudioTranscriber $transcriber,
    ): JsonResponse {
        $audio = $request->file('audio');

        if (! $audio instanceof UploadedFile) {
            return response()->json(
                [
                    'message' => 'A valid audio sample is required.',
                    'failure_type' => 'invalid_audio',
                ],
                422,
            );
        }

        try {
            $result = $transcriber->transcribe($audio);
        } catch (Throwable) {
            return response()->json(
                [
                    'message' => 'Local speech transcription could not be completed.',
                    'failure_type' => 'transcription_failure',
                ],
                500,
            );
        }

        if (! $result->successful) {
            return response()->json(
                [
                    'message' => $result->failureMessage
                        ?? 'Local speech transcription failed.',
                    'failure_type' => $result->failureType
                        ?? 'transcription_failure',
                ],
                503,
            );
        }

        return response()->json([
            'transcript' => $result->transcript,
        ]);
    }
}
