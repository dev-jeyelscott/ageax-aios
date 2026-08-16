---
paths:
  - 'app/Actions/**'
---

# Actions

## Coder attempts start from a deterministic Git base
Before a new normal Coder attempt, require a clean Git index and working tree and persist the clean base SHA. Derive task candidate paths from that base; never subtract baseline filenames. Dirty failed/interrupted task state may continue only through explicit recovery evidence tied to the same base. Never stash, reset, clean, discard, or auto-commit pre-existing changes.

## Reviewer operational failures never reject implementations
Only a validated Reviewer changes_required decision with actionable findings may transition a task to changes_required. Reviewer process, parsing, timeout, or stale-worker failures retain the completed implementation, record durable failure evidence, and retry review until the bounded limit blocks for operator intervention.

## Workflow Actions preserve AIOS orchestration ownership
Project Agent configuration is project-scoped execution configuration and must remain separate from `AgentWorker`, which is authoritative durable workflow-slot, lease, heartbeat, and runtime state. Actions may resolve the Agent bound to a core workflow role and select its persisted Codex or Claude Code harness, but the Agent or harness must never own task transitions, task ordering, Git lifecycle, deterministic validation, persistence, recovery, auditing, context assembly, or worker leases.

Project Manager, Coder, and Reviewer remain the executable core workflow roles. Task execution remains serial and dependency ordered under AIOS control; additional configured Agents must not create worker lanes, self-schedule, or bypass the persisted workflow ordering.

Every new roadmap-analysis, implementation, fix/retry, or review attempt must start a fresh harness execution context and capture a new immutable effective configuration snapshot before execution. Recovery of the same interrupted attempt must continue from its persisted snapshot, Git state, run evidence, and audit evidence rather than resolving mutable current Agent, Skill, or harness configuration into that existing attempt.

## Unbound vs. broken Agent bindings are not the same failure
`AgentResolver::forRole()` throws `App\Exceptions\AgentNotBoundToRole` when a workflow role has no Agent configured at all (no `AgentWorker` row, or `agent_id` is null). While the existing migration/compatibility path supports such projects, callers may use the established legacy Codex fallback only for this genuinely unconfigured case; do not treat Codex as the default for an already configured Agent.

When an Agent is bound, its persisted and validated harness configuration is authoritative. Any other failure (agent disabled, agent record missing, or `AgentHarnessResolver::resolve()` throwing because the persisted harness identifier is unsupported or unimplemented) means an Agent is bound but broken and must never fall back silently. PM/Coder wrap `resolveAgent()` in a `catch (LogicException $exception)` and fail the roadmap attempt / block the task (`roadmap.blocked_agent_misconfigured`, `task.blocked_agent_misconfigured`) without executing anything. Reviewer routes the same failure through `TaskWorkflow::recordReviewerOperationalFailure()` (reason `agent_misconfigured`) so it follows the existing bounded-retry-then-Blocked operational failure path instead of a new one-shot block, per the "Reviewer operational failures never reject implementations" rule above. See `App\Services\AgentResolver`, `RunProjectManager::blockMisconfiguredAgent()`, `RunCoderTask::blockMisconfiguredAgent()`, `RunReviewerTask::run()`.
