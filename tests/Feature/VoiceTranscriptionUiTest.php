<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\ProjectStatus;
use App\TaskStatus;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create one deterministic project for the P9-003 browser voice UI contract.
 */
function p9003Project(): Project
{
    return Project::create([
        'name' => 'P9-003 Voice UX',
        'path' => sys_get_temp_dir()
            .'/ageax-p9003-'
            .fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

/**
 * Create one task whose existing operator-message composer receives the voice adapter.
 */
function p9003Task(
    Project $project,
): Task {
    return Task::create([
        'project_id' => $project->id,
        'key' => 'P9003-001',
        'position' => 1,
        'title' => 'Confirm local voice transcript',
        'objective' => 'Require explicit confirmation before voice-derived text enters AIOS.',
        'acceptance_criteria' => [
            'Voice never submits automatically after transcription.',
            'The transcript remains editable.',
            'Keyboard input remains available.',
        ],
        'implementation_prompt' => 'Reuse the existing operator-message Action after explicit confirmation.',
        'context_capsule' => [],
        'status' => TaskStatus::Queued,
    ]);
}

/**
 * Create one verified authenticated operator for the protected task and voice routes.
 */
function p9003User(): User
{
    return User::factory()->create([
        'email_verified_at' => now(),
    ]);
}

test('task detail exposes only safe bounded voice capabilities', function (): void {
    config()->set(
        'aios.voice_stt_enabled',
        true,
    );
    config()->set(
        'aios.voice_stt_max_audio_bytes',
        3_145_728,
    );
    config()->set(
        'aios.voice_stt_max_duration_seconds',
        42,
    );
    config()->set(
        'aios.voice_stt_binary_path',
        '/private/voice/whisper-cli',
    );
    config()->set(
        'aios.voice_stt_model_path',
        '/private/voice/model.bin',
    );

    $project = p9003Project();
    $task = p9003Task($project);

    $this
        ->actingAs(
            p9003User(),
        )
        ->get(
            route(
                'projects.tasks.show',
                [
                    $project,
                    $task,
                ],
            ),
        )
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component(
                    'projects/tasks/show',
                )
                ->where(
                    'project.id',
                    $project->id,
                )
                ->where(
                    'task.id',
                    $task->id,
                )
                ->where(
                    'voice.enabled',
                    true,
                )
                ->where(
                    'voice.transcription_url',
                    route(
                        'voice.transcriptions.store',
                    ),
                )
                ->where(
                    'voice.max_audio_bytes',
                    3_145_728,
                )
                ->where(
                    'voice.max_duration_seconds',
                    42,
                )
                ->missing(
                    'voice.binary_path',
                )
                ->missing(
                    'voice.model_path',
                ),
        );
});

test('voice transcription and operator submission remain separate browser boundaries', function (): void {
    $taskPageSource = File::get(
        resource_path(
            'js/pages/projects/tasks/show.tsx',
        ),
    );

    $composerSource = File::get(
        resource_path(
            'js/components/task-operator-message-composer.tsx',
        ),
    );

    expect($taskPageSource)
        ->toContain(
            '<TaskOperatorMessageComposer',
        )
        ->not->toContain(
            'storeOperatorMessage',
        );

    expect($composerSource)
        ->toContain(
            'navigator.mediaDevices.getUserMedia',
        )
        ->toContain(
            'new MediaRecorder',
        )
        ->toContain(
            'MediaRecorder.isTypeSupported',
        )
        ->toContain(
            'decodeAudioData',
        )
        ->toContain(
            "type: 'audio/wav'",
        )
        ->toContain(
            'storeOperatorMessage.form({',
        )
        ->toContain(
            'Confirm transcript and send',
        )
        ->toContain(
            'Cancel recording',
        )
        ->toContain(
            'Cancel transcription',
        )
        ->toContain(
            'You can still type normally',
        )
        ->toContain(
            'max_audio_bytes',
        )
        ->toContain(
            'max_duration_seconds',
        )
        ->toContain(
            'AbortController',
        )
        ->not->toContain(
            'localStorage',
        )
        ->not->toContain(
            'sessionStorage',
        )
        ->not->toContain(
            'indexedDB',
        )
        ->not->toContain(
            'useRemember',
        )
        ->not->toContain(
            'dangerouslySetInnerHTML',
        );

    $transcriptionStart = strpos(
        $composerSource,
        'async function transcribeRecordedAudio',
    );

    $recorderStopStart = strpos(
        $composerSource,
        'async function handleRecorderStopped',
        $transcriptionStart === false
            ? 0
            : $transcriptionStart,
    );

    expect($transcriptionStart)
        ->not->toBeFalse()
        ->and($recorderStopStart)
        ->not->toBeFalse();

    $transcriptionSection = substr(
        $composerSource,
        (int) $transcriptionStart,
        (int) $recorderStopStart
            - (int) $transcriptionStart,
    );

    expect($transcriptionSection)
        ->toContain(
            'fetch(',
        )
        ->toContain(
            'bodyRef.current = combined',
        )
        ->toContain(
            "setVoiceState('ready')",
        )
        ->not->toContain(
            'storeOperatorMessage',
        )
        ->not->toContain(
            'router.',
        )
        ->not->toContain(
            'submit(',
        );
});

test('voice route stays authenticated and verified while disabled speech keeps the task UI available', function (): void {
    config()->set(
        'aios.voice_stt_enabled',
        false,
    );

    $route = app('router')
        ->getRoutes()
        ->getByName(
            'voice.transcriptions.store',
        );

    expect($route)
        ->toBeInstanceOf(Route::class)
        ->and(
            $route?->methods(),
        )
        ->toContain('POST')
        ->and(
            $route?->gatherMiddleware(),
        )
        ->toContain('auth')
        ->toContain('verified');

    $project = p9003Project();
    $task = p9003Task($project);

    $this
        ->actingAs(
            p9003User(),
        )
        ->get(
            route(
                'projects.tasks.show',
                [
                    $project,
                    $task,
                ],
            ),
        )
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component(
                    'projects/tasks/show',
                )
                ->where(
                    'voice.enabled',
                    false,
                ),
        );

    $composerSource = File::get(
        resource_path(
            'js/components/task-operator-message-composer.tsx',
        ),
    );

    expect($composerSource)
        ->toContain(
            'Local microphone transcription is disabled',
        )
        ->toContain(
            'name="body"',
        )
        ->toContain(
            'Send instruction',
        );
});
