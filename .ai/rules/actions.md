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

Project Agent configuration is project-scoped execution configuration and must remain separate from `AgentWorker`, which is authoritative durable workflow-slot, lease, heartbeat, and runtime state. Actions may resolve the Agent bound to a core workflow role and select its persisted Codex or Claude Code harness, but the Agent or harness must never own task transitions, task ordering, phase review barriers, worker task cooldowns, Git lifecycle, deterministic validation, persistence, recovery, auditing, context assembly, context budgeting, score calculation, recommendation eligibility, or worker leases.

Project Manager, Coder, and Reviewer remain the executable core workflow roles. Task execution remains serial and dependency ordered under AIOS control; same-phase implementation may accumulate validated `ready_for_review` tasks before phase review begins, but additional configured Agents must not create worker lanes, self-schedule, or bypass persisted workflow ordering.

Every new roadmap-analysis, Project Manager `ticket_triage`, implementation, fix/retry, or review attempt must start a fresh harness execution context and capture a new immutable effective configuration snapshot before execution. Recovery of the same interrupted attempt must continue from its persisted snapshot, Git state, run evidence, and audit evidence rather than resolving mutable current Agent, Skill, or harness configuration into that existing attempt.

## Ticket triage and conversion remain AIOS-owned Actions

`Ticket != Task`. A Ticket is durable project intake, conversation, triage, and escalation state. A Task is executable implementation work and enters the existing Coder, validation, Git, phase-review, and Reviewer workflow only after an AIOS-controlled conversion.

Project Manager roadmap analysis and `ticket_triage` share the existing Project Manager worker/lease boundary. Pending roadmap analysis has deterministic precedence over Ticket triage. Ticket triage claims exactly one eligible Ticket at a time using application/database serialization; Phase 3 must not introduce a Ticket Reviewer role, another Project Manager worker lane, or parallel PM execution.

The Project Manager Agent/harness may return structured triage output only. It must never directly claim or transition Tickets, persist Ticket replies, create Tasks, assign phase positions or dependencies, reorder roadmap work, resolve operator escalation, or mutate durable Ticket/Task workflow state.

Automatic Ticket-to-Task conversion is limited to exactly one clear, safe, bounded implementation-required Task and requires the approved eligibility rules, including confidence of at least `0.80` and no mandatory escalation condition.

Conversion must be transactional and idempotent. Under locking, AIOS must re-check that the Ticket has not already converted, the target project/phase still matches, the phase review barrier has not invalidated placement, dependencies remain valid, and the generated Task position/key cannot collide before committing Ticket linkage, Task creation, dependencies, audit evidence, and Ticket state.

A Ticket-created Task receives no special workflow permissions. It must not bypass dependency ordering, Coder repository preflight, deterministic validation, task-only commit rules, phase review barriers, Reviewer review, or normal Task transitions.

## Operator escalation gates are deterministic

Confidence must never suppress a mandatory escalation condition.

Operator judgment is required for the locked high-risk or ambiguous conditions, including low confidence, unclear or contradictory requirements, architectural decisions, breaking contracts, destructive or materially risky data migration, security/privacy/auth judgment, approved-documentation conflict, unclear business priority, high or multi-Task scope, roadmap interruption/reordering, critical work that would preempt queued work, or non-deterministic phase/dependency placement.

Critical or emergency roadmap interruption/reordering always requires explicit operator approval. No Action may infer approval from PM output, Ticket priority, UI state, or confidence.

Once phase review has begun, Ticket conversion must not mutate that phase's required composition. Eligible work that cannot safely join the current phase follows the approved append-only future intake/backlog placement; non-deterministic placement escalates.

## Ticket waiting, closure, and reopen operations are idempotent

`needs_information` and `self_service` requester-dependent outcomes may enter the approved requester-waiting state with a 72-hour deadline only through AIOS-controlled transitions.

Inactivity closure must be deterministic and idempotent and must persist system/audit evidence. A later requester reply may automatically reopen only an eligible inactivity-closed Ticket and must queue a fresh Project Manager triage attempt with a fresh execution context.

Explicit rejection, duplicate resolution, or operator closure must not be silently reopened through the inactivity rule.

## Context Budget failures never fake execution progress

AIOS must complete Context Budget evaluation after deterministic context assembly and before provider execution.

If permitted deterministic reduction cannot bring required context below the system hard ceiling, the Action must block/fail according to the owning workflow's approved semantics, persist actionable budget evidence, and must not call the harness or transition workflow state as though an Agent executed successfully.

Agents and harnesses cannot override the Context Budget result. Retry loops must not bypass a budget block.

## Scorecards never route work automatically

Harness scorecards and recommendations are advisory evidence only.

