# AGENTS.md — AGEAX AIOS 2.0

## Project

AGEAX AIOS 2.0 is the active project. AIOS v1 development is paused.

AIOS 2.0 is a local, deterministic software-development orchestration system managing projects under:

```text
~/workspace
```

It coordinates three Codex-powered roles:

```text
Project Manager
Coder
Reviewer
```

Core principle:

> **LLM execution is disposable. System state is durable.**

Persistent truth belongs in PostgreSQL, Git, repository documentation, Obsidian, and audit logs. Never depend on Codex conversation history for project state.

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

## Codex Execution

Every roadmap analysis, implementation attempt, fix attempt, and review must use a **fresh Codex context**.

Do not maintain persistent Codex conversations between tasks.

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

* analyzes uploaded roadmaps;
* creates ordered phases and implementation tasks;
* identifies dependencies;
* defines acceptance criteria;
* generates implementation-ready prompts;
* generates concise context capsules;
* produces structured Obsidian knowledge.

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

* inspect before editing;
* fix root causes;
* preserve architecture and project rules;
* make minimal changes;
* preserve authorization, tenant isolation, transactions, validation, idempotency, auditability, and data integrity;
* add focused tests;
* run verification;
* check for secrets and forbidden files;
* return structured implementation results.

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
Coder edits
→ inspect working tree
→ secret scan
→ validation
→ capture diff
→ commit
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

Never perform destructive Git operations unless explicitly required and safe.

---

## Deterministic Validation

Do not trust agent self-reported success.

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

* read `INDEX.md` first when useful for navigation;
* read `STATE.md` for current state;
* load only task-relevant notes;
* follow links intentionally;
* never recursively load the entire vault;
* summarize instead of duplicating information;
* update `STATE.md` after meaningful state changes;
* record durable decisions in ADR/decision notes;
* do not store chain-of-thought or temporary reasoning.

> **Store broadly. Retrieve selectively. Summarize aggressively.**

Do not introduce a vector database without demonstrated need. Prefer PostgreSQL search, repository search, Git history, Obsidian links, and explicit relationships first.

---

## Security

Treat Codex as a privileged local automation process.

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
GitHub credentials
SSH keys
cloud credentials
```

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
commands
Git changes
validation
reviews
errors
pause/resume
recovery
```

Workers must support heartbeat/crash detection.

Interrupted work must resume the **same task** after inspecting existing Git state, diffs, previous execution results, and logs.

Never skip a task because an agent crashed.

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

AI agents reason, inspect, implement, and review.

**AIOS controls:**

```text
state
permissions
task ordering
validation
Git lifecycle
persistence
recovery
auditing
context assembly
knowledge storage
```

Keep contexts disposable, state durable, prompts targeted, execution deterministic, and implementations minimal.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:

- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
    - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>
