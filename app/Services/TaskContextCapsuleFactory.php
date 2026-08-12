<?php

namespace App\Services;

use App\AgentRole;
use App\Models\Task;

class TaskContextCapsuleFactory
{
    public function __construct(private ObsidianProjectNotes $notes) {}

    /** @return array<string, mixed> */
    public function make(Task $task, AgentRole $recipientRole = AgentRole::Coder): array
    {
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
            'previous_attempt' => $previousAttempt?->only(['number', 'base_sha', 'head_sha', 'commit_sha', 'status', 'validation_results', 'changed_files', 'log_path', 'finished_at']),
            'obsidian_project_knowledge' => $this->notes->projectKnowledge($task->project),
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
}
