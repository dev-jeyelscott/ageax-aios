import { Link } from '@inertiajs/react';
import { Activity, AlertTriangle, CheckCircle2, CircleDot } from 'lucide-react';
import { useMemo } from 'react';
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

type OfficePresentation = {
    label: string;
    dotClass: string;
    textClass: string;
    ringClass: string;
};

const roleLabels: Record<string, string> = {
    project_manager: 'Project Manager',
    coder: 'Coder',
    reviewer: 'Reviewer',
};

const preferredRoleOrder = ['project_manager', 'coder', 'reviewer'] as const;

const roleThumbnails: Record<string, { idle: string; active: string }> = {
    project_manager: {
        idle: '/action-gif/pm-idle.gif',
        active: '/action-gif/pm-thinking.gif',
    },
    coder: {
        idle: '/action-gif/coder-idle.gif',
        active: '/action-gif/coder-coding.gif',
    },
    reviewer: {
        idle: '/action-gif/reviewer-idle.gif',
        active: '/action-gif/reviewer-reviewing.gif',
    },
};

const implementationStatuses = new Set([
    'coding',
    'validating',
    'changes_required',
]);

const reviewStatuses = new Set(['ready_for_review', 'reviewing']);

const attentionStatuses = new Set(['blocked', 'interrupted', 'failed']);

export function officePresentation(status: string): OfficePresentation {
    switch (status) {
        case 'working':
            return {
                label: 'Working',
                dotClass: 'bg-success',
                textClass: 'text-success-foreground',
                ringClass: 'border-primary/45 shadow-glow-md',
            };
        case 'recovering':
            return {
                label: 'Recovering',
                dotClass: 'bg-warning',
                textClass: 'text-warning-foreground',
                ringClass: 'border-warning/45',
            };
        case 'interrupted':
        case 'failed':
        case 'blocked':
            return {
                label: 'Needs attention',
                dotClass: 'bg-destructive',
                textClass: 'text-destructive-foreground',
                ringClass: 'border-destructive/45',
            };
        case 'idle':
            return {
                label: 'Available',
                dotClass: 'bg-muted-foreground',
                textClass: 'text-muted-foreground',
                ringClass: 'border-border',
            };
        default:
            return {
                label: 'Status unavailable',
                dotClass: 'bg-muted-foreground/70',
                textClass: 'text-muted-foreground',
                ringClass: 'border-border',
            };
    }
}

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
            return harness.replaceAll('_', ' ');
    }
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

    for (const worker of workers) {
        if (selected.length === preferredRoleOrder.length) {
            break;
        }

        if (!claimedIds.has(worker.id)) {
            selected.push(worker);
            claimedIds.add(worker.id);
        }
    }

    return selected.slice(0, preferredRoleOrder.length);
}

function thumbnailForWorker(worker: OfficeWorker): string | null {
    const thumbnails = roleThumbnails[worker.role];

    if (!thumbnails) {
        return null;
    }

    const active = ['working', 'recovering', 'reviewing'].includes(
        worker.status,
    );

    return active ? thumbnails.active : thumbnails.idle;
}

function messageForWorker(worker: OfficeWorker): string | null {
    if (worker.run?.latest_message) {
        return worker.run.latest_message;
    }

    if (worker.run?.failure_reason) {
        return worker.run.failure_reason;
    }

    if (worker.status === 'recovering') {
        return 'Recovering the current execution from durable AIOS evidence.';
    }

    if (worker.activity_mode === 'current' && worker.task) {
        return `${labelForRole(worker.role)} is working on ${worker.task.key}.`;
    }

    if (
        worker.activity_mode === 'recent' &&
        worker.run?.status === 'completed' &&
        worker.task
    ) {
        return `Completed ${worker.task.key}.`;
    }

    return null;
}

function EmptyAgentNode({ role }: { role: string }) {
    return (
        <article className="relative min-h-56 rounded-2xl border border-dashed border-border bg-surface-recessed/40 p-4">
            <div className="flex h-full min-h-48 flex-col items-center justify-center text-center">
                <div className="grid size-16 place-items-center rounded-2xl border border-border bg-background/70 font-mono text-xs text-muted-foreground">
                    {labelForRole(role)
                        .split(' ')
                        .map((part) => part[0])
                        .join('')}
                </div>
                <p className="mt-3 text-sm font-medium text-foreground">
                    {labelForRole(role)}
                </p>
                <p className="mt-1 text-xs text-muted-foreground">
                    Worker not provisioned
                </p>
            </div>
        </article>
    );
}

