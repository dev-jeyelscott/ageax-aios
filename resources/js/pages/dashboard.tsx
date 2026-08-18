import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    Bot,
    CheckCircle2,
    Clock3,
    Code2,
    Cpu,
    FolderKanban,
    GitBranch,
    Play,
    ShieldCheck,
    Ticket as TicketIcon,
    TriangleAlert,
    Users,
    Wrench,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { index as agentsIndex } from '@/actions/App/Http/Controllers/GlobalAgentController';
import {
    index as projectsIndex,
    show as showProject,
} from '@/actions/App/Http/Controllers/ProjectController';
import { show as showTicket } from '@/actions/App/Http/Controllers/TicketController';
import { AppBackground } from '@/components/app-background';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type DashboardSummary = {
    active_projects: number;
    open_tasks: number;
    enabled_agents: number;
    running_executions: number;
    open_tickets: number;
    active_workers: number;
};

type ProjectPulse = {
    id: number;
    name: string;
    status: string;
    git_status: string;
    task_count: number;
    done_tasks: number;
    open_tasks: number;
    progress_percent: number;
    current_phase: {
        id: number;
        title: string;
        position: number;
    } | null;
    updated_at: string | null;
};

type Workflow = {
    queued: number;
    coding: number;
    validating: number;
    ready_for_review: number;
    reviewing: number;
    changes_required: number;
    done: number;
    blocked: number;
};

type ConsoleRun = {
    id: number;
    status: string;
    started_at: string | null;
    finished_at: string | null;
    task: {
        id: number;
        key: string;
        title: string;
    } | null;
};

type ConsoleAgent = {
    id: string;
    scope: 'project' | 'system';
    project_id: number | null;
    name: string;
    role: string;
    harness: string | null;
    model: string | null;
    enabled: boolean;
    runtime_status: string;
    runtime_source: string;
    last_heartbeat_at: string | null;
    last_run: ConsoleRun | null;
};

type AgentConsole = {
    project: {
        id: number;
        name: string;
        status: string;
    } | null;
    agents: ConsoleAgent[];
};

type ActivityItem = {
    id: number;
    event_type: string;
    occurred_at: string | null;
    project: {
        id: number;
        name: string;
    };
    task: {
        id: number;
        key: string;
        title: string;
    } | null;
};

type OpenTicket = {
    id: number;
    key: string;
    title: string;
    status: string;
    category: string | null;
    priority: string | null;
    updated_at: string | null;
    project: {
        id: number;
        name: string;
    };
};

type DashboardProps = {
    summary: DashboardSummary;
    projects: ProjectPulse[];
    workflow: Workflow;
    agent_console: AgentConsole;
    recent_activity: ActivityItem[];
    open_tickets: OpenTicket[];
    generated_at: string;
};

type WorkflowKey =
    | 'queued'
    | 'coding'
    | 'validating'
    | 'ready_for_review'
    | 'reviewing'
    | 'done';

const workflowStages: {
    key: WorkflowKey;
    label: string;
    icon: LucideIcon;
}[] = [
    {
        key: 'queued',
        label: 'Queued',
        icon: Clock3,
    },
    {
        key: 'coding',
        label: 'Coding',
        icon: Code2,
    },
    {
        key: 'validating',
        label: 'Validating',
        icon: ShieldCheck,
    },
    {
        key: 'ready_for_review',
        label: 'Ready for review',
        icon: Users,
    },
    {
        key: 'reviewing',
        label: 'Reviewing',
        icon: Activity,
    },
    {
        key: 'done',
        label: 'Done',
        icon: CheckCircle2,
    },
];

