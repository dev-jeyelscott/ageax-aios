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
Codex and Claude Code are supported execution harnesses. Harness runners, adapters, and resolvers may validate supported execution configuration, start the provider process, stream execution output, support heartbeat callbacks, and normalize provider results. They must not claim or order tasks, mutate `AgentWorker` workflow/lease state, transition workflow state, control Git commits, decide deterministic validation outcomes, persist arbitrary workflow truth, perform recovery, own auditing, or assemble authoritative execution context. Those responsibilities remain AIOS-controlled.

Provider-specific behavior must remain behind the harness boundary. Services must not introduce a fixed Codex assumption where the effective project Agent configuration selects Claude Code, and they must not silently substitute one harness for another when persisted configuration is unsupported or invalid.

## Agent context and run configuration are attempt-scoped
Project Agent configuration must remain distinct from `AgentWorker` runtime and lease state. AIOS-managed Agent Skills are project-scoped declarative context only: instructions, constraints, and guidance may influence reasoning, but Skills must never execute shell commands, install packages, register hooks, mutate workflow state, or otherwise become executable plugins.

Every new execution attempt requires a fresh harness context and an immutable snapshot of the effective Agent configuration, selected harness/model/reasoning settings, bounded execution settings, default context, deterministic effective Skill identities/versions/order/content, and context schema version where applicable. Historical execution truth must be read from persisted run evidence and snapshots, not reconstructed from mutable current Agent or Skill records.

Recovery of the same interrupted attempt must preserve and reuse its persisted configuration snapshot and execution evidence. A later retry/fix attempt is a distinct execution attempt and captures a new snapshot from the then-current valid configuration.
