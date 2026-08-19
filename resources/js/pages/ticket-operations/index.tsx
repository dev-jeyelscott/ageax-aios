import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Bot,
    CheckCircle2,
    Clock3,
    ShieldAlert,
    TicketCheck,
    XCircle,
} from 'lucide-react';
import { AppBackground } from '@/components/app-background';
import { useAppHeaderSlot } from '@/components/app-header-slot';
import { Badge } from '@/components/ui/badge';

type OperationalView = {
    value: string;
    label: string;
    description: string;
    count: number;
    href: string;
};

type TicketSummary = {
    id: number;
    key: string;
    title: string;
    status: string;
    category: string | null;
    decision: string | null;
    final_priority: string | null;
    ai_suggested_priority: string | null;
    project: {
        id: number;
        name: string;
    };
    latest_triage: {
        number: number;
        status: string;
        agent_run_id: number | null;
        summary: string | null;
        escalation_reasons: string[];
    } | null;
    awaiting_response_until: string | null;
    triaged_at: string | null;
    inactivity_closed_at: string | null;
    updated_at: string | null;
    operations_url: string;
};

function humanize(value: string | null): string {
    if (!value) {
        return '—';
    }

    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

function viewIcon(value: string) {
    switch (value) {
        case 'needs_operator_decision':
            return ShieldAlert;
        case 'awaiting_requester':
            return Clock3;
        case 'recently_auto_converted':
            return CheckCircle2;
        case 'blocked_failed_triage':
            return XCircle;
        default:
            return TicketCheck;
    }
}

export default function TicketOperationsIndex({
    active_view,
    recent_window_days,
    views,
    tickets,
}: {
    active_view: string;
    recent_window_days: number;
    views: OperationalView[];
    tickets: TicketSummary[];
}) {
    const active = views.find((view) => view.value === active_view);

    useAppHeaderSlot(
        <div className="flex min-w-0 flex-1 items-center justify-between gap-3">
            <div className="min-w-0">
                <div className="flex items-center gap-2">
                    <ShieldAlert className="size-4 text-primary" />
                    <h1 className="truncate text-base font-semibold text-foreground">
                        Ticket Operations
                    </h1>
                </div>
                <p className="mt-0.5 truncate font-mono text-2xs text-muted-foreground">
                    Operator judgment only · routine triage remains autonomous
                </p>
            </div>
        </div>,
    );

    return (
        <>
            <Head title="Ticket Operations" />

            <div className="relative min-h-full overflow-hidden">
                <AppBackground contained />

                <div className="relative z-10 w-full space-y-5 p-4 sm:p-6 lg:p-8">
                    <section className="panel-elevated relative overflow-hidden p-5">
                        <div className="glow-line-accent" />
                        <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                            <div>
                                <div className="flex items-center gap-2 font-mono text-2xs tracking-[0.14em] text-primary uppercase">
                                    <Bot className="size-3.5" />
                                    Operational command center
                                </div>
                                <h2 className="mt-2 text-xl font-semibold tracking-tight text-foreground">
                                    Surface exceptions, not routine work
                                </h2>
                                <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                                    These queues are derived from durable
                                    Ticket, triage-attempt, conversion, and
                                    inactivity-close evidence. The UI does not
                                    own workflow state.
                                </p>
                            </div>
                            <span className="rounded-lg border border-primary/15 bg-primary/5 px-3 py-2 font-mono text-2xs text-primary">
                                Recent window: {recent_window_days} days
                            </span>
                        </div>
                    </section>

                    <section className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                        {views.map((view) => {
                            const Icon = viewIcon(view.value);
                            const selected = view.value === active_view;

                            return (
                                <Link
                                    key={view.value}
                                    href={view.href}
                                    preserveScroll
                                    className={`rounded-xl border p-4 transition ${
                                        selected
                                            ? 'border-primary/35 bg-primary/10 shadow-[0_0_24px_hsl(var(--primary)/0.08)]'
                                            : 'border-border/70 bg-card/70 hover:border-primary/20 hover:bg-card'
                                    }`}
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <Icon
                                            className={`size-4 ${
                                                selected
                                                    ? 'text-primary'
                                                    : 'text-muted-foreground'
                                            }`}
                                        />
                                        <span className="font-mono text-xl font-semibold text-foreground">
                                            {view.count}
                                        </span>
                                    </div>
                                    <p className="mt-3 text-sm font-medium text-foreground">
                                        {view.label}
                                    </p>
                                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                        {view.description}
                                    </p>
                                </Link>
                            );
                        })}
                    </section>

                    <section className="panel-elevated overflow-hidden">
                        <div className="flex flex-col gap-2 border-b border-border-subtle p-5 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <div className="flex items-center gap-2">
                                    {active_view ===
                                        'needs_operator_decision' && (
                                        <AlertTriangle className="size-4 text-destructive" />
                                    )}
                                    <h3 className="font-semibold text-foreground">
                                        {active?.label ?? 'Operational tickets'}
                                    </h3>
                                </div>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {active?.description}
                                </p>
                            </div>
                            <Badge variant="outline" className="font-mono">
                                {tickets.length} shown
                            </Badge>
                        </div>

                        {tickets.length === 0 ? (
                            <div className="p-10 text-center">
                                <CheckCircle2 className="mx-auto size-8 text-success" />
                                <p className="mt-3 text-sm font-medium text-foreground">
                                    No tickets in this operational queue
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Routine ticket handling continues without
                                    operator clicks.
                                </p>
                            </div>
                        ) : (
                            <div className="divide-y divide-border-subtle">
                                {tickets.map((ticket) => (
                                    <Link
                                        key={ticket.id}
                                        href={ticket.operations_url}
                                        className="grid gap-4 p-5 transition hover:bg-primary/[0.025] lg:grid-cols-[minmax(0,1fr)_auto]"
                                    >
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-mono text-xs text-primary">
                                                    {ticket.key}
                                                </span>
                                                <Badge variant="outline">
                                                    {humanize(ticket.status)}
                                                </Badge>
                                                {ticket.category && (
                                                    <Badge variant="secondary">
                                                        {humanize(
                                                            ticket.category,
                                                        )}
                                                    </Badge>
                                                )}
                                                {(ticket.final_priority ||
                                                    ticket.ai_suggested_priority) && (
                                                    <Badge variant="outline">
                                                        {humanize(
                                                            ticket.final_priority ??
                                                                ticket.ai_suggested_priority,
                                                        )}
                                                    </Badge>
                                                )}
                                            </div>
                                            <h4 className="mt-2 truncate text-sm font-semibold text-foreground">
                                                {ticket.title}
                                            </h4>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {ticket.project.name}
                                                {ticket.latest_triage?.summary
                                                    ? ` · ${ticket.latest_triage.summary}`
                                                    : ''}
                                            </p>
                                            {ticket.latest_triage &&
                                                ticket.latest_triage
                                                    .escalation_reasons.length >
                                                    0 && (
                                                    <div className="mt-2 flex flex-wrap gap-1.5">
                                                        {ticket.latest_triage.escalation_reasons.map(
                                                            (reason) => (
                                                                <span
                                                                    key={reason}
                                                                    className="rounded-md border border-destructive/20 bg-destructive/5 px-2 py-1 font-mono text-[10px] text-destructive"
                                                                >
                                                                    {humanize(
                                                                        reason,
                                                                    )}
                                                                </span>
                                                            ),
                                                        )}
                                                    </div>
                                                )}
                                        </div>

                                        <div className="grid content-start gap-1 text-left font-mono text-2xs text-muted-foreground lg:min-w-52 lg:text-right">
                                            <span>
                                                Decision:{' '}
                                                {humanize(ticket.decision)}
                                            </span>
                                            <span>
                                                Awaiting until:{' '}
                                                {formatDate(
                                                    ticket.awaiting_response_until,
                                                )}
                                            </span>
                                            <span>
                                                Triaged:{' '}
                                                {formatDate(ticket.triaged_at)}
                                            </span>
                                            <span>
                                                Auto-closed:{' '}
                                                {formatDate(
                                                    ticket.inactivity_closed_at,
                                                )}
                                            </span>
                                        </div>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}