function humanize(value: string | null): string {
    if (!value) {
        return '—';
    }

    return value
        .replaceAll('_', ' ')
        .replaceAll('.', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

function formatTimestamp(value: string | null): string {
    if (!value) {
        return 'No activity';
    }

    const timestamp = new Date(value);

    if (Number.isNaN(timestamp.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone: 'UTC',
        timeZoneName: 'short',
    }).format(timestamp);
}

function harnessLabel(value: string | null): string {
    switch (value) {
        case 'codex':
            return 'Codex';
        case 'claude_code':
            return 'Claude Code';
        case null:
            return 'Not configured';
        default:
            return humanize(value);
    }
}

function statusTone(status: string): string {
    if (
        ['running', 'working', 'completed', 'done', 'converted'].includes(
            status,
        )
    ) {
        return 'border-success/25 bg-success/10 text-success';
    }

    if (
        [
            'paused',
            'awaiting_requester',
            'recovering',
            'changes_required',
        ].includes(status)
    ) {
        return 'border-warning/25 bg-warning/10 text-warning';
    }

    if (['blocked', 'failed', 'interrupted', 'escalated'].includes(status)) {
        return 'border-destructive/25 bg-destructive/10 text-destructive';
    }

    if (
        [
            'coding',
            'validating',
            'ready_for_review',
            'reviewing',
            'triaging',
            'open',
        ].includes(status)
    ) {
        return 'border-primary/25 bg-primary/10 text-primary';
    }

    return 'border-border bg-muted/30 text-muted-foreground';
}

function priorityTone(priority: string | null): string {
    if (priority === 'critical' || priority === 'high') {
        return 'border-destructive/25 bg-destructive/10 text-destructive';
    }

    if (priority === 'medium' || priority === 'normal') {
        return 'border-warning/25 bg-warning/10 text-warning';
    }

    if (priority === 'low') {
        return 'border-primary/25 bg-primary/10 text-primary';
    }

    return 'border-border bg-muted/30 text-muted-foreground';
}

function roleIcon(role: string): LucideIcon {
    switch (role) {
        case 'coder':
            return Code2;
        case 'reviewer':
            return ShieldCheck;
        case 'recovery_engineer':
            return Wrench;
        default:
            return Bot;
    }
}

function MetricCard({
    label,
    value,
    detail,
    icon: Icon,
    tone = 'primary',
}: {
    label: string;
    value: number;
    detail: string;
    icon: LucideIcon;
    tone?: 'primary' | 'success' | 'warning' | 'secondary';
}) {
    const iconTone = {
        primary: 'border-primary/20 bg-primary/8 text-primary',
        success: 'border-success/20 bg-success/8 text-success',
        warning: 'border-warning/20 bg-warning/8 text-warning',
        secondary:
            'border-secondary-foreground/20 bg-secondary/20 text-secondary-foreground',
    }[tone];

    return (
        <div className="panel-elevated group relative overflow-hidden p-4 transition-colors hover:border-primary/30">
            <div className="glow-line-accent opacity-70 transition-opacity group-hover:opacity-100" />

            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <p className="font-mono text-2xs tracking-[0.13em] text-muted-foreground uppercase">
                        {label}
                    </p>

                    <p className="mt-2 text-3xl font-semibold tracking-tight text-foreground">
                        {value}
                    </p>

                    <p className="mt-1 truncate text-xs text-muted-foreground">
                        {detail}
                    </p>
                </div>

                <div
                    className={cn(
                        'flex size-10 shrink-0 items-center justify-center rounded-lg border',
                        iconTone,
                    )}
                >
                    <Icon className="size-5" aria-hidden="true" />
                </div>
            </div>
        </div>
    );
}

function PanelHeader({
    title,
    icon: Icon,
    detail,
    href,
    linkLabel,
}: {
    title: string;
    icon: LucideIcon;
    detail?: string;
    href?: string;
    linkLabel?: string;
}) {
    return (
        <div className="flex min-h-12 items-center justify-between gap-3 border-b border-border-subtle px-4 py-3">
            <div className="min-w-0">
                <div className="flex items-center gap-2">
                    <Icon
                        className="size-4 shrink-0 text-primary"
                        aria-hidden="true"
                    />

                    <h2 className="truncate text-sm font-semibold tracking-tight text-foreground">
                        {title}
                    </h2>
                </div>

                {detail && (
                    <p className="mt-1 truncate text-2xs text-muted-foreground">
                        {detail}
                    </p>
                )}
            </div>

            {href && linkLabel && (
                <Link
                    href={href}
                    className="inline-flex shrink-0 items-center gap-1 font-mono text-2xs text-primary transition hover:text-primary/80 focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    {linkLabel}
                    <ArrowRight className="size-3" aria-hidden="true" />
                </Link>
            )}
        </div>
    );
}

function ProjectPulsePanel({ projects }: { projects: ProjectPulse[] }) {
    return (
        <section className="panel-elevated relative min-w-0 overflow-hidden">
            <div className="glow-line-accent" />

            <PanelHeader
                title="Project Pulse"
                detail="Latest durable project state"
                icon={Activity}
                href={projectsIndex().url}
                linkLabel="View all"
            />

            <div className="grid gap-3 p-4">
                {projects.map((project) => (
                    <Link
                        key={project.id}
                        href={showProject(project.id).url}
                        className="tile-inset group relative overflow-hidden p-3 transition hover:border-primary/30 hover:bg-primary/[0.04] focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <div className="flex items-start gap-3">
                            <div className="flex size-9 shrink-0 items-center justify-center rounded-lg border border-primary/20 bg-primary/8 text-primary">
                                <FolderKanban
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            </div>

                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-semibold text-foreground transition group-hover:text-primary">
                                            {project.name}
                                        </p>

                                        <p className="mt-0.5 truncate text-xs text-muted-foreground">
                                            {project.current_phase
                                                ? `Phase ${project.current_phase.position} · ${project.current_phase.title}`
                                                : 'No phase data'}
                                        </p>
                                    </div>

                                    <Badge
                                        variant="outline"
                                        className={statusTone(project.status)}
                                    >
                                        {humanize(project.status)}
                                    </Badge>
                                </div>

                                <div className="mt-3 flex items-center gap-3">
                                    <div className="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-muted/40">
                                        <div
                                            className="progress-flow h-full rounded-full"
                                            style={{
                                                width: `${project.progress_percent}%`,
                                            }}
                                        />
                                    </div>

                                    <span className="font-mono text-xs font-semibold text-primary">
                                        {project.progress_percent}%
                                    </span>
                                </div>

                                <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 font-mono text-2xs text-muted-foreground">
                                    <span>{project.task_count} tasks</span>
                                    <span>{project.open_tasks} open</span>
                                    <span>
                                        Git: {humanize(project.git_status)}
                                    </span>
                                    <span>
                                        {formatTimestamp(project.updated_at)}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </Link>
                ))}

                {projects.length === 0 && (
                    <div className="py-8 text-center">
                        <FolderKanban
                            className="mx-auto size-7 text-muted-foreground"
                            aria-hidden="true"
                        />

                        <p className="mt-3 text-sm font-medium text-foreground">
                            No projects registered
                        </p>

                        <p className="mt-1 text-xs text-muted-foreground">
                            Register a workspace project to begin orchestration.
                        </p>
                    </div>
                )}
            </div>
        </section>
    );
}

function WorkflowPanel({ workflow }: { workflow: Workflow }) {
    return (
        <section className="panel-elevated relative min-w-0 overflow-hidden">
            <div className="glow-line-accent" />

            <PanelHeader
                title="Deterministic Workflow"
                detail="Current Task state-machine distribution"
                icon={GitBranch}
            />

            <div className="p-4">
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 2xl:grid-cols-6">
                    {workflowStages.map((stage) => {
                        const Icon = stage.icon;

                        return (
                            <div
                                key={stage.key}
                                className="tile-inset relative overflow-hidden px-3 py-4 text-center"
                            >
                                <div className="mx-auto flex size-8 items-center justify-center rounded-full border border-primary/20 bg-primary/8 text-primary shadow-glow-sm">
                                    <Icon
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                </div>

                                <p className="mt-2 font-mono text-2xs tracking-[0.08em] text-muted-foreground uppercase">
                                    {stage.label}
                                </p>

                                <p className="mt-1 text-xl font-semibold text-foreground">
                                    {workflow[stage.key]}
                                </p>

                                <p className="text-2xs text-muted-foreground">
                                    tasks
                                </p>
                            </div>
                        );
                    })}
                </div>

                <div className="mt-4 h-1 overflow-hidden rounded-full bg-muted/30">
                    <div className="pipeline-flow h-full w-full" />
                </div>

                <div className="mt-4 grid gap-3 sm:grid-cols-2">
                    <div className="tile-inset flex items-center justify-between gap-3 p-3">
                        <div className="flex items-center gap-2">
                            <TriangleAlert
                                className={cn(
                                    'size-4',
                                    workflow.changes_required > 0
                                        ? 'text-warning'
                                        : 'text-muted-foreground',
                                )}
                                aria-hidden="true"
                            />

                            <span className="text-xs text-muted-foreground">
                                Changes required
                            </span>
                        </div>

                        <span className="font-mono text-sm font-semibold text-warning">
                            {workflow.changes_required}
                        </span>
                    </div>

                    <div className="tile-inset flex items-center justify-between gap-3 p-3">
                        <div className="flex items-center gap-2">
                            <TriangleAlert
                                className={cn(
                                    'size-4',
                                    workflow.blocked > 0
                                        ? 'text-destructive'
                                        : 'text-muted-foreground',
                                )}
                                aria-hidden="true"
                            />

                            <span className="text-xs text-muted-foreground">
                                Blocked
                            </span>
                        </div>

                        <span className="font-mono text-sm font-semibold text-destructive">
                            {workflow.blocked}
                        </span>
                    </div>
                </div>
            </div>
        </section>
    );
}

function AgentConsolePanel({ agentConsole }: { agentConsole: AgentConsole }) {
    return (
        <section className="panel-elevated relative min-w-0 overflow-hidden">
            <div className="glow-line-secondary" />

            <PanelHeader
                title="Agent Console"
                detail={
                    agentConsole.project
                        ? `Workflow scope · ${agentConsole.project.name}`
                        : 'No project workflow selected'
                }
                icon={Cpu}
                href={agentsIndex().url}
                linkLabel="Manage"
            />

            <div className="grid gap-3 p-4 min-[1800px]:grid-cols-2 sm:grid-cols-2 2xl:grid-cols-1">
                {agentConsole.agents.map((agent) => {
                    const Icon = roleIcon(agent.role);
                    const isActive = [
                        'working',
                        'recovering',
                        'running',
                    ].includes(agent.runtime_status);

                    return (
                        <article
                            key={agent.id}
                            className={cn(
                                'tile-inset relative min-w-0 overflow-hidden p-3',
                                isActive && 'agent-card-active',
                            )}
                        >
                            <div className="relative z-10">
                                <div className="flex items-start gap-3">
                                    <div
                                        className={cn(
                                            'flex size-10 shrink-0 items-center justify-center rounded-lg border',
                                            agent.role === 'recovery_engineer'
                                                ? 'border-warning/25 bg-warning/10 text-warning'
                                                : agent.role === 'coder'
                                                  ? 'border-secondary-foreground/20 bg-secondary/20 text-secondary-foreground'
                                                  : 'border-primary/20 bg-primary/8 text-primary',
                                        )}
                                    >
                                        <Icon
                                            className="size-5"
                                            aria-hidden="true"
                                        />
                                    </div>

                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-semibold text-foreground">
                                                    {agent.name}
                                                </p>

                                                <p className="mt-0.5 font-mono text-2xs text-muted-foreground uppercase">
                                                    {humanize(agent.role)}
                                                </p>
                                            </div>

                                            <Badge
                                                variant="outline"
                                                className={statusTone(
                                                    agent.runtime_status,
                                                )}
                                            >
                                                {isActive && (
                                                    <span className="status-glow-pulse mr-1.5 size-1.5 rounded-full bg-current" />
                                                )}

                                                {humanize(agent.runtime_status)}
                                            </Badge>
                                        </div>

                                        <div className="mt-3 grid grid-cols-2 gap-2">
                                            <div>
                                                <p className="font-mono text-2xs tracking-[0.08em] text-muted-foreground uppercase">
                                                    Harness
                                                </p>
                                                <p className="mt-1 truncate text-xs text-foreground">
                                                    {harnessLabel(
                                                        agent.harness,
                                                    )}
                                                </p>
                                            </div>

                                            <div>
                                                <p className="font-mono text-2xs tracking-[0.08em] text-muted-foreground uppercase">
                                                    Scope
                                                </p>
                                                <p className="mt-1 truncate text-xs text-foreground">
                                                    {agent.scope === 'system'
                                                        ? 'AIOS system'
                                                        : 'Project worker'}
                                                </p>
                                            </div>
                                        </div>

                                        <div className="mt-3 border-t border-border-subtle pt-2 font-mono text-2xs text-muted-foreground">
                                            {agent.last_run?.task ? (
                                                <span>
                                                    {agent.last_run.task.key} ·{' '}
                                                    {agent.last_run.task.title}
                                                </span>
                                            ) : agent.last_run ? (
                                                <span>
                                                    Last run:{' '}
                                                    {humanize(
                                                        agent.last_run.status,
                                                    )}
                                                </span>
                                            ) : (
                                                <span>No run evidence yet</span>
                                            )}

                                            {agent.last_heartbeat_at && (
                                                <span className="mt-1 block">
                                                    Heartbeat:{' '}
                                                    {formatTimestamp(
                                                        agent.last_heartbeat_at,
                                                    )}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </article>
                    );
                })}

                {agentConsole.agents.length === 0 && (
                    <div className="col-span-full py-8 text-center">
                        <Bot
                            className="mx-auto size-7 text-muted-foreground"
                            aria-hidden="true"
                        />

                        <p className="mt-3 text-sm font-medium text-foreground">
                            No Agent runtime evidence
                        </p>

                        <p className="mt-1 text-xs text-muted-foreground">
                            Select or create a project to populate workflow
                            Agents.
                        </p>
                    </div>
                )}
            </div>
        </section>
    );
}

function RecentActivityPanel({ activity }: { activity: ActivityItem[] }) {
    return (
        <section className="panel-elevated relative min-w-0 overflow-hidden">
            <div className="glow-line-accent" />

            <PanelHeader
                title="Recent Activity"
                detail="Append-only AIOS audit evidence"
                icon={Activity}
            />

            <div className="divide-y divide-border-subtle px-4">
                {activity.map((item) => (
                    <Link
                        key={item.id}
                        href={showProject(item.project.id).url}
                        className="group flex min-w-0 items-start gap-3 py-3 focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <span className="status-glow-pulse mt-1.5 size-2 shrink-0 rounded-full bg-primary text-primary" />

                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm text-foreground transition group-hover:text-primary">
                                {humanize(item.event_type)}
                            </p>

                            <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 font-mono text-2xs text-muted-foreground">
                                <span>{item.project.name}</span>

                                {item.task && (
                                    <>
                                        <span>·</span>
                                        <span>{item.task.key}</span>
                                    </>
                                )}
                            </div>
                        </div>

                        <time className="shrink-0 font-mono text-2xs text-muted-foreground">
                            {formatTimestamp(item.occurred_at)}
                        </time>
                    </Link>
                ))}

                {activity.length === 0 && (
                    <div className="py-10 text-center">
                        <Activity
                            className="mx-auto size-7 text-muted-foreground"
                            aria-hidden="true"
                        />

                        <p className="mt-3 text-sm font-medium text-foreground">
                            No audit activity yet
                        </p>

                        <p className="mt-1 text-xs text-muted-foreground">
                            Durable workflow events will appear here.
                        </p>
                    </div>
                )}
            </div>
        </section>
    );
}

function OpenTicketsPanel({ tickets }: { tickets: OpenTicket[] }) {
    return (
        <section className="panel-elevated relative min-w-0 overflow-hidden">
            <div className="glow-line-secondary" />

            <PanelHeader
                title="Open Tickets"
                detail="Project intake requiring continued lifecycle handling"
                icon={TicketIcon}
            />

            <div className="divide-y divide-border-subtle px-4">
                {tickets.map((ticket) => (
                    <Link
                        key={ticket.id}
                        href={
                            showTicket({
                                project: ticket.project.id,
                                ticket: ticket.id,
                            }).url
                        }
                        className="group flex min-w-0 items-start gap-3 py-3 focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <span
                            className={cn(
                                'mt-1.5 size-2 shrink-0 rounded-full',
                                ticket.priority === 'critical' ||
                                    ticket.priority === 'high'
                                    ? 'bg-destructive'
                                    : ticket.priority === 'medium' ||
                                        ticket.priority === 'normal'
                                      ? 'bg-warning'
                                      : 'bg-primary',
                            )}
                        />

                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-mono text-2xs text-primary">
                                    {ticket.key}
                                </span>

                                <Badge
                                    variant="outline"
                                    className={statusTone(ticket.status)}
                                >
                                    {humanize(ticket.status)}
                                </Badge>

                                {ticket.priority && (
                                    <Badge
                                        variant="outline"
                                        className={priorityTone(
                                            ticket.priority,
                                        )}
                                    >
                                        {humanize(ticket.priority)}
                                    </Badge>
                                )}
                            </div>

                            <p className="mt-1.5 truncate text-sm text-foreground transition group-hover:text-primary">
                                {ticket.title}
                            </p>

                            <div className="mt-1 flex flex-wrap gap-x-2 font-mono text-2xs text-muted-foreground">
                                <span>{ticket.project.name}</span>

                                {ticket.category && (
                                    <>
                                        <span>·</span>
                                        <span>{humanize(ticket.category)}</span>
                                    </>
                                )}
                            </div>
                        </div>

                        <time className="shrink-0 font-mono text-2xs text-muted-foreground">
                            {formatTimestamp(ticket.updated_at)}
                        </time>
                    </Link>
                ))}

                {tickets.length === 0 && (
                    <div className="py-10 text-center">
                        <CheckCircle2
                            className="mx-auto size-7 text-success"
                            aria-hidden="true"
                        />

                        <p className="mt-3 text-sm font-medium text-foreground">
                            No open tickets
                        </p>

                        <p className="mt-1 text-xs text-muted-foreground">
                            There is no active Ticket intake requiring
                            attention.
                        </p>
                    </div>
                )}
            </div>
        </section>
    );
}

export default function Dashboard({
    summary,
    projects,
    workflow,
    agent_console: agentConsole,
    recent_activity: recentActivity,
    open_tickets: openTickets,
    generated_at: generatedAt,
}: DashboardProps) {
    return (
        <>
            <Head title="Dashboard" />

            <div className="relative min-h-full overflow-hidden">
                <AppBackground contained />

                <div className="relative z-10 flex w-full flex-col gap-5 p-4 sm:p-6 lg:p-8">
                    <header className="relative overflow-hidden rounded-xl border border-primary/15 bg-card/45 px-5 py-5 shadow-panel sm:px-6">
                        <div className="glow-line-accent" />

                        <div className="pointer-events-none absolute inset-y-0 right-0 hidden w-1/2 bg-[radial-gradient(circle_at_center,color-mix(in_oklch,var(--primary)_12%,transparent),transparent_65%)] lg:block" />

                        <div className="relative flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
                            <div className="max-w-3xl">
                                <div className="mb-3 inline-flex items-center gap-2 rounded-md border border-primary/15 bg-primary/5 px-2.5 py-1 font-mono text-2xs tracking-[0.1em] text-primary uppercase">
                                    <Cpu
                                        className="size-3"
                                        aria-hidden="true"
                                    />
                                    AI Operations Command Center
                                </div>

                                <div className="flex flex-wrap items-center gap-3">
                                    <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">
                                        AGEAX AIOS 2.0
                                    </h1>

                                    <Badge
                                        variant="outline"
                                        className="border-success/25 bg-success/10 text-success"
                                    >
                                        <span className="status-glow-pulse mr-1.5 size-1.5 rounded-full bg-success text-success" />
                                        Durable system snapshot
                                    </Badge>
                                </div>

                                <p className="mt-2 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                                    Deterministic AI development orchestration,
                                    workflow state, Agent execution, and project
                                    intake from persisted AIOS evidence.
                                </p>

                                <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 font-mono text-2xs text-muted-foreground">
                                    <span>MODE: Deterministic</span>
                                    <span>EXECUTION: Codex + Claude Code</span>
                                    <span>
                                        SNAPSHOT: {formatTimestamp(generatedAt)}
                                    </span>
                                </div>
                            </div>

                            <div className="flex flex-wrap gap-2 xl:justify-end">
                                <Button asChild className="shadow-glow-sm">
                                    <Link href={projectsIndex().url}>
                                        <FolderKanban
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Open projects
                                    </Link>
                                </Button>

                                <Button
                                    variant="outline"
                                    asChild
                                    className="border-primary/20 bg-primary/5"
                                >
                                    <Link href={agentsIndex().url}>
                                        <Bot
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Manage Agents
                                    </Link>
                                </Button>
                            </div>
                        </div>
                    </header>

                    <section
                        className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6"
                        aria-label="System summary"
                    >
                        <MetricCard
                            label="Active projects"
                            value={summary.active_projects}
                            detail="running now"
                            icon={FolderKanban}
                            tone="primary"
                        />

                        <MetricCard
                            label="Open tasks"
                            value={summary.open_tasks}
                            detail="not done or cancelled"
                            icon={GitBranch}
                            tone="secondary"
                        />

                        <MetricCard
                            label="Enabled Agents"
                            value={summary.enabled_agents}
                            detail="project configurations"
                            icon={Bot}
                            tone="primary"
                        />

                        <MetricCard
                            label="Running executions"
                            value={summary.running_executions}
                            detail="active AgentRuns"
                            icon={Play}
                            tone="success"
                        />

                        <MetricCard
                            label="Open tickets"
                            value={summary.open_tickets}
                            detail="active intake lifecycle"
                            icon={TicketIcon}
                            tone="warning"
                        />

                        <MetricCard
                            label="Active workers"
                            value={summary.active_workers}
                            detail="working or recovering"
                            icon={Cpu}
                            tone="success"
                        />
                    </section>

                    <section className="grid min-w-0 gap-4 2xl:grid-cols-[1.05fr_1.25fr_1.05fr]">
                        <ProjectPulsePanel projects={projects} />

                        <WorkflowPanel workflow={workflow} />

                        <AgentConsolePanel agentConsole={agentConsole} />
                    </section>

                    <section className="grid min-w-0 gap-4 xl:grid-cols-2">
                        <RecentActivityPanel activity={recentActivity} />
                        <OpenTicketsPanel tickets={openTickets} />
                    </section>

                    <footer className="panel-recessed flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex flex-wrap items-center gap-x-5 gap-y-2 font-mono text-2xs text-muted-foreground">
                            <span className="inline-flex items-center gap-2">
                                <span
                                    className={cn(
                                        'size-1.5 rounded-full',
                                        summary.active_workers > 0
                                            ? 'status-glow-pulse bg-success text-success'
                                            : 'bg-muted-foreground',
                                    )}
                                />
                                Workers: {summary.active_workers}
                            </span>

                            <span>
                                Executions: {summary.running_executions}
                            </span>

                            <span>
                                Changes required: {workflow.changes_required}
                            </span>

                            <span>Blocked: {workflow.blocked}</span>
                        </div>

                        <div className="flex items-center gap-2 font-mono text-2xs text-primary">
                            <ShieldCheck
                                className="size-3.5"
                                aria-hidden="true"
                            />
                            AIOS remains workflow authority
                        </div>
                    </footer>
                </div>
            </div>
        </>
    );
}
