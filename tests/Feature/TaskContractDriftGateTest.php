<?php

use App\Actions\RequeueBlockedTask;
use App\Actions\RunCoderTask;
use App\Actions\RunReviewerTask;
use App\AgentRole;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\Services\AgentContextAssembler;
use App\Services\AuditLogger;
use App\Services\TaskContextCapsuleFactory;
use App\Services\TaskContractGuard;
use App\TaskStatus;
use Illuminate\Support\Facades\File;

function taskContractProject(string $name): Project
{
    $path = sys_get_temp_dir().'/aios-contract-drift-'.fake()->uuid();
    File::ensureDirectoryExists($path);

    return Project::create([
        'name' => $name,
        'path' => $path,
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

function taskContractTask(Project $project, TaskStatus $status = TaskStatus::Coding): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Contract drift task',
        'objective' => 'Implement deterministic contract drift detection.',
        'acceptance_criteria' => ['Detect changed requirements.', 'Do not execute on drift.'],
        'scope' => ['Workflow orchestration'],
        'constraints' => ['No new infrastructure.', 'Keep state durable.'],
        'relevant_paths' => [],
        'verification_commands' => ['php artisan test --compact tests/Feature/TaskContractDriftGateTest.php'],
        'implementation_prompt' => 'Implement the smallest deterministic task contract gate.',
        'context_capsule' => [],
        'status' => $status,
    ]);
}

/** @return array<string, mixed> */
function taskContractContext(array $notes = [], array $selected = []): array
{
    return [
        'obsidian_project_knowledge' => $notes,
        'retrieval_manifest' => ['selected_note_paths' => $selected],
    ];
}

test('unchanged contracts proceed and set-like input reordering does not create false drift', function () {
    $project = taskContractProject('Equivalent contract project');
    $task = taskContractTask($project);
    $context = taskContractContext(
        ['STATE.md' => 'mutable runtime state', 'Specs/Contract.md' => 'Stable contract note.'],
        ['STATE.md', 'Specs/Contract.md'],
    );
    $guard = app(TaskContractGuard::class);
    $baseline = $guard->evidence($task, $context);
    TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 1,
        'status' => 'failed',
        'validation_results' => ['task_contract' => $baseline],
        'started_at' => now()->subMinute(),
        'finished_at' => now()->subMinute(),
    ]);

    $task->update([
        'acceptance_criteria' => array_reverse($task->acceptance_criteria),
        'constraints' => array_reverse($task->constraints),
        'scope' => array_reverse($task->scope),
    ]);
    $equivalentContext = taskContractContext(
        ['Specs/Contract.md' => 'Stable contract note.', 'STATE.md' => 'different runtime state'],
        ['Specs/Contract.md', 'STATE.md'],
    );

    $result = $guard->evaluate($task->refresh(), $equivalentContext);

    expect($result['drifted'])->toBeFalse()
        ->and($result['changed_inputs'])->toBe([])
        ->and($result['baseline']['fingerprint'])->toBe($result['current']['fingerprint']);
});

test('acceptance criteria changes create deterministic contract drift', function () {
    $project = taskContractProject('Acceptance drift project');
    $task = taskContractTask($project);
    $guard = app(TaskContractGuard::class);
    $context = taskContractContext();
    $baseline = $guard->evidence($task, $context);
    TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 1,
        'status' => 'failed',
        'validation_results' => ['task_contract' => $baseline],
        'started_at' => now()->subMinute(),
        'finished_at' => now()->subMinute(),
    ]);

    $task->update(['acceptance_criteria' => ['Detect changed requirements.', 'Reviewer must never execute after drift.']]);
    $result = $guard->evaluate($task->refresh(), $context);

    expect($result['drifted'])->toBeTrue()
        ->and($result['changed_inputs'])->toContain('acceptance_criteria');
});

