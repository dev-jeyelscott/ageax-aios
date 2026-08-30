# AGEAX AIOS 2.0 — Claude Code Governance

`AGENTS.md` contains the authoritative project governance and must be read before Claude Code performs AIOS work. This file supplements, but does not override, that contract.

- Claude Code is a supported AIOS execution harness, not the workflow orchestrator.
- Core workflow roles remain **Project Manager**, **Coder**, and **Reviewer**.
- Project Agent configuration is project-scoped and separate from `AgentWorker` runtime, lease, heartbeat, and orchestration state.
- Every roadmap analysis, Project Manager `ticket_triage` attempt, implementation attempt, fix/retry attempt, and review must use a fresh Claude Code execution context when Claude Code is the selected harness. Do not rely on persistent Claude conversations for durable project state.
- AIOS-managed Agent Skills are project-scoped, declarative, deterministic context only. They are non-executable and cannot introduce shell hooks, arbitrary code execution, package installation, or workflow control. They are separate from repository/harness tooling such as `.agents/skills/**` and `.claude/skills/**`, which AIOS must not automatically mutate.
- AIOS must persist an immutable configuration snapshot for each new execution attempt, including the selected Agent, harness, model/reasoning settings, bounded execution settings, default context, effective Skills and versions/order/content, and context schema version where applicable. Snapshots must exclude credentials, `.env` contents, and raw host environment values. Historical runs must not be reconstructed from mutable current configuration.
- AIOS exclusively controls state transitions, permissions, task ordering, Git lifecycle, deterministic validation, persistence, recovery, auditing, worker leases, and context assembly. Claude Code may reason, inspect, implement, review, and return structured Ticket triage proposals only within the context AIOS provides.
- Preserve the existing clean/recoverable Git preflight, task-only commit discipline, phase review barriers, reviewer independence, operational-failure retry behavior, same-task recovery guarantees, immutable run snapshots, and serial Coder/Reviewer execution.

## Phase 3 Ticket Governance

- **Ticket != Task.** Tickets are intake/conversation/triage records; Tasks are executable implementation work.
- The existing Project Manager performs `ticket_triage`. Phase 3 adds no Ticket Reviewer role or additional project worker lane.
- Project Manager roadmap analysis and Ticket triage share the existing PM worker/lease boundary.
- Ticket triage uses the currently bound Project Manager Agent/harness and a fresh execution context for every new triage/re-triage attempt.
- Claude Code must return only the structured Ticket triage result requested by AIOS. It must not directly claim Tickets, transition Ticket state, persist replies, create Tasks, assign phase positions/dependencies, reorder roadmap work, or resolve operator escalation.
- AIOS alone owns Ticket claiming/state, escalation, Ticket-to-Task conversion, phase placement, task ordering, persistence, recovery, and auditing.
- Automatic (unreviewed) conversion is limited to exactly one clear, safe, bounded implementation-required Task with confidence `>= 0.80` and no mandatory escalation condition.
- Automatic conversion must not bypass dependencies, phase review barriers, serial ordering, Coder Git preflight, deterministic validation, or Reviewer review.
- Mandatory operator escalation includes low confidence, unclear/contradictory requirements, architectural decisions, breaking/destructive/material migration risk, security/privacy/auth judgment, approved-documentation conflict, unclear business priority, high/multi-Task scope, roadmap reordering/interruption, and unsafe/non-deterministic placement.
- Confidence does not override deterministic escalation. Only an explicit dedicated operator escalation-decision action may resolve a mandatory escalation condition; PM re-triage output alone can never resolve one.
- **Operator-approved multi-Task conversion.** High/multi-Task scope stays a mandatory escalation for automatic conversion, but an operator may resolve it: when the Project Manager proposes an explicit bounded ordered set of Tasks (`proposed_tasks`, not one free-form multi-step Task) for a Ticket triage attempt, and an operator has explicitly reviewed and approved that exact attempt/proposal via the existing Ticket escalation-decision action, AIOS may create the full approved set of Tasks from that one Ticket. This resolves only the multi-Task-scope reason the operator reviewed; any other reason freshly surfaced at conversion time (state drift, new dependency risk, roadmap/critical preemption, etc.) still forces re-escalation for fresh operator review, exactly as for single-Task operator-approved conversion.
- Operator-approved multi-Task conversion remains AIOS-owned, atomic, and idempotent: all proposed Tasks in the approved set are created together with their declared in-set and cross-Ticket dependencies and one shared deterministic phase placement, or none are created; retries and crashes must never create a partial or duplicate set.
- Operator-approved multi-Task conversion is a Ticket-to-Task authoring capability only. It does not enable, imply, or require concurrent/parallel Coder execution — every Task created this way still uses the existing Task state machine, dependency ordering, phase review barrier, and serial Coder/Reviewer execution. Actual concurrent Coder execution remains governed exclusively by the separate Phase 4+ parallel-execution boundary below and stays disabled until that capability is separately implemented and approved.
- Critical/emergency roadmap interruption or reordering always requires explicit operator approval.
- Current-phase Ticket work may be appended only before phase review begins and only when placement/dependencies are deterministic. Once review begins, new work cannot alter that phase's required composition.
- AI-authored public Ticket replies must be visibly disclosed as `AI-generated response` and durably attributable to the AgentRun where applicable.
- `needs_information` and `self_service` requester-dependent Tickets use the approved 72-hour inactivity policy.
- No requester response within 72 hours causes AIOS-controlled inactivity closure with audit/system evidence.
- An eligible late requester response to an inactivity-closed Ticket reopens it and triggers a fresh PM triage attempt. Explicit rejection/duplicate/operator-close semantics remain governed separately by AIOS.

