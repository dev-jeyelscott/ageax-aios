import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    CircleDot,
    Cpu,
    GitBranch,
    GitCommitHorizontal,
    ShieldCheck,
    Workflow,
} from 'lucide-react';
import { Fragment, useMemo } from 'react';
import {
    showAgentRun,
    showTask,
} from '@/actions/App/Http/Controllers/ProjectController';
import { Badge } from '@/components/ui/badge';
import './agent-office.css';

export type OfficeWorker = {
    id: number;
    role: string;
    status: string;
    last_heartbeat_at: string | null;
    lease_state: 'active' | 'expired' | 'none';
    activity_mode: 'current' | 'recent' | null;
    run: {
        id: number;
        status: string;
        attempt_number: number | null;
        started_at: string | null;
        finished_at: string | null;
        failure_reason: string | null;
        latest_message: string | null;
    } | null;
    task: {
        id: number;
        key: string;
        title: string;
        status: string;
    } | null;
};

export type OfficeAgent = {
    id: number;
    name: string;
    role: string;
    harness: string;
    model: string | null;
    reasoning_setting: string | null;
    enabled: boolean;
    configuration_version: number;
};

export type OfficeWorkerBinding = {
    id: number;
    role: string;
    agent_id: number | null;
    status: string;
    last_heartbeat_at: string | null;
};

export type OfficeTask = {
    id: number;
    key: string;
    title: string;
    status: string;
};

export type OfficeWorkflow = {
    mode: 'current' | 'recent';
    worker_id: number;
    role: string;
    run_id: number;
    task: OfficeTask | null;
};

type GitEvidence = {
    task: {
        id: number;
        key: string;
        title: string;
    };
    attempt_number: number;
    status: string;
    base_sha: string | null;
    head_sha: string | null;
    commit_sha: string | null;
    changed_files: string[] | null;
    validation_results: {
        passed?: boolean;
        checks?: Record<string, boolean>;
    } | null;
};

type HarnessUsage = {
    run_count: number;
    token_usage: number;
};

type TokenObservability = {
    rolling_average: number | null;
    baseline_average: number | null;
    change_percentage: number | null;
    run_count: number;
    warning_threshold: number;
};

type OverviewProject = {
    id: number;
    status: string;
    git_status: string;
    git_head_sha: string | null;
    tasks: OfficeTask[];
    git_evidence: GitEvidence | null;
    token_usage_total: number;
    token_observability: Record<string, TokenObservability>;
    harness_usage: Record<string, HarnessUsage>;
    recent_agent_runs: {
        id: number;
        role: string;
        status: string;
        exit_code: number | null;
    }[];
};

type ConnectorState = 'active' | 'complete' | 'idle' | 'paused';

type WorkerPresentation = {
    label: string;
    dotClass: string;
    badgeClass: string;
};

type ValidationPresentation = {
    label: string;
    dotClass: string;
    badgeClass: string;
    summary: string;
};

const preferredRoleOrder = ['project_manager', 'coder', 'reviewer'] as const;

const roleLabels: Record<string, string> = {
    project_manager: 'Project Manager',
    coder: 'Coder',
    reviewer: 'Reviewer',
};

const roleThumbnails: Record<string, string> = {
    project_manager: '/action-gif/pm-idle.gif',
    coder: '/action-gif/coder-idle.gif',
    reviewer: '/action-gif/reviewer-idle.gif',
};

const implementationStatuses = new Set([
    'coding',
    'validating',
    'changes_required',
]);

const reviewStatuses = new Set(['ready_for_review', 'reviewing']);

const attentionStatuses = new Set(['blocked', 'interrupted', 'failed']);

function labelForRole(role: string): string {
    return (
        roleLabels[role] ??
        role
            .replaceAll('_', ' ')
            .replace(/\b\w/g, (letter) => letter.toUpperCase())
    );
}

function labelForHarness(harness: string): string {
    switch (harness) {
        case 'claude_code':
            return 'Claude Code';
        case 'codex':
            return 'Codex';
        default:
            return harness
                .replaceAll('_', ' ')
                .replace(/\b\w/g, (letter) => letter.toUpperCase());
    }
}

