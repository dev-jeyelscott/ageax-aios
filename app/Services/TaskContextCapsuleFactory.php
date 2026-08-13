<?php

namespace App\Services;

use App\AgentRole;
use App\Models\Task;
use App\Models\TaskAttempt;

class TaskContextCapsuleFactory
{
    public function __construct(private ObsidianProjectNotes $notes) {}

    /** @return array<string, mixed> */
    public function make(Task $task, AgentRole $recipientRole = AgentRole::Coder): array
    {
        $retrieval = $this->notes->taskRetrieval($task, $recipientRole);
        $previousAttempt = $task->attempts()
            ->latest('number')
            ->first(['id', 'task_id', 'number', 'base_sha', 'head_sha', 'commit_sha', 'status', 'validation_results', 'changed_files', 'log_path', 'finished_at']);

        return [
            'task_key' => $task->key,
            'title' => $task->title,
            'objective' => $task->objective,
            'acceptance_criteria' => $task->acceptance_criteria,
            'implementation_prompt' => $task->implementation_prompt,
            'scope' => $task->scope,
            'constraints' => $task->constraints,
            'dependencies' => $task->dependencies()->pluck('key')->all(),
            'relevant_paths' => $task->relevant_paths,
            'verification_commands' => $task->verification_commands,
            'previous_attempt' => $this->previousAttemptContext($previousAttempt, $recipientRole),
            'obsidian_project_knowledge' => $retrieval['notes'],
            'retrieval_manifest' => $retrieval['manifest'],
            'operator_messages' => $task->operatorMessages()
                ->where('recipient_role', $recipientRole)
                ->whereNull('delivered_at')
                ->oldest()
                ->get(['id', 'body', 'created_at'])
                ->map(fn ($message): array => ['id' => $message->id, 'body' => $message->body, 'created_at' => $message->created_at?->toIso8601String()])
                ->all(),
            'review_findings' => $task->reviews()->latest()->with('findings')->first()?->findings->map(fn ($finding): array => $finding->only(['severity', 'location', 'current_implementation', 'expected_implementation', 'why_incorrect', 'required_fix', 'verification_requirement', 'implementation_fix_context']))->all() ?? [],
        ];
    }

    /** @return array<string, mixed>|null */
    private function previousAttemptContext(?TaskAttempt $attempt, AgentRole $recipientRole): ?array
    {
        if ($attempt === null) {
            return null;
        }

        if ($recipientRole !== AgentRole::Coder) {
            return $attempt->only(['number', 'base_sha', 'head_sha', 'commit_sha', 'status', 'validation_results', 'changed_files', 'log_path', 'finished_at']);
        }

        $validationResults = json_decode((string) $attempt->getRawOriginal('validation_results'), true);
        $evidence = is_array($validationResults['evidence'] ?? null) ? $validationResults['evidence'] : [];
        $failedEvidence = collect($evidence)
            ->filter(fn (mixed $item): bool => is_array($item) && ($item['passed'] ?? true) === false)
            ->all();

        return [
            ...$attempt->only(['number', 'base_sha', 'head_sha', 'commit_sha', 'status', 'changed_files', 'finished_at']),
            'failed_validation_evidence' => $failedEvidence,
        ];
    }
}
