<?php

use App\Actions\AssignSkillToAgent;
use App\Actions\BindAgentWorker;
use App\Actions\ProvisionDefaultProjectAgents;
use App\Actions\ReorderAgentSkills;
use App\AgentRole;
use App\Exceptions\UnsafeProjectPath;
use App\Models\Agent;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Skill;
use App\Models\User;
use App\ProjectStatus;
use App\Services\AgentContextAssembler;
use App\Services\AgentRunRecorder;
use App\Services\AuditLogger;
use App\Services\CodexCliRunner;
use App\Services\SanitizedExecutionEnvironment;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function phase2HardeningProject(string $name): Project
{
    return Project::factory()->create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/aios-phase2-hardening-'.Str::uuid(),
        'status' => ProjectStatus::Paused,
        'git_status' => 'clean',
    ]);
}

test('audit logger recursively redacts secret material while preserving non secret observability data', function () {
    $project = phase2HardeningProject('Audit sanitization');

    $event = app(AuditLogger::class)->record('security.audit_test', [
        'project_id' => $project->id,
        'token_usage' => 321,
        'api_key' => 'sk-ant-'.str_repeat('a', 32),
        'nested' => [
            'authorization' => 'Bearer top-secret-token',
            'diagnostic' => 'CLAUDE_CODE_OAUTH_TOKEN=opaque-oauth-secret',
        ],
    ], $project);

    expect($event->payload['project_id'])->toBe($project->id)
        ->and($event->payload['token_usage'])->toBe(321)
        ->and($event->payload['api_key'])->toBe('[REDACTED]')
        ->and($event->payload['nested']['authorization'])->toBe('[REDACTED]')
        ->and($event->payload['nested']['diagnostic'])->toContain('[REDACTED]')
        ->and(json_encode($event->payload, JSON_THROW_ON_ERROR))
        ->not->toContain('top-secret-token')
        ->not->toContain('opaque-oauth-secret')
        ->not->toContain(str_repeat('a', 32));
});

test('default project agent provisioning audits only newly created agents and remains idempotent', function () {
    $project = phase2HardeningProject('Provisioning audit');
    $provisioner = app(ProvisionDefaultProjectAgents::class);

    $provisioner->handle($project);
    $provisioner->handle($project);

    $events = $project->auditEvents()
        ->where('event_type', 'agent.created')
        ->orderBy('id')
        ->get();

    expect($project->agents()->count())->toBe(3)
        ->and($events)->toHaveCount(3);

    foreach ($events as $event) {
        expect($event->payload['project_id'])->toBe($project->id)
            ->and($event->payload['agent_id'])->toBeInt()
            ->and($event->payload['configuration_version'])->toBe(1)
            ->and($event->payload['harness'])->toBe('codex');
    }
});

