import { Head, Link, usePoll } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Bot,
    CheckCircle2,
    ChevronRight,
    CircleDot,
    Clock3,
    Cpu,
    LockKeyhole,
    Radar,
    ShieldCheck,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { show } from '@/actions/App/Http/Controllers/GlobalAgentController';
import { AppBackground } from '@/components/app-background';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

type RuntimeStatus = 'working' | 'idle' | 'disabled';

type RunActivity = {
    id: number;
    status: string;
    started_at: string;
    finished_at: string | null;
    duration_seconds: number | null;
};

type Agent = {
    id: number;
    name: string;
    role: string;
    harness: string;
    model: string | null;
    reasoning_setting: string | null;
    enabled: boolean;
    configuration_version: number;
    open_incident_count: number;
    runtime_status: RuntimeStatus;
    latest_run: {
        id: number;
        status: string;
        started_at: string;
        finished_at: string | null;
    } | null;
    recent_activity: RunActivity[];
};

type SystemSignals = {
    total_agents: number;
    enabled_agents: number;
    open_incidents: number;
    active_recoveries: number;
};

type RecentEvent = {
    id: number;
    event_type: string;
    occurred_at: string;
    project: {
        id: number;
        name: string;
    } | null;
};

type ActiveIncident = {
    id: number;
    status: string;
    failure_type: string;
    root_cause_category: string | null;
    detected_at: string;
    project: {
        id: number;
        name: string;
    } | null;
    task: {
        key: string;
        title: string;
        project_id: number;
    } | null;
};

type Props = {
    agents: Agent[];
    system: SystemSignals;
    recent_events: RecentEvent[];
    active_incidents: ActiveIncident[];
    generated_at: string;
};

