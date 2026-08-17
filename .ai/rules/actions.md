---
paths:
  - 'app/Actions/**'
---

# Actions

## Coder attempts start from a deterministic Git base

Before a new normal Coder attempt, require a clean Git index and working tree and persist the clean base SHA. Derive task candidate paths from that base; never subtract baseline filenames. Dirty failed/interrupted task state may continue only through explicit recovery evidence tied to the same base. Never stash, reset, clean, discard, or auto-commit pre-existing changes.

## Phase-batched review remains serial and deterministic

Coder and Reviewer workflow remains strictly serial under AIOS control. Serial execution means only one eligible Coder task and one eligible Reviewer task may be actively claimed or executed at a time; it does not require each same-phase task to become `done` before the next eligible same-phase Coder implementation may begin.

Within the current phase, after a Coder task successfully reaches `ready_for_review`, AIOS may claim the next eligible same-phase Coder task when its persisted dependency requirements are satisfied. Phase batching must not bypass explicit dependency edges or allow concurrent Coder execution.

The Reviewer must not begin reviewing the current phase until every required task in that phase has reached `ready_for_review`. Before the first phase review, any required task still in `queued`, `coding`, `validating`, `changes_required`, `failed`, `blocked`, or `interrupted` keeps the phase review barrier closed.

Once phase review begins, tasks already approved as `done` count as having crossed the review barrier. The Reviewer must claim exactly one remaining `ready_for_review` task at a time in deterministic task-position order.

A validated `changes_required` result closes or pauses further phase review progression. Later tasks in the same phase must not continue through review until the rejected task has returned through Coder execution and validation to `ready_for_review`, after which AIOS may reopen the phase review barrier when the phase is eligible again.

The next phase must not begin while the current phase contains unresolved implementation or review work. Phase advancement, review-barrier eligibility, task ordering, and dependency enforcement are AIOS-owned durable workflow decisions and must be enforced through application/database controls rather than prompts or harness behavior.

Coder and Reviewer task cooldowns remain AIOS-owned scheduler behavior. Normal worker execution must respect the centrally configured per-role cooldown after completing a claimed task before another task for the same project and role may be claimed. The default cooldown is 300 seconds. Actions, Agents, and harnesses must not introduce a path that bypasses the scheduler's cooldown or creates a competing timer.

## Reviewer operational failures never reject implementations

Only a validated Reviewer changes_required decision with actionable findings may transition a task to changes_required. Reviewer process, parsing, timeout, or stale-worker failures retain the completed implementation, record durable failure evidence, and retry review until the bounded limit blocks for operator intervention.

Operational Reviewer failures must not be treated as implementation rejection and must not incorrectly advance, permanently close, or otherwise corrupt the current phase review barrier.

## Workflow Actions preserve AIOS orchestration ownership

Project Agent configuration is project-scoped execution configuration and must remain separate from `AgentWorker`, which is authoritative durable workflow-slot, lease, heartbeat, and runtime state. Actions may resolve the Agent bound to a core workflow role and select its persisted Codex or Claude Code harness, but the Agent or harness must never own task transitions, task ordering, phase review barriers, worker task cooldowns, Git lifecycle, deterministic validation, persistence, recovery, auditing, context assembly, or worker leases.

Project Manager, Coder, and Reviewer remain the executable core workflow roles. Task execution remains serial and dependency ordered under AIOS control; same-phase implementation may accumulate validated `ready_for_review` tasks before phase review begins, but additional configured Agents must not create worker lanes, self-schedule, or bypass persisted workflow ordering.

Every new roadmap-analysis, implementation, fix/retry, or review attempt must start a fresh harness execution context and capture a new immutable effective configuration snapshot before execution. Recovery of the same interrupted attempt must continue from its persisted snapshot, Git state, run evidence, and audit evidence rather than resolving mutable current Agent, Skill, or harness configuration into that existing attempt.

## Unbound vs. broken Agent bindings are not the same failure

`AgentResolver::forRole()` throws `App\Exceptions\AgentNotBoundToRole` when a workflow role has no Agent configured at all (no `AgentWorker` row, or `agent_id` is null). While the existing migration/compatibility path supports such projects, callers may use the established legacy Codex fallback only for this genuinely unconfigured case; do not treat Codex as the default for an already configured Agent.

When an Agent is bound, its persisted and validated harness configuration is authoritative. Any other failure (agent disabled, agent record missing, or `AgentHarnessResolver::resolve()` throwing because the persisted harness identifier is unsupported or unimplemented) means an Agent is bound but broken and must never fall back silently. PM/Coder wrap `resolveAgent()` in a `catch (LogicException $exception)` and fail the roadmap attempt / block the task (`roadmap.blocked_agent_misconfigured`, `task.blocked_agent_misconfigured`) without executing anything. Reviewer routes the same failure through `TaskWorkflow::recordReviewerOperationalFailure()` (reason `agent_misconfigured`) so it follows the existing bounded-retry-then-Blocked operational failure path instead of a new one-shot block, per the "Reviewer operational failures never reject implementations" rule above. See `App\Services\AgentResolver`, `RunProjectManager::blockMisconfiguredAgent()`, `RunCoderTask::blockMisconfiguredAgent()`, `RunReviewerTask::run()`.
