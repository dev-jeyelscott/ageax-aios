---
paths:
  - 'app/Actions/*KnowledgeImprovement*.php'
  - 'app/Services/*KnowledgeImprovement*.php'
  - 'app/Services/*KnowledgeSource*.php'
  - 'app/KnowledgeImprovement*.php'
  - 'app/Models/KnowledgeImprovement*.php'
  - 'app/Models/KnowledgeSource*.php'
  - 'app/Http/Controllers/**/*KnowledgeImprovement*.php'
  - 'app/Http/Requests/**/*KnowledgeImprovement*.php'
  - 'app/Console/Commands/*KnowledgeImprovement*.php'
  - 'database/migrations/*knowledge_improvement*.php'
  - 'database/migrations/*knowledge_source*.php'
  - 'database/factories/KnowledgeSource*.php'
  - 'resources/js/**/knowledge-improvements/**'
  - 'app/Services/**'
---

# Knowledge Improvement Queue

## Candidates are proposals, not self-modifying memory

Knowledge-improvement candidates are durable operator-review records derived from existing structured AIOS evidence. They are not workflow state, persistent LLM memory, Agent configuration, or executable Skills. Detection must not mutate Skills, Agent bindings, `.ai/rules/**`, tests, documentation, task ordering, Git state, or workflow transitions.

## Fingerprints must be deterministic and safe

Build recurring-failure fingerprints only from bounded project-scoped evidence such as structured review fields, deterministic validation check identifiers, known audit event types, recovery root-cause categories, and normalized subsystem/path identifiers. Do not persist chain-of-thought or use arbitrary model prose as the durable fingerprint payload. When prose is needed for classification, reduce it to a deterministic bounded family or hash and keep raw text out of the candidate.

Objective knowledge-integrity findings use the same rule. Fingerprint only stable normalized source identity, detector family, target/reference identity, temporal manifest transition identity where required, and other bounded deterministic fields. Never fingerprint timestamps, machine-specific absolute paths, secrets, random execution values, or LLM-generated prose.

## Operational/provider failures are not coding guidance

Transient harness failures, missing structured model output, provider process failures, leases, and other operational execution faults must not be promoted into Coder Skill guidance. Configuration/environment and recovery-operational patterns may become documentation or rule proposals when they recur, but they must remain operator-reviewed.

## Operator approval is mandatory

A recurring detected pattern may create or update a candidate only after the configured occurrence threshold is met. AIOS never automatically creates, enables, disables, assigns, unassigns, reorders, or rewrites Skills from detection alone. Rejected, dismissed, or previously approved recurring candidates remain suppressed until the configured amount of materially new supporting evidence is present.

A uniquely fingerprinted objective knowledge-integrity defect may create one pending candidate as soon as deterministic evidence proves the defect. Examples include a broken bounded project-local link, a missing required current knowledge source, an explicit reference to a removed repository path, or an explicit durable supersession relationship. Point-defect detection does not waive operator approval, does not authorize source mutation, and repeated scans of unchanged evidence must remain idempotent.

## Skill application preserves Phase 2 history

An approved Skill-target candidate may append bounded guidance only to the exact existing same-project Skill referenced by the candidate. The normal `Skill` model validation/versioning remains authoritative. Approval must never enable or assign the Skill. Existing `AgentRun.configuration_snapshot` evidence is immutable and must not be rewritten; only future runs may observe a newer Skill version.

## Repository knowledge still follows the normal Git lifecycle

Approval of a `.ai/rules/**`, regression-test, or documentation proposal does not directly edit repository files. It records operator approval and must be implemented through the normal AIOS Task/Coder/Git/validation/Reviewer lifecycle. The improvement queue must never become a second repository mutation path.

## Detection and decisions are project-scoped, idempotent, and auditable

The same project/fingerprint pair must resolve to one candidate. Scans may safely repeat without duplicate candidates or duplicate evidence. Candidate creation, evidence growth, reopen, decision, and approved Skill application must be auditable and must preserve project isolation.

## Phase 4+ Knowledge Architect remains proposal-only

`KnowledgeArchitect` may be activated only by a separately approved implementation task. When activated, it is an advisory semantic-analysis role over bounded AIOS-provided evidence. It may create or enrich a schema-validated knowledge-improvement proposal, but it must never directly mutate Skills, Agent bindings or configuration, `.ai/rules/**`, repository documentation, regression tests, Obsidian knowledge, Git state, Task ordering, worker state, permissions, or durable workflow transitions.

Deterministic detection remains preferred where an objective rule can identify a gap, stale source, conflict, or recurring failure. Knowledge Architect output is evidence or proposal only. AIOS validates and persists any resulting candidate, and the existing operator-review contract remains mandatory.

Approved repository knowledge changes still enter the normal Task, Coder, Git, deterministic validation, and Reviewer lifecycle. Approval of a Knowledge Architect proposal must not become a second repository mutation path. Cross-project knowledge must never be silently promoted or injected into another project; any future reusable global pattern requires explicit operator-controlled promotion, bounded/redacted evidence, and immutable provenance.

## Coder execution budgets are immutable per attempt
AIOS resolves a bounded execution setting before a Coder run, persists it in the assembled configuration snapshot, and passes it through the harness to both provider runners. Recovery must reuse that snapshot; do not recompute or let the harness choose a different limit mid-attempt.
