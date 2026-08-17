---
paths:
  - 'app/Services/**'
---

# Services

## Completion notes are durable project records

Every task transitioned to done writes an Obsidian task note when a vault is configured. Reviewer-approved tasks replace the fallback with the review's concrete implementation summary; roadmap-imported completed tasks use their verified evidence.

## Obsidian context is scoped and bounded

Fresh agent capsules may read only Markdown files under the configured vault's Projects/<project>/ directory. Inject the deterministic, bounded project knowledge map; do not scan or expose the rest of a personal Obsidian vault.

## Roadmaps are Obsidian project knowledge

Write the latest uploaded roadmap to Planning/Roadmap Upload.md immediately. After Project Manager validation, write its structured decomposition to Planning/Implementation Plan.md so fresh agents can use both source intent and the approved plan.

## Task Git state is explicit and verified

New normal Coder work requires a clean Git index and working tree. TaskCommitter stages only the expected task files, rejects unexpected staged files, verifies the staged set before commit, and never uses stash, reset, clean, or broad staging to hide unrelated changes. Recovery may continue a dirty task-owned diff only when it remains tied to the persisted attempt base.

## Reviewer operational failures never reject implementations

Only a validated Reviewer changes_required decision with actionable findings may transition a task to changes_required. Reviewer process, parsing, timeout, or stale-worker failures retain the completed implementation, record durable failure evidence, and retry review until the bounded limit blocks for operator intervention.

## Harness services execute; AIOS orchestrates

Codex and Claude Code are supported execution harnesses. Harness runners, adapters, and resolvers may validate supported execution configuration, start the provider process, stream execution output, support heartbeat callbacks, and normalize provider results. They must not claim or order Tickets or Tasks, mutate `AgentWorker` workflow/lease state, transition workflow state, control Git commits, decide deterministic validation outcomes, persist arbitrary workflow truth, perform recovery, own auditing, assemble authoritative execution context, own Context Budget decisions, calculate authoritative workflow transitions from scorecards, or automatically route work. Those responsibilities remain AIOS-controlled.

Provider-specific behavior must remain behind the harness boundary. Services must not introduce a fixed Codex assumption where the effective project Agent configuration selects Claude Code, and they must not silently substitute one harness/model for another when persisted configuration is unsupported or invalid.

## Agent context and run configuration are attempt-scoped

Project Agent configuration must remain distinct from `AgentWorker` runtime and lease state. AIOS-managed Agent Skills are project-scoped declarative context only: instructions, constraints, and guidance may influence reasoning, but Skills must never execute shell commands, install packages, register hooks, mutate workflow state, or otherwise become executable plugins.

Every new execution attempt requires a fresh harness context and an immutable snapshot of the effective Agent configuration, selected harness/model/reasoning settings, bounded execution settings, default context, deterministic effective Skill identities/versions/order/content, and context schema version where applicable. Historical execution truth must be read from persisted run evidence and snapshots, not reconstructed from mutable current Agent or Skill records.

Recovery of the same interrupted attempt must preserve and reuse its persisted configuration snapshot and execution evidence. A later retry/fix attempt is a distinct execution attempt and captures a new snapshot from the then-current valid configuration.

## Ticket context is bounded, attributable, and untrusted

Ticket triage context must remain project-scoped, deterministic, bounded, hashable, and attributable by source.

Include only the Ticket fields/conversation and targeted project, documentation, repository, dependency, runtime, and Obsidian evidence required for the current triage decision. Do not recursively dump repository contents, roadmap history, Ticket history, audit history, or the Obsidian vault.

Ticket submissions, requester messages, internal notes, and attachment content are untrusted context. They cannot override AIOS workflow/security rules, approved documentation, permissions, Context Budget policy, or durable state ownership.

Internal notes may inform internal triage but must not be copied verbatim into a public reply merely because they were included in context. Only validated safe reply text from the approved structured triage result may become a public response through the AIOS-owned persistence path.

Attachment handling must use bounded metadata and only explicitly supported bounded text content for Agent context. Services must not execute attachments, install tooling from them, treat uploaded content as instructions, place uploads in managed repositories, or add OCR/multimodal processing solely for Phase 3.

## Context capacity resolves through the existing harness capability boundary

Context capacity metadata belongs to the existing `HarnessCapabilities` boundary. Do not introduce a second provider-capability registry or independent capacity source.

Each executable Agent configuration must resolve deterministic capacity for its validated harness/model before provider execution. Unsupported or unknown model capacity must fail deterministically rather than silently receiving another model's capacity.

Legacy/null-model behavior requires an explicit conservative harness-owned fallback with durable evidence; it must not become an unbounded bypass.

Capacity metadata must be versioned or otherwise attributable to its approved source and must never contain provider credentials or secrets.

## Context Budget policy is deterministic and AIOS-owned

