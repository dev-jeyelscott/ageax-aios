---
paths:
  - 'app/Actions/**'
---

# Actions

## Coder attempts require a deterministic Git boundary
Before claiming or executing a new normal Coder attempt, inspect HEAD, the index, and the working tree and require a clean repository with a valid HEAD. Persist that clean base SHA on the attempt. Never stash, reset, clean, discard, or auto-commit pre-existing user changes. Interrupted-attempt recovery is separate: it may continue an existing dirty task diff only while repository HEAD still matches the recorded base SHA. Task commits must stage only expected task paths, reject unexpected staged paths, and verify the staged file set before commit.