function AgentNode({
    projectId,
    worker,
    agent,
    active,
}: {
    projectId: number;
    worker: OfficeWorker;
    agent: OfficeAgent | undefined;
    active: boolean;
}) {
    const presentation = officePresentation(worker.status);
    const thumbnail = thumbnailForWorker(worker);
    const message = messageForWorker(worker);
    const taskLabel =
        worker.activity_mode === 'current' ? 'Current task' : 'Recent task';

    return (
        <article
            className={`relative min-w-0 overflow-hidden rounded-2xl border bg-card/55 p-3 shadow-panel transition ${
                active
                    ? 'agent-card-active border-primary/35'
                    : 'border-border/70'
            }`}
        >
            <div className="glow-line-accent opacity-50" />

            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="font-mono text-2xs tracking-[0.15em] text-primary uppercase">
                        {labelForRole(worker.role)}
                    </p>
                    <h3 className="mt-0.5 truncate text-sm font-semibold text-foreground">
                        {agent?.name ?? 'Unbound agent'}
                    </h3>
                </div>

                <div
                    className={`flex shrink-0 items-center gap-1.5 rounded-full border border-border/70 bg-background/70 px-2 py-1 font-mono text-2xs ${presentation.textClass}`}
                    title={`Worker status: ${worker.status}`}
                >
                    <span
                        className={`size-1.5 rounded-full ${presentation.dotClass} ${
                            active ? 'status-glow-pulse' : ''
                        }`}
                    />
                    {presentation.label}
                </div>
            </div>

            <div className="relative mt-3 min-h-32 rounded-xl border border-border-subtle bg-surface-sunken/80 p-3">
                <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_50%_40%,color-mix(in_oklch,var(--primary)_13%,transparent),transparent_62%)]" />

                <div className="relative flex min-h-28 items-end gap-3">
                    <div
                        className={`relative grid size-20 shrink-0 place-items-center overflow-hidden rounded-2xl border bg-background/80 ${presentation.ringClass}`}
                    >
                        {thumbnail ? (
                            <img
                                src={thumbnail}
                                alt=""
                                aria-hidden="true"
                                className="h-full w-full object-cover object-top"
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

                    <div className="min-w-0 flex-1 self-center">
                        {message ? (
                            <div
                                aria-live={active ? 'polite' : 'off'}
                                className={`relative rounded-2xl rounded-bl-md border px-3 py-2 text-xs leading-relaxed ${
                                    active
                                        ? 'border-primary/30 bg-primary/8 text-foreground shadow-glow-sm'
                                        : 'border-border-subtle bg-background/65 text-muted-foreground'
                                }`}
                            >
                                <span
                                    aria-hidden="true"
                                    className={`absolute bottom-2 -left-1.5 size-3 rotate-45 border-b border-l bg-inherit ${
                                        active
                                            ? 'border-primary/30'
                                            : 'border-border-subtle'
                                    }`}
                                />
                                <p className="relative line-clamp-3">
                                    {message}
                                </p>
                            </div>
                        ) : (
                            <p className="text-xs text-muted-foreground">
                                {active
                                    ? 'Execution active. Waiting for the next agent message…'
                                    : 'No live message.'}
                            </p>
                        )}
                    </div>
                </div>
            </div>

            <div className="mt-3 min-w-0 rounded-xl border border-border-subtle bg-foreground/2 px-3 py-2">
                <p className="font-mono text-2xs text-muted-foreground uppercase">
                    {worker.task ? taskLabel : 'Task'}
                </p>

                {worker.task ? (
                    <Link
                        href={
                            showTask({
                                project: projectId,
                                task: worker.task.id,
                            }).url
                        }
                        className="mt-1 block truncate text-xs font-medium text-foreground transition hover:text-primary focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                    >
                        <span className="font-mono text-primary">
                            {worker.task.key}
                        </span>{' '}
                        · {worker.task.title}
                    </Link>
                ) : (
                    <p className="mt-1 text-xs text-muted-foreground">
                        No task currently assigned
                    </p>
                )}
            </div>

            <div className="mt-2 flex min-w-0 items-center justify-between gap-2 text-2xs text-muted-foreground">
                <span className="truncate">
                    {agent
                        ? `${labelForHarness(agent.harness)} · ${agent.model ?? 'default model'}`
                        : 'No agent configuration'}
                </span>

                {worker.run && (
                    <Link
                        href={
                            showAgentRun({
                                project: projectId,
                                run: worker.run.id,
                            }).url
                        }
                        className="shrink-0 font-mono text-primary hover:text-primary/80"
                    >
                        Run #{worker.run.id}
                    </Link>
                )}
            </div>
        </article>
    );
}