test('task scoped repository documentation and selected Obsidian changes are fingerprinted without persisting their contents', function () {
    $project = taskContractProject('Documentation drift project');
    File::ensureDirectoryExists($project->path.'/docs');
    File::put($project->path.'/docs/task-contract.md', 'Contract version one.');
    $task = taskContractTask($project);
    $task->update(['relevant_paths' => ['docs/task-contract.md']]);
    $guard = app(TaskContractGuard::class);
    $context = taskContractContext(
        ['STATE.md' => 'runtime state', 'Architecture/Contract.md' => 'Obsidian version one.'],
        ['STATE.md', 'Architecture/Contract.md'],
    );
    $baseline = $guard->evidence($task->refresh(), $context);
    $attempt = TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 1,
        'status' => 'failed',
        'validation_results' => ['task_contract' => $baseline],
        'started_at' => now()->subMinute(),
        'finished_at' => now()->subMinute(),
    ]);

    File::put($project->path.'/docs/task-contract.md', 'Contract version two.');
    $repositoryDrift = $guard->evaluate($task->refresh(), $context);
    File::put($project->path.'/docs/task-contract.md', 'Contract version one.');
    $obsidianDrift = $guard->evaluate($task->refresh(), taskContractContext(
        ['STATE.md' => 'another runtime value', 'Architecture/Contract.md' => 'Obsidian version two.'],
        ['Architecture/Contract.md', 'STATE.md'],
    ));
    $persisted = $guard->attemptEvidence($attempt->refresh());

    expect($repositoryDrift['changed_inputs'])->toContain('repository_documents:docs/task-contract.md')
        ->and($obsidianDrift['changed_inputs'])->toContain('obsidian_notes:Architecture/Contract.md')
        ->and(json_encode($persisted))->not->toContain('Contract version one')
        ->and(json_encode($persisted))->not->toContain('Obsidian version one');
});

test('a documented task output does not drift its own retry contract', function () {
    $project = taskContractProject('Documentation output recovery project');
    File::ensureDirectoryExists($project->path.'/docs');
    $task = taskContractTask($project);
    $task->update(['relevant_paths' => ['docs/task-output.md']]);
    $guard = app(TaskContractGuard::class);
    $context = taskContractContext();
    $baseline = $guard->evidence($task->refresh(), $context);

    File::put($project->path.'/docs/task-output.md', 'Generated task result.');
    TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 1,
        'status' => 'failed',
        'validation_results' => ['task_contract' => $baseline],
        'changed_files' => ['docs/task-output.md'],
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    $result = $guard->evaluate($task->refresh(), $context);

    expect($result['drifted'])->toBeFalse()
        ->and($result['changed_inputs'])->not->toContain('repository_documents:docs/task-output.md')
        ->and($result['current']['fingerprint'])->toBe($result['baseline']['fingerprint']);
});

test('coder retry is blocked on contract drift before an AgentRun can start', function () {
    $project = taskContractProject('Coder drift gate project');
    $task = taskContractTask($project, TaskStatus::Coding);
    $context = app(TaskContextCapsuleFactory::class)->make($task);
    $baseline = app(TaskContractGuard::class)->evidence($task, $context);
    TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 1,
        'base_sha' => 'test-base-sha',
        'status' => 'failed',
        'validation_results' => ['task_contract' => $baseline],
        'changed_files' => [],
        'started_at' => now()->subMinute(),
        'finished_at' => now()->subMinute(),
    ]);
    $task->update(['acceptance_criteria' => ['Changed acceptance criteria after implementation began.']]);

    $blockedAttempt = app(RunCoderTask::class)->handle($task->refresh());

    expect($blockedAttempt->status)->toBe('blocked')
        ->and($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and(AgentRun::query()->whereBelongsTo($task)->count())->toBe(0)
        ->and($task->auditEvents()->where('event_type', 'task.contract_drift_detected')->exists())->toBeTrue();
});

test('reviewer execution is blocked on drift before paid Agent execution', function () {
    $project = taskContractProject('Reviewer drift gate project');
    $task = taskContractTask($project, TaskStatus::Reviewing);
    $context = app(TaskContextCapsuleFactory::class)->make($task, AgentRole::Reviewer);
    $baseline = app(TaskContractGuard::class)->evidence($task, $context);
    $attempt = TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 1,
        'base_sha' => 'base',
        'head_sha' => 'head',
        'commit_sha' => 'head',
        'status' => 'completed',
        'validation_results' => ['task_contract' => $baseline],
        'changed_files' => ['app/Example.php'],
        'started_at' => now()->subMinute(),
        'finished_at' => now()->subMinute(),
    ]);
    $task->update(['acceptance_criteria' => ['A changed Reviewer contract.']]);

    $execution = app(RunReviewerTask::class)->run($task->refresh(), $attempt);

    expect($execution['exit_code'])->toBe(-1)
        ->and($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and(AgentRun::query()->whereBelongsTo($task)->count())->toBe(0)
        ->and($task->auditEvents()->where('event_type', 'review.started')->exists())->toBeFalse()
        ->and($task->auditEvents()->where('event_type', 'task.contract_drift_detected')->exists())->toBeTrue();
});

