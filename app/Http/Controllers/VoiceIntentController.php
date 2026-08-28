<?php

namespace App\Http\Controllers;

use App\Actions\RecordProjectManagerMessage;
use App\Actions\RecordTaskOperatorMessage;
use App\AgentRole;
use App\Http\Requests\RouteVoiceIntentRequest;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\VoiceIntentRouter;
use Illuminate\Http\JsonResponse;

final class VoiceIntentController extends Controller
{
    /**
     * Route one explicitly confirmed transcript through the deterministic,
     * allowlisted AGEAX voice-intent boundary.
     */
    public function __invoke(
        RouteVoiceIntentRequest $request,
        Project $project,
        VoiceIntentRouter $router,
        RecordProjectManagerMessage $projectManagerMessages,
        RecordTaskOperatorMessage $taskOperatorMessages,
        AuditLogger $audit,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $transcript = trim(
            (string) $request->validated('transcript'),
        );

        $intent = $router->parse($transcript);

        if ($intent === null) {
            return $this->correctionResponse(
                $transcript,
                'Unsupported or ambiguous voice intent. Review the transcript and use a supported command.',
            );
        }

        if (
            $intent['type'] === VoiceIntentRouter::PROJECT_MANAGER_MESSAGE
            && $intent['body'] !== null
        ) {
            $message = $projectManagerMessages->handle(
                $project,
                $user,
                $intent['body'],
            );

            $audit->record(
                'voice.action_confirmed',
                [
                    'source' => 'voice',
                    'intent' => VoiceIntentRouter::PROJECT_MANAGER_MESSAGE,
                    'confirmation' => 'explicit',
                    'message_id' => $message->id,
                ],
                $project,
            );

            return response()->json([
                'status' => 'executed',
                'intent' => VoiceIntentRouter::PROJECT_MANAGER_MESSAGE,
                'message' => 'Project Manager message recorded.',
                'message_id' => $message->id,
            ]);
        }

        if (
            $intent['type'] === VoiceIntentRouter::TASK_OPERATOR_MESSAGE
            && $intent['task_key'] !== null
            && $intent['recipient_role'] instanceof AgentRole
            && $intent['body'] !== null
        ) {
            $task = $this->findTask(
                $project,
                $intent['task_key'],
            );

            if ($task === null) {
                return $this->correctionResponse(
                    $transcript,
                    "Task {$intent['task_key']} was not found in this project.",
                );
            }

            $message = $taskOperatorMessages->handle(
                $task,
                $user,
                $intent['recipient_role'],
                $intent['body'],
            );

            $audit->record(
                'voice.action_confirmed',
                [
                    'source' => 'voice',
                    'intent' => VoiceIntentRouter::TASK_OPERATOR_MESSAGE,
                    'confirmation' => 'explicit',
                    'message_id' => $message->id,
                    'recipient_role' => $intent['recipient_role']->value,
                ],
                $project,
                $task,
            );

            return response()->json([
                'status' => 'executed',
                'intent' => VoiceIntentRouter::TASK_OPERATOR_MESSAGE,
                'message' => 'Task operator message recorded.',
                'message_id' => $message->id,
                'task' => [
                    'id' => $task->id,
                    'key' => $task->key,
                ],
            ]);
        }

        if ($intent['type'] === VoiceIntentRouter::OPEN_PROJECT) {
            return response()->json([
                'status' => 'ready',
                'intent' => VoiceIntentRouter::OPEN_PROJECT,
                'message' => 'Project navigation is ready.',
                'navigation_url' => route(
                    'projects.show',
                    $project,
                ),
            ]);
        }

        if (
            $intent['type'] === VoiceIntentRouter::OPEN_TASK
            && $intent['task_key'] !== null
        ) {
            $task = $this->findTask(
                $project,
                $intent['task_key'],
            );

            if ($task === null) {
                return $this->correctionResponse(
                    $transcript,
                    "Task {$intent['task_key']} was not found in this project.",
                );
            }

            return response()->json([
                'status' => 'ready',
                'intent' => VoiceIntentRouter::OPEN_TASK,
                'message' => "Task {$task->key} navigation is ready.",
                'navigation_url' => route(
                    'projects.tasks.show',
                    [$project, $task],
                ),
                'task' => [
                    'id' => $task->id,
                    'key' => $task->key,
                    'title' => $task->title,
                    'status' => (string) $task->getRawOriginal('status'),
                ],
            ]);
        }

        if ($intent['type'] === VoiceIntentRouter::LIST_TASKS) {
            $tasks = $project->tasks()
                ->notCleared()
                ->orderBy('position')
                ->limit(50)
                ->get([
                    'id',
                    'project_id',
                    'key',
                    'position',
                    'title',
                    'status',
                ])
                ->map(
                    fn (Task $task): array => [
                        'id' => $task->id,
                        'key' => $task->key,
                        'position' => $task->position,
                        'title' => $task->title,
                        'status' => (string) $task->getRawOriginal('status'),
                    ],
                )
                ->values();

            return response()->json([
                'status' => 'ready',
                'intent' => VoiceIntentRouter::LIST_TASKS,
                'message' => 'Showing up to 50 active project tasks.',
                'tasks' => $tasks,
            ]);
        }

        return $this->correctionResponse(
            $transcript,
            'Unsupported or ambiguous voice intent. Review the transcript and try again.',
        );
    }

    /**
     * Resolve a Task exclusively through the current Project to prevent
     * cross-project voice targeting.
     */
    private function findTask(
        Project $project,
        string $taskKey,
    ): ?Task {
        return $project->tasks()
            ->notCleared()
            ->where('key', $taskKey)
            ->first();
    }

    /**
     * Fail safely and return editable text without executing guessed behavior.
     */
    private function correctionResponse(
        string $transcript,
        string $message,
    ): JsonResponse {
        return response()->json(
            [
                'status' => 'needs_correction',
                'message' => $message,
                'editable_transcript' => $transcript,
                'supported_examples' => [
                    'message project manager: <message>',
                    'message task <TASK-KEY> coder: <message>',
                    'message task <TASK-KEY> reviewer: <message>',
                    'open project',
                    'open task <TASK-KEY>',
                    'show tasks',
                ],
            ],
            422,
        );
    }
}