function WorkflowConnector({
    active,
    label,
}: {
    active: boolean;
    label: string;
}) {
    return (
        <div
            aria-label={`${label} ${active ? 'active' : 'inactive'}`}
            className="relative mx-auto flex h-12 w-12 shrink-0 items-center justify-center lg:h-auto lg:w-full"
        >
            <div className="relative h-px w-12 rotate-90 lg:w-full lg:rotate-0">
                <span className="absolute inset-0 rounded-full bg-border" />
                {active && (
                    <span className="pipeline-flow absolute inset-0 rounded-full" />
                )}
                <span
                    aria-hidden="true"
                    className={`absolute top-1/2 -right-px size-2 -translate-y-1/2 rotate-45 border-t border-r ${
                        active
                            ? 'border-primary shadow-glow-sm'
                            : 'border-border'
                    }`}
                />
            </div>

            <span
                className={`absolute top-1/2 left-1/2 hidden -translate-x-1/2 translate-y-2.5 rounded-full border px-2 py-0.5 font-mono text-[9px] tracking-[0.08em] whitespace-nowrap uppercase lg:block ${
                    active
                        ? 'border-primary/25 bg-background text-primary'
                        : 'border-border-subtle bg-background/80 text-muted-foreground'
                }`}
            >
                {active ? 'handoff active' : label}
            </span>
        </div>
    );
}

