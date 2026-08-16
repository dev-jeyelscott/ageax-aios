# MASTER-PROMPT.md — AGEAX AIOS 2.0

## Project

AGEAX AIOS 2.0 is the active project. AIOS v1 development is paused.

AIOS 2.0 is a local, deterministic software-development orchestration system managing projects under:

```text
~/workspace
```

It coordinates three core workflow roles:

```text
Project Manager
Coder
Reviewer
```

Project Agents are project-scoped execution configuration for those workflow roles. Supported execution harnesses are:

```text
Codex
Claude Code
```

`AgentWorker` is not Agent configuration. It remains AIOS-owned durable orchestration, lease, heartbeat, and runtime state for workflow execution.

Core principle:

> **LLM execution is disposable. System state is durable.**

Persistent truth belongs in PostgreSQL, Git, repository documentation, Obsidian, and audit logs. Never depend on Codex or Claude Code conversation history for project or workflow state.

No global Agents or parallel task execution are introduced in Phase 2.

---

## Source of Truth

Follow this priority:

1. Current task and acceptance criteria
2. Approved project specifications / locked documentation
3. `AGENTS.md`
4. Existing implementation, schema, tests, configuration, and conventions
5. Official documentation

Do not contradict higher-priority sources.

---

## Mandatory Workflow

For every task:

1. Read applicable project documentation first.
2. Inspect the existing implementation before changing anything.
3. Inspect relevant code, models, migrations, services/actions, routes, authorization, UI, tests, configuration, and CI.
4. Identify the actual requirement or root cause.
5. Follow existing architecture and conventions.
6. Make the smallest correct, secure, production-ready change.
7. Avoid unrelated refactors or scope expansion.
8. Add focused regression tests.
9. Run relevant verification.
10. Report only results actually inspected or executed.

Never fabricate inspection, testing, verification, or completion.

Priority:

```text
correctness
→ security
→ data integrity
→ workflow determinism
→ requirements
→ existing architecture
→ maintainability
→ simplicity
→ testability
→ observability
→ performance when justified
→ token efficiency
```

---

## Agent Execution

Every roadmap analysis, implementation attempt, retry/fix attempt, and review must use a **fresh execution context**, regardless of whether the selected harness is Codex or Claude Code.

Do not maintain persistent Codex or Claude Code conversations between executions, and never use provider conversation history as durable project or workflow state.

Project Agent configuration is project-scoped and describes execution identity and behavior, including the workflow role, selected harness, supported model/reasoning settings, default context, assigned Skills, enabled state, and bounded execution settings where applicable.

`AgentWorker` remains separate durable AIOS-controlled orchestration/runtime state for workflow roles, including worker status, leases, and heartbeats. Agents and harnesses must not directly control worker state.

AIOS-managed Skills are project-scoped, declarative context/capability packages. Skills may provide instructions, constraints, contextual guidance, and role applicability metadata, but they are **non-executable** and must not introduce shell hooks, arbitrary code execution, package installation, or workflow control. AIOS-managed Skills remain separate from repository/harness tooling such as `.agents/skills/**` and `.claude/skills/**`.

Skill application order must be deterministic. Agent or Skill context must never override AIOS-owned workflow, security, Git, validation, recovery, persistence, auditing, permissions, or context-assembly rules.

At the start of every new execution attempt, AIOS must persist an **immutable snapshot of the effective run configuration** used for that attempt. The snapshot must identify the selected Agent and configuration version, workflow role, harness, model/reasoning settings, effective bounded execution settings, default context, effective Skills with versions/order/content, and context schema version where applicable. Snapshots must exclude credentials, `.env` contents, and raw host environment values.

Historical runs must be interpreted from their persisted execution evidence and configuration snapshot, not from mutable current Agent or Skill records. Editing Agent or Skill configuration affects future runs only.

Recovery of the same interrupted execution attempt must preserve its persisted configuration snapshot and evidence. A new retry/fix attempt is a new execution with a fresh context and a new immutable snapshot of the then-current valid configuration.

Each execution should receive only the smallest sufficient context:

```text
task
objective
acceptance criteria
scope
constraints
dependencies
relevant documentation
relevant repository paths
previous approved handoff
review findings
verification commands
```

Do not send entire conversations, repositories, roadmaps, logs, or Obsidian vaults unless explicitly required.

---

## Agent Responsibilities

### Project Manager

The Project Manager:

- analyzes uploaded roadmaps;
- creates ordered phases and implementation tasks;
- identifies dependencies;
- defines acceptance criteria;
- generates implementation-ready prompts;
- generates concise context capsules;
- produces structured Obsidian knowledge.

It returns structured output only.

It must **not directly mutate arbitrary application/database state**. AIOS validates output and performs persistence.

### Coder

The Coder works on exactly **one eligible task at a time**.

Required flow:

```text
queued
→ coding
→ validating
→ ready_for_review
```

After rejection:

```text
changes_required
→ coding
→ validating
→ ready_for_review
```

The Coder must:

- inspect before editing;
- fix root causes;
- preserve architecture and project rules;
- make minimal changes;
- preserve authorization, tenant isolation, transactions, validation, idempotency, auditability, and data integrity;
- add focused tests;
- run verification;
- check for secrets and forbidden files;
- return structured implementation results.

The Coder cannot mark a task `done`.

### Reviewer

The Reviewer independently reviews exactly **one `ready_for_review` task at a time**.

Inspect:

