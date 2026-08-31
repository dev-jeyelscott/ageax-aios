---
paths:
  - bootstrap/app.php
---

# Bootstrap

## AIOS worker scheduler fallback
Keep separate every-minute `aios:work --once --role=project_manager`, `--role=coder`, and `--role=reviewer` scheduled fallbacks. Run each in the background with its own name and `withoutOverlapping()` so the Coder and Reviewer lanes may overlap while durable per-role AgentWorker leases remain authoritative.
