import { Link } from '@inertiajs/react';
import { Activity, Bot, CircleDot, Radio } from 'lucide-react';
import { useMemo } from 'react';
import {
    showAgentRun,
    showTask,
} from '@/actions/App/Http/Controllers/ProjectController';
import { Badge } from '@/components/ui/badge';

export type OfficeWorker = {
    id: number;
    role: string;
    status: string;
    last_heartbeat_at: string | null;
    lease_state: 'active' | 'expired' | 'none';
    run: {
        id: number;
        status: string;
        attempt_number: number | null;
        started_at: string;
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

type OfficePresentation = {
    label: string;
    dotClass: string;
    textClass: string;
};

type RoleVisual = {
    idle: string;
    working: string;
    workingDescription: string;
};

type AgentVisual = {
    src: string;
    alt: string;
};

const roleLabels: Record<string, string> = {
    project_manager: 'Project Manager',
    coder: 'Coder',
    reviewer: 'Reviewer',
};

const roleVisuals: Record<string, RoleVisual> = {
    project_manager: {
        idle: '/action-gif/pm-idle.gif',
        working: '/action-gif/pm-thinking.gif',
        workingDescription: 'thinking and planning',
    },
    coder: {
        idle: '/action-gif/coder-idle.gif',
        working: '/action-gif/coder-coding.gif',
        workingDescription: 'coding',
    },
    reviewer: {
        idle: '/action-gif/reviewer-idle.gif',
        working: '/action-gif/reviewer-reviewing.gif',
        workingDescription: 'reviewing',
    },
};

const preferredRoleOrder = ['project_manager', 'coder', 'reviewer'];

const workflowStages = [
    { key: 'queued', label: 'Queued' },
    { key: 'coding', label: 'Coding' },
    { key: 'validating', label: 'Validating' },
    { key: 'review', label: 'Review' },
    { key: 'done', label: 'Done' },
];

export function officePresentation(status: string): OfficePresentation {
    switch (status) {
        case 'working':
            return {
                label: 'Working',
                dotClass: 'bg-emerald-400',
                textClass: 'text-emerald-300',
            };
        case 'recovering':
            return {
                label: 'Recovering',
                dotClass: 'bg-amber-400',
                textClass: 'text-amber-300',
            };
        case 'interrupted':
            return {
                label: 'Needs attention',
                dotClass: 'bg-rose-400',
                textClass: 'text-rose-300',
            };
        case 'idle':
            return {
                label: 'Available',
                dotClass: 'bg-slate-400',
                textClass: 'text-slate-300',
            };
        default:
            return {
                label: 'Status unavailable',
                dotClass: 'bg-slate-500',
                textClass: 'text-slate-400',
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

function workflowStageIndex(
    status: string | undefined,
    isComplete: boolean,
): number {
    if (isComplete) {
        return 4;
    }

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

export function agentVisualFor(
    role: string,
    status: string,
): AgentVisual | null {
    const visual = roleVisuals[role];

    if (!visual) {
        return null;
    }

    const isWorking = status === 'working';
    const roleLabel = labelForRole(role);

    return {
        src: isWorking ? visual.working : visual.idle,
        alt: isWorking
            ? `${roleLabel} ${visual.workingDescription} while working.`
            : `${roleLabel} idle visual for ${status} status.`,
    };
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
    const visual = agentVisualFor(worker.role, worker.status);
    const roleLabel = labelForRole(worker.role);

    return (
        <div className="relative h-28 overflow-hidden rounded-lg border border-cyan-300/10 bg-[radial-gradient(circle_at_50%_35%,rgba(34,211,238,0.14),transparent_48%),linear-gradient(145deg,#0b1424,#020617)]">
            <div className="pointer-events-none absolute inset-x-8 bottom-0 h-8 rounded-full bg-cyan-300/5 blur-xl" />

            {visual ? (
                <>
                    <img
                        src={visual.src}
                        alt={visual.alt}
                        className="h-full w-full object-contain p-1.5 motion-reduce:hidden"
                    />

                    <div
                        role="img"
                        aria-label={`${roleLabel} ${worker.status}. Animation disabled because reduced motion is requested.`}
                        className="hidden h-full w-full place-items-center text-center motion-reduce:grid"
                    >
                        <Bot
                            aria-hidden="true"
                            className="size-10 text-cyan-300"
                        />
                    </div>
                </>
            ) : (
                <div
                    role="img"
                    aria-label={`${roleLabel} has no role-specific animation.`}
                    className="grid h-full place-items-center text-center"
                >
                    <Bot
                        aria-hidden="true"
                        className="size-10 text-slate-500"
                    />
                </div>
            )}
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

    return (
        <article className="relative flex min-w-0 flex-col overflow-hidden rounded-xl border border-slate-700/70 bg-slate-950/70 p-3 shadow-[0_12px_40px_rgba(2,6,23,0.55)]">
            <div className="pointer-events-none absolute inset-x-4 top-0 h-px bg-gradient-to-r from-transparent via-cyan-300/50 to-transparent" />

            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="font-mono text-[10px] tracking-[0.16em] text-cyan-300 uppercase">
                        {labelForRole(worker.role)}
                    </p>

                    <h3 className="mt-1 truncate text-sm font-semibold text-white">
                        {agent?.name ?? 'Unbound agent'}
                    </h3>
                </div>

                <span
                    className={`mt-1 size-2 shrink-0 rounded-full ${presentation.dotClass} ${
                        worker.status === 'working'
                            ? 'animate-pulse motion-reduce:animate-none'
                            : ''
                    }`}
                    title={presentation.label}
                />
            </div>

            <div className="mt-2.5">
                <AgentVisualPanel worker={worker} />
            </div>

            <div className="mt-2.5 grid grid-cols-2 gap-1.5 text-[11px]">
                <div className="rounded-md border border-white/8 bg-white/[0.025] px-2 py-1.5">
                    <p className="text-slate-500">Harness</p>
                    <p className="mt-0.5 truncate font-medium text-cyan-100">
                        {agent ? labelForHarness(agent.harness) : '—'}
                    </p>
                </div>

                <div className="rounded-md border border-white/8 bg-white/[0.025] px-2 py-1.5">
                    <p className="text-slate-500">Model</p>
                    <p className="mt-0.5 truncate font-medium text-slate-200">
                        {agent?.model ?? 'Harness default'}
                    </p>
                </div>

                <div className="rounded-md border border-white/8 bg-white/[0.025] px-2 py-1.5">
                    <p className="text-slate-500">Worker</p>
                    <p
                        className={`mt-0.5 font-medium ${presentation.textClass}`}
                    >
                        {worker.status}
                    </p>
                </div>

                <div className="rounded-md border border-white/8 bg-white/[0.025] px-2 py-1.5">
                    <p className="text-slate-500">Lease</p>
                    <p className="mt-0.5 font-medium text-slate-200 capitalize">
                        {worker.lease_state}
                    </p>
                </div>
            </div>

            <div className="mt-2.5 min-h-12 rounded-lg border border-white/8 bg-slate-900/60 p-2.5">
                {worker.task ? (
                    <Link
                        href={
                            showTask({
                                project: projectId,
                                task: worker.task.id,
                            }).url
                        }
                        className="block focus-visible:ring-2 focus-visible:ring-cyan-300 focus-visible:outline-none"
                    >
                        <p className="font-mono text-[10px] text-cyan-300 uppercase">
                            Current task · {worker.task.status}
                        </p>

                        <p className="mt-1 truncate text-xs font-medium text-slate-100">
                            {worker.task.key}: {worker.task.title}
                        </p>
                    </Link>
                ) : (
                    <p className="text-xs text-slate-500">
                        No task currently assigned.
                    </p>
                )}
            </div>

            <div className="mt-2 flex items-center justify-between gap-2 text-[10px] text-slate-500">
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
                        className="font-mono text-violet-300 hover:text-violet-200"
                    >
                        Run #{worker.run.id}
                    </Link>
                ) : (
                    <span>No run</span>
                )}
            </div>

            {worker.run?.failure_reason && (
                <p className="mt-2 line-clamp-2 rounded-md border border-rose-400/20 bg-rose-400/10 px-2 py-1.5 text-[10px] text-rose-200">
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
        <div className="flex min-w-24 items-center gap-2 rounded-lg border border-white/8 bg-black/20 px-2.5 py-1.5">
            <span className={`size-1.5 rounded-full ${tone}`} />

            <div>
                <p className="text-sm leading-none font-semibold text-white">
                    {value}
                </p>

                <p className="mt-1 font-mono text-[9px] tracking-wide text-slate-500 uppercase">
                    {label}
                </p>
            </div>
        </div>
    );
}

function WorkflowPipeline({
    currentTask,
    taskProgress,
}: {
    currentTask: OfficeTask | null;
    taskProgress: {
        completed: number;
        total: number;
    };
}) {
    const isComplete =
        taskProgress.total > 0 && taskProgress.completed === taskProgress.total;

    const activeIndex = workflowStageIndex(currentTask?.status, isComplete);

    return (
        <div className="rounded-xl border border-white/8 bg-slate-950/55 px-3 py-3">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <p className="font-mono text-[10px] tracking-[0.15em] text-violet-300 uppercase">
                        Workflow pipeline
                    </p>

                    <p className="mt-0.5 text-xs text-slate-500">
                        AIOS-controlled deterministic lifecycle
                    </p>
                </div>

                <span className="font-mono text-[10px] text-slate-500">
                    {taskProgress.completed}/{taskProgress.total} done
                </span>
            </div>

            <div className="relative mt-3">
                <div className="absolute top-2.5 right-[9%] left-[9%] h-px bg-slate-800" />

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
                                    className={`z-10 size-5 rounded-full border ${
                                        active
                                            ? 'border-cyan-200 bg-cyan-300 shadow-[0_0_18px_rgba(34,211,238,0.65)]'
                                            : passed
                                              ? 'border-emerald-300/70 bg-emerald-400/60'
                                              : 'border-slate-700 bg-slate-950'
                                    } ${
                                        active
                                            ? 'animate-pulse motion-reduce:animate-none'
                                            : ''
                                    }`}
                                />

                                <span
                                    className={`mt-1.5 truncate font-mono text-[9px] uppercase ${
                                        active
                                            ? 'text-cyan-200'
                                            : passed
                                              ? 'text-emerald-300'
                                              : 'text-slate-600'
                                    }`}
                                >
                                    {stage.label}
                                </span>
                            </div>
                        );
                    })}
                </div>
            </div>

            <div className="mt-3 flex min-w-0 items-center justify-between gap-3 border-t border-white/5 pt-2.5">
                <div className="min-w-0">
                    <p className="text-[10px] text-slate-500 uppercase">
                        Active operation
                    </p>

                    <p className="truncate text-xs font-medium text-slate-200">
                        {currentTask
                            ? `${currentTask.key}: ${currentTask.title}`
                            : isComplete
                              ? 'Roadmap execution complete'
                              : 'No active task'}
                    </p>
                </div>

                {currentTask && (
                    <Badge
                        variant="outline"
                        className="shrink-0 border-cyan-300/20 bg-cyan-400/5 font-mono text-[9px] text-cyan-200"
                    >
                        {currentTask.status}
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
    currentTask,
    taskProgress,
}: {
    projectId: number;
    projectName: string;
    projectStatus: string;
    gitStatus: string;
    workers: OfficeWorker[];
    agents: OfficeAgent[];
    workerBindings: OfficeWorkerBinding[];
    currentTask: OfficeTask | null;
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
            className="relative flex h-full min-h-0 flex-col overflow-hidden rounded-2xl border border-cyan-300/15 bg-[#040a14]/95 text-slate-100 shadow-[0_24px_90px_rgba(2,6,23,0.7)]"
        >
            <div className="pointer-events-none absolute -top-24 -left-16 size-56 animate-pulse rounded-full bg-cyan-400/8 blur-3xl motion-reduce:animate-none" />

            <div className="pointer-events-none absolute -right-24 bottom-0 size-64 animate-pulse rounded-full bg-violet-500/8 blur-3xl motion-reduce:animate-none" />

            <header className="relative shrink-0 border-b border-cyan-300/10 bg-[linear-gradient(110deg,rgba(8,17,31,0.98),rgba(6,14,27,0.96),rgba(8,47,73,0.42))] px-4 py-3.5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2 font-mono text-[10px] tracking-[0.18em] text-cyan-300 uppercase">
                            <Activity className="size-3.5" aria-hidden="true" />
                            Live operations
                        </div>

                        <h2
                            id="agent-office-title"
                            className="mt-1 truncate text-xl font-semibold tracking-tight text-white"
                        >
                            AI Engineering Office
                        </h2>

                        <p className="mt-0.5 truncate text-xs text-slate-500">
                            {projectName} · AIOS controlled execution
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-1.5">
                        <Badge
                            variant="outline"
                            className="border-emerald-300/20 bg-emerald-400/5 font-mono text-[9px] text-emerald-300"
                        >
                            <CircleDot className="mr-1 size-3" />
                            {projectStatus}
                        </Badge>

                        <Badge
                            variant="outline"
                            className="border-violet-300/20 bg-violet-400/5 font-mono text-[9px] text-violet-200"
                        >
                            Git · {gitStatus}
                        </Badge>
                    </div>
                </div>

                <div className="mt-3 flex flex-wrap gap-1.5">
                    <Metric
                        label="working"
                        value={workingWorkers}
                        tone="bg-emerald-400"
                    />

                    <Metric
                        label="bound"
                        value={boundWorkers}
                        tone="bg-cyan-400"
                    />

                    <Metric
                        label="attention"
                        value={attentionWorkers}
                        tone="bg-rose-400"
                    />
                </div>
            </header>

            <div className="relative min-h-0 flex-1 overflow-y-auto p-3">
                {displayedWorkers.length > 0 ? (
                    <div className="grid gap-3 md:grid-cols-3">
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
                    <div className="grid min-h-52 place-items-center rounded-xl border border-dashed border-slate-700 bg-slate-950/40 text-center">
                        <div>
                            <Bot className="mx-auto size-10 text-slate-600" />

                            <p className="mt-2 text-sm text-slate-400">
                                No workflow workers are provisioned.
                            </p>
                        </div>
                    </div>
                )}

                <div className="mt-3">
                    <WorkflowPipeline
                        currentTask={currentTask}
                        taskProgress={taskProgress}
                    />
                </div>

                <div className="mt-3 grid gap-2 sm:grid-cols-3">
                    <div className="flex items-center gap-2 rounded-lg border border-white/5 bg-white/[0.02] px-3 py-2">
                        <Radio className="size-3.5 text-cyan-300" />

                        <div>
                            <p className="font-mono text-[9px] text-slate-500 uppercase">
                                Worker lanes
                            </p>

                            <p className="text-xs text-slate-200">
                                {displayedWorkers.length} visible
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2 rounded-lg border border-white/5 bg-white/[0.02] px-3 py-2">
                        <Bot className="size-3.5 text-violet-300" />

                        <div>
                            <p className="font-mono text-[9px] text-slate-500 uppercase">
                                Agents
                            </p>

                            <p className="text-xs text-slate-200">
                                {boundWorkers} bound
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2 rounded-lg border border-white/5 bg-white/[0.02] px-3 py-2">
                        <CircleDot className="size-3.5 text-emerald-300" />

                        <div>
                            <p className="font-mono text-[9px] text-slate-500 uppercase">
                                Roadmap
                            </p>

                            <p className="text-xs text-slate-200">
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