function titleCase(value: string): string {
    return value
        .replace(/[._-]+/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

function relativeTime(value: string, generatedAt: string): string {
    const timestamp = Date.parse(value);
    const generatedTimestamp = Date.parse(generatedAt);

    if (Number.isNaN(timestamp) || Number.isNaN(generatedTimestamp)) {
        return value;
    }

    const seconds = Math.floor(
        Math.max(0, generatedTimestamp - timestamp) / 1000,
    );

    if (seconds < 5) {
        return 'just now';
    }

    if (seconds < 60) {
        return `${seconds}s ago`;
    }

    const minutes = Math.floor(seconds / 60);

    if (minutes < 60) {
        return `${minutes}m ago`;
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
        return `${hours}h ago`;
    }

    return `${Math.floor(hours / 24)}d ago`;
}

function formatDuration(seconds: number): string {
    if (seconds < 60) {
        return `${seconds}s`;
    }

    if (seconds < 3600) {
        return `${Math.round(seconds / 60)}m`;
    }

    return `${(seconds / 3600).toFixed(1)}h`;
}

function runtimeTone(status: RuntimeStatus): string {
    if (status === 'working') {
        return 'border-primary/30 bg-primary/10 text-primary';
    }

    if (status === 'idle') {
        return 'border-success/25 bg-success/10 text-success';
    }

    return 'border-border bg-muted/40 text-muted-foreground';
}

function runTone(status: string): string {
    if (status === 'completed') {
        return 'border-success/25 bg-success/10 text-success';
    }

    if (status === 'failed') {
        return 'border-destructive/25 bg-destructive/10 text-destructive';
    }

    if (status === 'interrupted') {
        return 'border-warning/25 bg-warning/10 text-warning';
    }

    if (status === 'running') {
        return 'border-primary/30 bg-primary/10 text-primary';
    }

    return 'border-border bg-muted/30 text-muted-foreground';
}

function incidentTone(status: string): string {
    if (status === 'detected') {
        return 'border-warning/25 bg-warning/10 text-warning';
    }

    return 'border-primary/25 bg-primary/10 text-primary';
}

function MetricCard({
    label,
    value,
    detail,
    icon: Icon,
    iconClassName,
}: {
    label: string;
    value: number;
    detail: string;
    icon: LucideIcon;
    iconClassName?: string;
}) {
    return (
        <div className="panel-elevated relative overflow-hidden p-4">
            <div className="glow-line-accent" />

            <div className="flex items-center gap-3">
                <div
                    className={cn(
                        'flex size-10 shrink-0 items-center justify-center rounded-lg border border-primary/20 bg-primary/8 text-primary',
                        iconClassName,
                    )}
                >
                    <Icon className="size-5" aria-hidden="true" />
                </div>

                <div className="min-w-0">
                    <p className="font-mono text-2xs tracking-[0.14em] text-muted-foreground uppercase">
                        {label}
                    </p>
                    <div className="mt-1 flex items-end gap-2">
                        <span className="text-2xl font-semibold tracking-tight text-foreground">
                            {value}
                        </span>
                        <span className="pb-0.5 text-xs text-muted-foreground">
                            {detail}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    );
}

function DetailItem({ label, value }: { label: string; value: string }) {
    return (
        <div className="tile-inset min-w-0 p-3">
            <p className="font-mono text-2xs tracking-[0.12em] text-muted-foreground uppercase">
                {label}
            </p>
            <p className="mt-1 truncate text-sm font-medium text-foreground">
                {value}
            </p>
        </div>
    );
}

function RunDurationSparkline({ activity }: { activity: RunActivity[] }) {
    const durationRuns = activity.filter(
        (
            run,
        ): run is RunActivity & {
            duration_seconds: number;
        } => run.duration_seconds !== null,
    );

    if (durationRuns.length < 2) {
        return null;
    }

    const width = 150;
    const height = 34;
    const padding = 3;
    const durations = durationRuns.map((run) => run.duration_seconds);
    const minimum = Math.min(...durations);
    const maximum = Math.max(...durations);
    const range = maximum - minimum;

    const coordinates = durationRuns.map((run, index) => {
        const x =
            padding +
            (index / (durationRuns.length - 1)) * (width - padding * 2);

        const y =
            range === 0
                ? height / 2
                : padding +
                  ((maximum - run.duration_seconds) / range) *
                      (height - padding * 2);

        return {
            id: run.id,
            x,
            y,
        };
    });

    const points = coordinates.map(({ x, y }) => `${x},${y}`).join(' ');

    const latestDuration =
        durationRuns[durationRuns.length - 1].duration_seconds;

    return (
        <div>
            <div className="mb-2 flex items-center justify-between gap-3">
                <span className="font-mono text-2xs tracking-[0.12em] text-muted-foreground uppercase">
                    Run duration
                </span>
                <span className="font-mono text-2xs text-primary">
                    {formatDuration(latestDuration)}
                </span>
            </div>

            <svg
                viewBox={`0 0 ${width} ${height}`}
                className="h-9 w-full text-primary"
                role="img"
                aria-label="Recent completed agent run duration trend"
            >
                <polyline
                    points={points}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.75"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    vectorEffect="non-scaling-stroke"
                />

                {coordinates.map(({ id, x, y }) => (
                    <circle
                        key={id}
                        cx={x}
                        cy={y}
                        r="1.8"
                        fill="currentColor"
                    />
                ))}
            </svg>
        </div>
    );
}

function AgentCommandCard({
    agent,
    generatedAt,
}: {
    agent: Agent;
    generatedAt: string;
}) {
    const hasDurationTrend =
        agent.recent_activity.filter((run) => run.duration_seconds !== null)
            .length >= 2;

    return (
        <Link
            href={show(agent).url}
            className="group block rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
        >
            <article
                className={cn(
                    'panel-elevated relative overflow-hidden transition-colors group-hover:border-primary/40 group-hover:bg-card/85',
                    agent.runtime_status === 'working' && 'agent-card-active',
                )}
            >
                <div className="glow-line-accent" />

                <div className="flex flex-col gap-4 border-b border-border-subtle p-5 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex min-w-0 items-center gap-4">
                        <div className="relative flex size-14 shrink-0 items-center justify-center rounded-xl border border-primary/30 bg-primary/10 text-primary shadow-glow-sm">
                            <Bot className="size-7" aria-hidden="true" />

                            <span
                                className={cn(
                                    'absolute -top-1 -right-1 size-2.5 rounded-full border-2 border-card',
                                    agent.runtime_status === 'working' &&
                                        'status-glow-pulse bg-primary text-primary',
                                    agent.runtime_status === 'idle' &&
                                        'bg-success',
                                    agent.runtime_status === 'disabled' &&
                                        'bg-muted-foreground',
                                )}
                            />
                        </div>

                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="truncate text-lg font-semibold tracking-tight text-foreground">
                                    {agent.name}
                                </h2>

                                <LockKeyhole
                                    className="size-3.5 text-muted-foreground"
                                    aria-label="Protected AIOS system identity"
                                />
                            </div>

                            <p className="mt-1 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                                Protected AIOS system identity. Its role and
                                ownership are fixed while operational
                                configuration remains manageable from the agent
                                detail.
                            </p>
                        </div>
                    </div>

                    <div className="flex shrink-0 flex-wrap gap-2">
                        <Badge
                            variant="outline"
                            className={
                                agent.enabled
                                    ? 'border-success/25 bg-success/10 text-success'
                                    : 'border-border bg-muted/30 text-muted-foreground'
                            }
                        >
                            {agent.enabled ? 'Enabled' : 'Disabled'}
                        </Badge>

                        <Badge
                            variant="outline"
                            className={runtimeTone(agent.runtime_status)}
                        >
                            <CircleDot
                                className="mr-1 size-3"
                                aria-hidden="true"
                            />
                            {titleCase(agent.runtime_status)}
                        </Badge>
                    </div>
                </div>

                <div className="p-5">
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <DetailItem
                            label="Role"
                            value={titleCase(agent.role)}
                        />
                        <DetailItem
                            label="Harness"
                            value={titleCase(agent.harness)}
                        />
                        <DetailItem
                            label="Model"
                            value={agent.model ?? 'Provider default'}
                        />
                        <DetailItem
                            label="Reasoning"
                            value={
                                agent.reasoning_setting ?? 'Provider default'
                            }
                        />
                        <DetailItem
                            label="Configuration"
                            value={`v${agent.configuration_version}`}
                        />
                    </div>

                    <div className="mt-4 grid gap-3 lg:grid-cols-[minmax(0,1fr)_16rem]">
                        <div className="tile-inset grid gap-4 p-4 sm:grid-cols-2">
                            <div>
                                <p className="font-mono text-2xs tracking-[0.12em] text-muted-foreground uppercase">
                                    Linked open incidents
                                </p>

                                <div className="mt-2 flex items-center gap-2">
                                    <AlertTriangle
                                        className={cn(
                                            'size-4',
                                            agent.open_incident_count > 0
                                                ? 'text-warning'
                                                : 'text-muted-foreground',
                                        )}
                                        aria-hidden="true"
                                    />
                                    <span className="text-lg font-semibold">
                                        {agent.open_incident_count}
                                    </span>
                                </div>
                            </div>

                            <div>
                                <p className="font-mono text-2xs tracking-[0.12em] text-muted-foreground uppercase">
                                    Last run
                                </p>

                                {agent.latest_run ? (
                                    <div className="mt-2 flex flex-wrap items-center gap-2">
                                        <Badge
                                            variant="outline"
                                            className={runTone(
                                                agent.latest_run.status,
                                            )}
                                        >
                                            {titleCase(agent.latest_run.status)}
                                        </Badge>

                                        <span className="text-xs text-muted-foreground">
                                            {relativeTime(
                                                agent.latest_run.started_at,
                                                generatedAt,
                                            )}
                                        </span>
                                    </div>
                                ) : (
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        No execution history yet
                                    </p>
                                )}
                            </div>
                        </div>

                        <div className="tile-inset flex min-h-24 flex-col justify-center p-4">
                            {hasDurationTrend ? (
                                <RunDurationSparkline
                                    activity={agent.recent_activity}
                                />
                            ) : (
                                <>
                                    <p className="font-mono text-2xs tracking-[0.12em] text-muted-foreground uppercase">
                                        Run duration
                                    </p>
                                    <p className="mt-2 text-xs leading-relaxed text-muted-foreground">
                                        Duration trend appears after two
                                        completed runs.
                                    </p>
                                </>
                            )}
                        </div>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2 border-t border-border-subtle bg-surface-recessed/40 px-5 py-3 font-mono text-2xs text-muted-foreground">
                    <span className="inline-flex items-center gap-1 rounded border border-primary/15 bg-primary/5 px-2 py-1 text-primary">
                        <ShieldCheck className="size-3" aria-hidden="true" />
                        system identity
                    </span>

                    <span className="rounded border border-border-subtle px-2 py-1">
                        {titleCase(agent.role)}
                    </span>

                    <span className="rounded border border-border-subtle px-2 py-1">
                        {titleCase(agent.harness)}
                    </span>

                    <span className="ml-auto inline-flex items-center gap-1 text-primary">
                        Open agent
                        <ChevronRight className="size-3" aria-hidden="true" />
                    </span>
                </div>
            </article>
        </Link>
    );
}

function SystemSignalsPanel({ system }: { system: SystemSignals }) {
    return (
        <section className="panel-elevated relative overflow-hidden p-4">
            <div className="glow-line-accent" />

            <div className="flex items-center gap-2">
                <Radar className="size-4 text-primary" aria-hidden="true" />
                <h2 className="font-mono text-xs font-semibold tracking-[0.12em] uppercase">
                    System signals
                </h2>
            </div>

            <div className="tile-inset mt-4 p-4">
                <p className="font-mono text-2xs tracking-[0.12em] text-muted-foreground uppercase">
                    Open recovery incidents
                </p>
                <div className="mt-2 flex items-end justify-between">
                    <span className="text-4xl font-semibold tracking-tight">
                        {system.open_incidents}
                    </span>
                    <AlertTriangle
                        className={cn(
                            'size-6',
                            system.open_incidents > 0
                                ? 'text-warning'
                                : 'text-muted-foreground',
                        )}
                        aria-hidden="true"
                    />
                </div>
            </div>

            <div className="mt-3 grid grid-cols-2 gap-3">
                <div className="tile-inset p-3">
                    <p className="font-mono text-2xs text-muted-foreground uppercase">
                        Enabled
                    </p>
                    <p className="mt-1 text-lg font-semibold">
                        {system.enabled_agents}/{system.total_agents}
                    </p>
                </div>

                <div className="tile-inset p-3">
                    <p className="font-mono text-2xs text-muted-foreground uppercase">
                        Active recovery
                    </p>
                    <p className="mt-1 text-lg font-semibold text-primary">
                        {system.active_recoveries}
                    </p>
                </div>
            </div>

            <p className="mt-3 text-2xs leading-relaxed text-muted-foreground">
                Raw durable Agent and RecoveryIncident signals only. No
                synthetic health score is calculated.
            </p>
        </section>
    );
}

function RecentEventsPanel({
    events,
    generatedAt,
}: {
    events: RecentEvent[];
    generatedAt: string;
}) {
    return (
        <section className="panel-elevated relative overflow-hidden p-4">
            <div className="glow-line-secondary" />

            <div className="flex items-center gap-2">
                <Activity
                    className="size-4 text-secondary-foreground"
                    aria-hidden="true"
                />
                <h2 className="font-mono text-xs font-semibold tracking-[0.12em] uppercase">
                    Recent events
                </h2>
            </div>

            <div className="mt-4 divide-y divide-border-subtle">
                {events.map((event) => (
                    <div
                        key={event.id}
                        className="flex gap-3 py-3 first:pt-0 last:pb-0"
                    >
                        <div className="mt-1 size-2 shrink-0 rounded-full bg-secondary-foreground shadow-glow-secondary" />

                        <div className="min-w-0 flex-1">
                            <p className="truncate text-xs font-medium text-foreground">
                                {titleCase(event.event_type)}
                            </p>
                            <p className="mt-1 truncate text-2xs text-muted-foreground">
                                {event.project?.name ?? 'AIOS'}
                            </p>
                        </div>

                        <time
                            className="shrink-0 font-mono text-2xs text-muted-foreground"
                            dateTime={event.occurred_at}
                        >
                            {relativeTime(event.occurred_at, generatedAt)}
                        </time>
                    </div>
                ))}

                {events.length === 0 && (
                    <p className="py-4 text-center text-xs text-muted-foreground">
                        No audit events recorded yet.
                    </p>
                )}
            </div>
        </section>
    );
}

function ActiveIncidentsPanel({
    incidents,
    generatedAt,
}: {
    incidents: ActiveIncident[];
    generatedAt: string;
}) {
    return (
        <section className="panel-elevated relative overflow-hidden p-4">
            <div className="glow-line-accent" />

            <div className="flex items-center gap-2">
                <AlertTriangle
                    className="size-4 text-warning"
                    aria-hidden="true"
                />
                <h2 className="font-mono text-xs font-semibold tracking-[0.12em] uppercase">
                    Active incidents
                </h2>
            </div>

            {incidents.length === 0 ? (
                <div className="tile-inset mt-4 flex items-center gap-3 p-4">
                    <CheckCircle2
                        className="size-5 shrink-0 text-success"
                        aria-hidden="true"
                    />
                    <div>
                        <p className="text-xs font-medium text-foreground">
                            No open recovery incidents
                        </p>
                        <p className="mt-1 text-2xs text-muted-foreground">
                            The durable recovery queue is clear.
                        </p>
                    </div>
                </div>
            ) : (
                <div className="mt-4 divide-y divide-border-subtle">
                    {incidents.map((incident) => (
                        <div
                            key={incident.id}
                            className="py-3 first:pt-0 last:pb-0"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="truncate text-xs font-medium text-foreground">
                                        {titleCase(
                                            incident.root_cause_category ??
                                                incident.failure_type,
                                        )}
                                    </p>

                                    <p className="mt-1 truncate text-2xs text-muted-foreground">
                                        {incident.project?.name ??
                                            'Unknown project'}
                                        {incident.task
                                            ? ` · ${incident.task.key}`
                                            : ''}
                                    </p>
                                </div>

                                <Badge
                                    variant="outline"
                                    className={incidentTone(incident.status)}
                                >
                                    {titleCase(incident.status)}
                                </Badge>
                            </div>

                            <div className="mt-2 flex items-center gap-1 font-mono text-2xs text-muted-foreground">
                                <Clock3 className="size-3" aria-hidden="true" />
                                {relativeTime(
                                    incident.detected_at,
                                    generatedAt,
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}

export default function AgentsIndex({
    agents,
    system,
    recent_events: recentEvents,
    active_incidents: activeIncidents,
    generated_at: generatedAt,
}: Props) {
    usePoll(
        5_000,
        {
            only: [
                'agents',
                'system',
                'recent_events',
                'active_incidents',
                'generated_at',
            ],
            preserveErrors: true,
        },
        { mode: 'rest' },
    );

    return (
        <>
            <Head title="Agents" />

            <div className="relative min-h-full overflow-hidden">
                <AppBackground contained />

                <div className="relative z-10 flex w-full flex-col gap-5 p-4 sm:p-6 lg:p-8">
                    <header className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div className="max-w-3xl">
                            <div className="mb-3 inline-flex items-center gap-2 rounded-md border border-secondary-foreground/15 bg-secondary/20 px-2.5 py-1 font-mono text-2xs tracking-[0.1em] text-secondary-foreground uppercase">
                                <LockKeyhole
                                    className="size-3"
                                    aria-hidden="true"
                                />
                                Protected AIOS system identities
                            </div>

                            <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">
                                AIOS agents
                            </h1>

                            <p className="mt-2 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                                AIOS-level reliability identities that operate
                                separately from project-scoped Phase 2 Agents.
                                Durable workflow state remains owned by AIOS.
                            </p>
                        </div>

                        <div className="inline-flex items-center gap-2 self-start rounded-md border border-primary/15 bg-primary/5 px-3 py-2 font-mono text-2xs text-muted-foreground">
                            <span className="status-glow-pulse size-2 rounded-full bg-primary text-primary" />
                            Live data · refreshes every 5s
                        </div>
                    </header>

                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <MetricCard
                            label="System agents"
                            value={system.total_agents}
                            detail="protected identities"
                            icon={Bot}
                        />
                        <MetricCard
                            label="Enabled"
                            value={system.enabled_agents}
                            detail="available"
                            icon={CheckCircle2}
                            iconClassName="border-success/20 bg-success/8 text-success"
                        />
                        <MetricCard
                            label="Open incidents"
                            value={system.open_incidents}
                            detail="recovery queue"
                            icon={AlertTriangle}
                            iconClassName="border-warning/20 bg-warning/8 text-warning"
                        />
                        <MetricCard
                            label="Active recovery"
                            value={system.active_recoveries}
                            detail="in progress"
                            icon={Cpu}
                            iconClassName="border-secondary-foreground/20 bg-secondary/20 text-secondary-foreground"
                        />
                    </section>

                    <div className="grid min-w-0 gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
                        <main className="min-w-0 space-y-4">
                            {agents.map((agent) => (
                                <AgentCommandCard
                                    key={agent.id}
                                    agent={agent}
                                    generatedAt={generatedAt}
                                />
                            ))}

                            {agents.length === 0 && (
                                <div className="panel-elevated p-10 text-center">
                                    <ShieldCheck
                                        className="mx-auto size-8 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <h2 className="mt-3 text-sm font-medium">
                                        No AIOS system agents
                                    </h2>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        No protected system identities are
                                        currently configured.
                                    </p>
                                </div>
                            )}
                        </main>

                        <aside className="space-y-4 xl:sticky xl:top-6 xl:self-start">
                            <SystemSignalsPanel system={system} />
                            <RecentEventsPanel
                                events={recentEvents}
                                generatedAt={generatedAt}
                            />
                            <ActiveIncidentsPanel
                                incidents={activeIncidents}
                                generatedAt={generatedAt}
                            />
                        </aside>
                    </div>
                </div>
            </div>
        </>
    );
}
