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
    task: { id: number; key: string; title: string; status: string } | null;
};

type OfficePresentation = {
    label: string;
    dotClass: string;
    textClass: string;
    color: string;
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

export function officePresentation(status: string): OfficePresentation {
    switch (status) {
        case 'working':
            return {
                label: 'Working',
                dotClass: 'bg-emerald-400',
                textClass: 'text-emerald-300',
                color: '#34d399',
            };
        case 'recovering':
            return {
                label: 'Recovering',
                dotClass: 'bg-amber-400',
                textClass: 'text-amber-300',
                color: '#fbbf24',
            };
        case 'interrupted':
            return {
                label: 'Needs attention',
                dotClass: 'bg-rose-400',
                textClass: 'text-rose-300',
                color: '#fb7185',
            };
        case 'idle':
            return {
                label: 'Available',
                dotClass: 'bg-slate-400',
                textClass: 'text-slate-300',
                color: '#94a3b8',
            };
        default:
            return {
                label: 'Status unavailable',
                dotClass: 'bg-slate-500',
                textClass: 'text-slate-400',
                color: '#64748b',
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
        <div className="relative aspect-video overflow-hidden rounded-xl border border-white/10 bg-[radial-gradient(circle_at_50%_35%,rgba(34,211,238,0.18),transparent_42%),linear-gradient(145deg,#0f172a,#020617)]">
            {visual ? (
                <>
                    <img
                        src={visual.src}
                        alt={visual.alt}
                        className="h-full w-full object-contain p-2 motion-reduce:hidden sm:p-3"
                    />
                    <div
                        role="img"
                        aria-label={`${roleLabel} ${worker.status}. Animation disabled because reduced motion is requested.`}
                        className="hidden h-full w-full place-items-center p-6 text-center motion-reduce:grid"
                    >
                        <div>
                            <Bot
                                aria-hidden="true"
                                className="mx-auto size-14 text-cyan-300"
                            />
                            <p className="mt-3 text-sm font-medium text-white">
                                {roleLabel}
                            </p>
                            <p className="mt-1 text-xs text-slate-400">
                                Animation paused for reduced motion
                            </p>
                        </div>
                    </div>
                </>
            ) : (
                <div
                    role="img"
                    aria-label={`${roleLabel} has no role-specific animation.`}
                    className="grid h-full w-full place-items-center p-6 text-center"
                >
                    <div>
                        <Bot
                            aria-hidden="true"
                            className="mx-auto size-14 text-slate-400"
                        />
                        <p className="mt-3 text-sm font-medium text-white">
                            {roleLabel}
                        </p>
                        <p className="mt-1 text-xs text-slate-400">
                            Role-specific animation unavailable
                        </p>
                    </div>
                </div>
            )}
        </div>
    );
}

function AgentCard({
    projectId,
    worker,
}: {
    projectId: number;
    worker: OfficeWorker;
}) {
    const presentation = officePresentation(worker.status);

    return (
        <article className="flex min-w-0 flex-col rounded-2xl border border-white/10 bg-slate-950/80 p-4 shadow-xl">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-xs font-medium tracking-[0.14em] text-cyan-300 uppercase">
                        AI Agent
                    </p>
                    <h3 className="mt-1 truncate text-lg font-semibold text-white">
                        {labelForRole(worker.role)}
                    </h3>
                </div>
                <Badge
                    variant="outline"
                    title={presentation.label}
                    className="shrink-0 border-white/10 bg-white/5 text-slate-200"
                >
                    <span
                        aria-hidden="true"
                        className={`mr-1.5 size-1.5 rounded-full ${presentation.dotClass}`}
                    />
                    {worker.status}
                </Badge>
            </div>

            <div className="mt-4">
                <AgentVisualPanel worker={worker} />
            </div>

            <dl className="mt-4 grid gap-3 text-sm">
                <div className="flex items-center justify-between gap-3 border-b border-white/8 pb-3">
                    <dt className="flex items-center gap-2 text-slate-400">
                        <CircleDot
                            aria-hidden="true"
                            className={`size-4 ${presentation.textClass}`}
                        />
                        Status
                    </dt>
                    <dd className="text-right font-medium text-slate-200">
                        {worker.status}
                    </dd>
                </div>
                <div className="flex items-center justify-between gap-3 border-b border-white/8 pb-3">
                    <dt className="flex items-center gap-2 text-slate-400">
                        <Radio aria-hidden="true" className="size-4" /> Lease
                    </dt>
                    <dd className="text-right text-slate-200 capitalize">
                        {worker.lease_state}
                    </dd>
                </div>
                <div className="flex items-center justify-between gap-3 border-b border-white/8 pb-3">
                    <dt className="flex items-center gap-2 text-slate-400">
                        <Activity aria-hidden="true" className="size-4" />
                        Heartbeat
                    </dt>
                    <dd className="max-w-44 text-right text-xs text-slate-200">
                        {formatDate(worker.last_heartbeat_at)}
                    </dd>
                </div>
            </dl>

            {worker.task ? (
                <Link
                    href={
                        showTask({
                            project: projectId,
                            task: worker.task.id,
                        }).url
                    }
                    className="mt-4 block rounded-lg border border-cyan-300/20 bg-cyan-400/10 p-3 transition hover:border-cyan-200/50 hover:bg-cyan-400/15 focus-visible:ring-2 focus-visible:ring-cyan-200 focus-visible:outline-none"
                >
                    <span className="text-xs font-medium tracking-wide text-cyan-100 uppercase">
                        Current task · {worker.task.status}
                    </span>
                    <p className="mt-1 text-sm font-medium text-white">
                        {worker.task.key}: {worker.task.title}
                    </p>
                </Link>
            ) : (
                <p className="mt-4 rounded-lg border border-dashed border-white/10 p-3 text-sm text-slate-400">
                    No task is assigned to this agent.
                </p>
            )}

            {worker.run ? (
                <>
                    <Link
                        href={
                            showAgentRun({
                                project: projectId,
                                run: worker.run.id,
                            }).url
                        }
                        className="mt-3 flex items-center justify-between gap-3 rounded-lg border border-white/10 px-3 py-2 text-sm text-slate-300 transition hover:bg-white/5 focus-visible:ring-2 focus-visible:ring-cyan-200 focus-visible:outline-none"
                    >
                        <span>Run #{worker.run.id}</span>
                        <span className="text-right text-xs text-slate-500">
                            {worker.run.status} · Attempt{' '}
                            {worker.run.attempt_number ?? '—'}
                        </span>
                    </Link>
                    {worker.run.failure_reason && (
                        <p className="mt-2 rounded-lg border border-red-400/20 bg-red-400/10 px-3 py-2 text-xs text-red-200">
                            {worker.run.failure_reason}
                        </p>
                    )}
                </>
            ) : (
                <p className="mt-3 rounded-lg border border-dashed border-white/10 px-3 py-2 text-sm text-slate-400">
                    No run is recorded for this agent.
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
        <div className="flex items-center gap-2 rounded-lg border border-white/10 bg-slate-950/70 px-3 py-2 text-sm shadow-lg">
            <span className={`size-2 rounded-full ${tone}`} />
            <span className="font-semibold text-white">{value}</span>
            <span className="text-slate-400">{label}</span>
        </div>
    );
}

export function AgentOffice({
    projectId,
    workers,
}: {
    projectId: number;
    workers: OfficeWorker[];
}) {
    const displayedWorkers = useMemo(
        () => selectOfficeWorkers(workers),
        [workers],
    );
    const workingWorkers = workers.filter(
        (worker) => worker.status === 'working',
    ).length;
    const recoveringWorkers = workers.filter(
        (worker) => worker.status === 'recovering',
    ).length;
    const attentionWorkers = workers.filter(
        (worker) => worker.status === 'interrupted',
    ).length;

    if (workers.length === 0) {
        return null;
    }

    return (
        <section
            aria-labelledby="agent-office-title"
            className="overflow-hidden rounded-2xl border border-slate-700/70 bg-slate-950 text-slate-100 shadow-2xl"
        >
            <div className="border-b border-white/8 bg-[linear-gradient(105deg,rgba(15,23,42,0.98),rgba(15,23,42,0.84),rgba(8,145,178,0.2))] px-5 py-5 md:px-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2 text-sm font-medium text-cyan-300">
                            <Bot aria-hidden="true" className="size-4" /> Live
                            AI organization
                        </div>
                        <h2
                            id="agent-office-title"
                            className="mt-1 text-2xl font-semibold tracking-tight text-white"
                        >
                            AI Engineering Office
                        </h2>
                        <p className="mt-1 text-sm text-slate-400">
                            Persisted worker status drives each agent visual.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Metric
                            label="working"
                            value={workingWorkers}
                            tone="bg-emerald-400"
                        />
                        <Metric
                            label="recovering"
                            value={recoveringWorkers}
                            tone="bg-amber-400"
                        />
                        <Metric
                            label="need attention"
                            value={attentionWorkers}
                            tone="bg-rose-400"
                        />
                    </div>
                </div>
            </div>

            <div className="grid gap-4 p-4 md:grid-cols-2 md:p-6 xl:grid-cols-3">
                {displayedWorkers.map((worker) => (
                    <AgentCard
                        key={worker.id}
                        projectId={projectId}
                        worker={worker}
                    />
                ))}
            </div>
        </section>
    );
}
