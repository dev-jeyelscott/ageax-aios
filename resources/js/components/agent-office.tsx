import { Form, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    CircleDot,
    Clock,
    Cpu,
    FileUp,
    GitBranch,
    GitCommitHorizontal,
    RotateCcw,
    ShieldCheck,
    Workflow,
} from 'lucide-react';
import { Fragment, useEffect, useMemo, useState } from 'react';
import {
    requeueRoadmap,
    show as showProject,
    showAgentRun,
    showTask,
} from '@/actions/App/Http/Controllers/ProjectController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { store as storeRoadmap } from '@/routes/projects/roadmaps';
import './agent-office.css';

type RunConfiguration = {
    harness: string | null;
    model: string | null;
    reasoning_setting: string | null;
    configuration_version: number | null;
    source: 'snapshot' | 'run';
};

export type OfficeWorker = {
    id: number;
    role: string;
    status: string;
    last_heartbeat_at: string | null;
    cooldown_ends_at: string | null;
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
        configuration: RunConfiguration | null;
    } | null;
    task: {
        id: number;
        key: string;
        title: string;
        status: string;
        started_at: string | null;
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
    return_from_reviewer?: boolean;
};

export type OfficeWorkflow = {
    mode: 'current' | 'recent';
    worker_id: number;
    role: string;
    run_id: number;
    task: OfficeTask | null;
};

type OfficeRoadmap = {
    id: number;
    original_filename: string;
    status: string;
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
    token_usage: number | null;
    known_token_usage: number;
    token_usage_run_count: number;
    average_tokens_per_run: number | null;
    legacy_incomplete_run_count: number;
    configurations: {
        model: string | null;
        reasoning_setting: string | null;
        run_count: number;
        token_usage: number;
    }[];
};

type TokenUsageEvidence = {
    window: { key: '24h' | '7d' | 'all'; label: string };
    run_count: number;
    token_usage_run_count: number;
    total_processed_tokens: number;
    average_tokens_per_run: number | null;
    legacy_incomplete_run_count: number;
    legacy_token_usage: number;
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
    roadmaps: OfficeRoadmap[];
    tasks: OfficeTask[];
    git_evidence: GitEvidence | null;
    token_usage_total: number;
    token_usage_evidence: TokenUsageEvidence;
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
};

type ExecutionConfiguration = {
    harness: string;
    model: string | null;
    reasoningSetting: string | null;
    configurationVersion: number | null;
    source: 'snapshot' | 'run' | 'bound_agent';
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
    return value ? value.slice(0, 10) : '—';
}

function formatEvidenceTime(value: string | null): string {
    if (!value) {
        return 'Not recorded';
    }

    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return 'Not recorded';
    }

    return parsed.toLocaleString();
}

