import { Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Bot,
    BrainCircuit,
    CircleDot,
    Code2,
    Coffee,
    Footprints,
    Orbit,
    Radio,
    ScanEye,
    ShieldCheck,
    Sparkles as SparklesIcon,
} from 'lucide-react';
import { useMemo, useState } from 'react';
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
    } | null;
    task: { id: number; key: string; title: string; status: string } | null;
};

type AgentBehavior = 'walk' | 'think' | 'work' | 'rest' | 'brainstorm';

type OfficePresentation = {
    label: string;
    dotClass: string;
    textClass: string;
    color: string;
};

type FeaturedAgent = {
    worker: OfficeWorker;
    behavior: AgentBehavior;
    room: string;
    color: string;
};

const roleLabels: Record<string, string> = {
    project_manager: 'Project Manager',
    coder: 'Developer',
    reviewer: 'Reviewer',
};

const roleColors: Record<string, string> = {
    project_manager: '#a78bfa',
    coder: '#38bdf8',
    reviewer: '#34d399',
};

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

function behaviorFor(worker: OfficeWorker): AgentBehavior {
    if (worker.status === 'recovering') {
        return 'rest';
    }

    if (worker.status === 'interrupted') {
        return 'think';
    }

    if (worker.status === 'idle') {
        return 'walk';
    }

    return worker.role === 'project_manager' ? 'brainstorm' : 'work';
}

function behaviorLabel(behavior: AgentBehavior): string {
    return {
        walk: 'Walking the floor',
        think: 'Thinking through a blocker',
        work: 'Building now',
        rest: 'Resting and recovering',
        brainstorm: 'Brainstorming the next move',
    }[behavior];
}

function buildFeaturedAgents(workers: OfficeWorker[]): FeaturedAgent[] {
    const roleOrder = ['project_manager', 'coder', 'reviewer'];
    const rooms = ['Strategy Room', 'Development Room', 'QA Room'];
    const claimedIds = new Set<number>();
    const selected = roleOrder.flatMap((role) => {
        const worker = workers.find(
            (candidate) =>
                candidate.role === role && !claimedIds.has(candidate.id),
        );

        if (worker) {
            claimedIds.add(worker.id);

            return [worker];
        }

        return [];
    });

    for (const worker of workers) {
        if (selected.length === 3) {
            break;
        }

        if (!claimedIds.has(worker.id)) {
            selected.push(worker);
            claimedIds.add(worker.id);
        }
    }

    return selected.slice(0, 3).map((worker, index) => ({
        worker,
        behavior: behaviorFor(worker),
        room: rooms[index],
        color: roleColors[worker.role] ?? '#94a3b8',
    }));
}

function FallbackOffice({
    agents,
    selectedWorkerId,
    onSelect,
}: {
    agents: FeaturedAgent[];
    selectedWorkerId: number | null;
    onSelect: (worker: OfficeWorker) => void;
}) {
    return (
        <div className="grid min-h-132 gap-3 bg-[radial-gradient(circle_at_50%_52%,rgba(124,58,237,0.28),transparent_17%),linear-gradient(140deg,#111827,#030712)] p-4 sm:grid-cols-3">
            {['Strategy Room', 'Development Room', 'QA Room'].map(
                (room, index) => {
                    const agent = agents[index];

                    return (
                        <div
                            key={room}
                            className="relative min-h-48 overflow-hidden rounded-xl border border-white/10 bg-slate-900/75 p-4 shadow-[inset_0_1px_0_rgba(255,255,255,0.08)]"
                        >
                            <div className="absolute inset-x-4 bottom-4 h-20 rounded-lg border border-sky-300/15 bg-[linear-gradient(135deg,rgba(14,165,233,0.12),transparent)]" />
                            <p className="relative text-sm font-semibold text-white">
                                {room}
                            </p>
                            <p className="relative mt-1 text-xs text-slate-400">
                                {agent
                                    ? behaviorLabel(agent.behavior)
                                    : 'Prepared for assignment'}
                            </p>
                            {agent && (
                                <button
                                    type="button"
                                    aria-pressed={
                                        agent.worker.id === selectedWorkerId
                                    }
                                    onClick={() => onSelect(agent.worker)}
                                    className={`relative mt-9 flex w-full items-center gap-3 rounded-lg border p-3 text-left transition focus-visible:ring-2 focus-visible:ring-violet-300 focus-visible:outline-none ${agent.worker.id === selectedWorkerId ? 'border-violet-300 bg-violet-400/15' : 'border-white/10 bg-slate-950/65 hover:bg-white/8'}`}
                                >
                                    <span
                                        className="grid size-12 place-items-center rounded-full border border-white/20 bg-slate-800 shadow-lg"
                                        style={{
                                            boxShadow: `0 0 22px ${agent.color}`,
                                        }}
                                    >
                                        <Bot
                                            className="size-6"
                                            style={{ color: agent.color }}
                                        />
                                    </span>
                                    <span>
                                        <span className="block text-sm font-medium text-white">
                                            {labelForRole(agent.worker.role)}
                                        </span>
                                        <span className="mt-0.5 block text-xs text-slate-400">
                                            {behaviorLabel(agent.behavior)}
                                        </span>
                                    </span>
                                </button>
                            )}
                        </div>
                    );
                },
            )}
            <div className="col-span-full grid place-items-center rounded-xl border border-violet-300/25 bg-slate-950/70 py-5 text-center shadow-[0_0_34px_rgba(124,58,237,0.23)]">
                <ShieldCheck className="size-7 text-violet-300" />
                <p className="mt-2 text-sm font-semibold text-white">
                    AI Operating System
                </p>
                <p className="mt-1 text-xs text-slate-400">
                    Accessible office floor plan
                </p>
            </div>
        </div>
    );
}

