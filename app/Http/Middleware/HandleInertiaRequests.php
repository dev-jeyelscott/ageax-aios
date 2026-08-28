<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Share safe application and local voice capability metadata with Inertia.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'voice' => [
                'enabled' => (bool) config(
                    'aios.voice_stt_enabled',
                    false,
                ),
                'transcription_url' => route(
                    'voice.transcriptions.store',
                ),
                'max_audio_bytes' => max(
                    0,
                    (int) config(
                        'aios.voice_stt_max_audio_bytes',
                        0,
                    ),
                ),
                'max_duration_seconds' => max(
                    0,
                    (int) config(
                        'aios.voice_stt_max_duration_seconds',
                        0,
                    ),
                ),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state')
                || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