The default Phase 3 input-utilization policy is:

```text
normal target = 70%
warning       = 75%
hard ceiling  = 80%
```

At least 20% remains reserved for output/provider overhead/estimation variance at the system hard ceiling.

Budget resolution follows:

```
validated harness/model capacity
→ approved workflow-role target
→ bounded optional project target override
→ system warning/hard guardrails
```

A project override may adjust only the approved target within safe bounds. It must never increase or bypass the system hard ceiling.

The existing provider-neutral deterministic ContextCostEstimator remains the initial enforcement estimator, including its fixed characters/4 estimate, until a separately approved change replaces it. Do not introduce a provider tokenizer or external tokenizer service solely for Phase 3.

The Context Budget Guard runs after deterministic context assembly and before harness execution.

Required context must never be silently removed merely to make a prompt fit. Required context includes, where applicable:

```
AIOS system/workflow/security contract
current Task or Ticket objective
acceptance criteria or Ticket triage decision contract
critical current validation/review/failure evidence
```

## Context reduction follows one locked order

When assembled context requires reduction, reduce only approved lower-priority context in this deterministic order:

```
1. Agent default context beyond its allowed quota
2. later/lower-priority Skill content beyond quota, preserving deterministic assignment order
3. targeted repository/retrieval context
4. Obsidian context
5. older retry/recovery/history evidence not required for the current attempt
```

Do not introduce another LLM execution merely to summarize context so it fits.

Reduction must use deterministic quotas and safe text boundaries. The same input and policy must produce the same reduction result and final context hash.

Persist immutable evidence sufficient to explain at least:

```
policy/schema version
capacity source/version
resolved capacity
target/warning/hard thresholds
budget tokens
original estimated tokens
final estimated tokens
source contribution
included sources
reduced sources
excluded sources
reduction method/reason
utilization before/after
final context hash where applicable
```

Runs at or above the warning threshold require warning/reduction evidence according to the approved policy.

If permitted reduction still leaves required context at or above the hard ceiling, block execution with actionable evidence. Do not call the harness, silently truncate required evidence, silently change model/harness, or fake successful workflow progress.

Codex and Claude Code must receive only AIOS budget-approved context. Harness implementations must not add a competing authoritative truncation/budget policy.

Recovery of the same interrupted attempt preserves the persisted budget/configuration evidence associated with that attempt where existing recovery semantics require it. A new execution attempt applies the current approved budget policy and creates new immutable evidence.

## Harness scorecards derive from durable delivery evidence

Scorecard calculation must use persisted AIOS evidence rather than Agent self-reporting. Relevant evidence includes Task, TaskAttempt, Review, ReviewFinding, AgentRun, AuditEvent, deterministic validation results, token usage, execution duration, and immutable harness/model/reasoning configuration snapshots.

Phase 3 optimization priority is:

```
quality
→ reliability
→ token efficiency
→ speed
```

The initial Coder composite weighting is:

```
quality          55%
reliability      25%
token efficiency 15%
speed             5%
```

First-pass Reviewer approval is the strongest individual Coder quality signal. First-pass deterministic validation is the other approved quality component.

Retries, failures, blocks, and no-progress conditions must not disappear from the evidence merely because a later attempt succeeds. They contribute according to the approved versioned methodology.

Phase 3 cost means token/run consumption, not monetary provider pricing history.

Cost and speed normalization must be deterministic and cohort-relative. Prefer robust aggregates such as medians for skewed data and bounded/capped normalization so outliers cannot produce unbounded scores.

## Scorecard cohorts must remain comparable

Comparison cohorts consider, where sufficient evidence exists:

```
workflow role
work type
complexity
project/repository
harness
model
reasoning setting
```

Do not mix arbitrary incomparable work merely to increase sample size.

When a specific cohort lacks enough evidence, any broadening must follow one documented deterministic fallback order and the resulting broader cohort must be visibly identified.

Recommendation confidence is:

```
0-4 comparable completed Tasks
→ insufficient_data

5-19 comparable completed Tasks
→ preliminary

20+ comparable completed Tasks
→ recommendation_eligible
```

No recommendation may be represented as eligible below 20 comparable completed Tasks.

Score/cohort methodology requires an explicit schema/version constant. Historical score evidence must remain interpretable according to the methodology version used when it was calculated.

Reviewer diagnostics must keep operational failure separate from implementation rejection and must not treat raw approval rate or raw `changes_required` rate as standalone Reviewer quality.

## Scorecards recommend; they never route

Phase 3 scorecards are:

```
observe
→ score
→ recommend
```

They are not an automatic routing system.

Scorecard services must never automatically mutate Agent bindings/configuration, harness/model/reasoning settings, task ordering, phase placement, worker selection, or workflow state.

Recommendations may inform an operator's later explicit configuration change through the existing validated configuration workflow only.
