---
paths:
  - 'app/Actions/**'
---

# Actions

## Coder attempts start from a deterministic Git base
Before a new normal Coder attempt, require a clean Git index and working tree and persist the clean base SHA. Derive task candidate paths from that base; never subtract baseline filenames. Dirty failed/interrupted task state may continue only through explicit recovery evidence tied to the same base. Never stash, reset, clean, discard, or auto-commit pre-existing changes.

## Reviewer operational failures never reject implementations
Only a validated Reviewer changes_required decision with actionable findings may transition a task to changes_required. Reviewer process, parsing, timeout, or stale-worker failures retain the completed implementation, record durable failure evidence, and retry review until the bounded limit blocks for operator intervention.
