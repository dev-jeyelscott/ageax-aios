import { Form, Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    ExternalLink,
    FileWarning,
    GitBranch,
    MessageSquare,
    ShieldAlert,
} from 'lucide-react';
import { AppBackground } from '@/components/app-background';
import { useAppHeaderSlot } from '@/components/app-header-slot';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

type Ticket = {
    id: number;
    key: string;
    title: string;
    description: string;
    status: string;
    category: string | null;
    decision: string | null;
    requester_urgency: string | null;
    ai_suggested_priority: string | null;
    final_priority: string | null;
    triage_confidence: string | null;
    awaiting_response_until: string | null;
    triaged_at: string | null;
    closed_at: string | null;
    inactivity_closed_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    project: {
        id: number;
        name: string;
    };
    converted_task: {
        id: number;
        key: string;
        title: string;
        status: string;
    } | null;
};

type ProposedTask = {
    title?: string;
    objective?: string;
    acceptance_criteria?: string[];
    scope?: string[];
    constraints?: string[];
    relevant_paths?: string[];
    verification_commands?: string[];
    depends_on_task_ids?: number[];
    preferred_phase_id?: number | null;
};

type Triage = {
    attempt_id: number;
    number: number;
    status: string;
    agent_run_id: number | null;
    agent_run_url: string | null;
    finished_at: string | null;
    classification: {
        category: string | null;
        decision: string | null;
        confidence: number | null;
        complexity: string | null;
        suggested_priority: string | null;
        implementation_required: boolean;
    };
    summary: string | null;
    decision_evidence: string | null;
    documentation_alignment: string[];
    escalation_flags: string[];
    aios_escalation_reasons: string[];
    requester_reply: string | null;
    questions: string[];
    blockers: string[];
    proposed_task: ProposedTask | null;
    phase_placement_consequence: string;
    critical_roadmap_interruption: boolean;
};

type OperatorDecision = {
    id: number;
    action: string;
    direction: string | null;
    decided_by: string | null;
    created_at: string | null;
} | null;

type OperatorAction = {
    active: boolean;
    url: string | null;
    approval_action: string;
    approval_label: string;
    reject_action: string;
    request_information_action: string;
    provide_direction_action: string;
};

