import { Link } from '@inertiajs/react';
import { Activity, Bot, CircleDot, Radio } from 'lucide-react';
import { useMemo } from 'react';
import {
    showAgentRun,
    showTask,
} from '@/actions/App/Http/Controllers/ProjectController';
import { AgeaxRobotVisual } from '@/components/ageax-robot';
import type { RobotAnimationState } from '@/components/ageax-robot';
import { Badge } from '@/components/ui/badge';

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
    pulseClass: string;
};

const roleLabels: Record<string, string> = {
    project_manager: 'Project Manager',
    coder: 'Coder',
    reviewer: 'Reviewer',
};

const preferredRoleOrder = ['project_manager', 'coder', 'reviewer'];

const workflowStages = [
    { key: 'queued', label: 'Queued' },
    { key: 'coding', label: 'Coding' },
    { key: 'validating', label: 'Validating' },
    { key: 'review', label: 'Review' },
    { key: 'done', label: 'Done' },
];

const exceptionalWorkflowStatuses = new Set([
    'blocked',
    'interrupted',
    'failed',
]);

export function officePresentation(status: string): OfficePresentation {
    switch (status) {
        case 'working':
            return {
                label: 'Working',
                dotClass: 'bg-success',
                textClass: 'text-success-foreground',
                pulseClass: 'status-glow-pulse text-success',
            };
        case 'recovering':
            return {
                label: 'Recovering',
                dotClass: 'bg-warning',
                textClass: 'text-warning-foreground',
                pulseClass: 'status-glow-pulse text-warning',
            };
        case 'interrupted':
            return {
                label: 'Needs attention',
                dotClass: 'bg-destructive',
                textClass: 'text-destructive-foreground',
                pulseClass: 'status-glow-pulse text-destructive',
            };
        case 'idle':
            return {
                label: 'Available',
                dotClass: 'bg-muted-foreground',
                textClass: 'text-muted-foreground',
                pulseClass: '',
            };
        default:
            return {
                label: 'Status unavailable',
                dotClass: 'bg-muted-foreground/70',
                textClass: 'text-muted-foreground',
                pulseClass: '',
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

function workflowStageIndex(status: string | undefined): number {
    switch (status) {
        case 'queued':
            return 0;
        case 'coding':
        case 'changes_required':
            return 1;
        case 'validating':
            return 2;
        case 'ready_for_review':
        case 'reviewing':
            return 3;
        case 'done':
            return 4;
        default:
            return -1;
    }
}

export function robotAnimationStateFor(
    worker: OfficeWorker,
): RobotAnimationState {
    switch (worker.status) {
        case 'working':
            if (worker.role === 'project_manager') {
                return 'thinking';
            }

            if (worker.role === 'reviewer') {
                return 'reviewing';
            }

            return 'working';
        case 'thinking':
        case 'recovering':
            return 'thinking';
        case 'reviewing':
            return 'reviewing';
        case 'success':
        case 'completed':
            return 'success';
        case 'failed':
            return 'failed';
        case 'interrupted':
        case 'blocked':
            return 'interrupted';
    }

    if (worker.run?.status === 'failed') {
        return 'failed';
    }

    if (worker.run?.status === 'interrupted') {
        return 'interrupted';
    }

    if (
        worker.activity_mode === 'recent' &&
        worker.run?.status === 'completed'
    ) {
        return 'success';
    }

    return 'idle';
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

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : 'Not recorded';
}

function AgentVisualPanel({ worker }: { worker: OfficeWorker }) {
    const roleLabel = labelForRole(worker.role);
    const animationState = robotAnimationStateFor(worker);

    return (
        <div className="visual-stage relative h-full overflow-hidden rounded-lg border border-primary/10">
            <div className="pointer-events-none absolute inset-x-8 bottom-0 h-8 rounded-full bg-primary/5 blur-xl" />

            <AgeaxRobotVisual
                role={worker.role}
                state={animationState}
                label={`${roleLabel} AGEAX robot in ${animationState} presentation state from worker status ${worker.status}.`}
            />
        </div>
    );
}

function AgentCard({
    projectId,
    worker,
    agent,
}: {
    projectId: number;
    worker: OfficeWorker;
    agent: OfficeAgent | undefined;
}) {
    const presentation = officePresentation(worker.status);
    const taskLabel =
        worker.activity_mode === 'current' ? 'Current task' : 'Recent task';

    return (
        <article
            className={`panel-elevated relative flex h-full min-h-0 min-w-0 flex-col overflow-hidden p-2.5 ${
                worker.status === 'working' ? 'agent-card-active' : ''
            }`}
        >
            <div className="glow-edge glow-line-accent" />

            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="font-mono text-2xs tracking-[0.16em] text-primary uppercase">
                        {labelForRole(worker.role)}
                    </p>

                    <h3 className="mt-0.5 truncate text-sm font-semibold text-foreground">
                        {agent?.name ?? 'Unbound agent'}
                    </h3>
                </div>

                <span
                    className={`mt-1 size-2 shrink-0 rounded-full ${presentation.dotClass} ${presentation.pulseClass}`}
                    title={presentation.label}
                />
            </div>

            <div className="mt-2 min-h-0 flex-1">
                <AgentVisualPanel worker={worker} />
            </div>

            <div className="mt-2 grid grid-cols-2 gap-1.5 text-2xs">
                <div className="tile-inset px-2 py-1">
                    <p className="text-muted-foreground">Harness</p>
                    <p className="mt-0.5 truncate font-medium text-primary/80">
                        {agent ? labelForHarness(agent.harness) : '—'}
                    </p>
                </div>

                <div className="tile-inset px-2 py-1">
                    <p className="text-muted-foreground">Model</p>
                    <p className="mt-0.5 truncate font-medium text-foreground">
                        {agent?.model ?? 'Harness default'}
                    </p>
                </div>

                <div className="tile-inset px-2 py-1">
                    <p className="text-muted-foreground">Worker</p>
                    <p
                        className={`mt-0.5 font-medium ${presentation.textClass}`}
                    >
                        {worker.status}
                    </p>
                </div>

                <div className="tile-inset px-2 py-1">
                    <p className="text-muted-foreground">Lease</p>
                    <p className="mt-0.5 font-medium text-foreground capitalize">
                        {worker.lease_state}
                    </p>
                </div>
            </div>

            <div className="panel-recessed mt-2 min-h-10 p-2">
                {worker.task ? (
                    <Link
                        href={
                            showTask({
                                project: projectId,
                                task: worker.task.id,
                            }).url
                        }
                        className="block focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                    >
                        <p className="font-mono text-2xs text-primary uppercase">
                            {taskLabel} · {worker.task.status}
                        </p>

                        <p className="mt-0.5 truncate text-xs font-medium text-foreground">
                            <span className="font-mono">{worker.task.key}</span>
                            : {worker.task.title}
                        </p>
                    </Link>
                ) : (
                    <p className="text-xs text-muted-foreground">
                        No task currently assigned.
                    </p>
                )}
            </div>

            <div className="mt-1.5 flex items-center justify-between gap-2 text-2xs text-muted-foreground">
                <span title={formatDate(worker.last_heartbeat_at)}>
                    Heartbeat {worker.last_heartbeat_at ? 'recorded' : '—'}
                </span>

                {worker.run ? (
                    <Link
                        href={
                            showAgentRun({
                                project: projectId,
                                run: worker.run.id,
                            }).url
                        }
                        className="font-mono text-secondary-foreground hover:text-secondary-foreground/80"
                    >
                        Run #{worker.run.id}
                    </Link>
                ) : (
                    <span>No run</span>
                )}
            </div>

            {worker.run?.failure_reason && (
                <p className="mt-2 line-clamp-2 rounded-md border border-destructive/20 bg-destructive/10 px-2 py-1.5 text-2xs text-destructive-foreground">
                    {worker.run.failure_reason}
                </p>
            )}
        </article>
    );
}

function Metric({
    label,
    value,
    tone,
}: {
    label: string;
    value: number;
    tone: string;
}) {
    return (
        <div className="flex min-w-24 items-center gap-2 rounded-lg border border-border-subtle bg-background/40 px-2.5 py-1">
            <span className={`size-1.5 rounded-full ${tone}`} />

            <div>
                <p className="text-sm leading-none font-semibold text-foreground">
                    {value}
                </p>

                <p className="mt-1 font-mono text-2xs tracking-wide text-muted-foreground uppercase">
                    {label}
                </p>
            </div>
        </div>
    );
}

function WorkflowPipeline({
    workflow,
    taskProgress,
}: {
    workflow: OfficeWorkflow | null;
    taskProgress: {
        completed: number;
        total: number;
    };
}) {
    const isComplete =
        taskProgress.total > 0 && taskProgress.completed === taskProgress.total;
    const workflowTask = workflow?.task ?? null;
    const activeIndex = workflowTask
        ? workflowStageIndex(workflowTask.status)
        : workflow === null && isComplete
          ? workflowStages.length - 1
          : -1;
    const trackFillPercent =
        activeIndex <= 0
            ? 0
            : (Math.min(activeIndex, workflowStages.length - 1) /
                  (workflowStages.length - 1)) *
              100;
    const hasWorkflowException = workflowTask
        ? exceptionalWorkflowStatuses.has(workflowTask.status)
        : false;
    const operationLabel =
        workflow?.mode === 'recent' ? 'Recent operation' : 'Current operation';

    return (
        <div className="panel-recessed px-3 py-2.5">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <p className="font-mono text-2xs tracking-[0.15em] text-secondary-foreground uppercase">
                        Workflow pipeline
                    </p>

                    <p className="mt-0.5 text-xs text-muted-foreground">
                        AIOS-controlled deterministic lifecycle
                    </p>
                </div>

                <span className="font-mono text-2xs text-muted-foreground">
                    {taskProgress.completed}/{taskProgress.total} done
                </span>
            </div>

            <div className="relative mt-2.5">
                <div className="absolute top-2.5 right-[9%] left-[9%] h-px overflow-hidden rounded-full bg-border">
                    <div
                        className="pipeline-flow h-full rounded-full"
                        style={{ width: `${trackFillPercent}%` }}
                    />
                </div>

                <div className="relative grid grid-cols-5 gap-1">
                    {workflowStages.map((stage, index) => {
                        const active = index === activeIndex;
                        const passed = activeIndex > index;

                        return (
                            <div
                                key={stage.key}
                                className="flex min-w-0 flex-col items-center"
                            >
                                <span
                                    className={`z-10 rounded-full border transition-[width,height] ${
                                        active
                                            ? 'glow-border size-6 border-primary bg-primary shadow-glow-lg'
                                            : passed
                                              ? 'size-5 border-success/70 bg-success/60'
                                              : 'size-5 border-border bg-background'
                                    }`}
                                />

                                <span
                                    className={`mt-1.5 truncate font-mono text-2xs uppercase ${
                                        active
                                            ? 'text-primary'
                                            : passed
                                              ? 'text-success-foreground'
                                              : 'text-muted-foreground'
                                    }`}
                                >
                                    {stage.label}
                                </span>
                            </div>
                        );
                    })}
                </div>

                {hasWorkflowException && workflowTask && (
                    <p className="mt-1.5 text-center font-mono text-2xs text-destructive-foreground uppercase">
                        Workflow exception · {workflowTask.status}
                    </p>
                )}
            </div>

            <div className="mt-2.5 flex min-w-0 items-center justify-between gap-3 border-t border-border-subtle pt-2">
                <div className="min-w-0">
                    <p className="text-2xs text-muted-foreground uppercase">
                        {workflow ? operationLabel : 'Workflow operation'}
                    </p>

                    <p className="truncate text-xs font-medium text-foreground">
                        {workflowTask
                            ? `${workflowTask.key}: ${workflowTask.title}`
                            : workflow
                              ? `${labelForRole(workflow.role)} run #${workflow.run_id} has no task association`
                              : isComplete
                                ? 'Roadmap execution complete'
                                : 'No current or recent task execution'}
                    </p>

                    {workflow && (
                        <p className="mt-0.5 truncate font-mono text-2xs text-muted-foreground">
                            {labelForRole(workflow.role)} · Run #
                            {workflow.run_id}
                        </p>
                    )}
                </div>

                {workflowTask && (
                    <Badge
                        variant={
                            hasWorkflowException ? 'destructive' : 'outline'
                        }
                        className={
                            hasWorkflowException
                                ? 'shrink-0 font-mono text-2xs'
                                : 'shrink-0 border-primary/20 bg-primary/5 font-mono text-2xs text-primary'
                        }
                    >
                        {workflowTask.status}
                    </Badge>
                )}
            </div>
        </div>
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
    const displayedWorkers = useMemo(
        () => selectOfficeWorkers(workers),
        [workers],
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

    const workingWorkers = displayedWorkers.filter(
        (worker) => worker.status === 'working',
    ).length;

    const boundWorkers = displayedWorkers.filter((worker) =>
        agentByWorkerId.has(worker.id),
    ).length;

    const attentionWorkers = displayedWorkers.filter((worker) =>
        ['recovering', 'interrupted'].includes(worker.status),
    ).length;

    return (
        <section
            aria-labelledby="agent-office-title"
            className="relative flex h-full min-h-0 flex-col overflow-hidden rounded-2xl border border-primary/15 bg-background/95 text-foreground shadow-panel"
        >
            <div className="pointer-events-none absolute -top-24 -left-16 size-56 animate-pulse rounded-full bg-primary/8 blur-3xl motion-reduce:animate-none" />

            <div className="pointer-events-none absolute -right-24 bottom-0 size-64 animate-pulse rounded-full bg-secondary/20 blur-3xl motion-reduce:animate-none" />

            <header className="relative shrink-0 border-b border-primary/10 bg-[linear-gradient(110deg,var(--sidebar),var(--background),color-mix(in_oklch,var(--primary)_18%,transparent))] px-4 py-2.5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2 font-mono text-2xs tracking-[0.18em] text-primary uppercase">
                            <Activity className="size-3.5" aria-hidden="true" />
                            Live operations
                        </div>

                        <h2
                            id="agent-office-title"
                            className="mt-0.5 truncate text-base font-semibold tracking-tight text-foreground"
                        >
                            AI Engineering Office
                        </h2>

                        <p className="mt-0.5 truncate text-xs text-muted-foreground">
                            {projectName} · AIOS controlled execution
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
                            className="border-secondary/30 bg-secondary/10 font-mono text-2xs text-secondary-foreground"
                        >
                            Git · {gitStatus}
                        </Badge>
                    </div>
                </div>

                <div className="mt-2 flex flex-wrap gap-1.5">
                    <Metric
                        label="working"
                        value={workingWorkers}
                        tone="bg-success"
                    />

                    <Metric
                        label="bound"
                        value={boundWorkers}
                        tone="bg-primary"
                    />

                    <Metric
                        label="attention"
                        value={attentionWorkers}
                        tone="bg-destructive"
                    />
                </div>
            </header>

            <div className="relative flex min-h-0 flex-1 flex-col overflow-hidden p-2.5">
                {displayedWorkers.length > 0 ? (
                    <div className="grid min-h-0 flex-1 gap-2.5 md:grid-cols-3">
                        {displayedWorkers.map((worker) => (
                            <AgentCard
                                key={worker.id}
                                projectId={projectId}
                                worker={worker}
                                agent={agentByWorkerId.get(worker.id)}
                            />
                        ))}
                    </div>
                ) : (
                    <div className="grid min-h-32 flex-1 place-items-center rounded-xl border border-dashed border-border bg-background/40 text-center">
                        <div>
                            <Bot className="mx-auto size-10 text-muted-foreground" />

                            <p className="mt-2 text-sm text-muted-foreground">
                                No workflow workers are provisioned.
                            </p>
                        </div>
                    </div>
                )}

                <div className="mt-2.5 shrink-0">
                    <WorkflowPipeline
                        workflow={workflow}
                        taskProgress={taskProgress}
                    />
                </div>

                <div className="mt-2.5 grid shrink-0 gap-2 sm:grid-cols-3">
                    <div className="flex items-center gap-2 rounded-lg border border-border-subtle bg-foreground/2 px-3 py-1.5">
                        <Radio className="size-3.5 text-primary" />

                        <div>
                            <p className="font-mono text-2xs text-muted-foreground uppercase">
                                Worker lanes
                            </p>

                            <p className="text-xs text-foreground">
                                {displayedWorkers.length} visible
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2 rounded-lg border border-border-subtle bg-foreground/2 px-3 py-1.5">
                        <Bot className="size-3.5 text-secondary-foreground" />

                        <div>
                            <p className="font-mono text-2xs text-muted-foreground uppercase">
                                Agents
                            </p>

                            <p className="text-xs text-foreground">
                                {boundWorkers} bound
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2 rounded-lg border border-border-subtle bg-foreground/2 px-3 py-1.5">
                        <CircleDot className="size-3.5 text-success-foreground" />

                        <div>
                            <p className="font-mono text-2xs text-muted-foreground uppercase">
                                Roadmap
                            </p>

                            <p className="text-xs text-foreground">
                                {taskProgress.completed}/{taskProgress.total}{' '}
                                complete
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}