test('agent configuration lifecycle emits versioned audit evidence without persisting prompt content', function () {
    $user = User::factory()->create();
    $project = phase2HardeningProject('Agent audit lifecycle');

    $this->actingAs($user)
        ->post(route('projects.agents.store', $project), [
            'name' => 'Audited Coder',
            'role' => 'coder',
            'harness' => 'codex',
            'default_context' => 'INITIAL-CONTEXT-MUST-NOT-BE-AUDITED',
            'enabled' => true,
        ])
        ->assertRedirect(route('projects.show', $project));

    $agent = Agent::query()
        ->where('project_id', $project->id)
        ->where('name', 'Audited Coder')
        ->sole();

    $this->patch(route('projects.agents.update', [$project, $agent]), [
        'name' => $agent->name,
        'role' => 'coder',
        'harness' => 'codex',
        'default_context' => 'UPDATED-CONTEXT-MUST-NOT-BE-AUDITED',
        'enabled' => false,
    ])->assertRedirect(route('projects.show', $project));

    $this->patch(route('projects.agents.update', [$project, $agent->refresh()]), [
        'name' => $agent->name,
        'role' => 'coder',
        'harness' => 'codex',
        'default_context' => $agent->default_context,
        'enabled' => true,
    ])->assertRedirect(route('projects.show', $project));

    $events = $project->auditEvents()
        ->whereIn('event_type', [
            'agent.created',
            'agent.updated',
            'agent.enabled',
            'agent.disabled',
        ])
        ->get();

    expect($events->where('event_type', 'agent.created'))->toHaveCount(1)
        ->and($events->where('event_type', 'agent.updated'))->toHaveCount(2)
        ->and($events->where('event_type', 'agent.disabled'))->toHaveCount(1)
        ->and($events->where('event_type', 'agent.enabled'))->toHaveCount(1)
        ->and($agent->refresh()->configuration_version)->toBe(3);

    $serialized = json_encode(
        $events->pluck('payload')->all(),
        JSON_THROW_ON_ERROR,
    );

    expect($serialized)
        ->not->toContain('INITIAL-CONTEXT-MUST-NOT-BE-AUDITED')
        ->not->toContain('UPDATED-CONTEXT-MUST-NOT-BE-AUDITED');

    foreach ($events as $event) {
        expect($event->payload['project_id'])->toBe($project->id)
            ->and($event->payload['agent_id'])->toBe($agent->id)
            ->and($event->payload['configuration_version'])->toBeInt();
    }
});

test('skill lifecycle emits versioned audit evidence without persisting skill content', function () {
    $user = User::factory()->create();
    $project = phase2HardeningProject('Skill audit lifecycle');

    $this->actingAs($user)
        ->post(route('projects.skills.store', $project), [
            'name' => 'Audit Skill',
            'description' => 'Description not needed in audit evidence.',
            'instructions' => 'SKILL-INSTRUCTIONS-MUST-NOT-BE-AUDITED',
            'applicable_roles' => ['coder'],
            'enabled' => true,
        ])
        ->assertRedirect(route('projects.show', $project));

    $skill = Skill::query()
        ->where('project_id', $project->id)
        ->where('name', 'Audit Skill')
        ->sole();

    $this->patch(route('projects.skills.update', [$project, $skill]), [
        'name' => $skill->name,
        'description' => $skill->description,
        'instructions' => 'UPDATED-SKILL-INSTRUCTIONS-MUST-NOT-BE-AUDITED',
        'applicable_roles' => ['coder'],
        'enabled' => false,
    ])->assertRedirect(route('projects.show', $project));

    $this->patch(route('projects.skills.update', [$project, $skill->refresh()]), [
        'name' => $skill->name,
        'description' => $skill->description,
        'instructions' => $skill->instructions,
        'applicable_roles' => ['coder'],
        'enabled' => true,
    ])->assertRedirect(route('projects.show', $project));

    $skillId = $skill->id;

    $this->delete(route('projects.skills.destroy', [$project, $skill->refresh()]))
        ->assertRedirect(route('projects.show', $project));

    $events = $project->auditEvents()
        ->whereIn('event_type', [
            'skill.created',
            'skill.updated',
            'skill.enabled',
            'skill.disabled',
            'skill.deleted',
        ])
        ->get();

    expect($events->where('event_type', 'skill.created'))->toHaveCount(1)
        ->and($events->where('event_type', 'skill.updated'))->toHaveCount(2)
        ->and($events->where('event_type', 'skill.disabled'))->toHaveCount(1)
        ->and($events->where('event_type', 'skill.enabled'))->toHaveCount(1)
        ->and($events->where('event_type', 'skill.deleted'))->toHaveCount(1);

    $serialized = json_encode(
        $events->pluck('payload')->all(),
        JSON_THROW_ON_ERROR,
    );

    expect($serialized)
        ->not->toContain('SKILL-INSTRUCTIONS-MUST-NOT-BE-AUDITED')
        ->not->toContain('UPDATED-SKILL-INSTRUCTIONS-MUST-NOT-BE-AUDITED');

    foreach ($events as $event) {
        expect($event->payload['project_id'])->toBe($project->id)
            ->and($event->payload['skill_id'])->toBe($skillId)
            ->and($event->payload['version'])->toBeInt();
    }
});

