---
paths:
  - resources/js/components/agent-office.tsx
---

# Components

## Task cards show durable task state
The recent/current task badge must always render the linked Task.status. AgentRun.status is execution evidence and may be completed even when the task is failed or blocked; never substitute it as a task status.