function AgentInspector({
    agent,
    projectId,
}: {
    agent: FeaturedAgent;
    projectId: number;
}) {
    const presentation = officePresentation(agent.worker.status);
    const Icon = {
        walk: Footprints,
        think: BrainCircuit,
        work: Code2,
        rest: Coffee,
        brainstorm: SparklesIcon,
    }[agent.behavior];

    return (
        <aside className="rounded-xl border border-white/10 bg-slate-950/85 p-4 shadow-2xl backdrop-blur-xl">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-xs font-medium tracking-[0.16em] text-violet-300 uppercase">
                        Selected agent
                    </p>
                    <h3 className="mt-1 text-lg font-semibold text-white">
                        {labelForRole(agent.worker.role)}
                    </h3>
                </div>
                <Badge
                    className="border-white/10 bg-white/5 text-slate-200"
                    variant="outline"
                >
                    <span
                        className={`mr-1.5 size-1.5 rounded-full ${presentation.dotClass}`}
                    />
                    {presentation.label}
                </Badge>
            </div>
            <div className="mt-4 rounded-lg border border-violet-300/20 bg-violet-400/10 p-3">
                <div className="flex items-center gap-2 text-sm font-medium text-violet-100">
                    <Icon className="size-4" />
                    {behaviorLabel(agent.behavior)}
                </div>
                <p className="mt-1 text-xs text-violet-200/70">
                    {agent.room} · derived from the live worker status
                </p>
            </div>
            <dl className="mt-4 grid gap-3 text-sm">
                <div className="flex items-center justify-between border-b border-white/8 pb-3">
                    <dt className="flex items-center gap-2 text-slate-400">
                        <Radio className="size-4" /> Lease
                    </dt>
                    <dd className="text-slate-200 capitalize">
                        {agent.worker.lease_state}
                    </dd>
                </div>
                <div className="flex items-center justify-between gap-3 border-b border-white/8 pb-3">
                    <dt className="flex items-center gap-2 text-slate-400">
                        <Activity className="size-4" /> Heartbeat
                    </dt>
                    <dd className="text-right text-xs text-slate-200">
                        {agent.worker.last_heartbeat_at
                            ? new Date(
                                  agent.worker.last_heartbeat_at,
                              ).toLocaleString()
                            : 'Not recorded'}
                    </dd>
                </div>
            </dl>
            {agent.worker.task ? (
                <Link
                    href={
                        showTask({
                            project: projectId,
                            task: agent.worker.task.id,
                        }).url
                    }
                    className="mt-4 block rounded-lg border border-cyan-300/20 bg-cyan-400/10 p-3 transition hover:border-cyan-200/50 hover:bg-cyan-400/15 focus-visible:ring-2 focus-visible:ring-cyan-200 focus-visible:outline-none"
                >
                    <span className="text-xs font-medium tracking-wide text-cyan-100 uppercase">
                        Current task · {agent.worker.task.status}
                    </span>
                    <p className="mt-1 text-sm font-medium text-white">
                        {agent.worker.task.key}: {agent.worker.task.title}
                    </p>
                </Link>
            ) : (
                <p className="mt-4 rounded-lg border border-dashed border-white/10 p-3 text-sm text-slate-400">
                    No task is assigned to this agent.
                </p>
            )}
            {agent.worker.run && (
                <Link
                    href={
                        showAgentRun({
                            project: projectId,
                            run: agent.worker.run.id,
                        }).url
                    }
                    className="mt-3 flex items-center justify-between rounded-lg border border-white/10 px-3 py-2 text-sm text-slate-300 transition hover:bg-white/5 focus-visible:ring-2 focus-visible:ring-violet-300 focus-visible:outline-none"
                >
                    <span>Run #{agent.worker.run.id}</span>
                    <span className="text-xs text-slate-500">
                        Attempt {agent.worker.run.attempt_number ?? '—'}
                    </span>
                </Link>
            )}
        </aside>
    );
}