test('skill assignment ordering unassignment and worker binding are audited transactionally', function () {
    $user = User::factory()->create();
    $project = phase2HardeningProject('Binding audit');
    $agent = Agent::factory()
        ->for($project)
        ->create([
            'role' => AgentRole::Coder,
            'name' => 'Binding Coder',
        ]);

    $firstSkill = Skill::factory()
        ->for($project)
        ->create([
            'name' => 'First Audit Skill',
            'slug' => 'first-audit-skill',
        ]);

    $secondSkill = Skill::factory()
        ->for($project)
        ->create([
            'name' => 'Second Audit Skill',
            'slug' => 'second-audit-skill',
        ]);

    $worker = AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'status' => 'idle',
    ]);

    app(AssignSkillToAgent::class)->handle($agent, $firstSkill);
    app(AssignSkillToAgent::class)->handle($agent, $secondSkill);

    app(ReorderAgentSkills::class)->handle(
        $agent,
        [$secondSkill->id, $firstSkill->id],
    );

    $this->actingAs($user)
        ->delete(route('projects.agents.skills.destroy', [
            $project,
            $agent,
            $firstSkill,
        ]))
        ->assertRedirect(route('projects.show', $project));

    app(BindAgentWorker::class)->handle($worker, $agent);

    expect($project->auditEvents()->where('event_type', 'skill.assigned')->count())->toBe(2)
        ->and($project->auditEvents()->where('event_type', 'skill.reordered')->count())->toBe(1)
        ->and($project->auditEvents()->where('event_type', 'skill.unassigned')->count())->toBe(1)
        ->and($project->auditEvents()->where('event_type', 'agent.bound')->count())->toBe(1);

    $reorder = $project->auditEvents()
        ->where('event_type', 'skill.reordered')
        ->sole();

    expect($reorder->payload['project_id'])->toBe($project->id)
        ->and($reorder->payload['agent_id'])->toBe($agent->id)
        ->and($reorder->payload['skills'])->toBe([
            [
                'skill_id' => $secondSkill->id,
                'skill_version' => $secondSkill->version,
                'position' => 1,
            ],
            [
                'skill_id' => $firstSkill->id,
                'skill_version' => $firstSkill->version,
                'position' => 2,
            ],
        ]);

    $binding = $project->auditEvents()
        ->where('event_type', 'agent.bound')
        ->sole();

    expect($binding->payload['agent_worker_id'])->toBe($worker->id)
        ->and($binding->payload['agent_id'])->toBe($agent->id)
        ->and($binding->payload['agent_configuration_version'])->toBe($agent->configuration_version)
        ->and($binding->payload['harness'])->toBe('codex');
});

