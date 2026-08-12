---
paths:
  - 'app/Actions/**'
---

# Actions

## Task commits exclude pre-existing working-tree changes
Snapshot git status before every Coder attempt. Persist baseline and candidate paths on the attempt, and pass only candidate paths to TaskCommitter; never use git add --all for task commits.
