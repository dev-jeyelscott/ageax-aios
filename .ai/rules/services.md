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