test('explicit operator requeue after contract drift establishes a new contract baseline boundary', function () {
    $project = taskContractProject('Contract rebase project');
    $task = taskContractTask($project, TaskStatus::Blocked);
    $guard = app(TaskContractGuard::class);
    $context = taskContractContext();
    $baseline = $guard->evidence($task, $context);
    TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 1,
        'status' => 'failed',
        'validation_results' => ['task_contract' => $baseline],
        'started_at' => now()->subMinute(),
        'finished_at' => now()->subMinute(),
    ]);
    $task->update(['objective' => 'Operator-approved replacement objective.']);
    $current = $guard->evidence($task->refresh(), $context);
    app(AuditLogger::class)->record('task.contract_drift_detected', [
        'operation' => 'reviewer',
        'baseline_attempt_number' => 1,
        'baseline_fingerprint' => $baseline['fingerprint'],
        'current_fingerprint' => $current['fingerprint'],
        'changed_inputs' => ['objective'],
    ], $project, $task);

    app(RequeueBlockedTask::class)->handle($task->refresh());
    $result = $guard->evaluate($task->refresh(), $context);

    expect($task->refresh()->status)->toBe(TaskStatus::ChangesRequired)
        ->and($result['baseline'])->toBeNull()
        ->and($result['drifted'])->toBeFalse();
});

test('project rule index rows with a single-segment wildcard glob do not crash contract evidence gathering', function () {
    $project = taskContractProject('Wildcard rule glob project');
    File::ensureDirectoryExists($project->path.'/.ai/rules');
    File::put($project->path.'/.ai/rules/index.md', <<<'MARKDOWN'
        | Applies to | Rule file |
        | --- | --- |
        | app/Http/Requests/**/*Ticket*.php | .ai/rules/tickets.md |
        MARKDOWN);
    File::put($project->path.'/.ai/rules/tickets.md', 'Ticket rules.');
    $task = taskContractTask($project);
    $task->update(['relevant_paths' => ['app/Http/Requests/Foo/CreateTicketRequest.php']]);
    $guard = app(TaskContractGuard::class);

    $evidence = $guard->evidence($task->refresh(), taskContractContext());

    expect($evidence['input_hashes']['repository_documents'])
        ->toHaveKey('.ai/rules/tickets.md');
});

test('same interrupted attempt remains pinned to its original contract and immutable Agent configuration snapshot', function () {
    $project = taskContractProject('Pinned recovery project');
    $task = taskContractTask($project, TaskStatus::Failed);
    $context = taskContractContext();
    $guard = app(TaskContractGuard::class);
    $baseline = $guard->evidence($task, $context);
    $attempt = TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 1,
        'base_sha' => 'base',
        'status' => 'interrupted',
        'validation_results' => ['task_contract' => $baseline],
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);
    $agent = Agent::factory()->for($project)->create(['role' => AgentRole::Coder, 'model' => null, 'default_context' => null]);
    $assembler = app(AgentContextAssembler::class);
    $original = $assembler->assemble($agent, AgentRole::Coder, $context)->configurationSnapshot();

    $agent->update(['model' => 'gpt-5.6-sol', 'default_context' => 'New mutable context.']);
    $recoveryContract = $guard->evaluate($task->refresh(), $context, $attempt);
    $restoredAgent = $assembler->agentFromSnapshot($original, $project->id);
    $restoredContext = $assembler->restore($original, $context);

    expect($recoveryContract['drifted'])->toBeFalse()
        ->and($recoveryContract['recovery_pinned'])->toBeTrue()
        ->and($recoveryContract['baseline'])->toBe($baseline)
        ->and($restoredAgent->getRawOriginal('model'))->toBeNull()
        ->and($restoredAgent->getRawOriginal('default_context'))->toBeNull()
        ->and($restoredContext->configurationSnapshot())->toBe($original);
});