type Links = {
    operations_index: string;
    ticket_detail: string;
    project: string;
    converted_task: string | null;
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

function StringList({ items }: { items: string[] }) {
    if (items.length === 0) {
        return (
            <p className="text-xs text-muted-foreground">
                No evidence recorded.
            </p>
        );
    }

    return (
        <ul className="grid gap-2 text-sm text-foreground">
            {items.map((item) => (
                <li
                    key={item}
                    className="rounded-lg border border-border/70 bg-surface-recessed/60 p-3"
                >
                    {item}
                </li>
            ))}
        </ul>
    );
}

function DirectionForm({
    url,
    action,
    label,
    description,
    publicReply = false,
}: {
    url: string;
    action: string;
    label: string;
    description: string;
    publicReply?: boolean;
}) {
    return (
        <Form
            action={url}
            method="post"
            className="grid gap-3 rounded-xl border border-border/70 bg-card/60 p-4"
        >
            {({ processing, errors }) => (
                <>
                    <input type="hidden" name="action" value={action} />
                    <div>
                        <p className="text-sm font-medium text-foreground">
                            {label}
                        </p>
                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                            {description}
                        </p>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor={`direction-${action}`}>
                            {publicReply
                                ? 'Requester message'
                                : 'Operator direction'}
                        </Label>
                        <Textarea
                            id={`direction-${action}`}
                            name="direction"
                            required
                            maxLength={8000}
                            rows={4}
                            placeholder={
                                publicReply
                                    ? 'Ask for the exact missing information…'
                                    : 'Record the bounded direction that should inform the next fresh PM triage attempt…'
                            }
                        />
                        <InputError message={errors.direction} />
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={processing}
                    >
                        {label}
                    </Button>
                </>
            )}
        </Form>
    );
}

export default function TicketOperationsShow({
    ticket,
    triage,
    operator_decision,
    operator_action,
    links,
}: {
    ticket: Ticket;
    triage: Triage | null;
    operator_decision: OperatorDecision;
    operator_action: OperatorAction;
    links: Links;
}) {
    useAppHeaderSlot(
        <div className="flex min-w-0 flex-1 flex-wrap items-center justify-between gap-3">
            <div className="flex min-w-0 items-center gap-3">
                <Link
                    href={links.operations_index}
                    aria-label="Back to Ticket Operations"
                    className="grid size-8 shrink-0 place-items-center rounded-lg border border-border bg-card/60 text-muted-foreground transition hover:border-primary/30 hover:text-primary"
                >
                    <ArrowLeft className="size-4" />
                </Link>
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <span className="font-mono text-xs text-primary">
                            {ticket.key}
                        </span>
                        <Badge variant="outline">
                            {humanize(ticket.status)}
                        </Badge>
                    </div>
                    <h1 className="mt-0.5 truncate text-base font-semibold text-foreground">
                        {ticket.title}
                    </h1>
                </div>
            </div>

            <Link
                href={links.ticket_detail}
                className="inline-flex items-center gap-1.5 text-xs text-muted-foreground transition hover:text-primary"
            >
                Open conversation
                <ExternalLink className="size-3.5" />
            </Link>
        </div>,
    );

    return (
        <>
            <Head title={`${ticket.key} Operations`} />

            <div className="relative min-h-full overflow-hidden">
                <AppBackground contained />

                <div className="relative z-10 grid w-full gap-5 p-4 sm:p-6 lg:p-8 xl:grid-cols-[minmax(0,1.35fr)_minmax(22rem,0.65fr)]">
                    <main className="min-w-0 space-y-5">
                        {triage?.critical_roadmap_interruption && (
                            <div className="rounded-xl border border-destructive/35 bg-destructive/10 p-5">
                                <div className="flex items-start gap-3">
                                    <AlertTriangle className="mt-0.5 size-5 shrink-0 text-destructive" />
                                    <div>
                                        <h2 className="font-semibold text-foreground">
                                            Critical roadmap interruption
                                            requires explicit approval
                                        </h2>
                                        <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                            Viewing, editing, commenting on, or
                                            otherwise touching this Ticket does
                                            not approve roadmap interruption.
                                            Only the dedicated approval action
                                            below records affirmative consent,
                                            after which fresh PM triage and
                                            commit-time AIOS placement
                                            validation still apply.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}

                        <Card className="panel-elevated border-border/70 bg-card/70 text-foreground">
                            <CardHeader>
                                <CardTitle>Ticket request</CardTitle>
                                <CardDescription>
                                    Original durable requester evidence ·{' '}
                                    {ticket.project.name}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div>
                                    <p className="text-sm font-medium text-foreground">
                                        {ticket.title}
                                    </p>
                                    <p className="mt-2 text-sm leading-6 whitespace-pre-wrap text-muted-foreground">
                                        {ticket.description}
                                    </p>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <Badge variant="outline">
                                        {humanize(ticket.status)}
                                    </Badge>
                                    <Badge variant="secondary">
                                        {humanize(ticket.category)}
                                    </Badge>
                                    <Badge variant="outline">
                                        Urgency:{' '}
                                        {humanize(ticket.requester_urgency)}
                                    </Badge>
                                    <Badge variant="outline">
                                        Priority:{' '}
                                        {humanize(
                                            ticket.final_priority ??
                                                ticket.ai_suggested_priority,
                                        )}
                                    </Badge>
                                </div>
                            </CardContent>
                        </Card>

                        <Card className="panel-elevated border-border/70 bg-card/70 text-foreground">
                            <CardHeader>
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <CardTitle>
                                            PM classification & escalation
                                            evidence
                                        </CardTitle>
                                        <CardDescription>
                                            Durable structured result only · no
                                            chain-of-thought
                                        </CardDescription>
                                    </div>
                                    {triage?.agent_run_url && (
                                        <Link
                                            href={triage.agent_run_url}
                                            className="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-primary/20 bg-primary/5 px-3 py-2 font-mono text-2xs text-primary transition hover:bg-primary/10"
                                        >
                                            AgentRun #{triage.agent_run_id}
                                            <ExternalLink className="size-3" />
                                        </Link>
                                    )}
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-5">
                                {triage === null ? (
                                    <p className="text-sm text-muted-foreground">
                                        No triage attempt exists yet.
                                    </p>
                                ) : (
                                    <>
                                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                            {[
                                                [
                                                    'Category',
                                                    triage.classification
                                                        .category,
                                                ],
                                                [
                                                    'Decision',
                                                    triage.classification
                                                        .decision,
                                                ],
                                                [
                                                    'Confidence',
                                                    triage.classification.confidence?.toString() ??
                                                        null,
                                                ],
                                                [
                                                    'Complexity',
                                                    triage.classification
                                                        .complexity,
                                                ],
                                                [
                                                    'Suggested priority',
                                                    triage.classification
                                                        .suggested_priority,
                                                ],
                                                [
                                                    'Implementation',
                                                    triage.classification
                                                        .implementation_required
                                                        ? 'required'
                                                        : 'not required',
                                                ],
                                            ].map(([label, value]) => (
                                                <div
                                                    key={label}
                                                    className="rounded-lg border border-border/70 bg-surface-recessed/50 p-3"
                                                >
                                                    <p className="font-mono text-2xs text-muted-foreground uppercase">
                                                        {label}
                                                    </p>
                                                    <p className="mt-1 text-sm font-medium text-foreground">
                                                        {humanize(value)}
                                                    </p>
                                                </div>
                                            ))}
                                        </div>

                                        <div>
                                            <p className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                                PM summary
                                            </p>
                                            <p className="rounded-lg border border-border/70 bg-surface-recessed/50 p-3 text-sm leading-6 text-foreground">
                                                {triage.summary ??
                                                    'No summary recorded.'}
                                            </p>
                                        </div>

                                        <div>
                                            <div className="mb-2 flex items-center gap-2">
                                                <FileWarning className="size-4 text-primary" />
                                                <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                                    Documentation alignment /
                                                    conflicts
                                                </p>
                                            </div>
                                            <StringList
                                                items={
                                                    triage.documentation_alignment
                                                }
                                            />
                                        </div>

                                        <div className="grid gap-4 lg:grid-cols-2">
                                            <div>
                                                <p className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                                    PM escalation flags
                                                </p>
                                                <StringList
                                                    items={
                                                        triage.escalation_flags
                                                    }
                                                />
                                            </div>
                                            <div>
                                                <p className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                                    AIOS escalation reasons
                                                </p>
                                                <StringList
                                                    items={
                                                        triage.aios_escalation_reasons
                                                    }
                                                />
                                            </div>
                                        </div>
                                    </>
                                )}
                            </CardContent>
                        </Card>

                        {triage && (
                            <Card className="panel-elevated border-border/70 bg-card/70 text-foreground">
                                <CardHeader>
                                    <CardTitle>Proposed handling</CardTitle>
                                    <CardDescription>
                                        Proposal only. AIOS remains
                                        authoritative for persistence,
                                        placement, ordering, and execution.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-5">
                                    <div>
                                        <div className="mb-2 flex items-center gap-2">
                                            <MessageSquare className="size-4 text-primary" />
                                            <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                                Proposed requester reply
                                            </p>
                                        </div>
                                        <p className="rounded-lg border border-border/70 bg-surface-recessed/50 p-3 text-sm leading-6 whitespace-pre-wrap text-foreground">
                                            {triage.requester_reply ??
                                                'No public reply proposed.'}
                                        </p>
                                    </div>

                                    <div>
                                        <div className="mb-2 flex items-center gap-2">
                                            <GitBranch className="size-4 text-primary" />
                                            <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                                Phase-placement consequence
                                            </p>
                                        </div>
                                        <p className="rounded-lg border border-border/70 bg-surface-recessed/50 p-3 text-sm leading-6 text-foreground">
                                            {triage.phase_placement_consequence}
                                        </p>
                                    </div>

                                    {triage.proposed_task && (
                                        <div className="rounded-xl border border-primary/15 bg-primary/[0.035] p-4">
                                            <div className="flex items-center justify-between gap-3">
                                                <p className="text-sm font-semibold text-foreground">
                                                    {triage.proposed_task
                                                        .title ??
                                                        'Proposed Task'}
                                                </p>
                                                <Badge variant="outline">
                                                    Proposal
                                                </Badge>
                                            </div>
                                            <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                                {triage.proposed_task
                                                    .objective ??
                                                    'No objective recorded.'}
                                            </p>
                                            {triage.proposed_task
                                                .acceptance_criteria && (
                                                <div className="mt-4">
                                                    <p className="mb-2 font-mono text-2xs text-muted-foreground uppercase">
                                                        Acceptance criteria
                                                    </p>
                                                    <StringList
                                                        items={
                                                            triage.proposed_task
                                                                .acceptance_criteria
                                                        }
                                                    />
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}
                    </main>

                    <aside className="min-w-0 space-y-5">
                        <Card className="panel-elevated border-border/70 bg-card/70 text-foreground">
                            <CardHeader>
                                <CardTitle>Operator decision</CardTitle>
                                <CardDescription>
                                    Explicit, append-only, and auditable.
                                    Routine tickets never need this panel.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {operator_decision ? (
                                    <div className="rounded-xl border border-success/25 bg-success/10 p-4">
                                        <div className="flex items-center gap-2 text-success-foreground">
                                            <CheckCircle2 className="size-4" />
                                            <p className="text-sm font-medium">
                                                Decision recorded
                                            </p>
                                        </div>
                                        <dl className="mt-3 grid gap-2 text-xs text-muted-foreground">
                                            <div>
                                                <dt className="font-mono uppercase">
                                                    Action
                                                </dt>
                                                <dd className="mt-0.5 text-foreground">
                                                    {humanize(
                                                        operator_decision.action,
                                                    )}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="font-mono uppercase">
                                                    Operator
                                                </dt>
                                                <dd className="mt-0.5 text-foreground">
                                                    {operator_decision.decided_by ??
                                                        'Unknown'}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="font-mono uppercase">
                                                    Recorded
                                                </dt>
                                                <dd className="mt-0.5 text-foreground">
                                                    {formatDate(
                                                        operator_decision.created_at,
                                                    )}
                                                </dd>
                                            </div>
                                        </dl>
                                    </div>
                                ) : operator_action.active &&
                                  operator_action.url ? (
                                    <>
                                        <Form
                                            action={operator_action.url}
                                            method="post"
                                            className={`rounded-xl border p-4 ${triage?.critical_roadmap_interruption ? 'border-destructive/35 bg-destructive/10' : 'border-primary/20 bg-primary/5'}`}
                                        >
                                            {({ processing }) => (
                                                <>
                                                    <input
                                                        type="hidden"
                                                        name="action"
                                                        value={
                                                            operator_action.approval_action
                                                        }
                                                    />
                                                    <div className="flex items-start gap-2">
                                                        <ShieldAlert className="mt-0.5 size-4 shrink-0 text-primary" />
                                                        <div>
                                                            <p className="text-sm font-medium text-foreground">
                                                                {
                                                                    operator_action.approval_label
                                                                }
                                                            </p>
                                                            <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                                                This submit is
                                                                the affirmative
                                                                approval event.
                                                                It records
                                                                evidence and
                                                                reopens the
                                                                Ticket for fresh
                                                                PM triage; it
                                                                does not
                                                                directly mutate
                                                                roadmap order.
                                                            </p>
                                                        </div>
                                                    </div>
                                                    <Button
                                                        type="submit"
                                                        className="mt-4 w-full"
                                                        disabled={processing}
                                                    >
                                                        {
                                                            operator_action.approval_label
                                                        }
                                                    </Button>
                                                </>
                                            )}
                                        </Form>

                                        <DirectionForm
                                            url={operator_action.url}
                                            action={
                                                operator_action.provide_direction_action
                                            }
                                            label="Provide direction & re-triage"
                                            description="Store bounded internal operator direction, reopen the Ticket, and let the next fresh PM triage attempt consume it."
                                        />

                                        <DirectionForm
                                            url={operator_action.url}
                                            action={
                                                operator_action.request_information_action
                                            }
                                            label="Request requester information"
                                            description="Send a requester-visible question and move the Ticket to awaiting requester."
                                            publicReply
                                        />

                                        <Form
                                            action={operator_action.url}
                                            method="post"
                                            className="rounded-xl border border-destructive/20 bg-destructive/5 p-4"
                                        >
                                            {({ processing }) => (
                                                <>
                                                    <input
                                                        type="hidden"
                                                        name="action"
                                                        value={
                                                            operator_action.reject_action
                                                        }
                                                    />
                                                    <p className="text-sm font-medium text-foreground">
                                                        Reject proposed handling
                                                    </p>
                                                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                                        Close this escalation
                                                        explicitly. This does
                                                        not happen from viewing
                                                        or editing the Ticket.
                                                    </p>
                                                    <Button
                                                        type="submit"
                                                        variant="destructive"
                                                        className="mt-4 w-full"
                                                        disabled={processing}
                                                    >
                                                        Reject
                                                    </Button>
                                                </>
                                            )}
                                        </Form>
                                    </>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        No active operator escalation exists for
                                        the latest durable triage attempt.
                                    </p>
                                )}
                            </CardContent>
                        </Card>

                        <Card className="panel-elevated border-border/70 bg-card/70 text-foreground">
                            <CardHeader>
                                <CardTitle>Operational state</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3 text-xs">
                                {[
                                    ['Project', ticket.project.name],
                                    ['Decision', humanize(ticket.decision)],
                                    [
                                        'Awaiting until',
                                        formatDate(
                                            ticket.awaiting_response_until,
                                        ),
                                    ],
                                    ['Triaged', formatDate(ticket.triaged_at)],
                                    ['Closed', formatDate(ticket.closed_at)],
                                    [
                                        'Auto-closed',
                                        formatDate(ticket.inactivity_closed_at),
                                    ],
                                ].map(([label, value]) => (
                                    <div
                                        key={label}
                                        className="flex items-start justify-between gap-4 border-b border-border-subtle pb-2 last:border-0 last:pb-0"
                                    >
                                        <span className="font-mono text-muted-foreground uppercase">
                                            {label}
                                        </span>
                                        <span className="text-right text-foreground">
                                            {value}
                                        </span>
                                    </div>
                                ))}

                                {links.converted_task &&
                                    ticket.converted_task && (
                                        <Link
                                            href={links.converted_task}
                                            className="mt-2 inline-flex items-center justify-center gap-1.5 rounded-lg border border-primary/20 bg-primary/5 px-3 py-2 text-primary transition hover:bg-primary/10"
                                        >
                                            {ticket.converted_task.key}
                                            <ExternalLink className="size-3" />
                                        </Link>
                                    )}
                            </CardContent>
                        </Card>

                        <div className="rounded-xl border border-border/70 bg-card/55 p-4 text-xs leading-5 text-muted-foreground">
                            Internal notes remain on the internal Ticket
                            conversation. Future-client-safe conversation
                            payloads continue to exclude them; this command
                            center does not create a second conversation model.
                        </div>
                    </aside>
                </div>
            </div>
        </>
    );
}
