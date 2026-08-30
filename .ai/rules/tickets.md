---
paths:
  - 'app/Ticket*.php'
  - 'app/Models/Ticket*.php'
  - 'app/Policies/Ticket*.php'
  - 'app/Http/Controllers/**/*Ticket*.php'
  - 'app/Http/Requests/**/*Ticket*.php'
  - 'app/Console/Commands/*Ticket*.php'
  - 'app/Console/Commands/RunAiosWorkers.php'
  - 'database/migrations/*ticket*.php'
  - 'resources/js/**/tickets/**'
---

# Tickets

These rules define the Ticket domain, HTTP/UI boundary, persistence shape, and PM scheduling invariants. Ticket state mutation and orchestration must still flow through the authoritative AIOS Actions/Services rather than being reimplemented in models, controllers, commands, or frontend code.

## Ticket is not Task

```text
Ticket != Task
```

A Ticket is project-scoped intake, conversation, triage, requester-waiting, escalation, and conversion evidence.

A Task is executable implementation work.

Ticket submission must never directly enter Coder or Reviewer execution. Only an AIOS-validated Ticket-to-Task conversion may create executable work.

Ticket-origin Tasks use the normal Task state machine, Git lifecycle, validation, dependency ordering, phase review barrier, Reviewer semantics, recovery, and auditing rules.

## Ticket state is server-authoritative

Ticket lifecycle values and triage decisions must be explicit validated domain values, not arbitrary free-form frontend state.

Agents, harnesses, frontend code, Eloquent model callbacks, and presentation code must not own durable Ticket transitions.

Controllers and UI may request an operation and render resulting durable state; AIOS-owned Actions/Services validate and persist the transition transactionally.

Cross-project Ticket, Task, attachment, message, duplicate, or conversion relationships must be rejected.

## Project Manager triage reuses the existing PM worker

The existing Project Manager performs `ticket_triage`. Do not create a Ticket Reviewer Agent role or another project worker lane.

Roadmap analysis and Ticket triage share the existing Project Manager `AgentWorker` lease/heartbeat boundary and remain serial.

Worker ordering is:

```
pending/recoverable roadmap analysis
→ one eligible Ticket triage item
→ Coder
→ Reviewer
```

Pending roadmap analysis retains precedence over Ticket triage.

Ticket claiming must be serialized through AIOS transactions/row locks so two PM executions cannot claim the same Ticket concurrently.

`RunAiosWorkers::handle()`'s persistent per-project loop must never let an uncaught exception from `recoverPendingTicketConversion()`, `RunProjectManager`, `RunTicketTriage`/`ConvertTicketToTask`, `RunCoderTask`, or `RunReviewerTask` propagate out of that project's iteration: each call is wrapped in its own `catch (Throwable) { report($throwable); }` alongside the existing lease-release `finally`, so one project's or one role's failure cannot stop every other project's Coder/Reviewer/PM work for the life of the worker process (this is what actually happened in the `TaskContractGuard` regex-delimiter incident, on top of the scheduled `aios:work --once` fallback described in `bootstrap.md`). Preserve this containment when touching the loop; do not let a `finally`-only block become the sole safety net again.

Every new triage or re-triage attempt uses a fresh execution context and the currently bound Project Manager Agent/harness configuration. Recovery and retry behavior must use durable attempt/run evidence.

The PM returns structured proposals only. PM output cannot directly change Ticket state, send a reply, create a Task, reorder work, or resolve escalation.

## Automatic conversion is atomic and idempotent

Automatic (unreviewed) conversion may create exactly one Task only when the approved decision is `approved`, implementation is required, confidence is at least `0.80`, scope is clear/safe/bounded, complexity is not high, no escalation predicate applies, and phase/dependency placement is deterministic.

Conversion must transactionally lock and re-check the relevant Ticket/project/phase placement evidence before commit.

At minimum, re-check:

```
Ticket has not already converted
Ticket and target Task/phase belong to the same project
phase review has not invalidated current-phase placement
dependencies remain valid
Task key/position cannot collide
proposal remains exactly one bounded Task
```

Persist Task creation, Ticket linkage, dependencies, Ticket transition, and required audit/system evidence atomically or leave the Ticket unconverted.

Retries and crashes must never create duplicate Tasks from one Ticket.

## Operator-approved multi-Task conversion

Multi-Task/phase scope is a mandatory escalation for automatic conversion (see below) and is never resolved by PM re-triage alone. It has exactly one resolution path: an operator explicitly reviewing and approving a bounded, ordered `proposed_tasks` set on a specific completed triage attempt via the existing Ticket escalation-decision action (`DecideTicketEscalation`).