function humanize(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replaceAll('.', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

function formatTokens(tokens: number): string {
    return new Intl.NumberFormat().format(tokens);
}

function shortSha(value: string | null): string {
    return value ? value.slice(0, 10) : 'Not recorded';
}

function formatEvidenceTime(value: string | null): string {
    if (!value) {
        return 'No timestamp';
    }

    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return 'No timestamp';
    }

    return parsed.toLocaleString();
}

function formatRunDuration(
    startedAt: string | null,
    finishedAt: string | null,
): string {
    if (!startedAt) {
        return 'Not recorded';
    }

    if (!finishedAt) {
        return 'In progress';
    }

    const started = new Date(startedAt).getTime();
    const finished = new Date(finishedAt).getTime();

    if (!Number.isFinite(started) || !Number.isFinite(finished)) {
        return 'Not recorded';
    }

    const totalSeconds = Math.max(0, Math.round((finished - started) / 1_000));

    if (totalSeconds < 60) {
        return `${totalSeconds}s`;
    }

    const totalMinutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    if (totalMinutes < 60) {
        return `${totalMinutes}m ${seconds.toString().padStart(2, '0')}s`;
    }

    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    return `${hours}h ${minutes.toString().padStart(2, '0')}m`;
}

function selectOfficeWorkers(workers: OfficeWorker[]): OfficeWorker[] {
    const claimedIds = new Set<number>();

    const selected = preferredRoleOrder.flatMap((role) => {
        const worker = workers.find(
            (candidate) =>
                candidate.role === role && !claimedIds.has(candidate.id),
        );

        if (!worker) {
            return [];
        }

        claimedIds.add(worker.id);

        return [worker];
    });

    return selected;
}

function workerMessage(worker: OfficeWorker, active: boolean): string {
    if (worker.run?.latest_message) {
        return worker.run.latest_message;
    }

    if (worker.run?.failure_reason) {
        return worker.run.failure_reason;
    }

    if (worker.status === 'recovering') {
        return 'Recovering execution from durable AIOS evidence.';
    }

    if (active && worker.task) {
        switch (worker.role) {
            case 'coder':
                return `Implementing ${worker.task.key} · ${worker.task.title}.`;
            case 'reviewer':
                return `Reviewing ${worker.task.key} against persisted implementation evidence.`;
            case 'project_manager':
                return 'Processing the current planning or triage operation.';
            default:
                return `Working on ${worker.task.key}.`;
        }
    }

    if (
        worker.activity_mode === 'recent' &&
        worker.run?.status === 'completed' &&
        worker.task
    ) {
        return `Completed ${worker.task.key} · ${worker.task.title}.`;
    }

    switch (worker.role) {
        case 'project_manager':
            return 'Standing by for roadmap analysis or ticket triage.';
        case 'coder':
            return 'Waiting for the next eligible implementation task.';
        case 'reviewer':
            return 'Standing by for the next deterministic review handoff.';
        default:
            return 'Waiting for the next AIOS-controlled operation.';
    }
}

function workerPresentation(
    worker: OfficeWorker,
    active: boolean,
    projectStatus: string,
): WorkerPresentation {
    if (projectStatus !== 'running') {
        return {
            label: 'Paused',
            dotClass: 'bg-warning',
            badgeClass:
                'border-warning/25 bg-warning/8 text-warning-foreground',
        };
    }

    if (
        attentionStatuses.has(worker.status) ||
        worker.run?.status === 'failed' ||
        Boolean(worker.run?.failure_reason) ||
        (worker.task && attentionStatuses.has(worker.task.status))
    ) {
        return {
            label: 'Blocked',
            dotClass: 'bg-destructive',
            badgeClass:
                'border-destructive/25 bg-destructive/8 text-destructive-foreground',
        };
    }

    if (worker.status === 'recovering') {
        return {
            label: 'Recovering',
            dotClass: 'bg-warning',
            badgeClass:
                'border-warning/25 bg-warning/8 text-warning-foreground',
        };
    }

    if (active) {
        if (worker.role === 'coder' && worker.task?.status === 'validating') {
            return {
                label: 'Validating',
                dotClass: 'bg-primary',
                badgeClass: 'border-primary/30 bg-primary/8 text-primary',
            };
        }

        if (worker.role === 'reviewer') {
            return {
                label: 'Reviewing',
                dotClass: 'bg-secondary-foreground',
                badgeClass:
                    'border-secondary-foreground/30 bg-secondary/20 text-secondary-foreground',
            };
        }

        if (worker.role === 'project_manager') {
            return {
                label: 'Planning',
                dotClass: 'bg-primary',
                badgeClass: 'border-primary/30 bg-primary/8 text-primary',
            };
        }

        return {
            label: 'Working',
            dotClass: 'bg-success',
            badgeClass:
                'border-success/25 bg-success/8 text-success-foreground',
        };
    }

    if (
        worker.role === 'reviewer' &&
        worker.task?.status === 'ready_for_review'
    ) {
        return {
            label: 'Waiting',
            dotClass: 'bg-primary',
            badgeClass: 'border-primary/20 bg-primary/5 text-primary',
        };
    }

    return {
        label: 'Available',
        dotClass: 'bg-muted-foreground',
        badgeClass: 'border-border bg-background/55 text-muted-foreground',
    };
}

function fallbackTaskForRole(
    role: string,
    tasks: OfficeTask[],
): OfficeTask | null {
    if (role === 'coder') {
        return (
            tasks.find((task) =>
                ['queued', 'coding', 'validating', 'changes_required'].includes(
                    task.status,
                ),
            ) ?? null
        );
    }

    if (role === 'reviewer') {
        return (
            tasks.find((task) =>
                ['ready_for_review', 'reviewing'].includes(task.status),
            ) ?? null
        );
    }

    return null;
}

function validationPresentation(
    evidence: GitEvidence | null,
    workflow: OfficeWorkflow | null,
): ValidationPresentation {
    if (evidence?.validation_results?.passed === true) {
        const checks = Object.values(evidence.validation_results.checks ?? {});
        const passed = checks.filter(Boolean).length;

        return {
            label: 'Passed',
            dotClass: 'bg-success',
            badgeClass:
                'border-success/25 bg-success/8 text-success-foreground',
            summary:
                checks.length > 0
                    ? `${passed}/${checks.length} recorded checks passed`
                    : 'Deterministic validation passed',
        };
    }

    if (evidence?.validation_results?.passed === false) {
        return {
            label: 'Failed',
            dotClass: 'bg-destructive',
            badgeClass:
                'border-destructive/25 bg-destructive/8 text-destructive-foreground',
            summary: 'Deterministic validation recorded a failure',
        };
    }

    if (
        workflow?.mode === 'current' &&
        workflow.task?.status === 'validating'
    ) {
        return {
            label: 'Running',
            dotClass: 'bg-primary',
            badgeClass: 'border-primary/25 bg-primary/8 text-primary',
            summary: 'AIOS deterministic validation is in progress',
        };
    }

    if (
        workflow?.mode === 'current' &&
        workflow.task &&
        ['coding', 'changes_required'].includes(workflow.task.status)
    ) {
        return {
            label: 'Pending',
            dotClass: 'bg-warning',
            badgeClass:
                'border-warning/25 bg-warning/8 text-warning-foreground',
            summary: 'Validation has not completed for the active attempt',
        };
    }

    return {
        label: 'Not recorded',
        dotClass: 'bg-muted-foreground',
        badgeClass: 'border-border bg-background/55 text-muted-foreground',
        summary: 'No deterministic validation evidence recorded',
    };
}

function nextStageFor(
    workflow: OfficeWorkflow | null,
    projectStatus: string,
): {
    stage: string;
    detail: string;
    trigger: string;
} {
    if (projectStatus !== 'running') {
        return {
            stage: 'Paused',
            detail: 'Execution is not currently advancing.',
            trigger: 'Resume through the existing project control',
        };
    }

    if (workflow?.mode !== 'current') {
        return {
            stage: 'AIOS scheduler',
            detail: 'Waiting for the next eligible durable operation.',
            trigger: 'AIOS task ordering and worker eligibility',
        };
    }

    switch (workflow.role) {
        case 'project_manager':
            return {
                stage: 'Coder',
                detail: 'Implementation',
                trigger: 'After AIOS validates and persists eligible PM output',
            };
        case 'coder':
            return {
                stage: 'Reviewer',
                detail: 'Validation & review',
                trigger:
                    'After implementation validation and the phase review barrier permit review',
            };
        case 'reviewer':
            return {
                stage: 'AIOS decision',
                detail: 'Approve or changes required',
                trigger: 'After AIOS validates the structured review result',
            };
        default:
            return {
                stage: 'AIOS scheduler',
                detail: 'Next eligible workflow stage',
                trigger: 'AIOS-controlled deterministic ordering',
            };
    }
}

function EmptyAgentNode({ role, index }: { role: string; index: number }) {
    return (
        <article
            data-workflow-role={role}
            className="execution-agent-card execution-agent-card--empty"
        >
            <div className="flex items-start gap-2">
                <span className="execution-step-number">
                    {index.toString().padStart(2, '0')}
                </span>
                <div>
                    <p className="execution-role-label">{labelForRole(role)}</p>
                    <p className="mt-1 text-sm font-semibold text-foreground">
                        Worker unavailable
                    </p>
                </div>
            </div>

            <div className="grid flex-1 place-items-center text-center">
                <div>
                    <div className="mx-auto grid size-16 place-items-center rounded-full border border-dashed border-border bg-background/70 font-mono text-xs text-muted-foreground">
                        {labelForRole(role)
                            .split(' ')
                            .map((part) => part[0])
                            .join('')}
                    </div>

                    <p className="mt-3 text-xs text-muted-foreground">
                        No durable worker slot was returned for this role.
                    </p>
                </div>
            </div>
        </article>
    );
}

function AgentNode({
    projectId,
    worker,
    agent,
    active,
    index,
    projectStatus,
    fallbackTask,
}: {
    projectId: number;
    worker: OfficeWorker;
    agent: OfficeAgent | undefined;
    active: boolean;
    index: number;
    projectStatus: string;
    fallbackTask: OfficeTask | null;
}) {
    const presentation = workerPresentation(worker, active, projectStatus);
    const thumbnail = roleThumbnails[worker.role] ?? null;
    const message = workerMessage(worker, active);
    const displayedTask = worker.task ?? fallbackTask;
    const timestamp =
        worker.run?.finished_at ??
        worker.run?.started_at ??
        worker.last_heartbeat_at;

    return (
        <article
            data-workflow-role={worker.role}
            data-active={active ? 'true' : 'false'}
            className={`execution-agent-card ${
                active ? 'execution-agent-card--active agent-card-active' : ''
            }`}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 items-start gap-2.5">
                    <span className="execution-step-number">
                        {index.toString().padStart(2, '0')}
                    </span>

                    <div className="min-w-0">
                        <p className="execution-role-label">
                            {labelForRole(worker.role)}
                        </p>
                        <h3 className="mt-0.5 truncate text-sm font-semibold text-foreground">
                            {agent?.name ?? 'Unbound agent'}
                        </h3>
                    </div>
                </div>

                <Badge
                    variant="outline"
                    className={`shrink-0 gap-1.5 rounded-full px-2.5 py-1 font-mono text-2xs ${presentation.badgeClass}`}
                >
                    <span
                        className={`size-1.5 rounded-full ${presentation.dotClass} ${
                            active ? 'status-glow-pulse' : ''
                        }`}
                    />
                    {presentation.label}
                </Badge>
            </div>

            <div className="execution-agent-conversation">
                <div
                    className={`execution-avatar ${
                        active ? 'execution-avatar--active' : ''
                    }`}
                >
                    {thumbnail ? (
                        <img
                            src={thumbnail}
                            alt={`${labelForRole(worker.role)} avatar thumbnail`}
                            className="h-full w-full object-cover object-top"
                            decoding="async"
                        />
                    ) : (
                        <span className="font-mono text-sm text-primary">
                            {labelForRole(worker.role)
                                .split(' ')
                                .map((part) => part[0])
                                .join('')}
                        </span>
                    )}
                </div>

                <div
                    aria-live={active ? 'polite' : 'off'}
                    className={`execution-chat-bubble ${
                        active ? 'execution-chat-bubble--active' : ''
                    }`}
                >
                    <span aria-hidden="true" className="execution-chat-tail" />

                    <p className="relative line-clamp-3 text-xs leading-relaxed text-foreground">
                        {message}
                    </p>

                    <time
                        dateTime={timestamp ?? undefined}
                        className="relative mt-1.5 block font-mono text-[10px] text-muted-foreground"
                    >
                        {formatEvidenceTime(timestamp)}
                    </time>
                </div>
            </div>

            <div className="mt-3 grid gap-2">
                <div className="execution-agent-meta-row">
                    <div className="flex min-w-0 items-center gap-2">
                        <Cpu className="size-3.5 shrink-0 text-primary" />

                        <div className="min-w-0">
                            <p className="execution-meta-label">
                                Harness / Model
                            </p>
                            <p className="mt-0.5 truncate text-xs font-medium text-foreground">
                                {agent
                                    ? `${labelForHarness(agent.harness)} · ${
                                          agent.model ?? 'default model'
                                      }`
                                    : 'Not recorded'}
                            </p>
                        </div>
                    </div>
                </div>

                <div className="execution-agent-meta-row">
                    <div className="min-w-0 flex-1">
                        <div className="flex items-center justify-between gap-2">
                            <p className="execution-meta-label">
                                {worker.task
                                    ? worker.activity_mode === 'recent'
                                        ? 'Recent Task'
                                        : 'Current Task'
                                    : worker.role === 'reviewer'
                                      ? 'Next Task'
                                      : worker.role === 'coder'
                                        ? 'Next Eligible Task'
                                        : 'Workflow Scope'}
                            </p>

                            {displayedTask && (
                                <Badge
                                    variant="outline"
                                    className="border-border bg-background/50 px-1.5 py-0 font-mono text-[9px] text-muted-foreground"
                                >
                                    {humanize(displayedTask.status)}
                                </Badge>
                            )}
                        </div>

                        {displayedTask ? (
                            <Link
                                href={
                                    showTask({
                                        project: projectId,
                                        task: displayedTask.id,
                                    }).url
                                }
                                className="mt-1 block truncate text-xs font-medium text-foreground transition hover:text-primary focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                            >
                                <span className="font-mono text-primary">
                                    {displayedTask.key}
                                </span>{' '}
                                · {displayedTask.title}
                            </Link>
                        ) : (
                            <p className="mt-1 text-xs text-muted-foreground">
                                {worker.role === 'project_manager'
                                    ? 'Roadmap analysis and ticket triage'
                                    : 'No eligible task recorded'}
                            </p>
                        )}
                    </div>
                </div>
            </div>

            <div className="mt-auto flex items-center justify-between gap-2 border-t border-border-subtle pt-2.5">
                <span className="font-mono text-2xs text-muted-foreground">
                    Run ID
                </span>

                {worker.run ? (
                    <Link
                        href={
                            showAgentRun({
                                project: projectId,
                                run: worker.run.id,
                            }).url
                        }
                        className="font-mono text-xs text-primary transition hover:text-primary/80 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                    >
                        #{worker.run.id}
                    </Link>
                ) : (
                    <span className="font-mono text-2xs text-muted-foreground">
                        Not recorded
                    </span>
                )}
            </div>
        </article>
    );
}

function WorkflowConnector({
    state,
    label,
}: {
    state: ConnectorState;
    label: string;
}) {
    return (
        <div
            role="img"
            aria-label={`${label}: ${state}`}
            data-connector-state={state}
            className={`workflow-connector workflow-connector--${state}`}
        >
            <div className="workflow-connector__rail">
                <span className="workflow-connector__base" />
                <span className="workflow-connector__energy" />
                <span className="workflow-connector__particle" />
                <span className="workflow-connector__arrow" />
            </div>
        </div>
    );
}

function RoadmapProgressCard({
    projectId,
    tasks,
    completed,
    total,
}: {
    projectId: number;
    tasks: OfficeTask[];
    completed: number;
    total: number;
}) {
    const progress = total === 0 ? 0 : Math.round((completed / total) * 100);

    const nextTask =
        tasks.find((task) => !['done', 'cancelled'].includes(task.status)) ??
        null;

    return (
        <section className="execution-ops-card">
            <div className="execution-ops-heading">
                <Workflow className="size-3.5 text-primary" />
                <span>Roadmap Progress</span>
            </div>

            <div className="mt-3">
                <p className="text-3xl font-semibold tracking-tight text-primary">
                    {progress}%
                </p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {completed} of {total} tasks complete
                </p>
            </div>

            <div className="mt-2.5 h-1.5 overflow-hidden rounded-full bg-muted">
                <div
                    className="progress-flow h-full rounded-full"
                    style={{ width: `${progress}%` }}
                />
            </div>

            <div className="mt-3 min-w-0">
                <p className="execution-meta-label">Next unfinished task</p>

                {nextTask ? (
                    <>
                        <p className="mt-1 truncate text-xs font-medium text-foreground">
                            <span className="font-mono text-primary">
                                {nextTask.key}
                            </span>{' '}
                            · {nextTask.title}
                        </p>

                        <Link
                            href={
                                showTask({
                                    project: projectId,
                                    task: nextTask.id,
                                }).url
                            }
                            className="execution-card-action"
                        >
                            View task details
                        </Link>
                    </>
                ) : (
                    <p className="mt-1 text-xs text-muted-foreground">
                        {total === 0
                            ? 'No roadmap tasks recorded.'
                            : 'Roadmap execution complete.'}
                    </p>
                )}
            </div>
        </section>
    );
}

function CurrentOperationCard({
    projectId,
    workflow,
    workers,
}: {
    projectId: number;
    workflow: OfficeWorkflow | null;
    workers: OfficeWorker[];
}) {
    const worker = workflow
        ? workers.find((candidate) => candidate.id === workflow.worker_id)
        : undefined;

    return (
        <section className="execution-ops-card">
            <div className="execution-ops-heading">
                <Activity className="size-3.5 text-primary" />
                <span>Current Operation</span>
            </div>

            <div className="mt-3">
                <p className="execution-meta-label">Active Stage</p>
                <p className="mt-1 text-sm font-semibold text-foreground">
                    {workflow?.mode === 'current'
                        ? labelForRole(workflow.role)
                        : 'No active stage'}
                </p>
            </div>

            <div className="mt-3 min-w-0">
                <p className="execution-meta-label">Current Task</p>

                {workflow?.task ? (
                    <p className="mt-1 line-clamp-2 text-xs font-medium text-foreground">
                        <span className="font-mono text-primary">
                            {workflow.task.key}
                        </span>{' '}
                        · {workflow.task.title}
                    </p>
                ) : (
                    <p className="mt-1 text-xs text-muted-foreground">
                        Not recorded
                    </p>
                )}
            </div>

            <div className="mt-3 grid grid-cols-2 gap-2 border-t border-border-subtle pt-2.5">
                <div>
                    <p className="execution-meta-label">Started</p>
                    <p className="mt-1 truncate text-2xs text-muted-foreground">
                        {formatEvidenceTime(worker?.run?.started_at ?? null)}
                    </p>
                </div>

                <div>
                    <p className="execution-meta-label">Duration</p>
                    <p className="mt-1 text-2xs text-muted-foreground">
                        {formatRunDuration(
                            worker?.run?.started_at ?? null,
                            worker?.run?.finished_at ?? null,
                        )}
                    </p>
                </div>
            </div>

            {workflow?.task && (
                <Link
                    href={
                        showTask({
                            project: projectId,
                            task: workflow.task.id,
                        }).url
                    }
                    className="execution-card-action"
                >
                    View task details
                </Link>
            )}
        </section>
    );
}

function NextStageCard({
    workflow,
    projectStatus,
}: {
    workflow: OfficeWorkflow | null;
    projectStatus: string;
}) {
    const nextStage = nextStageFor(workflow, projectStatus);

    return (
        <section className="execution-ops-card">
            <div className="execution-ops-heading">
                <CircleDot className="size-3.5 text-primary" />
                <span>Next Stage</span>
            </div>

            <div className="mt-3">
                <p className="text-sm font-semibold text-foreground">
                    {nextStage.stage}
                </p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {nextStage.detail}
                </p>
            </div>

            <div className="mt-4 border-t border-border-subtle pt-2.5">
                <p className="execution-meta-label">Trigger</p>
                <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                    {nextStage.trigger}
                </p>
            </div>
        </section>
    );
}

function RepositoryEvidenceCard({
    projectId,
    evidence,
    gitStatus,
    repositoryHeadSha,
}: {
    projectId: number;
    evidence: GitEvidence | null;
    gitStatus: string;
    repositoryHeadSha: string | null;
}) {
    const changedFiles =
        evidence?.changed_files === null ||
        evidence?.changed_files === undefined
            ? 'Not recorded'
            : evidence.changed_files.length.toString();

    return (
        <section className="execution-ops-card execution-ops-card--repository">
            <div className="flex items-start justify-between gap-3">
                <div className="execution-ops-heading">
                    <GitBranch className="size-3.5 text-primary" />
                    <span>Repository · Git Evidence</span>
                </div>

                <div className="text-right">
                    <p className="execution-meta-label">Branch</p>
                    <p className="mt-0.5 font-mono text-2xs text-muted-foreground">
                        Not recorded
                    </p>
                </div>
            </div>

            <dl className="mt-3 grid grid-cols-3 gap-2">
                {[
                    ['Base', evidence?.base_sha ?? null],
                    ['Head', evidence?.head_sha ?? repositoryHeadSha ?? null],
                    ['Commit', evidence?.commit_sha ?? null],
                ].map(([label, value]) => (
                    <div
                        key={label}
                        className="rounded-lg border border-border-subtle bg-background/35 px-2 py-2"
                    >
                        <dt className="execution-meta-label">{label}</dt>
                        <dd
                            title={value ?? undefined}
                            className="mt-1 truncate font-mono text-2xs text-primary"
                        >
                            {shortSha(value)}
                        </dd>
                    </div>
                ))}
            </dl>

            <div className="mt-3 grid grid-cols-3 gap-2 border-t border-border-subtle pt-2.5">
                <div>
                    <p className="execution-meta-label">Changed Files</p>
                    <p className="mt-1 text-xs font-medium text-foreground">
                        {changedFiles}
                    </p>
                </div>

                <div>
                    <p className="execution-meta-label">Working Tree</p>
                    <p
                        className={`mt-1 text-xs font-medium ${
                            gitStatus === 'clean'
                                ? 'text-success-foreground'
                                : 'text-warning-foreground'
                        }`}
                    >
                        {humanize(gitStatus)}
                    </p>
                </div>

                <div>
                    <p className="execution-meta-label">Attempt</p>
                    <p className="mt-1 text-xs font-medium text-foreground">
                        {evidence
                            ? `#${evidence.attempt_number}`
                            : 'Not recorded'}
                    </p>
                </div>
            </div>

            {evidence && (
                <Link
                    href={
                        showTask({
                            project: projectId,
                            task: evidence.task.id,
                        }).url
                    }
                    className="execution-card-action"
                >
                    <GitCommitHorizontal className="size-3.5" />
                    View diff & evidence
                </Link>
            )}
        </section>
    );
}

function ValidationStateCard({
    projectId,
    evidence,
    workflow,
}: {
    projectId: number;
    evidence: GitEvidence | null;
    workflow: OfficeWorkflow | null;
}) {
    const presentation = validationPresentation(evidence, workflow);
    const task = evidence?.task ?? workflow?.task ?? null;

    return (
        <section className="execution-ops-card">
            <div className="execution-ops-heading">
                <ShieldCheck className="size-3.5 text-primary" />
                <span>Validation State</span>
            </div>

            <div className="mt-4 flex items-center justify-between gap-3">
                <span className="text-xs text-muted-foreground">Status</span>

                <Badge
                    variant="outline"
                    className={`gap-1.5 font-mono text-2xs ${presentation.badgeClass}`}
                >
                    <span
                        className={`size-1.5 rounded-full ${presentation.dotClass}`}
                    />
                    {presentation.label}
                </Badge>
            </div>

            <div className="mt-3 border-t border-border-subtle pt-2.5">
                <p className="execution-meta-label">Evidence</p>
                <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                    {presentation.summary}
                </p>
            </div>

            <div className="mt-3 flex items-center justify-between border-t border-border-subtle pt-2.5">
                <span className="execution-meta-label">Checks</span>
                <span className="font-mono text-2xs text-muted-foreground">
                    {evidence?.validation_results?.checks
                        ? Object.keys(evidence.validation_results.checks).length
                        : '—'}
                </span>
            </div>

            {task && (
                <Link
                    href={
                        showTask({
                            project: projectId,
                            task: task.id,
                        }).url
                    }
                    className="execution-card-action"
                >
                    View validation
                </Link>
            )}
        </section>
    );
}

function TokenUsageCard({
    total,
    harnessUsage,
    observability,
}: {
    total: number;
    harnessUsage: Record<string, HarnessUsage>;
    observability: Record<string, TokenObservability>;
}) {
    const harnesses = ['claude_code', 'codex']
        .map((harness) => ({
            key: harness,
            usage: harnessUsage[harness] ?? {
                run_count: 0,
                token_usage: 0,
            },
        }))
        .filter(
            ({ usage }) =>
                usage.run_count > 0 ||
                usage.token_usage > 0 ||
                Object.keys(harnessUsage).length === 0,
        );

    const barTotal = Math.max(
        1,
        harnesses.reduce((sum, entry) => sum + entry.usage.token_usage, 0),
    );

    return (
        <section className="execution-ops-card execution-ops-card--tokens">
            <div className="execution-ops-heading">
                <Activity className="size-3.5 text-primary" />
                <span>Execution / Token Usage</span>
            </div>

            <div className="mt-3 grid gap-4 xl:grid-cols-[minmax(11rem,0.75fr)_minmax(0,1.25fr)_minmax(11rem,0.8fr)]">
                <div>
                    <p className="execution-meta-label">Total Observed</p>
                    <p className="mt-1 text-2xl font-semibold text-foreground">
                        {formatTokens(total)}
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        persisted tokens
                    </p>
                </div>

                <div className="grid gap-2.5">
                    {harnesses.length > 0 ? (
                        harnesses.map(({ key, usage }) => {
                            const percentage = Math.round(
                                (usage.token_usage / barTotal) * 100,
                            );

                            return (
                                <div key={key}>
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-xs font-medium text-foreground">
                                            {labelForHarness(key)}
                                        </span>

                                        <span className="font-mono text-2xs text-primary">
                                            {formatTokens(usage.token_usage)}{' '}
                                            tokens
                                        </span>
                                    </div>

                                    <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted">
                                        <div
                                            className="execution-token-bar h-full rounded-full"
                                            style={{
                                                width: `${percentage}%`,
                                            }}
                                        />
                                    </div>
                                </div>
                            );
                        })
                    ) : (
                        <p className="text-xs text-muted-foreground">
                            No harness usage recorded.
                        </p>
                    )}
                </div>

                <div className="border-t border-border-subtle pt-3 xl:border-t-0 xl:border-l xl:pt-0 xl:pl-4">
                    <p className="execution-meta-label">
                        Rolling Role Averages
                    </p>

                    <div className="mt-2 grid gap-1.5">
                        {preferredRoleOrder.map((role) => {
                            const observation = observability[role];

                            return (
                                <div
                                    key={role}
                                    className="flex items-center justify-between gap-2"
                                >
                                    <span className="truncate text-2xs text-muted-foreground">
                                        {labelForRole(role)}
                                    </span>

                                    <span className="shrink-0 font-mono text-2xs text-foreground">
                                        {observation?.rolling_average === null
                                            ? 'No runs'
                                            : observation?.rolling_average !==
                                                undefined
                                              ? `${formatTokens(
                                                    observation.rolling_average,
                                                )} avg`
                                              : 'Not recorded'}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>
        </section>
    );
}

function HealthCard({
    errors,
    warnings,
}: {
    errors: string[];
    warnings: string[];
}) {
    const healthy = errors.length === 0 && warnings.length === 0;

    return (
        <section className="execution-ops-card">
            <div className="execution-ops-heading">
                <Activity className="size-3.5 text-success-foreground" />
                <span>Health & Warnings</span>
            </div>

            <div className="mt-3 grid grid-cols-[minmax(0,1fr)_auto_auto] gap-3 rounded-xl border border-border-subtle bg-background/30 p-3">
                <div className="flex min-w-0 items-center gap-2">
                    <div
                        className={`grid size-8 shrink-0 place-items-center rounded-full border ${
                            healthy
                                ? 'border-success/25 bg-success/8 text-success-foreground'
                                : errors.length > 0
                                  ? 'border-destructive/25 bg-destructive/8 text-destructive-foreground'
                                  : 'border-warning/25 bg-warning/8 text-warning-foreground'
                        }`}
                    >
                        {healthy ? (
                            <CheckCircle2 className="size-4" />
                        ) : (
                            <AlertTriangle className="size-4" />
                        )}
                    </div>

                    <div className="min-w-0">
                        <p className="execution-meta-label">System Health</p>
                        <p
                            className={`mt-0.5 truncate text-xs font-medium ${
                                healthy
                                    ? 'text-success-foreground'
                                    : errors.length > 0
                                      ? 'text-destructive-foreground'
                                      : 'text-warning-foreground'
                            }`}
                        >
                            {healthy
                                ? 'Operational'
                                : errors.length > 0
                                  ? 'Needs attention'
                                  : 'Warning'}
                        </p>
                    </div>
                </div>

                <div className="border-l border-border-subtle pl-3 text-center">
                    <p className="execution-meta-label">Warnings</p>
                    <p
                        className={`mt-1 text-xl font-semibold ${
                            warnings.length > 0
                                ? 'text-warning-foreground'
                                : 'text-foreground'
                        }`}
                    >
                        {warnings.length}
                    </p>
                </div>

                <div className="border-l border-border-subtle pl-3 text-center">
                    <p className="execution-meta-label">Errors</p>
                    <p
                        className={`mt-1 text-xl font-semibold ${
                            errors.length > 0
                                ? 'text-destructive-foreground'
                                : 'text-foreground'
                        }`}
                    >
                        {errors.length}
                    </p>
                </div>
            </div>

            {!healthy && (
                <div className="mt-2 grid gap-1">
                    {[...errors, ...warnings].slice(0, 2).map((message) => (
                        <p
                            key={message}
                            className="truncate text-2xs text-muted-foreground"
                        >
                            {message}
                        </p>
                    ))}
                </div>
            )}
        </section>
    );
}

export function AgentOffice({
    projectId,
    projectName,
    projectStatus,
    gitStatus,
    workers,
    agents,
    workerBindings,
    workflow,
    taskProgress,
}: {
    projectId: number;
    projectName: string;
    projectStatus: string;
    gitStatus: string;
    workers: OfficeWorker[];
    agents: OfficeAgent[];
    workerBindings: OfficeWorkerBinding[];
    workflow: OfficeWorkflow | null;
    taskProgress: {
        completed: number;
        total: number;
    };
}) {
    const pageProject = usePage().props.project as OverviewProject | undefined;

    const displayedWorkers = useMemo(
        () => selectOfficeWorkers(workers),
        [workers],
    );

    const workerByRole = useMemo(
        () =>
            new Map(
                displayedWorkers.map(
                    (worker) => [worker.role, worker] as const,
                ),
            ),
        [displayedWorkers],
    );

    const agentByWorkerId = useMemo(() => {
        const agentMap = new Map<number, OfficeAgent>();

        for (const binding of workerBindings) {
            if (binding.agent_id === null) {
                continue;
            }

            const agent = agents.find(
                (candidate) => candidate.id === binding.agent_id,
            );

            if (agent) {
                agentMap.set(binding.id, agent);
            }
        }

        return agentMap;
    }, [agents, workerBindings]);

    const tasks = pageProject?.tasks ?? [];
    const currentWorkflow = workflow?.mode === 'current' ? workflow : null;
    const activeWorkerId = currentWorkflow?.worker_id ?? null;
    const workflowStatus = currentWorkflow?.task?.status;

    const projectPaused = projectStatus !== 'running';

    let pmToCoderState: ConnectorState = 'idle';
    let coderToReviewerState: ConnectorState = 'idle';

    if (projectPaused) {
        pmToCoderState = 'paused';
        coderToReviewerState = 'paused';
    } else if (
        currentWorkflow?.role === 'reviewer' ||
        (workflowStatus !== undefined && reviewStatuses.has(workflowStatus))
    ) {
        pmToCoderState = 'complete';
        coderToReviewerState = 'active';
    } else if (
        currentWorkflow?.role === 'coder' ||
        (workflowStatus !== undefined &&
            implementationStatuses.has(workflowStatus))
    ) {
        pmToCoderState = 'active';
        coderToReviewerState = 'idle';
    }

    const validation = validationPresentation(
        pageProject?.git_evidence ?? null,
        workflow,
    );

    const healthErrors = new Set<string>();
    const healthWarnings = new Set<string>();

    for (const worker of displayedWorkers) {
        if (
            attentionStatuses.has(worker.status) ||
            worker.run?.status === 'failed' ||
            Boolean(worker.run?.failure_reason)
        ) {
            healthErrors.add(`${labelForRole(worker.role)} requires attention`);
        }
    }

    if (validation.label === 'Failed') {
        healthErrors.add('Deterministic validation failed');
    }

    if (projectStatus !== 'running') {
        healthWarnings.add(`Project execution is ${humanize(projectStatus)}`);
    }

    if (gitStatus !== 'clean') {
        healthWarnings.add(`Repository state is ${humanize(gitStatus)}`);
    }

    if (
        pageProject?.recent_agent_runs.some(
            (run) =>
                run.status === 'failed' ||
                (run.exit_code !== null && run.exit_code !== 0),
        )
    ) {
        healthWarnings.add(
            'Recent execution failure exists in persisted run history',
        );
    }

    const activeStageLabel = projectPaused
        ? 'PAUSED'
        : currentWorkflow
          ? `${labelForRole(currentWorkflow.role).toUpperCase()} ACTIVE`
          : healthErrors.size > 0
            ? 'ATTENTION'
            : 'IDLE';

    return (
        <section
            data-aios-execution-office="true"
            data-active-stage={currentWorkflow?.role ?? 'none'}
            aria-labelledby="agent-office-title"
            className="execution-command-center"
        >
            <header className="execution-command-header">
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <Activity
                            className="size-4 text-primary"
                            aria-hidden="true"
                        />
                        <span className="font-mono text-2xs tracking-[0.18em] text-primary uppercase">
                            Live Execution
                        </span>
                    </div>

                    <h2
                        id="agent-office-title"
                        className="mt-1 text-base font-semibold tracking-tight text-foreground"
                    >
                        AI Engineering Workflow
                    </h2>

                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Deterministic workflow with verifiable AIOS handoffs ·{' '}
                        {projectName}
                    </p>
                </div>

                <div className="flex flex-wrap items-center justify-end gap-2">
                    <Badge
                        variant="outline"
                        className={`gap-1.5 rounded-full px-3 py-1 font-mono text-2xs ${
                            projectPaused
                                ? 'border-warning/25 bg-warning/8 text-warning-foreground'
                                : healthErrors.size > 0
                                  ? 'border-destructive/25 bg-destructive/8 text-destructive-foreground'
                                  : currentWorkflow
                                    ? 'border-success/25 bg-success/8 text-success-foreground'
                                    : 'border-border bg-background/55 text-muted-foreground'
                        }`}
                    >
                        <span
                            className={`size-1.5 rounded-full ${
                                projectPaused
                                    ? 'bg-warning'
                                    : healthErrors.size > 0
                                      ? 'bg-destructive'
                                      : currentWorkflow
                                        ? 'status-glow-pulse bg-success'
                                        : 'bg-muted-foreground'
                            }`}
                        />
                        {activeStageLabel}
                    </Badge>

                    <Badge
                        variant="outline"
                        className={`rounded-full px-3 py-1 font-mono text-2xs ${
                            gitStatus === 'clean'
                                ? 'border-primary/20 bg-primary/5 text-primary'
                                : 'border-warning/25 bg-warning/8 text-warning-foreground'
                        }`}
                    >
                        Git · {humanize(gitStatus)}
                    </Badge>
                </div>
            </header>

            <div className="execution-command-body">
                <div
                    className="execution-workflow-grid"
                    aria-label="Project Manager to Coder to Reviewer execution workflow"
                >
                    {preferredRoleOrder.map((role, index) => {
                        const worker = workerByRole.get(role);

                        const node = worker ? (
                            <AgentNode
                                projectId={projectId}
                                worker={worker}
                                agent={agentByWorkerId.get(worker.id)}
                                active={worker.id === activeWorkerId}
                                index={index + 1}
                                projectStatus={projectStatus}
                                fallbackTask={fallbackTaskForRole(role, tasks)}
                            />
                        ) : (
                            <EmptyAgentNode role={role} index={index + 1} />
                        );

                        return (
                            <Fragment key={role}>
                                {index === 1 && (
                                    <WorkflowConnector
                                        state={pmToCoderState}
                                        label="Project Manager to Coder"
                                    />
                                )}

                                {index === 2 && (
                                    <WorkflowConnector
                                        state={coderToReviewerState}
                                        label="Coder to Reviewer"
                                    />
                                )}

                                {node}
                            </Fragment>
                        );
                    })}
                </div>

                <div className="execution-primary-grid">
                    <RoadmapProgressCard
                        projectId={projectId}
                        tasks={tasks}
                        completed={taskProgress.completed}
                        total={taskProgress.total}
                    />

                    <CurrentOperationCard
                        projectId={projectId}
                        workflow={workflow}
                        workers={displayedWorkers}
                    />

                    <NextStageCard
                        workflow={workflow}
                        projectStatus={projectStatus}
                    />

                    <RepositoryEvidenceCard
                        projectId={projectId}
                        evidence={pageProject?.git_evidence ?? null}
                        gitStatus={gitStatus}
                        repositoryHeadSha={pageProject?.git_head_sha ?? null}
                    />

                    <ValidationStateCard
                        projectId={projectId}
                        evidence={pageProject?.git_evidence ?? null}
                        workflow={workflow}
                    />
                </div>

                <div className="execution-secondary-grid">
                    <TokenUsageCard
                        total={pageProject?.token_usage_total ?? 0}
                        harnessUsage={pageProject?.harness_usage ?? {}}
                        observability={pageProject?.token_observability ?? {}}
                    />

                    <HealthCard
                        errors={Array.from(healthErrors)}
                        warnings={Array.from(healthWarnings)}
                    />
                </div>
            </div>
        </section>
    );
}
