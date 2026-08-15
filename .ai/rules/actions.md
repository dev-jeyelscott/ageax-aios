---
paths:
  - 'app/Actions/**'
---

# Actions

## Coder attempts start from a deterministic Git base
Before a new normal Coder attempt, require a clean Git index and working tree and persist the clean base SHA. Derive task candidate paths from that base; never subtract baseline filenames. Dirty failed/interrupted task state may continue only through explicit recovery evidence tied to the same base. Never stash, reset, clean, discard, or auto-commit pre-existing changes.

## Reviewer operational failures never reject implementations
Only a validated Reviewer changes_required decision with actionable findings may transition a task to changes_required. Reviewer process, parsing, timeout, or stale-worker failures retain the completed implementation, record durable failure evidence, and retry review until the bounded limit blocks for operator intervention.

## Unbound vs. broken Agent bindings are not the same failure
`AgentResolver::forRole()` throws `App\Exceptions\AgentNotBoundToRole` when a workflow role has no Agent configured at all (no `AgentWorker` row, or `agent_id` is null) — callers must catch only that subtype and fall back to the legacy Codex execution path, since a project that never configured an Agent must keep working. Any other failure (agent disabled, agent record missing, or `AgentHarnessResolver::resolve()` throwing because the persisted harness identifier is unsupported or unimplemented) means an Agent *is* bound but broken, and must never fall back silently: it has to block processing and record actionable audit evidence instead. PM/Coder wrap `resolveAgent()` in a `catch (LogicException $exception)` and fail the roadmap attempt / block the task (`roadmap.blocked_agent_misconfigured`, `task.blocked_agent_misconfigured`) without executing anything. Reviewer routes the same failure through `TaskWorkflow::recordReviewerOperationalFailure()` (reason `agent_misconfigured`) so it follows the existing bounded-retry-then-Blocked operational failure path instead of a new one-shot block, per the "Reviewer operational failures never reject implementations" rule above. See `App\Services\AgentResolver`, `RunProjectManager::blockMisconfiguredAgent()`, `RunCoderTask::blockMisconfiguredAgent()`, `RunReviewerTask::run()`.