When that approval exists, AIOS (via `ConvertTicketToTask`, driven by the same durable worker-loop recovery step used for automatic conversion, never synchronously from the approval request) may create the full approved set of Tasks from that one Ticket, subject to the same re-check discipline as single-Task conversion:

```
Ticket has not already converted
every Task in the proposed set is itself clear/safe/bounded
in-set and cross-Ticket dependencies remain valid and resolve to real Tasks
the whole set shares one deterministic target phase
no escalation reason has newly surfaced since the operator's approval
   (any reason present at fresh re-evaluation that was not part of the
   approved attempt's reviewed escalation reasons forces re-escalation,
   never silent conversion or silent failure)
```

All Tasks in an approved set are created together, or none are created — never a partial set, on first attempt or on retry after a crash.

An operator-approved multi-Task conversion is strictly a Ticket-to-Task authoring capability. It must never be treated as, or used to bootstrap, concurrent/parallel Coder execution — every Task it creates still goes through the unmodified Task state machine, dependency ordering, phase review barrier, and serial Coder/Reviewer execution.

## Phase review barrier remains authoritative

Current-phase Ticket work may be appended only before phase review has begun and only when phase composition may still safely change and phase/dependency placement is deterministic.

Once phase review begins, Ticket work must not alter that phase's required composition.

Otherwise eligible work uses the approved append-only future intake/backlog placement and must not be inserted ahead of existing phases.

Roadmap interruption, reordering, or non-deterministic placement requires operator escalation.

## Operator escalation cannot be bypassed

Mandatory escalation includes:

```
confidence < 0.80
unclear or contradictory requirements
architectural decision required
breaking public/API/data contract
material schema/data migration risk
destructive operation
security/privacy/auth impact requiring judgment
conflict with approved documentation
unclear business priority
high complexity
multiple Tasks/phases required
roadmap/phase interruption or reordering
critical/emergency work that would preempt queued work
unsafe or unresolved dependency/phase placement
```

Confidence never overrides a deterministic escalation flag.

Critical/emergency roadmap interruption or reordering always requires explicit operator approval.

Viewing a Ticket, editing presentation data, PM recommendation, requester urgency, or frontend interaction must never be interpreted as operator approval.

## Message visibility is explicit and server-enforced

Ticket messages are explicitly typed:

```
public_reply
internal_note
system_event
```

Backend authorization/payload construction must enforce the visibility boundary. Frontend hiding alone is never sufficient protection for `internal_note`.

Future client-safe payloads must not expose internal notes.

AI-authored public replies must:

```
be visibly labeled "AI-generated response"
retain durable AgentRun attribution where applicable
be persisted only after AIOS validation
```

Requester content, internal notes, PM output, or attachment text must not be blindly copied into public responses.

## Requester waiting uses the locked 72-hour lifecycle

Only approved requester-dependent outcomes:

```
needs_information
self_service
```

use the requester-waiting lifecycle and 72-hour response deadline.

No eligible requester response within 72 hours:

```
awaiting requester
→ inactivity close
→ system event
→ audit evidence
```

Closure must be deterministic and idempotent.

An eligible requester reply after inactivity closure:

```
reopen Ticket
→ clear requester-waiting deadline
→ queue fresh PM triage
→ use fresh execution context
```

Do not automatically reopen explicitly rejected, duplicate, or operator-closed Tickets unless a separate approved policy permits it.

## Attachments are untrusted and non-executable

Ticket attachments must use bounded size/type/name validation and established Laravel storage conventions.

Attachments must remain outside managed project repositories and must never participate in Git changes.

Prevent path traversal, absolute-path injection, unsafe filenames, symlink escape, and executable/script upload behavior according to the approved attachment policy.

Never execute uploaded content or treat attachment content as trusted instructions.

Phase 3 may expose safe metadata and bounded supported text to triage context only. Do not add an OCR, arbitrary binary extraction, multimodal, package-installation, or attachment-execution pipeline solely for Ticket triage.

## Frontend remains presentational

Ticket UI may submit validated commands/requests and render durable server state.

Frontend code must not invent or own:

```
Ticket transitions
triage claim ownership
conversion eligibility
Task creation/placement
phase review eligibility
operator escalation approval
72-hour closure/reopen decisions
Context Budget decisions
scorecard methodology
automatic harness/model routing
```

Server responses and persisted AIOS state remain authoritative.

Do not expose internal Ticket notes, hidden Agent reasoning, secrets, raw environment data, or private execution evidence through client payloads.
