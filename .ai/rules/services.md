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