function formatRunDuration(
    startedAt: string | null,
    finishedAt: string | null,
    currentTime = Date.now(),
): string {
    if (!startedAt) {
        return '—';
    }

    const started = new Date(startedAt).getTime();
    const finished = finishedAt ? new Date(finishedAt).getTime() : currentTime;

    if (!Number.isFinite(started) || !Number.isFinite(finished)) {
        return '—';
    }

    const totalSeconds = Math.max(0, Math.round((finished - started) / 1_000));
    const seconds = totalSeconds % 60;
    const totalMinutes = Math.floor(totalSeconds / 60);

    if (totalMinutes < 60) {
        return `${totalMinutes.toString().padStart(2, '0')}:${seconds
            .toString()
            .padStart(2, '0')}`;
    }

    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    return `${hours.toString().padStart(2, '0')}:${minutes
        .toString()
        .padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
}

function selectOfficeWorkers(workers: OfficeWorker[]): OfficeWorker[] {
    const claimedIds = new Set<number>();

    return preferredRoleOrder.flatMap((role) => {
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
        worker.role === 'coder' &&
        worker.status === 'working' &&
        worker.task?.status === 'validating'
    ) {
        return `Validating ${worker.task.key} against the task contract and repository evidence.`;
    }

    if (
        worker.activity_mode === 'recent' &&
        worker.run?.status === 'completed' &&
        worker.task
    ) {
        return `Completed ${labelForRole(worker.role)} run for ${worker.task.key} · ${worker.task.title}.`;
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
        (worker.activity_mode !== 'recent' &&
            worker.task &&
            attentionStatuses.has(worker.task.status))
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

    if (
        worker.role === 'coder' &&
        worker.status === 'working' &&
        worker.task?.status === 'validating'
    ) {
        return {
            label: 'Validating',
            dotClass: 'bg-primary',
            badgeClass: 'border-primary/30 bg-primary/8 text-primary',
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

function useCurrentTime(): number {
    const [currentTime, setCurrentTime] = useState(() => Date.now());

    useEffect(() => {
        const interval = window.setInterval(() => {
            setCurrentTime(Date.now());
        }, 1_000);

        return () => window.clearInterval(interval);
    }, []);

    return currentTime;
}

function cooldownCountdown(
    endsAt: string | null,
    currentTime: number,
): string | null {
    if (endsAt === null) {
        return null;
    }

    const remainingSeconds = Math.max(
        0,
        Math.ceil((new Date(endsAt).getTime() - currentTime) / 1_000),
    );

    if (remainingSeconds === 0) {
        return 'Execution check pending';
    }

    const hours = Math.floor(remainingSeconds / 3_600);
    const minutes = Math.floor((remainingSeconds % 3_600) / 60);
    const seconds = remainingSeconds % 60;

    return hours > 0
        ? `${hours}h ${minutes.toString().padStart(2, '0')}m`
        : `${minutes.toString().padStart(2, '0')}:${seconds
              .toString()
              .padStart(2, '0')}`;
}

function executionConfiguration(
    worker: OfficeWorker,
    agent: OfficeAgent | undefined,
): ExecutionConfiguration | null {
    if (agent) {
        return {
            harness: agent.harness,
            model: agent.model,
            reasoningSetting: agent.reasoning_setting,
            configurationVersion: agent.configuration_version,
            source: 'bound_agent',
        };
    }

    const runConfiguration = worker.run?.configuration;

    if (!runConfiguration?.harness) {
        return null;
    }

    return {
        harness: runConfiguration.harness,
        model: runConfiguration.model,
        reasoningSetting: runConfiguration.reasoning_setting,
        configurationVersion: runConfiguration.configuration_version,
        source: runConfiguration.source,
    };
}

function validationPresentation(
    evidence: GitEvidence | null,
    workflow: OfficeWorkflow | null,
): ValidationPresentation {
    if (evidence?.validation_results?.passed === true) {
        return {
            label: 'Passed',
            dotClass: 'bg-success',
            badgeClass:
                'border-success/25 bg-success/8 text-success-foreground',
        };
    }

    if (evidence?.validation_results?.passed === false) {
        return {
            label: 'Failed',
            dotClass: 'bg-destructive',
            badgeClass:
                'border-destructive/25 bg-destructive/8 text-destructive-foreground',
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
        };
    }

    return {
        label: 'Not recorded',
        dotClass: 'bg-muted-foreground',
        badgeClass: 'border-border bg-background/55 text-muted-foreground',
    };
}

function validationCheck(
    checks: Record<string, boolean> | undefined,
    aliases: string[],
): string {
    if (!checks) {
        return '—';
    }

    const entry = Object.entries(checks).find(([name]) => {
        const normalized = name.toLowerCase();

        return aliases.some((alias) => normalized.includes(alias));
    });

    if (!entry) {
        return '—';
    }

    return entry[1] ? 'Passed' : 'Failed';
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

function lastHandoffFor(workflow: OfficeWorkflow | null): string {
    if (workflow?.mode !== 'current') {
        return 'No active handoff';
    }

    switch (workflow.role) {
        case 'coder':
            return 'PM → Coder';
        case 'reviewer':
            return 'Coder → Reviewer';
        case 'project_manager':
            return 'No handoff yet';
        default:
            return 'AIOS-controlled';
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
    currentTime,
}: {
    projectId: number;
    worker: OfficeWorker;
    agent: OfficeAgent | undefined;
    active: boolean;
    index: number;
    projectStatus: string;
    fallbackTask: OfficeTask | null;
    currentTime: number;
}) {
    const presentation = workerPresentation(worker, active, projectStatus);
    const thumbnail = roleThumbnails[worker.role] ?? null;
    const message = workerMessage(worker, active);
    const displayedTask = worker.task ?? fallbackTask;
    const displayedTaskStatus = displayedTask?.status;
    const configuration = executionConfiguration(worker, agent);
    const timestamp =
        worker.run?.finished_at ??
        worker.run?.started_at ??
        worker.last_heartbeat_at;
    const duration = formatRunDuration(
        worker.run?.started_at ?? null,
        worker.run?.finished_at ?? null,
        currentTime,
    );
    const taskRuntime = active
        ? formatRunDuration(
              worker.task?.started_at ?? worker.run?.started_at ?? null,
              null,
              currentTime,
          )
        : null;
    const cooldown = cooldownCountdown(worker.cooldown_ends_at, currentTime);

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

                    <p className="relative line-clamp-4 text-xs leading-relaxed text-foreground">
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
                {taskRuntime && (
                    <div className="execution-agent-meta-row">
                        <div className="flex min-w-0 items-center gap-2">
                            <Clock className="size-3.5 shrink-0 text-primary" />
                            <div className="min-w-0">
                                <p className="execution-meta-label">
                                    Task runtime
                                </p>
                                <p className="mt-0.5 font-mono text-xs font-medium text-foreground">
                                    {taskRuntime}
                                </p>
                            </div>
                        </div>
                    </div>
                )}

                {cooldown && !active && (
                    <div className="execution-agent-meta-row">
                        <div className="flex min-w-0 items-center gap-2">
                            <Clock className="size-3.5 shrink-0 text-primary" />
                            <div className="min-w-0">
                                <p className="execution-meta-label">
                                    Next execution check
                                </p>
                                <p className="mt-0.5 font-mono text-xs font-medium text-foreground">
                                    {cooldown}
                                </p>
                            </div>
                        </div>
                    </div>
                )}

                <div className="execution-agent-meta-row">
                    <div className="flex min-w-0 items-center gap-2">
                        <Cpu className="size-3.5 shrink-0 text-primary" />

                        <div className="min-w-0">
                            <p className="execution-meta-label">
                                Harness / Model
                            </p>
                            <p
                                className="mt-0.5 truncate text-xs font-medium text-foreground"
                                title={
                                    configuration
                                        ? `${labelForHarness(configuration.harness)} · ${configuration.model ?? 'default model'}`
                                        : undefined
                                }
                            >
                                {configuration
                                    ? `${labelForHarness(configuration.harness)} · ${configuration.model ?? 'default model'}`
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

                            {displayedTask && displayedTaskStatus && (
                                <Badge
                                    variant="outline"
                                    className="border-border bg-background/50 px-1.5 py-0 font-mono text-[9px] text-muted-foreground"
                                >
                                    {humanize(displayedTaskStatus)}
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

            <div className="mt-auto flex items-center justify-between gap-3 border-t border-border-subtle pt-2.5">
                <div className="flex items-center gap-2">
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
                            —
                        </span>
                    )}
                </div>

                {worker.run && (
                    <span className="flex items-center gap-1 font-mono text-2xs text-muted-foreground">
                        <Clock className="size-3" aria-hidden="true" />
                        {duration}
                    </span>
                )}
            </div>
        </article>
    );
}

function WorkflowConnector({
    state,
    label,
    reverse = false,
}: {
    state: ConnectorState;
    label: string;
    reverse?: boolean;
}) {
    return (
        <div
            role="img"
            aria-label={`${reverse ? 'Reviewer to Coder' : label}: ${state}`}
            data-connector-state={state}
            className={`workflow-connector workflow-connector--${state} ${
                reverse ? 'workflow-connector--reverse' : ''
            }`}
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

function RoadmapActionsBar({
    projectId,
    roadmap,
}: {
    projectId: number;
    roadmap: OfficeRoadmap | null;
}) {
    return (
        <div className="execution-roadmap-actions">
            <div>
                <p className="execution-ops-heading">Roadmap Actions</p>
                <p className="mt-0.5 text-2xs text-muted-foreground">
                    Existing AIOS operator controls
                </p>
            </div>

            <div className="execution-roadmap-actions__controls">
                {roadmap?.status === 'blocked' && (
                    <Form
                        {...requeueRoadmap.form({
                            project: projectId,
                            roadmap: roadmap.id,
                        })}
                    >
                        {({ processing }) => (
                            <Button
                                size="sm"
                                type="submit"
                                variant="outline"
                                disabled={processing}
                                className="h-8 border-destructive/25 bg-destructive/8 px-3 text-xs text-destructive-foreground hover:bg-destructive/15"
                            >
                                <RotateCcw className="size-3.5" />
                                {processing ? 'Retrying…' : 'Retry roadmap'}
                            </Button>
                        )}
                    </Form>
                )}

                <Form
                    {...storeRoadmap.form(projectId)}
                    encType="multipart/form-data"
                >
                    {({ errors, processing }) => (
                        <div>
                            <label className="flex h-8 cursor-pointer items-center justify-center gap-2 rounded-md border border-primary/20 bg-primary/5 px-3 text-xs font-medium text-primary transition focus-within:ring-2 focus-within:ring-primary hover:border-primary/35 hover:bg-primary/10">
                                <input
                                    name="roadmap"
                                    type="file"
                                    accept=".md,.txt,text/markdown,text/plain"
                                    required
                                    disabled={processing}
                                    className="sr-only"
                                    onChange={(event) => {
                                        if (event.currentTarget.files?.length) {
                                            event.currentTarget.form?.requestSubmit();
                                        }
                                    }}
                                />
                                <FileUp className="size-3.5" />
                                {processing
                                    ? 'Uploading…'
                                    : roadmap
                                      ? 'Upload new roadmap'
                                      : 'Upload roadmap'}
                            </label>
                            <InputError message={errors.roadmap} />
                        </div>
                    )}
                </Form>
            </div>
        </div>
    );
}

function ExecutionContextPanel({
    tasks,
    workflow,
    workers,
    completed,
    total,
}: {
    tasks: OfficeTask[];
    workflow: OfficeWorkflow | null;
    workers: OfficeWorker[];
    completed: number;
    total: number;
}) {
    const currentWorkflow = workflow?.mode === 'current' ? workflow : null;
    const activeWorker = currentWorkflow
        ? workers.find((worker) => worker.id === currentWorkflow.worker_id)
        : undefined;
    const currentTask =
        currentWorkflow?.task ??
        tasks.find((task) => !['done', 'cancelled'].includes(task.status)) ??
        null;
    const progress = total === 0 ? 0 : Math.round((completed / total) * 100);

    return (
        <section
            className="execution-context-panel"
            aria-label="Execution context"
        >
            <div className="execution-ops-heading">
                <Activity className="size-3.5 text-primary" />
                <span>Execution Context</span>
            </div>

            <div className="execution-context-grid">
                <div className="execution-context-item">
                    <p className="execution-meta-label">Current Task</p>
                    {currentTask ? (
                        <p className="mt-1 truncate text-xs font-medium text-foreground">
                            <span className="font-mono text-primary">
                                {currentTask.key}
                            </span>{' '}
                            · {currentTask.title}
                        </p>
                    ) : (
                        <p className="mt-1 text-xs text-muted-foreground">
                            No current task recorded
                        </p>
                    )}
                </div>

                <div className="execution-context-item">
                    <p className="execution-meta-label">Task Progress</p>
                    <div className="mt-1 flex items-center gap-2">
                        <span className="font-mono text-xs text-foreground">
                            {completed} / {total}
                        </span>
                        <div className="h-1.5 min-w-12 flex-1 overflow-hidden rounded-full bg-muted">
                            <div
                                className="progress-flow h-full rounded-full"
                                style={{ width: `${progress}%` }}
                            />
                        </div>
                        <span className="font-mono text-2xs text-muted-foreground">
                            {progress}%
                        </span>
                    </div>
                </div>

                <div className="execution-context-item">
                    <p className="execution-meta-label">Active Run</p>
                    {activeWorker?.run ? (
                        <div className="mt-1 flex items-center gap-2">
                            <span className="font-mono text-xs text-primary">
                                #{activeWorker.run.id}
                            </span>
                            <Badge
                                variant="outline"
                                className="border-primary/20 bg-primary/5 px-1.5 py-0 font-mono text-[9px] text-primary"
                            >
                                In progress
                            </Badge>
                            <span className="ml-auto font-mono text-2xs text-muted-foreground">
                                {formatRunDuration(
                                    activeWorker.run.started_at,
                                    activeWorker.run.finished_at,
                                )}
                            </span>
                        </div>
                    ) : (
                        <p className="mt-1 text-xs text-muted-foreground">
                            No active run
                        </p>
                    )}
                </div>

                <div className="execution-context-item">
                    <p className="execution-meta-label">Last Handoff</p>
                    <p className="mt-1 text-xs font-medium text-foreground">
                        {lastHandoffFor(currentWorkflow)}
                    </p>
                </div>
            </div>

            <div className="execution-context-policy-grid">
                <div>
                    <p className="execution-meta-label">Workflow Scope</p>
                    <p className="mt-1 text-2xs text-muted-foreground">
                        PM planning/triage · Coder implementation · Reviewer
                        review
                    </p>
                </div>
                <div>
                    <p className="execution-meta-label">Deterministic Mode</p>
                    <p className="mt-1 text-2xs font-medium text-success-foreground">
                        Enabled
                    </p>
                </div>
                <div>
                    <p className="execution-meta-label">Handoff Policy</p>
                    <p className="mt-1 text-2xs text-muted-foreground">
                        Verifiable · Deterministic · Auditable
                    </p>
                </div>
            </div>
        </section>
    );
}

function RoadmapProgressCard({
    projectId,
    roadmap,
    tasks,
    completed,
    total,
}: {
    projectId: number;
    roadmap: OfficeRoadmap | null;
    tasks: OfficeTask[];
    completed: number;
    total: number;
}) {
    const progress = total === 0 ? 0 : Math.round((completed / total) * 100);
    const nextTask =
        tasks.find((task) => !['done', 'cancelled'].includes(task.status)) ??
        null;

    return (
        <section className="execution-ops-card execution-ops-card--roadmap">
            <div className="flex items-start justify-between gap-3">
                <div className="execution-ops-heading">
                    <Workflow className="size-3.5 text-primary" />
                    <span>Roadmap Progress</span>
                </div>

                {roadmap && (
                    <Badge
                        variant="outline"
                        className="border-primary/20 bg-primary/5 font-mono text-2xs text-primary"
                    >
                        {humanize(roadmap.status)}
                    </Badge>
                )}
            </div>

            <div className="mt-2.5 flex items-end justify-between gap-3">
                <div>
                    <p className="text-3xl font-semibold tracking-tight text-primary">
                        {progress}%
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {completed} of {total} tasks complete
                    </p>
                </div>

                <div className="text-right">
                    <p className="font-mono text-xs text-foreground">
                        {completed} / {total} Tasks
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {nextTask
                            ? 'In progress'
                            : total > 0
                              ? 'Complete'
                              : 'No tasks'}
                    </p>
                </div>
            </div>

            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                <div
                    className="progress-flow h-full rounded-full"
                    style={{ width: `${progress}%` }}
                />
            </div>

            <div className="mt-2.5 flex min-w-0 items-center justify-between gap-3 border-t border-border-subtle pt-2.5">
                <div className="min-w-0">
                    <p className="execution-meta-label">
                        {roadmap ? 'Current roadmap' : 'Roadmap'}
                    </p>
                    <p
                        className="mt-1 truncate text-xs text-muted-foreground"
                        title={roadmap?.original_filename}
                    >
                        {roadmap?.original_filename ?? 'No roadmap recorded'}
                    </p>
                </div>

                {nextTask && (
                    <Link
                        href={
                            showTask({
                                project: projectId,
                                task: nextTask.id,
                            }).url
                        }
                        className="shrink-0 text-2xs font-medium text-primary transition hover:text-primary/80 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                    >
                        {nextTask.key}
                    </Link>
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
    const active = workflow?.mode === 'current';

    return (
        <section className="execution-ops-card">
            <div className="execution-ops-heading">
                <Activity className="size-3.5 text-primary" />
                <span>Current Operation</span>
            </div>

            <dl className="mt-2.5 grid gap-2">
                <div className="execution-data-row">
                    <dt className="execution-meta-label">Active Stage</dt>
                    <dd className="text-xs font-semibold text-foreground">
                        {active && workflow
                            ? labelForRole(workflow.role)
                            : 'No active stage'}
                    </dd>
                </div>

                <div className="execution-data-row">
                    <dt className="execution-meta-label">Current Task</dt>
                    <dd className="min-w-0 truncate text-xs font-medium text-foreground">
                        {active && workflow?.task ? (
                            <>
                                <span className="font-mono text-primary">
                                    {workflow.task.key}
                                </span>{' '}
                                · {workflow.task.title}
                            </>
                        ) : (
                            'Not recorded'
                        )}
                    </dd>
                </div>
            </dl>

            <div className="mt-2.5 grid grid-cols-2 gap-2 border-t border-border-subtle pt-2.5">
                <div>
                    <p className="execution-meta-label">Started</p>
                    <p className="mt-1 truncate text-2xs text-muted-foreground">
                        {formatEvidenceTime(
                            active ? (worker?.run?.started_at ?? null) : null,
                        )}
                    </p>
                </div>

                <div>
                    <p className="execution-meta-label">Duration</p>
                    <p className="mt-1 font-mono text-2xs text-muted-foreground">
                        {formatRunDuration(
                            active ? (worker?.run?.started_at ?? null) : null,
                            active ? (worker?.run?.finished_at ?? null) : null,
                        )}
                    </p>
                </div>
            </div>

            {active && workflow?.task && (
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

            <div className="mt-2.5">
                <p className="execution-meta-label">Next Agent</p>
                <p className="mt-1 text-sm font-semibold text-foreground">
                    {nextStage.stage}
                </p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {nextStage.detail}
                </p>
            </div>

            <div className="mt-3 border-t border-border-subtle pt-2.5">
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
            ? '—'
            : evidence.changed_files.length.toString();

    return (
        <section className="execution-ops-card execution-ops-card--repository">
            <div className="flex items-start justify-between gap-3">
                <div className="execution-ops-heading">
                    <GitBranch className="size-3.5 text-primary" />
                    <span>Repository · Git Evidence</span>
                </div>

                <div className="text-right">
                    <p className="execution-meta-label">Working Tree</p>
                    <p
                        className={`mt-0.5 font-mono text-2xs ${
                            gitStatus === 'clean'
                                ? 'text-success-foreground'
                                : 'text-warning-foreground'
                        }`}
                    >
                        {humanize(gitStatus)}
                    </p>
                </div>
            </div>

            <dl className="execution-git-grid">
                <div>
                    <dt className="execution-meta-label">Base</dt>
                    <dd className="mt-1 truncate font-mono text-2xs text-primary">
                        {shortSha(evidence?.base_sha ?? null)}
                    </dd>
                </div>
                <div>
                    <dt className="execution-meta-label">Head</dt>
                    <dd className="mt-1 truncate font-mono text-2xs text-primary">
                        {shortSha(
                            evidence?.head_sha ?? repositoryHeadSha ?? null,
                        )}
                    </dd>
                </div>
                <div>
                    <dt className="execution-meta-label">Commit</dt>
                    <dd className="mt-1 truncate font-mono text-2xs text-primary">
                        {shortSha(evidence?.commit_sha ?? null)}
                    </dd>
                </div>
                <div>
                    <dt className="execution-meta-label">Changes</dt>
                    <dd className="mt-1 font-mono text-2xs text-foreground">
                        {changedFiles}
                    </dd>
                </div>
            </dl>

            {evidence && (
                <Link
                    href={
                        showTask({
                            project: projectId,
                            task: evidence.task.id,
                        }).url
                    }
                    className="execution-inline-link"
                >
                    <GitCommitHorizontal className="size-3" />
                    Attempt #{evidence.attempt_number} evidence
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
    const checks = evidence?.validation_results?.checks;
    const task = evidence?.task ?? workflow?.task ?? null;
    const columns = [
        ['Tests', validationCheck(checks, ['test', 'pest', 'phpunit'])],
        ['Static Analysis', validationCheck(checks, ['static', 'phpstan'])],
        ['Build', validationCheck(checks, ['build', 'vite'])],
        ['Latest Result', presentation.label],
    ] as const;

    return (
        <section className="execution-ops-card">
            <div className="flex items-center justify-between gap-3">
                <div className="execution-ops-heading">
                    <ShieldCheck className="size-3.5 text-primary" />
                    <span>Validation State</span>
                </div>

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

            <dl className="execution-validation-grid">
                {columns.map(([label, value]) => (
                    <div key={label} className="min-w-0">
                        <dt className="execution-meta-label">{label}</dt>
                        <dd
                            className={`mt-1 truncate font-mono text-2xs ${
                                value === 'Passed'
                                    ? 'text-success-foreground'
                                    : value === 'Failed'
                                      ? 'text-destructive-foreground'
                                      : value === 'Running' ||
                                          value === 'Pending'
                                        ? 'text-warning-foreground'
                                        : 'text-muted-foreground'
                            }`}
                        >
                            {value}
                        </dd>
                    </div>
                ))}
            </dl>

            {task && (
                <Link
                    href={
                        showTask({
                            project: projectId,
                            task: task.id,
                        }).url
                    }
                    className="execution-inline-link"
                >
                    View validation evidence
                </Link>
            )}
        </section>
    );
}

function TokenUsageCard({
    projectId,
    total,
    harnessUsage,
    observability,
    evidence,
}: {
    projectId: number;
    total: number;
    harnessUsage: Record<string, HarnessUsage>;
    observability: Record<string, TokenObservability>;
    evidence: TokenUsageEvidence;
}) {
    const preferredHarnesses = ['claude_code', 'codex'];
    const allHarnesses = Array.from(
        new Set([...preferredHarnesses, ...Object.keys(harnessUsage)]),
    );
    const harnesses = allHarnesses
        .map((harness) => ({
            key: harness,
            usage: harnessUsage[harness] ?? {
                run_count: 0,
                token_usage: null,
                known_token_usage: 0,
                token_usage_run_count: 0,
                average_tokens_per_run: null,
                legacy_incomplete_run_count: 0,
                configurations: [],
            },
        }))
        .filter(
            ({ usage }) =>
                usage.run_count > 0 ||
                usage.known_token_usage > 0 ||
                Object.keys(harnessUsage).length === 0,
        );
    const barTotal = Math.max(
        1,
        harnesses.reduce(
            (sum, entry) => sum + entry.usage.known_token_usage,
            0,
        ),
    );

    return (
        <section className="execution-ops-card execution-ops-card--tokens">
            <div className="execution-ops-heading">
                <Activity className="size-3.5 text-primary" />
                <span>Execution / Token Usage</span>
            </div>

            <div className="mt-2 flex flex-wrap items-center gap-1.5">
                {(['24h', '7d', 'all'] as const).map((window) => (
                    <Link
                        key={window}
                        href={
                            showProject(
                                { project: projectId },
                                { query: { usage_window: window } },
                            ).url
                        }
                        preserveScroll
                        className={`rounded-md px-2 py-1 font-mono text-2xs ${
                            evidence.window.key === window
                                ? 'bg-primary/15 text-primary'
                                : 'text-muted-foreground hover:bg-muted'
                        }`}
                    >
                        {window === 'all' ? 'All time' : window}
                    </Link>
                ))}
                <span className="text-2xs text-muted-foreground">
                    {evidence.window.label}
                </span>
            </div>

            <div className="execution-token-layout">
                <div>
                    <p className="execution-meta-label">
                        Recorded processed tokens
                    </p>
                    <p className="mt-1 text-2xl font-semibold text-foreground">
                        {formatTokens(total)}
                    </p>
                </div>

                <div className="grid min-w-0 gap-2">
                    {harnesses.length > 0 ? (
                        harnesses.map(({ key, usage }) => {
                            const percentage = Math.round(
                                (usage.known_token_usage / barTotal) * 100,
                            );

                            return (
                                <div key={key}>
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-xs font-medium text-foreground">
                                            {labelForHarness(key)}
                                        </span>
                                        <span className="font-mono text-2xs text-primary">
                                            {usage.token_usage === null
                                                ? 'Unavailable'
                                                : formatTokens(
                                                      usage.token_usage,
                                                  )}{' '}
                                            · {usage.run_count} runs
                                        </span>
                                    </div>
                                    <p className="mt-1 text-2xs text-muted-foreground">
                                        Avg{' '}
                                        {usage.average_tokens_per_run === null
                                            ? 'unavailable'
                                            : formatTokens(
                                                  usage.average_tokens_per_run,
                                              )}{' '}
                                        / recorded run
                                    </p>
                                    {usage.token_usage !== null &&
                                        usage.configurations.map(
                                            (configuration) => (
                                                <p
                                                    key={`${configuration.model}-${configuration.reasoning_setting}`}
                                                    className="mt-0.5 text-2xs text-muted-foreground"
                                                >
                                                    {configuration.model ??
                                                        'Immutable config unavailable'}{' '}
                                                    ·{' '}
                                                    {configuration.reasoning_setting ??
                                                        'default'}
                                                    :{' '}
                                                    {formatTokens(
                                                        configuration.token_usage,
                                                    )}{' '}
                                                    / {configuration.run_count}{' '}
                                                    runs
                                                </p>
                                            ),
                                        )}
                                    {usage.token_usage !== null && (
                                        <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted">
                                            <div
                                                className="execution-token-bar h-full rounded-full"
                                                style={{
                                                    width: `${percentage}%`,
                                                }}
                                            />
                                        </div>
                                    )}
                                </div>
                            );
                        })
                    ) : (
                        <p className="text-xs text-muted-foreground">
                            No harness usage recorded.
                        </p>
                    )}
                </div>
            </div>

            <p className="mt-2 text-2xs text-muted-foreground">
                Usage recorded for {evidence.token_usage_run_count} of{' '}
                {evidence.run_count} executions; averages use{' '}
                {evidence.window.key === 'all'
                    ? 'all recorded'
                    : 'windowed recorded'}{' '}
                usage. Raw harness totals are observational, not efficiency
                rankings.
                {evidence.legacy_incomplete_run_count > 0 &&
                    ` ${evidence.legacy_incomplete_run_count} legacy/incomplete runs (${formatTokens(evidence.legacy_token_usage)} legacy tokens) are excluded from processed-token totals.`}
            </p>

            <div className="mt-3 border-t border-border-subtle pt-2.5">
                <p className="execution-meta-label">Rolling Role Averages</p>

                <div className="mt-2 grid gap-1.5 sm:grid-cols-3">
                    {preferredRoleOrder.map((role) => {
                        const observation = observability[role];

                        return (
                            <div
                                key={role}
                                className="min-w-0 rounded-lg border border-border-subtle bg-background/25 px-2 py-1.5"
                            >
                                <span className="block truncate text-2xs text-muted-foreground">
                                    {labelForRole(role)}
                                </span>
                                <span className="mt-0.5 block truncate font-mono text-2xs text-foreground">
                                    {observation?.rolling_average === null
                                        ? 'No runs'
                                        : observation?.rolling_average !==
                                            undefined
                                          ? formatTokens(
                                                observation.rolling_average,
                                            ) + ' avg'
                                          : 'Not recorded'}
                                </span>
                            </div>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}

function HealthCard({
    errors,
    warnings,
    blocked,
}: {
    errors: string[];
    warnings: string[];
    blocked: number;
}) {
    const healthy =
        errors.length === 0 && warnings.length === 0 && blocked === 0;

    return (
        <section className="execution-ops-card">
            <div className="execution-ops-heading">
                <Activity className="size-3.5 text-success-foreground" />
                <span>Health & Warnings</span>
            </div>

            <div className="execution-health-grid">
                <div className="flex min-w-0 items-center gap-2">
                    <div
                        className={`grid size-7 shrink-0 place-items-center rounded-full border ${
                            healthy
                                ? 'border-success/25 bg-success/8 text-success-foreground'
                                : errors.length > 0 || blocked > 0
                                  ? 'border-destructive/25 bg-destructive/8 text-destructive-foreground'
                                  : 'border-warning/25 bg-warning/8 text-warning-foreground'
                        }`}
                    >
                        {healthy ? (
                            <CheckCircle2 className="size-3.5" />
                        ) : (
                            <AlertTriangle className="size-3.5" />
                        )}
                    </div>

                    <div className="min-w-0">
                        <p className="execution-meta-label">System Health</p>
                        <p
                            className={`mt-0.5 truncate text-xs font-medium ${
                                healthy
                                    ? 'text-success-foreground'
                                    : errors.length > 0 || blocked > 0
                                      ? 'text-destructive-foreground'
                                      : 'text-warning-foreground'
                            }`}
                        >
                            {healthy ? 'Healthy' : 'Needs attention'}
                        </p>
                    </div>
                </div>

                <div className="execution-health-metric">
                    <p className="execution-meta-label">Warnings</p>
                    <p className="mt-1 text-lg font-semibold text-warning-foreground">
                        {warnings.length}
                    </p>
                </div>

                <div className="execution-health-metric">
                    <p className="execution-meta-label">Errors</p>
                    <p className="mt-1 text-lg font-semibold text-destructive-foreground">
                        {errors.length}
                    </p>
                </div>

                <div className="execution-health-metric">
                    <p className="execution-meta-label">Blocked</p>
                    <p className="mt-1 text-lg font-semibold text-primary">
                        {blocked}
                    </p>
                </div>
            </div>

            <p className="mt-2 truncate text-2xs text-muted-foreground">
                {healthy
                    ? 'All recorded systems operational'
                    : ([...errors, ...warnings][0] ??
                      'Blocked workflow evidence exists')}
            </p>
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
    const currentTime = useCurrentTime();

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
    const coderToReviewerReverse =
        currentWorkflow?.role === 'coder' &&
        currentWorkflow.task?.return_from_reviewer === true;

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
        if (coderToReviewerReverse) {
            pmToCoderState = 'complete';
            coderToReviewerState = 'active';
        } else {
            pmToCoderState = 'active';
            coderToReviewerState = 'idle';
        }
    }

    const validation = validationPresentation(
        pageProject?.git_evidence ?? null,
        workflow,
    );

    const healthErrors = new Set<string>();
    const healthWarnings = new Set<string>();
    let blockedWorkerCount = 0;

    for (const worker of displayedWorkers) {
        const blocked =
            attentionStatuses.has(worker.status) ||
            worker.run?.status === 'failed' ||
            Boolean(worker.run?.failure_reason) ||
            Boolean(
                worker.activity_mode !== 'recent' &&
                worker.task &&
                attentionStatuses.has(worker.task.status),
            );

        if (blocked) {
            blockedWorkerCount += 1;
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

    const activeRole = projectPaused
        ? 'Paused'
        : currentWorkflow
          ? labelForRole(currentWorkflow.role)
          : healthErrors.size > 0
            ? 'Attention required'
            : 'No active role';

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

                <div className="execution-command-meta">
                    <div className="execution-command-meta__item">
                        <p className="execution-meta-label">Active Role</p>
                        <p className="mt-1 text-sm font-semibold text-foreground">
                            {activeRole}
                        </p>
                    </div>

                    <div className="execution-command-meta__item">
                        <p className="execution-meta-label">Repository State</p>
                        <Badge
                            variant="outline"
                            className={`mt-1 rounded-full px-2.5 py-0.5 font-mono text-2xs ${
                                gitStatus === 'clean'
                                    ? 'border-success/25 bg-success/8 text-success-foreground'
                                    : 'border-warning/25 bg-warning/8 text-warning-foreground'
                            }`}
                        >
                            <span
                                className={`mr-1.5 inline-block size-1.5 rounded-full ${
                                    gitStatus === 'clean'
                                        ? 'bg-success'
                                        : 'bg-warning'
                                }`}
                            />
                            Git {humanize(gitStatus)}
                        </Badge>
                    </div>
                </div>
            </header>

            <div className="execution-command-body">
                <div className="execution-workflow-panel">
                    <div className="execution-workflow-panel__label">
                        <span>Workflow Agents</span>
                        <span className="text-muted-foreground">
                            PM → Coder → Reviewer
                        </span>
                    </div>

                    <RoadmapActionsBar
                        projectId={projectId}
                        roadmap={pageProject?.roadmaps[0] ?? null}
                    />

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
                                    active={
                                        worker.id === activeWorkerId ||
                                        worker.status === 'working'
                                    }
                                    index={index + 1}
                                    projectStatus={projectStatus}
                                    currentTime={currentTime}
                                    fallbackTask={fallbackTaskForRole(
                                        role,
                                        tasks,
                                    )}
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
                                            reverse={coderToReviewerReverse}
                                        />
                                    )}
                                    {node}
                                </Fragment>
                            );
                        })}
                    </div>

                    <ExecutionContextPanel
                        tasks={tasks}
                        workflow={workflow}
                        workers={displayedWorkers}
                        completed={taskProgress.completed}
                        total={taskProgress.total}
                    />
                </div>

                <aside
                    className="execution-operations-panel"
                    aria-label="Operational intelligence"
                >
                    <RoadmapProgressCard
                        projectId={projectId}
                        roadmap={pageProject?.roadmaps[0] ?? null}
                        tasks={tasks}
                        completed={taskProgress.completed}
                        total={taskProgress.total}
                    />

                    <div className="execution-operations-pair">
                        <CurrentOperationCard
                            projectId={projectId}
                            workflow={workflow}
                            workers={displayedWorkers}
                        />

                        <NextStageCard
                            workflow={workflow}
                            projectStatus={projectStatus}
                        />
                    </div>

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

                    <TokenUsageCard
                        projectId={projectId}
                        total={pageProject?.token_usage_total ?? 0}
                        harnessUsage={pageProject?.harness_usage ?? {}}
                        observability={pageProject?.token_observability ?? {}}
                        evidence={
                            pageProject?.token_usage_evidence ?? {
                                window: { key: 'all', label: 'All time' },
                                run_count: 0,
                                token_usage_run_count: 0,
                                total_processed_tokens: 0,
                                average_tokens_per_run: null,
                                legacy_incomplete_run_count: 0,
                                legacy_token_usage: 0,
                            }
                        }
                    />

                    <HealthCard
                        errors={Array.from(healthErrors)}
                        warnings={Array.from(healthWarnings)}
                        blocked={blockedWorkerCount}
                    />
                </aside>
            </div>
        </section>
    );
}