test('agent run audit evidence records selected harness and immutable snapshot identity without prompt content', function () {
    $project = phase2HardeningProject('Run configuration audit');

    $agent = Agent::factory()
        ->for($project)
        ->create([
            'role' => AgentRole::Coder,
            'default_context' => 'AGENT-CONTEXT-MUST-NOT-ENTER-AUDIT',
        ]);

    $skill = Skill::factory()
        ->for($project)
        ->create([
            'name' => 'Run Audit Skill',
            'slug' => 'run-audit-skill',
            'instructions' => 'SKILL-CONTENT-MUST-NOT-ENTER-AUDIT',
            'applicable_roles' => ['coder'],
        ]);

    app(AssignSkillToAgent::class)->handle($agent, $skill);

    AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'agent_id' => $agent->id,
        'status' => 'idle',
    ]);

    $context = app(AgentContextAssembler::class)->assemble(
        $agent,
        AgentRole::Coder,
        ['objective' => 'Verify immutable run audit evidence.'],
    );

    $run = app(AgentRunRecorder::class)->start(
        project: $project,
        role: AgentRole::Coder,
        prompt: 'RUN-PROMPT-MUST-NOT-ENTER-AUDIT',
        agent: $agent,
        context: $context,
    );

    $selection = $project->auditEvents()
        ->where('event_type', 'agent_run.harness_selected')
        ->sole();

    $snapshot = $project->auditEvents()
        ->where('event_type', 'agent_run.configuration_snapshotted')
        ->sole();

    $costEstimate = $project->auditEvents()
        ->where('event_type', 'agent_run.context_cost_estimated')
        ->sole();

    expect($selection->payload['agent_run_id'])->toBe($run->id)
        ->and($selection->payload['project_id'])->toBe($project->id)
        ->and($selection->payload['agent_id'])->toBe($agent->id)
        ->and($selection->payload['agent_configuration_version'])->toBe($agent->configuration_version)
        ->and($selection->payload['harness'])->toBe('codex')
        ->and($snapshot->payload['context_schema_version'])->toBe($context->contextSchemaVersion)
        ->and($snapshot->payload['context_hash'])->toBe($context->hash)
        ->and($snapshot->payload['skills'])->toBe([
            [
                'skill_id' => $skill->id,
                'skill_version' => $skill->version,
                'position' => 0,
            ],
        ])
        ->and($costEstimate->payload['agent_run_id'])->toBe($run->id)
        ->and($costEstimate->payload['context_cost_schema_version'])->toBe($context->contextCostSchemaVersion)
        ->and($costEstimate->payload['breakdown'])->toBe($context->contextCostEstimate)
        ->and($run->fresh()->context_cost_estimate)->toBe($context->contextCostEstimate)
        ->and($run->fresh()->context_cost_schema_version)->toBe($context->contextCostSchemaVersion);

    $serialized = json_encode(
        [$selection->payload, $snapshot->payload, $costEstimate->payload],
        JSON_THROW_ON_ERROR,
    );

    expect($serialized)
        ->not->toContain('RUN-PROMPT-MUST-NOT-ENTER-AUDIT')
        ->not->toContain('AGENT-CONTEXT-MUST-NOT-ENTER-AUDIT')
        ->not->toContain('SKILL-CONTENT-MUST-NOT-ENTER-AUDIT');
});

test('disproportionate context cost sections are flagged as a distinct audit warning', function () {
    $project = phase2HardeningProject('Context cost warning');

    $agent = Agent::factory()
        ->for($project)
        ->create(['role' => AgentRole::Coder, 'default_context' => null]);

    AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'agent_id' => $agent->id,
        'status' => 'idle',
    ]);

    $context = app(AgentContextAssembler::class)->assemble(
        $agent,
        AgentRole::Coder,
        ['obsidian_project_knowledge' => str_repeat('Oversized Obsidian retrieval content. ', 500)],
    );

    $run = app(AgentRunRecorder::class)->start(
        project: $project,
        role: AgentRole::Coder,
        prompt: 'Prompt.',
        agent: $agent,
        context: $context,
    );

    expect($context->contextCostEstimate['disproportionate_sections'])->toContain('obsidian_context');

    $warning = $project->auditEvents()
        ->where('event_type', 'agent_run.context_cost_warning')
        ->sole();

    expect($warning->payload['agent_run_id'])->toBe($run->id)
        ->and($warning->payload['disproportionate_sections'])->toContain('obsidian_context');
});

test('legacy runs without a resolved agent or context leave context cost evidence unset', function () {
    $project = phase2HardeningProject('Legacy run context cost');

    $run = app(AgentRunRecorder::class)->start(
        $project,
        AgentRole::Coder,
        'Legacy prompt.',
    );

    expect($run->context_cost_estimate)->toBeNull()
        ->and($run->context_cost_schema_version)->toBeNull()
        ->and($project->auditEvents()->where('event_type', 'agent_run.context_cost_estimated')->count())->toBe(0)
        ->and($project->auditEvents()->where('event_type', 'agent_run.context_cost_warning')->count())->toBe(0);
});

