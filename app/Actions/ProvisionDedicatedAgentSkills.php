<?php

namespace App\Actions;

use App\AgentRole;
use App\Models\Agent;
use App\Models\Project;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class ProvisionDedicatedAgentSkills
{
    public function __construct(
        private AssignSkillToAgent $assignSkill,
        private AuditLogger $audit,
    ) {}

    public function handle(Project $project): void
    {
        DB::transaction(function () use ($project): void {
            $lockedProject = Project::query()
                ->whereKey($project->id)
                ->lockForUpdate()
                ->firstOrFail();

            $boundAgents = $this->boundAgents($lockedProject);

            foreach ($this->definitions() as $roleValue => $definitions) {
                foreach ($definitions as $definition) {
                    $existingSkill = $lockedProject->skills()
                        ->where('slug', $definition['slug'])
                        ->first()
                        ?? $lockedProject->skills()
                            ->where('name', $definition['name'])
                            ->first();

                    if ($existingSkill !== null) {
                        continue;
                    }

                    $skill = $lockedProject->skills()->create([
                        'name' => $definition['name'],
                        'slug' => $definition['slug'],
                        'description' => $definition['description'],
                        'instructions' => $definition['instructions'],
                        'constraints' => $definition['constraints'],
                        'applicable_roles' => [$roleValue],
                        'enabled' => true,
                    ]);

                    $this->audit->record('skill.created', [
                        'project_id' => $lockedProject->id,
                        'skill_id' => $skill->id,
                        'version' => $skill->version,
                        'slug' => $skill->slug,
                        'source' => 'dedicated_agent_skills_seeder',
                    ], $lockedProject);

                    if (! $definition['auto_assign']) {
                        continue;
                    }

                    $agent = $boundAgents[$roleValue] ?? null;

                    if ($agent === null) {
                        continue;
                    }

                    $this->assignSkill->handle($agent, $skill);
                }
            }
        }, attempts: 3);
    }

    /** @return array<string, Agent> */
    private function boundAgents(Project $project): array
    {
        $agents = [];

        foreach ($project->workers()->with('agent')->get() as $worker) {
            $agent = $worker->agent;

            if ($agent === null || $agent->project_id !== $project->id || $agent->role !== $worker->role) {
                continue;
            }

            if (! in_array($worker->role, [
                AgentRole::ProjectManager,
                AgentRole::Coder,
                AgentRole::Reviewer,
            ], true)) {
                continue;
            }

            $agents[$worker->role->value] = $agent;
        }

        return $agents;
    }

    /**
     * @return array<string, list<array{
     *     name: string,
     *     slug: string,
     *     description: string,
     *     instructions: string,
     *     constraints: string,
     *     auto_assign: bool
     * }>>
     */
    private function definitions(): array
    {
        return [
            AgentRole::ProjectManager->value => [
                [
                    'name' => 'Roadmap Decomposition',
                    'slug' => 'pm-roadmap-decomposition',
                    'description' => 'Convert approved roadmaps and specifications into deterministic implementation-ready phases and tasks.',
                    'instructions' => 'Identify explicit dependencies and execution order. Keep tasks small, bounded, independently verifiable, and implementation-ready. Define objective, scope, constraints, acceptance criteria, relevant paths, and verification commands. Preserve serial execution and approved phase barriers.',
                    'constraints' => 'Do not invent requirements, reorder durable workflow state, or expand scope beyond approved documentation.',
                    'auto_assign' => true,
                ],
                [
                    'name' => 'Acceptance Criteria Engineering',
                    'slug' => 'pm-acceptance-criteria-engineering',
                    'description' => 'Produce precise acceptance criteria that Coder and Reviewer can independently verify.',
                    'instructions' => 'Write observable and testable criteria. Separate behavioral requirements from implementation preferences. Include security, authorization, data integrity, recovery, compatibility, and validation requirements when applicable. Use measurable conditions instead of subjective wording.',
                    'constraints' => 'Do not encode personal implementation preferences as mandatory acceptance criteria.',
                    'auto_assign' => true,
                ],
                [
                    'name' => 'Implementation Prompt Engineering',
                    'slug' => 'pm-implementation-prompt-engineering',
                    'description' => 'Generate compact implementation prompts for fresh Coder execution contexts.',
                    'instructions' => 'Include only task-relevant requirements, authoritative constraints, repository areas, dependencies, prior evidence, and verification expectations. Require inspection before editing and root-cause correction. Keep the prompt self-contained and implementation-ready.',
                    'constraints' => 'Do not dump full conversations, complete repositories, unrelated roadmaps, logs, or Obsidian vaults.',
                    'auto_assign' => false,
                ],
                [
                    'name' => 'Dependency & Scope Control',
                    'slug' => 'pm-dependency-scope-control',
                    'description' => 'Prevent hidden scope expansion and invalid task execution order.',
                    'instructions' => 'Detect prerequisites before dependent tasks. Keep unrelated concerns separate. Preserve explicit task dependencies, phase barriers, and serial execution. Escalate architectural or business decisions instead of inventing them.',
                    'constraints' => 'Do not introduce speculative refactors, parallel work, or requirements outside the current objective.',
                    'auto_assign' => true,
                ],
                [
                    'name' => 'Documentation Alignment',
                    'slug' => 'pm-documentation-alignment',
                    'description' => 'Align incoming requirements with authoritative project documentation and implementation truth.',
                    'instructions' => 'Apply source priority in order: current task and acceptance criteria, approved specifications, AGENTS.md, then existing implementation and conventions. Surface contradictions explicitly. Identify documentation that must change before implementation when durable contracts change.',
                    'constraints' => 'Do not silently reinterpret or override higher-priority requirements.',
                    'auto_assign' => true,
                ],
                [
                    'name' => 'Context Capsule Design',
                    'slug' => 'pm-context-capsule-design',
                    'description' => 'Produce the smallest sufficient context for downstream Agent executions.',
                    'instructions' => 'Include only the objective, acceptance criteria, constraints, dependencies, relevant rules, repository paths, durable decisions, and current failure or review evidence needed for the execution. Retrieve Obsidian knowledge selectively and summarize aggressively.',
                    'constraints' => 'Do not include redundant history or broad repository, log, conversation, or vault dumps.',
                    'auto_assign' => true,
                ],
                [
                    'name' => 'Ticket Triage & Task Qualification',
                    'slug' => 'pm-ticket-triage-task-qualification',
                    'description' => 'Classify tickets and determine whether bounded implementation work is actually required.',
                    'instructions' => 'Classify the request, distinguish self-service guidance from implementation work, identify missing information, and produce one bounded proposed Task only when implementation is clear and necessary. Surface deterministic escalation flags and affected areas.',
                    'constraints' => 'Do not directly create Tasks, reorder phases, close tickets, or mutate workflow state. Assign this skill only when the approved ticket-triage workflow is active.',
                    'auto_assign' => false,
                ],
                [
                    'name' => 'Risk & Escalation Assessment',
                    'slug' => 'pm-risk-escalation-assessment',
                    'description' => 'Identify cases that require explicit operator judgment instead of autonomous expansion.',
                    'instructions' => 'Flag architectural decisions, destructive changes, security or privacy judgment, breaking contracts, conflicting specifications, unclear business priority, roadmap interruption, high or multi-task complexity, and unresolved dependency placement.',
                    'constraints' => 'Confidence or convenience must never suppress a deterministic escalation condition.',
                    'auto_assign' => false,
                ],
            ],

            AgentRole::Coder->value => [
                [
                    'name' => 'Repository Reconnaissance',
                    'slug' => 'coder-repository-reconnaissance',
                    'description' => 'Inspect the relevant implementation thoroughly before making changes.',
                    'instructions' => 'Read task-specific governance first. Inspect related models, migrations, actions or services, controllers, authorization, configuration, tests, UI, and CI where applicable. Search for established patterns and identify the actual root cause before editing.',
                    'constraints' => 'Do not implement from assumptions when the repository contains authoritative behavior or conventions.',
                    'auto_assign' => true,
                ],
                [
                    'name' => 'Minimal Production-Ready Implementation',
                    'slug' => 'coder-minimal-production-ready-implementation',
                    'description' => 'Implement the smallest architecture-consistent change that fully satisfies the task contract.',
                    'instructions' => 'Prefer framework-native mechanisms and existing dependencies. Preserve compatibility unless explicitly changed. Fix the root cause, keep changes task-scoped, and avoid unnecessary abstractions or infrastructure.',
                    'constraints' => 'Do not perform unrelated refactors or broaden scope beyond the acceptance criteria.',
                    'auto_assign' => true,
                ],
                [
                    'name' => 'Architecture & Data Integrity',
                    'slug' => 'coder-architecture-data-integrity',
                    'description' => 'Preserve application architecture, transactional integrity, deterministic state, and recoverability.',
                    'instructions' => 'Follow the managed project established framework and architecture. Preserve transactions, atomicity, idempotency, foreign-key and ownership invariants, explicit state transitions, and recovery behavior. Use locking where concurrent claims or transitions require deterministic serialization.',
                    'constraints' => 'Do not introduce hidden state, destructive migrations, or parallel workflow control outside AIOS.',
                    'auto_assign' => false,
                ],
                [
                    'name' => 'Security & Authorization Guard',
                    'slug' => 'coder-security-authorization-guard',
                    'description' => 'Protect authorization, isolation, workspace boundaries, and process execution during implementation.',
                    'instructions' => 'Preserve authorization and project isolation. Validate workspace and repository boundaries. Treat uploaded, user-provided, and agent-generated content as untrusted input. Protect process execution, command arguments, and durable evidence from sensitive material.',
                    'constraints' => 'Do not weaken sanitized execution, path protections, authorization, or sensitive-data handling controls.',
                    'auto_assign' => false,
                ],
                [
                    'name' => 'Regression Test Engineering',
                    'slug' => 'coder-regression-test-engineering',
                    'description' => 'Add focused automated proof for the behavioral defect or requirement being implemented.',
                    'instructions' => 'Add the smallest regression coverage that proves the intended behavior. Prefer behavioral assertions over implementation-detail assertions. Cover meaningful failure paths when workflow, security, data integrity, recovery, or idempotency is involved. Preserve existing coverage.',
                    'constraints' => 'Do not weaken or remove valid assertions merely to make an incorrect implementation pass.',
                    'auto_assign' => true,
                ],
                [
                    'name' => 'Deterministic Validation',
                    'slug' => 'coder-deterministic-validation',
                    'description' => 'Validate implementation using repository-owned deterministic checks before review.',
                    'instructions' => 'Run the narrow affected tests first, then applicable static analysis, formatting, lint, type checks, build, sensitive-data checks, Git checks, and task-specific verification. Report exact executed evidence and failures.',
                    'constraints' => 'Never claim a verification step passed unless it was actually executed successfully.',
                    'auto_assign' => true,
                ],
                [
                    'name' => 'Git Change Isolation',
                    'slug' => 'coder-git-change-isolation',
                    'description' => 'Keep implementation changes attributable only to the current Task.',
                    'instructions' => 'Inspect base SHA, index, and working-tree state before implementation. Preserve unrelated user changes. Keep changed files directly attributable to the current Task and maintain exact evidence for AIOS validation and Reviewer diff inspection.',
                    'constraints' => 'Do not stash, reset, clean, discard, or silently include unrelated changes.',
                    'auto_assign' => true,
                ],
                [
                    'name' => 'Failure & Retry Diagnosis',
                    'slug' => 'coder-failure-retry-diagnosis',
                    'description' => 'Make retry attempts corrective and evidence-driven instead of repeating failed approaches.',
                    'instructions' => 'Start from durable validation or review failure evidence. Identify why the previous attempt failed, preserve already-correct work, and change only what is necessary to resolve actionable failures. Use fresh context and compare against current repository state.',
                    'constraints' => 'Do not blindly repeat materially identical unsuccessful changes or bypass a failing deterministic gate.',
                    'auto_assign' => false,
                ],
            ],

            AgentRole::Reviewer->value => [
                [
                    'name' => 'Acceptance Criteria Verification',
                    'slug' => 'reviewer-acceptance-criteria-verification',
                    'description' => 'Review the implementation against the authoritative Task contract.',
                    'instructions' => 'Evaluate every acceptance criterion as satisfied, violated, or unverifiable using current implementation and durable evidence. Reject only for material task-contract failures. Distinguish mandatory requirements from optional implementation preferences.',
                    'constraints' => 'Do not reject based on taste, personal design preference, or requirements absent from the authoritative task and specifications.',
                    'auto_assign' => true,
                ],
                [
                    'name' => 'Git Diff Review',
                    'slug' => 'reviewer-git-diff-review',
                    'description' => 'Independently inspect the exact implementation produced for the Task.',
                    'instructions' => 'Review base SHA, head SHA, exact Git diff, changed files, tests, configuration or migration implications, and unexpected changes. Compare the diff with the task objective and acceptance criteria instead of relying on the Coder summary.',
                    'constraints' => 'Review only the task-scoped implementation evidence and do not silently include unrelated repository changes.',
                    'auto_assign' => true,
                ],
                [
                    'name' => 'Architecture Consistency Review',
                    'slug' => 'reviewer-architecture-consistency-review',
                    'description' => 'Detect changes that satisfy surface behavior while violating established project architecture.',
                    'instructions' => 'Verify existing services and patterns are extended rather than duplicated, workflow authority remains in AIOS, durable state is not delegated to Agents, and recovery, leases, state machines, context boundaries, and persistence contracts remain consistent.',
                    'constraints' => 'Do not redesign a valid architecture-consistent implementation solely because another design is possible.',
                    'auto_assign' => true,
                ],
                [
                    'name' => 'Security & Data Integrity Review',
                    'slug' => 'reviewer-security-data-integrity-review',
                    'description' => 'Independently assess security, authorization, transactional, and recovery risks introduced by the change.',
                    'instructions' => 'Check authorization, project isolation, transactions, row locking, idempotency, migrations, sensitive-data handling, workspace boundaries, process safety, audit evidence, and recovery semantics where relevant to the task.',
                    'constraints' => 'Report only risks grounded in the changed implementation and applicable project requirements.',
                    'auto_assign' => false,
                ],
                [
                    'name' => 'Regression & Validation Evidence Review',
                    'slug' => 'reviewer-regression-validation-evidence-review',
                    'description' => 'Verify the implementation is supported by meaningful tests and deterministic validation evidence.',
                    'instructions' => 'Confirm tests cover the changed behavior, deterministic checks correspond to the task, existing assertions were not weakened improperly, and reported validation evidence is consistent with the implementation. Identify only material missing regression coverage.',
                    'constraints' => 'Do not reject solely because speculative or unrelated additional tests could be written.',
                    'auto_assign' => false,
                ],
                [
                    'name' => 'Actionable Finding Authoring',
                    'slug' => 'reviewer-actionable-finding-authoring',
                    'description' => 'Produce precise findings that can directly drive a fresh Coder correction attempt.',
                    'instructions' => 'For each material finding provide severity, location, current behavior, expected behavior, reason, required fix, and verification requirement. Keep findings independently understandable and tied to the task contract.',
                    'constraints' => 'Do not return vague requests such as improve, clean up, or refactor without a concrete contract violation and required correction.',
                    'auto_assign' => true,
                ],
                [
                    'name' => 'Scope Discipline',
                    'slug' => 'reviewer-scope-discipline',
                    'description' => 'Prevent Reviewer-driven scope expansion while preserving strict correctness.',
                    'instructions' => 'Separate rejection-worthy defects from optional observations. Approve when the task contract is satisfied even if another valid implementation exists. Keep review limited to the current Task, relevant regression risk, and authoritative architecture constraints.',
                    'constraints' => 'Do not introduce new requirements, subjective style demands, or unrelated refactors during review.',
                    'auto_assign' => true,
                ],
                [
                    'name' => 'Operational Failure Classification',
                    'slug' => 'reviewer-operational-failure-classification',
                    'description' => 'Distinguish Reviewer execution failures from actual implementation defects.',
                    'instructions' => 'Separate timeout, harness failure, malformed structured output, process failure, and other operational faults from code-review findings. Preserve completed implementation evidence and require a fresh Reviewer execution when operational review itself failed.',
                    'constraints' => 'Do not convert operational Reviewer failure into fabricated changes_required findings.',
                    'auto_assign' => false,
                ],
            ],
        ];
    }
}