function nextStageFor(workflow: OfficeWorkflow | null): string {
    if (workflow?.mode !== 'current') {
        return 'Waiting for eligible work';
    }

    switch (workflow.role) {
        case 'project_manager':
            return 'Coder implementation';
        case 'coder':
            return 'Reviewer handoff';
        case 'reviewer':
            return 'AIOS completion decision';
        default:
            return 'AIOS-controlled next stage';
    }
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

    const currentWorkflow = workflow?.mode === 'current' ? workflow : null;
    const workflowStatus = currentWorkflow?.task?.status;
    const pmToCoderActive =
        currentWorkflow?.role === 'coder' ||
        (workflowStatus !== undefined &&
            implementationStatuses.has(workflowStatus));
    const coderToReviewerActive =
        currentWorkflow?.role === 'reviewer' ||
        (workflowStatus !== undefined && reviewStatuses.has(workflowStatus));
    const activeWorkerId = currentWorkflow?.worker_id ?? null;
    const progress =
        taskProgress.total === 0
            ? 0
            : Math.round((taskProgress.completed / taskProgress.total) * 100);
    const needsAttention = displayedWorkers.some(
        (worker) =>
            attentionStatuses.has(worker.status) ||
            worker.run?.status === 'failed' ||
            Boolean(worker.run?.failure_reason),
    );
    const currentOperation = workflow?.task
        ? `${workflow.task.key}: ${workflow.task.title}`
        : workflow
          ? `${labelForRole(workflow.role)} · Run #${workflow.run_id}`
          : taskProgress.total > 0 &&
              taskProgress.completed === taskProgress.total
            ? 'Roadmap execution complete'
            : 'No active execution';

    return (
        <section
            data-aios-execution-office="true"
            aria-labelledby="agent-office-title"
            className="relative flex min-h-full flex-col overflow-hidden rounded-2xl border border-primary/15 bg-background/95 text-foreground shadow-panel"
        >
            <div className="pointer-events-none absolute -top-28 left-1/4 size-64 rounded-full bg-primary/8 blur-3xl" />
            <div className="pointer-events-none absolute -right-24 bottom-0 size-64 rounded-full bg-secondary/15 blur-3xl" />

            <header className="relative shrink-0 border-b border-primary/10 bg-[linear-gradient(110deg,var(--sidebar),var(--background),color-mix(in_oklch,var(--primary)_15%,transparent))] px-4 py-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2 font-mono text-2xs tracking-[0.18em] text-primary uppercase">
                            <Activity className="size-3.5" aria-hidden="true" />
                            Live execution
                        </div>
                        <h2
                            id="agent-office-title"
                            className="mt-1 truncate text-base font-semibold tracking-tight"
                        >
                            PM → Coder → Reviewer
                        </h2>
                        <p className="mt-0.5 truncate text-xs text-muted-foreground">
                            {projectName} · AIOS-controlled deterministic
                            workflow
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-1.5">
                        <Badge
                            variant="outline"
                            className="border-success/20 bg-success/5 font-mono text-2xs text-success-foreground"
                        >
                            <CircleDot className="mr-1 size-3" />
                            {projectStatus}
                        </Badge>
                        <Badge
                            variant="outline"
                            className="border-primary/20 bg-primary/5 font-mono text-2xs text-primary"
                        >
                            Git · {gitStatus}
                        </Badge>
                    </div>
                </div>
            </header>

            <div className="relative flex flex-1 flex-col p-3 sm:p-4">
                <div className="grid flex-1 items-center gap-1 lg:grid-cols-[minmax(0,1fr)_minmax(4rem,7rem)_minmax(0,1fr)_minmax(4rem,7rem)_minmax(0,1fr)] lg:gap-3">
                    {preferredRoleOrder.map((role, index) => {
                        const worker = workerByRole.get(role);
                        const node = worker ? (
                            <AgentNode
                                key={worker.id}
                                projectId={projectId}
                                worker={worker}
                                agent={agentByWorkerId.get(worker.id)}
                                active={worker.id === activeWorkerId}
                            />
                        ) : (
                            <EmptyAgentNode key={role} role={role} />
                        );

                        if (index === 0) {
                            return node;
                        }

                        const connector =
                            index === 1 ? (
                                <WorkflowConnector
                                    key="pm-coder-connector"
                                    active={pmToCoderActive}
                                    label="PM to Coder"
                                />
                            ) : (
                                <WorkflowConnector
                                    key="coder-reviewer-connector"
                                    active={coderToReviewerActive}
                                    label="Coder to Reviewer"
                                />
                            );

                        return [connector, node];
                    })}
                </div>

                <div className="mt-4 grid shrink-0 gap-2 border-t border-border-subtle pt-3 md:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)_auto]">
                    <div className="min-w-0 rounded-xl border border-border-subtle bg-foreground/2 px-3 py-2">
                        <p className="font-mono text-2xs text-muted-foreground uppercase">
                            Current operation
                        </p>
                        <p className="mt-1 truncate text-xs font-medium text-foreground">
                            {currentOperation}
                        </p>
                    </div>

                    <div className="min-w-0 rounded-xl border border-border-subtle bg-foreground/2 px-3 py-2">
                        <p className="font-mono text-2xs text-muted-foreground uppercase">
                            Next stage
                        </p>
                        <p className="mt-1 truncate text-xs font-medium text-foreground">
                            {nextStageFor(workflow)}
                        </p>
                    </div>

                    <div className="rounded-xl border border-border-subtle bg-foreground/2 px-3 py-2 md:min-w-44">
                        <div className="flex items-center justify-between gap-3">
                            <p className="font-mono text-2xs text-muted-foreground uppercase">
                                Roadmap
                            </p>
                            <span className="font-mono text-2xs text-primary">
                                {progress}%
                            </span>
                        </div>
                        <div className="mt-1.5 h-1 overflow-hidden rounded-full bg-muted">
                            <div
                                className="progress-flow h-full rounded-full transition-[width]"
                                style={{ width: `${progress}%` }}
                            />
                        </div>
                        <p className="mt-1 text-2xs text-muted-foreground">
                            {taskProgress.completed}/{taskProgress.total}{' '}
                            complete
                        </p>
                    </div>
                </div>

                {needsAttention && (
                    <div className="mt-2 flex items-center gap-2 rounded-xl border border-destructive/20 bg-destructive/8 px-3 py-2 text-xs text-destructive-foreground">
                        <AlertTriangle className="size-3.5 shrink-0" />
                        One or more workflow agents require operator attention.
                    </div>
                )}

                {!needsAttention && currentWorkflow === null && (
                    <div className="mt-2 flex items-center gap-2 rounded-xl border border-success/15 bg-success/5 px-3 py-2 text-xs text-success-foreground">
                        <CheckCircle2 className="size-3.5 shrink-0" />
                        Workflow is healthy and waiting for the next
                        AIOS-controlled operation.
                    </div>
                )}
            </div>
        </section>
    );
}