## Phase 3 Context Budget Governance

- Context budgeting is owned by AIOS, not Claude Code.
- Default Phase 3 utilization policy is `70%` target, `75%` warning, `80%` hard ceiling.
- AIOS resolves context capacity from validated harness/model capability and approved policy before launching Claude Code.
- Required workflow/security contract, current Task/Ticket objective, acceptance criteria/triage contract, and required current failure/review/validation evidence are non-overridable.
- Project target configuration cannot override the system hard ceiling.
- Context reduction is deterministic, reproducible, hashable, and auditable and may reduce only approved lower-priority context.
- Do not initiate an extra Claude Code summarization execution merely to make another execution fit its Context Budget.
- If required context still cannot fit below the hard ceiling, AIOS blocks provider execution rather than silently removing required evidence.
- Claude Code must not implement a competing authoritative prompt truncation or budget policy.
- AIOS persists immutable Context Budget evidence including policy/capacity source, original/final estimates, utilization, and reduced/excluded source evidence where applicable.

## Phase 3 Harness Scorecard Governance

- Harness scorecards are AIOS-derived from durable Task/attempt/review/AgentRun/audit/token/timing evidence, not Claude Code self-reported quality.
- Optimization priority is **quality > reliability > token efficiency > speed**.
- Initial Coder composite weighting is `55%` quality, `25%` reliability, `15%` token efficiency, and `5%` speed.
- First-pass Reviewer approval is the strongest individual Coder quality signal.
- Phase 3 cost means token/run consumption, not monetary provider pricing history.
- Comparisons must use fair comparable cohorts across workflow role, work type, complexity, project/repository, harness, model, and reasoning configuration where sufficient evidence exists.
- Confidence thresholds are:
  - `0-4` comparable completed Tasks → `insufficient_data`;
  - `5-19` → `preliminary`;
  - `20+` → `recommendation_eligible`.
- Reviewer diagnostics must not use raw approval or raw rejection rate as a standalone quality score.
- Scorecard methodology must be versioned and reproducible.
- Phase 3 scorecards are recommendation-only. They must not automatically switch Claude Code/Codex, change models/reasoning, mutate Agent bindings, route Tasks, or reorder workflow execution.
- Do not introduce global Agents, parallel task execution, agent self-scheduling, persistent shared LLM conversations, executable Skills, a Ticket Reviewer role, parallel PM triage workers, automatic harness/model routing, or LLM summarization solely to bypass Context Budget limits.

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
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where(\"active\", true)->count();'`

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

## Phase 4+ Claude Code capability boundaries

Phase 4+ governance does not activate any new Claude Code authority. `AGENTS.md` and `MASTER-PROMPT.md` remain authoritative, and the current Project Manager/Coder/Reviewer worker model stays serial until a separately approved implementation task changes it.

Claude Code may reason, inspect, implement, review, diagnose within an approved recovery worktree, or return an approved structured recommendation/proposal. It must never treat a future capability prompt as permission to own authorization, Agent/worker eligibility, Ticket/Task claiming, dependencies, ordering, durable transitions, phase barriers, deterministic validation, persistence, Git integration, recovery state, auditing, context budgeting, knowledge authority, or routing policy.

- A future **Global Orchestrator** remains advisory. Claude Code may return structured recommendation evidence only; it cannot directly mutate Agent configuration/bindings, workers, Tasks, workflow definitions, Git, permissions, or durable state.
- A future **Knowledge Architect** remains proposal-only. Claude Code may create or enrich bounded knowledge-improvement proposals, but cannot directly mutate Skills, `.ai/rules/**`, documentation, tests, Obsidian, Agent configuration, Git, or workflow state.
- **Voice** is input/output only. A confirmed transcript must use the same authenticated and authorized Laravel Action as equivalent text. Voice must never become a shell, Agent-selection, permission, or transition path.
- **Runtime self-healing** must use the existing `RecoveryIncident` and Workflow Recovery Engineer lifecycle. Claude Code recovery execution remains limited to bounded diagnosis or isolated-worktree changes that AIOS independently validates.
- **Agent collaboration** uses typed, bounded, project-scoped AIOS-mediated handoffs. Claude Code cannot open persistent shared Agent conversations or treat a handoff as authority to transition state.
- **Parallel execution** remains disabled until separately implemented. Any later concurrent Coder execution must use isolated AIOS-owned Git workspaces, preserve dependencies/phase eligibility/leases/Context Budget/validation, and keep integration serialized under AIOS control.
- **Custom workflows** are versioned declarative graphs of approved AIOS step types only. Claude Code must not introduce executable PHP, JavaScript, shell, dynamic class references, arbitrary hooks/plugins, or use workflow definitions to grant permissions or choose durable transitions.
- **Automatic routing** remains disabled until an explicit operator-owned policy is implemented and enabled after `advisory -> shadow -> automatic`. Claude Code must not select its own Agent, harness, model, reasoning setting, or permissions, and no execution may switch configuration mid-attempt.

Any conflict between Claude Code output and these boundaries must fail in favor of AIOS-owned governance.