No Action may automatically change Agent bindings, harnesses, models, reasoning settings, task placement, task ordering, or execution routing because one scorecard configuration ranks above another.

Phase 3 remains:

```text
observe
→ score
→ recommend
```

Manual operator configuration changes continue through the existing validated Agent configuration/binding workflow.

## Project creation/linking always seeds the dedicated default Skill set

`CreateProject::handle()` provisions the three core Agents (`ProvisionDefaultProjectAgents`), binds their `AgentWorker`s, then calls `ProvisionDedicatedAgentSkills::handle($project)` before recording the `project.created`/`project.registered` audit event — for both brand-new and linked-existing projects. This must run after the `AgentWorker` bindings exist, because skill auto-assignment resolves the bound Agent per role from `project->workers()->with('agent')`, not from Agent name/role alone.

`ProvisionDedicatedAgentSkills` is the single source of truth for the dedicated per-role Skill catalogue (8 Skills per core role: Project Manager, Coder, Reviewer; 5 of each auto-assigned). `database/seeders/DedicatedAgentSkillsSeeder` is a thin backfill wrapper that loops existing projects and delegates to this same Action — it must not duplicate the catalogue. Both paths are idempotent (skip by existing slug/name) and non-destructive (never overwrite operator edits, re-enable a disabled Skill, or re-attach a Skill an operator intentionally detached); adding a new default Skill to the catalogue only affects projects that don't already have a Skill of that slug/name.

## Database protection guards every protected execution the same way

`RunProjectManager`, `RunCoderTask`, and `RunReviewerTask` each call `DatabaseProtectionGuard::guard($project)` as the very first statement inside the existing try-block that wraps the harness call, after `AgentRunRecorder::start()` has already persisted the `AgentRun`. A guard failure (`DatabaseProtectionFailed`/`UnsafeProjectPath`) is caught by that same block's existing `catch (Throwable)` and follows the role's normal bounded-retry-then-block failure path; do not special-case it into a different transition. This applies identically regardless of the resolved Agent's harness (Codex or Claude Code) — switching harness configuration must never bypass it.

## Pre-execution setup failures never escape a role Action

`RunProjectManager::handle()`, `RunCoderTask::handle()`, and `RunReviewerTask::run()` each wrap their pre-harness setup (repository preflight, context capsule assembly, `TaskContractGuard::evaluate()`, runtime-capability detection, context assembly) in its own `catch (Throwable)`, separate from the existing harness-execution `catch (Throwable)`. An uncaught exception in this setup code previously escaped the Action entirely and killed the persistent `aios:work` worker process for every project (the `TaskContractGuard::globMatches()` regex-delimiter incident). PM routes the failure through `failAttempt()` (reason `pre_execution_exception`); Coder through a new `blockUnexpectedFailure()` (audit `task.blocked_unexpected_error`, mirrors `blockUnsafeProjectPath`); Reviewer through the existing `recordReviewerOperationalFailure()` (reason `pre_execution_exception`), per the "Reviewer operational failures never reject implementations" rule above. Any new pre-harness code added to these three Actions must stay inside this boundary.

## Unbound vs. broken Agent bindings are not the same failure

`AgentResolver::forRole()` throws `App\Exceptions\AgentNotBoundToRole` when a workflow role has no Agent configured at all (no `AgentWorker` row, or `agent_id` is null). While the existing migration/compatibility path supports such projects, callers may use the established legacy Codex fallback only for this genuinely unconfigured case; do not treat Codex as the default for an already configured Agent.

When an Agent is bound, its persisted and validated harness configuration is authoritative. Any other failure (agent disabled, agent record missing, or `AgentHarnessResolver::resolve()` throwing because the persisted harness identifier is unsupported or unimplemented) means an Agent is bound but broken and must never fall back silently. PM/Coder wrap `resolveAgent()` in a `catch (LogicException $exception)` and fail the roadmap attempt / block the task (`roadmap.blocked_agent_misconfigured`, `task.blocked_agent_misconfigured`) without executing anything. Reviewer routes the same failure through `TaskWorkflow::recordReviewerOperationalFailure()` (reason `agent_misconfigured`) so it follows the existing bounded-retry-then-Blocked operational failure path instead of a new one-shot block, per the "Reviewer operational failures never reject implementations" rule above. See `App\Services\AgentResolver`, `RunProjectManager::blockMisconfiguredAgent()`, `RunCoderTask::blockMisconfiguredAgent()`, `RunReviewerTask::run()`.

## Task planning revisions remain PM proposal-only
Deterministic Task-contract defects block the Task and queue one revision under the existing Project Manager lease. PM may propose only allowlisted Task-contract replacements; AIOS validates and atomically applies them. Never create another lane or allow PM to mutate identity, phase, position, or workflow state directly.