function Metric({
    icon: Icon,
    label,
    value,
    tone,
}: {
    icon: typeof Activity;
    label: string;
    value: number;
    tone: string;
}) {
    return (
        <div className="flex items-center gap-2 rounded-lg border border-white/10 bg-slate-950/70 px-3 py-2 text-sm shadow-lg backdrop-blur">
            <Icon className={`size-4 ${tone}`} />
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
    const agents = useMemo(() => buildFeaturedAgents(workers), [workers]);
    const [selectedWorkerId, setSelectedWorkerId] = useState<number | null>(
        agents[0]?.worker.id ?? null,
    );
    const selectedAgent =
        agents.find((agent) => agent.worker.id === selectedWorkerId) ??
        agents[0];
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
            className="flex min-h-[calc(100svh-8.5rem)] flex-col overflow-hidden rounded-2xl border border-slate-700/70 bg-slate-950 text-slate-100 shadow-2xl"
        >
            <div className="border-b border-white/8 bg-[linear-gradient(105deg,rgba(15,23,42,0.98),rgba(15,23,42,0.84),rgba(49,46,129,0.33))] px-5 py-5 md:px-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2 text-sm font-medium text-violet-300">
                            <Bot className="size-4" /> Live AI organization
                        </div>
                        <h2
                            id="agent-office-title"
                            className="mt-1 text-2xl font-semibold tracking-tight text-white"
                        >
                            AI Engineering Office
                        </h2>
                        <p className="mt-1 text-sm text-slate-400">
                            A cinematic command floor for the three agents
                            currently moving this project forward.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Metric
                            icon={Activity}
                            label="agents online"
                            value={workingWorkers}
                            tone="text-emerald-400"
                        />
                        <Metric
                            icon={Orbit}
                            label="recovering"
                            value={recoveringWorkers}
                            tone="text-amber-400"
                        />
                        <Metric
                            icon={AlertTriangle}
                            label="need attention"
                            value={attentionWorkers}
                            tone="text-rose-400"
                        />
                    </div>
                </div>
            </div>
            <div className="grid min-h-0 flex-1 gap-4 p-3 md:p-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="relative min-h-[calc(100svh-18rem)] overflow-hidden rounded-xl border border-white/10 bg-slate-950 shadow-[inset_0_0_60px_rgba(15,23,42,0.85)] xl:min-h-0">
                    <FallbackOffice
                        agents={agents}
                        selectedWorkerId={selectedAgent?.worker.id ?? null}
                        onSelect={(worker) => setSelectedWorkerId(worker.id)}
                    />
                    <div className="absolute right-3 bottom-3 flex items-center gap-2 rounded-lg border border-white/10 bg-slate-950/80 px-3 py-2 text-xs text-slate-300 shadow-lg backdrop-blur">
                        <span className="mr-2 inline-block size-1.5 rounded-full bg-emerald-400 shadow-[0_0_8px_#34d399]" />
                        Live office view
                    </div>
                </div>
                {selectedAgent && (
                    <AgentInspector
                        agent={selectedAgent}
                        projectId={projectId}
                    />
                )}
            </div>
            <div className="border-t border-white/8 bg-slate-950/80 px-4 py-3 md:px-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-2 text-sm font-semibold text-slate-100">
                        <ScanEye className="size-4 text-violet-300" /> Agent
                        states
                    </div>
                    <span className="text-xs text-slate-500">
                        Select an agent to inspect its real task, run,
                        heartbeat, and lease.
                    </span>
                </div>
                <div className="mt-3 grid gap-2 sm:grid-cols-3">
                    {agents.map((agent) => {
                        const Icon = {
                            walk: Footprints,
                            think: BrainCircuit,
                            work: Code2,
                            rest: Coffee,
                            brainstorm: SparklesIcon,
                        }[agent.behavior];
                        const isSelected =
                            agent.worker.id === selectedAgent?.worker.id;

                        return (
                            <button
                                key={agent.worker.id}
                                type="button"
                                aria-pressed={isSelected}
                                onClick={() =>
                                    setSelectedWorkerId(agent.worker.id)
                                }
                                className={`flex items-center gap-3 rounded-lg border p-3 text-left transition focus-visible:ring-2 focus-visible:ring-violet-300 focus-visible:outline-none ${isSelected ? 'border-violet-300/70 bg-violet-400/12' : 'border-white/8 bg-white/3 hover:bg-white/8'}`}
                            >
                                <span
                                    className="grid size-9 place-items-center rounded-lg border border-white/10 bg-slate-950"
                                    style={{ color: agent.color }}
                                >
                                    <Icon className="size-4" />
                                </span>
                                <span>
                                    <span className="block text-sm font-medium text-slate-100">
                                        {labelForRole(agent.worker.role)}
                                    </span>
                                    <span className="mt-0.5 block text-xs text-slate-400">
                                        {behaviorLabel(agent.behavior)}
                                    </span>
                                </span>
                                <CircleDot
                                    className={`ml-auto size-4 ${officePresentation(agent.worker.status).textClass}`}
                                />
                            </button>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}