test('sanitized execution environment allowlists runtime keys and rejects provider and application credentials', function () {
    $values = [
        'OPENAI_API_KEY' => 'sk-'.str_repeat('o', 32),
        'ANTHROPIC_API_KEY' => 'sk-ant-'.str_repeat('a', 32),
        'ANTHROPIC_AUTH_TOKEN' => 'anthropic-auth-secret',
        'CLAUDE_CODE_OAUTH_TOKEN' => 'claude-oauth-secret',
        'DB_PASSWORD' => 'database-secret',
        'AIOS_TEST_API_TOKEN' => 'application-secret',
    ];

    $originals = [];

    foreach ($values as $key => $value) {
        $originals[$key] = getenv($key);
        putenv("{$key}={$value}");
    }

    try {
        $command = app(SanitizedExecutionEnvironment::class)
            ->wrap(['/usr/local/bin/provider']);

        expect($command[0])->toBe('/usr/bin/env')
            ->and($command[1])->toBe('-i')
            ->and($command)->toContain('/usr/local/bin/provider');

        $serialized = implode("\n", $command);

        foreach ($values as $key => $value) {
            expect($serialized)
                ->not->toContain("{$key}=")
                ->not->toContain($value);
        }
    } finally {
        foreach ($originals as $key => $value) {
            $value === false
                ? putenv($key)
                : putenv("{$key}={$value}");
        }
    }
});

test('workspace symlink escape is rejected before any harness process can start', function () {
    if (! function_exists('symlink')) {
        $this->markTestSkipped('Symlinks are unavailable on this platform.');
    }

    $workspace = sys_get_temp_dir().'/aios-hardening-workspace-'.Str::uuid();
    $outside = sys_get_temp_dir().'/aios-hardening-outside-'.Str::uuid();
    $escape = $workspace.'/escaped-project';

    mkdir($workspace, 0700, true);
    mkdir($outside, 0700, true);

    expect(symlink($outside, $escape))->toBeTrue();

    config()->set('aios.workspace_root', $workspace);

    $project = Project::factory()->create([
        'name' => 'Symlink Escape',
        'path' => $escape,
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    Process::fake();

    try {
        expect(fn () => app(CodexCliRunner::class)->run(
            $project,
            'This process must never start.',
        ))->toThrow(UnsafeProjectPath::class);

        Process::assertNotRan(fn (): bool => true);
    } finally {
        @unlink($escape);
        @rmdir($outside);
        @rmdir($workspace);
    }
});

test('agent run logs and command evidence redact credentials before durable persistence', function () {
    Storage::fake('local');

    $project = phase2HardeningProject('Run output redaction');

    $run = app(AgentRunRecorder::class)->start(
        $project,
        AgentRole::Coder,
        'Safe prompt.',
    );

    $rawCredential = 'runtime-secret-credential';

    $output = json_encode([
        'type' => 'item.completed',
        'item' => [
            'type' => 'command_execution',
            'command' => 'curl -H "Authorization: Bearer '.$rawCredential.'" https://example.invalid',
            'exit_code' => 0,
        ],
    ], JSON_THROW_ON_ERROR);

    $completed = app(AgentRunRecorder::class)->complete($run, [
        'exit_code' => 0,
        'output' => $output,
        'error_output' => 'OPENAI_API_KEY=sk-'.str_repeat('z', 32),
    ]);

    $log = Storage::disk('local')->get($completed->log_path);

    expect($log)
        ->not->toContain($rawCredential)
        ->not->toContain(str_repeat('z', 32));

    $commandEvidence = json_encode(
        $completed->commands,
        JSON_THROW_ON_ERROR,
    );

    expect($commandEvidence)->not->toContain($rawCredential);

    $audit = $project->auditEvents()
        ->where('event_type', 'agent.execution_completed')
        ->sole();

    expect(json_encode($audit->payload, JSON_THROW_ON_ERROR))
        ->not->toContain($rawCredential)
        ->not->toContain(str_repeat('z', 32));
});
