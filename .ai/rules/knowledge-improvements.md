---
paths:
  - 'app/Actions/*KnowledgeImprovement*.php'
  - 'app/Services/*KnowledgeImprovement*.php'
  - 'app/KnowledgeImprovement*.php'
  - 'app/Models/KnowledgeImprovement*.php'
  - 'app/Http/Controllers/**/*KnowledgeImprovement*.php'
  - 'app/Http/Requests/**/*KnowledgeImprovement*.php'
  - 'app/Console/Commands/*KnowledgeImprovement*.php'
  - 'database/migrations/*knowledge_improvement*.php'
  - 'resources/js/**/knowledge-improvements/**'
---

# Knowledge Improvement Queue

## Candidates are proposals, not self-modifying memory
Knowledge-improvement candidates are durable operator-review records derived from existing structured AIOS evidence. They are not workflow state, persistent LLM memory, Agent configuration, or executable Skills. Detection must not mutate Skills, Agent bindings, `.ai/rules/**`, tests, documentation, task ordering, Git state, or workflow transitions.

## Future Knowledge Architect remains proposal-only
`AgentRole::KnowledgeArchitect` exists as an enum value, but it is not currently an allowed persisted global Agent role in `Agent::GlobalRoles`. The currently supported persisted global Agent role is `RecoveryEngineer`. P4-001 does not activate, provision, bind, schedule, or create a worker lane for Knowledge Architect.

A future Knowledge Architect may detect, analyze, correlate, enrich, or propose knowledge improvements from bounded AIOS evidence. It must not directly create, enable, disable, assign, unassign, reorder, or rewrite Skills; edit repository documentation, Obsidian sources, `.ai/rules/**`, or tests; mutate Agent configuration or bindings; change Git state; or transition workflow state. Its output is advisory structured evidence only.

Any authoritative repository knowledge mutation proposed by a Knowledge Architect must remain operator-approved and must follow the existing normal Task, Coder, Git, deterministic validation, and Reviewer lifecycle. Knowledge analysis must never become a second repository mutation path.

## Cross-project knowledge intelligence never implies cross-project mutation
Current candidate detection and decisions remain project-scoped. Future cross-project knowledge intelligence may compare bounded, privacy-safe evidence and propose reusable guidance, stale/conflicting knowledge findings, or promotion candidates, but it must not silently inject one project's knowledge into another project or mutate any project's authoritative sources.

Cross-project promotion or reuse requires an explicit operator-approved policy and durable evidence identifying the source, scope, version, and intended applicability. Until that later policy exists, cross-project findings remain proposals only and cannot alter Skills, Agent context, repository docs, Obsidian notes, workflow definitions, routing policy, or Agent configuration.

## Fingerprints must be deterministic and safe
Build recurring-failure fingerprints only from bounded project-scoped evidence such as structured review fields, deterministic validation check identifiers, known audit event types, recovery root-cause categories, and normalized subsystem/path identifiers. Do not persist chain-of-thought or use arbitrary model prose as the durable fingerprint payload. When prose is needed for classification, reduce it to a deterministic bounded family or hash and keep raw text out of the candidate.

## Operational/provider failures are not coding guidance
Transient harness failures, missing structured model output, provider process failures, leases, and other operational execution faults must not be promoted into Coder Skill guidance. Configuration/environment and recovery-operational patterns may become documentation or rule proposals when they recur, but they must remain operator-reviewed.

## Operator approval is mandatory
A detected pattern may create or update a candidate only after the configured occurrence threshold is met. AIOS never automatically creates, enables, disables, assigns, unassigns, reorders, or rewrites Skills from detection alone. Rejected, dismissed, or previously approved candidates remain suppressed until the configured amount of materially new supporting evidence is present.

## Skill application preserves Phase 2 history
An approved Skill-target candidate may append bounded guidance only to the exact existing same-project Skill referenced by the candidate. The normal `Skill` model validation/versioning remains authoritative. Approval must never enable or assign the Skill. Existing `AgentRun.configuration_snapshot` evidence is immutable and must not be rewritten; only future runs may observe a newer Skill version.

## Repository knowledge still follows the normal Git lifecycle
Approval of a `.ai/rules/**`, regression-test, or documentation proposal does not directly edit repository files. It records operator approval and must be implemented through the normal AIOS Task/Coder/Git/validation/Reviewer lifecycle. The improvement queue must never become a second repository mutation path.

## Detection and decisions are project-scoped, idempotent, and auditable
The same project/fingerprint pair must resolve to one candidate. Scans may safely repeat without duplicate candidates or duplicate evidence. Candidate creation, evidence growth, reopen, decision, and approved Skill application must be auditable and must preserve project isolation.