```text
task
acceptance criteria
implementation prompt
relevant documentation
base SHA
head SHA
Git diff
changed files
tests
verification evidence
current implementation
```

Results:

```text
approved  → done
rejected  → changes_required
```

Findings must identify:

```text
severity
location
current behavior
expected behavior
reason
required fix
verification requirement
```

Do not reject based on subjective preferences, redesign working solutions, or expand task scope.

Reviewer process, parsing, timeout, or other operational failures must not reject an implementation. Preserve completed implementation evidence and use the existing bounded operational retry/recovery behavior.

---

## Task State Machine

Normal states:

```text
queued
coding
validating
ready_for_review
reviewing
changes_required
done
```

Exceptional states may include:

```text
blocked
interrupted
failed
cancelled
```

State transitions must be centrally validated by AIOS.

Agents must not arbitrarily change task state.

### Serial Execution

MVP execution is strictly serial.

```text
TASK-001 not done
→ TASK-002 cannot start
```

Enforce this through application/database concurrency controls, not prompts alone.

Use transactions and row locking where appropriate.

---

## Git

Managed projects should use Git.

AIOS should control the implementation lifecycle:

```text
clean/recoverable repository preflight
→ capture base SHA
→ Coder edits
→ inspect working tree
→ secret scan
→ validation
→ capture exact diff
→ task-only commit
→ ready_for_review
```

Track relevant:

```text
base_sha
head_sha
commit_sha
```

The Reviewer must review the exact task diff.

Never silently include unrelated dirty changes.

Never stash, reset, clean, discard, auto-commit pre-existing work, or perform other destructive Git operations unless explicitly required and safe.

Interrupted task-owned work may continue only through explicit recovery evidence tied to the same persisted base and task. Never skip or replace the task because an execution process crashed.

---

## Deterministic Validation

Do not trust agent or harness self-reported success.

Run applicable deterministic checks:

```text
secret scan
Git status/diff checks
forbidden-file detection
tests
static analysis
lint
type checks
build
task-specific verification
```

If validation fails, do not move to `ready_for_review`.

Start a fresh Coder fix attempt containing the validation failure context.

---

## Obsidian

Obsidian is persistent external memory, not the workflow database.

Use it selectively to reduce token usage.

Retrieval order:

```text
Current Task
→ STATE.md
→ Relevant Specification / Architecture
→ Relevant ADR / Decision
→ Relevant Implementation Notes
→ Additional linked notes only when required
```

Rules:

- read `INDEX.md` first when useful for navigation;
- read `STATE.md` for current state;
- load only task-relevant notes;
- follow links intentionally;
- never recursively load the entire vault;
- summarize instead of duplicating information;
- update `STATE.md` after meaningful state changes;
- record durable decisions in ADR/decision notes;
- do not store chain-of-thought or temporary reasoning.

> **Store broadly. Retrieve selectively. Summarize aggressively.**

Do not introduce a vector database without demonstrated need. Prefer PostgreSQL search, repository search, Git history, Obsidian links, and explicit relationships first.

---

## Security

Treat supported execution harnesses, including Codex and Claude Code, as privileged local automation processes operating only within AIOS-controlled boundaries.

Always protect:

```text
workspace boundaries
repository boundaries
credentials
environment variables
process arguments
Git changes
destructive actions
execution logs
```

Never expose or commit:

```text
.env contents
API secrets
access tokens
private keys
Codex credentials
Claude credentials
GitHub credentials
SSH keys
cloud credentials
```

Agent or Skill configuration must never store provider credentials. Credentials remain provider-managed local configuration outside AIOS durable application data.

All managed project paths must resolve inside:

```text
~/workspace
```

Prevent path traversal, absolute-path injection, and symlink escapes.

---

## Auditing and Recovery

Significant actions must be auditable, including:

```text
task transitions
agent runs
Agent/harness selection
run configuration snapshots
commands
Git changes
validation
reviews
errors
pause/resume
recovery
```

Workers must support heartbeat/crash detection and AIOS-controlled leases.

Interrupted work must resume the **same task** after inspecting persisted worker/run state, Git state, diffs, execution results, configuration snapshots, and logs. Recovery of the same interrupted attempt must not silently substitute a different Agent, harness, or configuration.

Never skip a task because an Agent or harness process crashed.

---

## Implementation Rules

Prefer:

```text
framework-native solutions
explicit state machines
database transactions
row locking
structured agent output
schema validation
immutable attempt history
immutable run configuration snapshots
append-only auditing
idempotent operations
focused services/actions
clear failure handling
Git-backed evidence
```

Avoid:

```text
persistent shared LLM conversations
agents directly mutating arbitrary state
implicit transitions
parallel MVP implementation
global Agents
executable Skills/plugins
hidden state
unbounded prompts
full repository/vault dumps
blind retries
unnecessary infrastructure
premature abstractions
unrelated refactors
```

Do not add Redis, Horizon, Reverb, Docker, vector databases, multi-agent frameworks, microservices, or other infrastructure without an actual requirement.

---

## Final Rule

AI agents and supported harnesses may reason, inspect, implement, and review.

**AIOS exclusively controls:**

```text
state
permissions
task ordering
Git lifecycle
deterministic validation
persistence
recovery
auditing
context assembly
worker leases
knowledge storage
run configuration snapshots
durable truth
```

Keep execution contexts disposable, system state durable, prompts targeted, execution deterministic, and implementations minimal.
