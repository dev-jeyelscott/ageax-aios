---
paths:
  - bootstrap/app.php
---

# Bootstrap

## AIOS worker scheduler fallback
Keep the every-minute `aios:work --once` scheduled fallback. It self-heals an exited long-lived worker; run it in the background with `withoutOverlapping()`. Durable AgentWorker leases remain the serial-execution authority when it overlaps the normal worker process.
