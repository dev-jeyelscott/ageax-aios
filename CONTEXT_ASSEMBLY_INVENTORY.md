# AIOS Context Assembly Inventory

Repository-backed inventory for SCG-001. This document describes current behavior; it does not define or change runtime policy.

## Assembly callers

| Caller | Context source | Assembly and run evidence |
| --- | --- | --- |
| Project Manager roadmap planning | `ObsidianProjectNotes::roadmapRetrieval()` plus pending PM messages and runtime capabilities in `app/Actions/RunProjectManager.php` | `AgentContextAssembler::assemble(..., AgentRole::ProjectManager, ...)`; `AgentRunRecorder::start()` receives the retrieval manifest and assembled context. |
| Coder | `TaskContextCapsuleFactory::make($task, AgentRole::Coder)` in `app/Actions/RunCoderTask.php` | The action adds immutable execution settings, assembles the Coder context, starts an `AgentRun`, then dispatches through the resolved harness. |
| Reviewer | `TaskContextCapsuleFactory::make($task, AgentRole::Reviewer)` in `app/Actions/RunReviewerTask.php` | The action adds the recorded attempt's Git and validation fields to the prompt, records the run, and dispatches through the resolved harness. |
| Ticket triage | `TicketContextCapsuleFactory::make($ticket)` in `app/Actions/RunTicketTriage.php` | The action removes `retrieval_manifest` from the provider context, passes it separately to `AgentRunRecorder`, and assembles the remainder for the Project Manager role. |

`TaskContextCapsuleFactory` supplies task contract fields, dependencies, relevant paths, safe verification commands, runtime capabilities, bounded Obsidian knowledge, pending role-specific operator messages, and review findings. Its Reviewer variant also adds a bounded advisory risk map. `TicketContextCapsuleFactory` bounds ticket conversation, attachments, related records, documentation/rules, conflicts, Obsidian knowledge, and conditional runtime evidence.

## Precedence, snapshot, and estimation

`app/Services/AgentContextAssembler.php` is the single provider-facing context boundary. Its fixed schema-v2 system rules remain non-overridable. The declared precedence is task contract/acceptance criteria, AIOS system rules, Agent default context, then enabled role-applicable Skills in deterministic pivot order. The payload is hashed from schema version, system rules, Agent snapshot, Skill snapshots, task context, and execution settings.

`app/Services/AssembledAgentContext.php` exposes the provider payload and a distinct immutable configuration snapshot. The latter stores only context schema/hash, Agent identity/configuration, ordered Skill identities/content/versions, and execution settings. `AgentContextAssembler::restore()` reuses that configuration for recovery rather than resolving mutable Agent/Skill records.

`app/Services/ContextCostEstimator.php` uses the deterministic provider-neutral `ceil(characters / 4)` heuristic (schema v1). It attributes system rules, Agent default context, Skills, task core, Obsidian context, retry/recovery evidence, and review evidence; estimates are stored separately from the provider payload.

## Context Budget

`app/Services/ContextBudgetPolicy.php` is the current sole policy (schema/policy v1): target 70%, warning 75%, hard ceiling 80%, with a project target override bounded to 50–70%. It derives integer limits from harness capability evidence and retains quotas for default context (8%), Skills (18%), repository retrieval (20%), Obsidian (10%), and older history (8%) of the target budget.

`app/Services/ContextBudgetGuard.php` estimates the complete prompt, warns/reduces at or above warning, and blocks when required context is at or above the hard ceiling or reduced context remains at/above it. Required context removes optional default context, Skills, repository retrieval, Obsidian, and older history; workflow contract, system rules, task core, critical current evidence, and handoffs remain. Reduction is deterministic (`fixed_quota_safe_boundary_v1`) in this order:

1. Agent default context
2. Skills
3. Repository retrieval
4. Obsidian context
5. Older history

It records capacity and policy versions, thresholds, source quotas/contributions, original/final/required estimates and hashes, included/reduced/excluded sources, reduction details, utilization, and the approved/reduced/blocked decision.

## Retrieval, dispatch, and persisted evidence

`app/Services/ObsidianProjectNotes.php` performs bounded deterministic retrieval. Task retrieval ranks task brief (10), project state (20), explicit links (30), related manifest references, and approved role-scoped global patterns; its manifest records selected sources, ranks, hashes, character counts, and reason. Roadmap retrieval is targeted project knowledge only. Ticket triage reuses deterministic task retrieval for a matched task or selects the bounded PM fallback set.

`app/Services/AgentRunRecorder.php` persists the immutable configuration snapshot, context-cost estimate/schema, retrieval manifest, harness selection, prompt hash, and audit events at run start. At completion it persists normalized provider usage, bounded/redacted output metadata, commands, and file modifications. `ContextBudgetedAgentHarness` then updates the same run with the final prompt/configuration snapshot and `context_budget_snapshot`, records `context_budget.*` audit evidence, blocks before provider execution when necessary, and reuses persisted budget evidence during eligible recovery.

`app/Services/AgentHarnessResolver.php` validates the Agent's persisted harness/model/reasoning configuration. Both `CodexHarness` and `ClaudeCodeHarness` route provider calls through `ContextBudgetedAgentHarness`; only the budget-approved prompt and AIOS-selected execution settings reach their respective runners.

## Boundary: reusable versus AIOS-owned

| Reusable context components | AIOS-owned orchestration components |
| --- | --- |
| `AgentContextAssembler`, `AssembledAgentContext`, `ContextCostEstimator`, `ContextBudgetPolicy`, and `ContextBudgetGuard` provide deterministic assembly, snapshots, estimation, and reduction. | `RunCoderTask`, `RunReviewerTask`, `RunProjectManager`, and `RunTicketTriage` own role contracts, workflow eligibility, structured result handling, and durable transitions. |
| `ObsidianProjectNotes`, `TaskContextCapsuleFactory`, `TicketContextCapsuleFactory`, and `OrchestratorContextCapsuleFactory` produce bounded typed context and retrieval manifests. | `AgentRunRecorder` and `ContextBudgetedAgentHarness` attach evidence to AIOS runs, coordinate handoffs/recovery, and gate dispatch; harness runners never choose policy. |
| `AgentHarnessResolver`, `CodexHarness`, and `ClaudeCodeHarness` are provider adapters behind the shared budget gate. | Git/worktree lifecycle, database protection, validation, task/review/ticket state, leases, ordering, and audit ownership remain in AIOS Actions/services, not reusable context policy. |

## Focused regression evidence

- `tests/Feature/AgentContextAssemblerTest.php`: precedence, deterministic hash, targeted role-applicable Skills, and estimate separation.
- `tests/Feature/ContextBudgetAgentRunTest.php`: registered harness capacity, hard-ceiling block before provider dispatch, immutable evidence, and recovery-policy reuse.
- `tests/Feature/ObsidianFirstRetrievalTest.php`: bounded task retrieval ordering/manifest and persisted retrieval/token-observability evidence.
