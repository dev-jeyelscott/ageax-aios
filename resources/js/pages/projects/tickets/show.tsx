import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Bot,
    CheckCircle2,
    Clock3,
    FileText,
    LockKeyhole,
    MessageSquare,
    Paperclip,
    Send,
    ShieldAlert,
    TicketCheck,
} from 'lucide-react';
import {
    showAgentRun,
    showTask,
} from '@/actions/App/Http/Controllers/ProjectController';
import {
    index as ticketIndex,
    storeMessage,
} from '@/actions/App/Http/Controllers/TicketController';
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

type Project = {
    id: number;
    name: string;
    path: string;
};

type Attachment = {
    id: number;
    original_name: string;
    mime_type: string;
    extension: string;
    size_bytes: number;
    text_context_supported: boolean;
};

type ConversationMessage = {
    id: number;
    message_type: 'public_reply' | 'internal_note' | 'system_event';
    author_type: 'user' | 'ai' | 'system';
    author_name: string | null;
    body: string;
    ai_generated: boolean;
    ai_badge: string | null;
    agent_run_id: number | null;
    created_at: string | null;
    attachments: Attachment[];
};

type Ticket = {
    id: number;
    key: string;
    title: string;
    description: string;
    requester_category: string | null;
    category: string | null;
    status: string;
    decision: string | null;
    requester_urgency: string | null;
    ai_suggested_priority: string | null;
    final_priority: string | null;
    triage_confidence: number | null;
    awaiting_response_until: string | null;
    triaged_at: string | null;
    closed_at: string | null;
    created_at: string | null;
    converted_task: {
        id: number;
        key: string;
        title: string;
        status: string;
    } | null;
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

function formatBytes(value: number): string {
    if (value < 1024) {
        return `${value} B`;
    }

    if (value < 1024 * 1024) {
        return `${(value / 1024).toFixed(1)} KB`;
    }

    return `${(value / (1024 * 1024)).toFixed(1)} MB`;
}

function statusClasses(status: string): string {
    if (['converted', 'closed'].includes(status)) {
        return 'border-success/25 bg-success/10 text-success-foreground';
    }

    if (['triaging', 'awaiting_requester'].includes(status)) {
        return 'border-primary/25 bg-primary/10 text-primary';
    }

    if (['escalated', 'failed'].includes(status)) {
        return 'border-destructive/25 bg-destructive/10 text-destructive-foreground';
    }

    return 'border-border bg-card text-muted-foreground';
}

function messageClasses(
    messageType: ConversationMessage['message_type'],
): string {
    if (messageType === 'internal_note') {
        return 'border-warning/20 bg-warning/5';
    }

    if (messageType === 'system_event') {
        return 'border-border bg-muted/20';
    }

    return 'border-primary/15 bg-card/70';
}

function AttachmentList({ attachments }: { attachments: Attachment[] }) {
    if (attachments.length === 0) {
        return (
            <p className="text-xs text-muted-foreground">
                No attachments recorded.
            </p>
        );
    }

    return (
        <div className="grid gap-2">
            {attachments.map((attachment) => (
                <div
                    key={attachment.id}
                    className="flex min-w-0 items-center gap-2 rounded-md border border-border-subtle bg-foreground/[0.025] px-3 py-2"
                >
                    <Paperclip className="size-3.5 shrink-0 text-primary" />
                    <div className="min-w-0 flex-1">
                        <p
                            className="truncate text-xs font-medium text-foreground"
                            title={attachment.original_name}
                        >
                            {attachment.original_name}
                        </p>
                        <p className="mt-0.5 font-mono text-2xs text-muted-foreground">
                            {attachment.mime_type} ·{' '}
                            {formatBytes(attachment.size_bytes)}
                        </p>
                    </div>
                    {attachment.text_context_supported && (
                        <Badge variant="outline" className="shrink-0 text-2xs">
                            text-safe
                        </Badge>
                    )}
                </div>
            ))}
        </div>
    );
}

function MessageComposer({
    project,
    ticket,
    messageType,
}: {
    project: Project;
    ticket: Ticket;
    messageType: 'public_reply' | 'internal_note';
}) {
    const isInternal = messageType === 'internal_note';

    return (
        <Card
            className={`overflow-hidden ${
                isInternal
                    ? 'border-warning/20 bg-warning/5'
                    : 'border-primary/15 bg-card/70'
            }`}
        >
            <CardHeader className="pb-3">
                <div className="flex items-start gap-2">
                    <div
                        className={`grid size-8 shrink-0 place-items-center rounded-lg border ${
                            isInternal
                                ? 'border-warning/20 bg-warning/10 text-warning-foreground'
                                : 'border-primary/20 bg-primary/8 text-primary'
                        }`}
                    >
                        {isInternal ? (
                            <LockKeyhole className="size-4" />
                        ) : (
                            <MessageSquare className="size-4" />
                        )}
                    </div>
                    <div>
                        <CardTitle className="text-sm">
                            {isInternal ? 'Internal note' : 'Public reply'}
                        </CardTitle>
                        <CardDescription className="mt-1 text-xs">
                            {isInternal
                                ? 'Visible only in the authorized internal Ticket view.'
                                : 'Requester-visible content for the future client-safe conversation.'}
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>

            <CardContent>
                <Form
                    {...storeMessage.form({
                        project: project.id,
                        ticket: ticket.id,
                    })}
                    className="grid gap-2"
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="message_type"
                                value={messageType}
                            />
                            <Label
                                htmlFor={`${messageType}-body`}
                                className="sr-only"
                            >
                                {isInternal ? 'Internal note' : 'Public reply'}
                            </Label>
                            <textarea
                                id={`${messageType}-body`}
                                name="body"
                                required
                                maxLength={100000}
                                rows={4}
                                disabled={processing}
                                className="rounded-md border border-border bg-surface-recessed px-3 py-2 text-sm text-foreground outline-none placeholder:text-muted-foreground focus:border-primary/40"
                                placeholder={
                                    isInternal
                                        ? 'Add operator-only context…'
                                        : 'Write a requester-visible reply…'
                                }
                            />
                            <InputError message={errors.body} />
                            <div className="flex justify-end">
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant={isInternal ? 'outline' : 'default'}
                                    disabled={processing}
                                >
                                    <Send className="size-3.5" />
                                    {processing ? 'Saving…' : 'Send'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}

export default function TicketShow({
    project,
    ticket,
    conversation,
    attachments,
}: {
    project: Project;
    ticket: Ticket;
    conversation: ConversationMessage[];
    attachments: Attachment[];
}) {
    useAppHeaderSlot(
        <div className="flex min-w-0 flex-1 flex-wrap items-center justify-between gap-3">
            <div className="flex min-w-0 items-center gap-3">
                <Link
                    href={ticketIndex(project.id).url}
                    aria-label="Back to tickets"
                    className="grid size-8 shrink-0 place-items-center rounded-lg border border-border bg-card/60 text-muted-foreground transition hover:border-primary/30 hover:text-primary"
                >
                    <ArrowLeft className="size-4" />
                </Link>

                <div className="min-w-0">
                    <div className="flex min-w-0 items-center gap-2">
                        <span className="shrink-0 font-mono text-xs text-primary">
                            {ticket.key}
                        </span>
                        <h1 className="truncate text-base font-semibold text-foreground">
                            {ticket.title}
                        </h1>
                    </div>
                    <p className="mt-0.5 truncate font-mono text-2xs text-muted-foreground">
                        {project.name} · Internal Ticket
                    </p>
                </div>
            </div>

            <Badge
                variant="outline"
                className={`font-mono text-2xs uppercase ${statusClasses(
                    ticket.status,
                )}`}
            >
                {humanize(ticket.status)}
            </Badge>
        </div>,
    );

    return (
        <>
            <Head title={`${ticket.key} ${ticket.title}`} />

            <div className="relative min-h-full overflow-hidden">
                <AppBackground contained />

                <div className="relative z-10 grid w-full gap-4 p-4 sm:p-6 lg:p-8 xl:grid-cols-[minmax(0,1.35fr)_minmax(22rem,0.65fr)]">
                    <section className="min-w-0 space-y-4">
                        <Card className="panel-elevated overflow-hidden border-primary/15 bg-card/70 text-foreground">
                            <div className="glow-line-accent" />
                            <CardHeader>
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <TicketCheck className="size-4 text-primary" />
                                            <span className="font-mono text-xs text-primary">
                                                {ticket.key}
                                            </span>
                                            <Badge
                                                variant="outline"
                                                className={statusClasses(
                                                    ticket.status,
                                                )}
                                            >
                                                {humanize(ticket.status)}
                                            </Badge>
                                        </div>
                                        <CardTitle className="mt-2 text-xl">
                                            {ticket.title}
                                        </CardTitle>
                                        <CardDescription className="mt-1">
                                            Submitted{' '}
                                            {formatDate(ticket.created_at)}
                                        </CardDescription>
                                    </div>

                                    {ticket.status === 'escalated' && (
                                        <div className="flex items-center gap-2 rounded-lg border border-destructive/20 bg-destructive/5 px-3 py-2 text-xs text-destructive-foreground">
                                            <ShieldAlert className="size-4" />
                                            Needs operator decision
                                        </div>
                                    )}
                                </div>
                            </CardHeader>
                            <CardContent>
                                <pre className="font-sans text-sm leading-6 whitespace-pre-wrap text-foreground/90">
                                    {ticket.description}
                                </pre>
                            </CardContent>
                        </Card>

                        <Card className="panel-elevated overflow-hidden border-border/70 bg-card/70 text-foreground">
                            <CardHeader className="border-b border-border-subtle">
                                <div className="flex items-center gap-2">
                                    <MessageSquare className="size-4 text-primary" />
                                    <div>
                                        <CardTitle>
                                            Conversation timeline
                                        </CardTitle>
                                        <CardDescription>
                                            Public, internal, and system
                                            messages remain explicitly typed.
                                        </CardDescription>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent className="grid gap-3 pt-4">
                                {conversation.map((message) => (
                                    <article
                                        key={message.id}
                                        className={`rounded-xl border p-4 ${messageClasses(
                                            message.message_type,
                                        )}`}
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Badge variant="outline">
                                                    {humanize(
                                                        message.message_type,
                                                    )}
                                                </Badge>
                                                <span className="text-xs font-medium text-foreground">
                                                    {message.author_name ??
                                                        humanize(
                                                            message.author_type,
                                                        )}
                                                </span>
                                                {message.ai_badge && (
                                                    <Badge className="gap-1 border-primary/20 bg-primary/10 text-primary">
                                                        <Bot className="size-3" />
                                                        {message.ai_badge}
                                                    </Badge>
                                                )}
                                                {message.agent_run_id && (
                                                    <Link
                                                        href={
                                                            showAgentRun({
                                                                project:
                                                                    project.id,
                                                                run: message.agent_run_id,
                                                            }).url
                                                        }
                                                        className="font-mono text-2xs text-primary hover:underline"
                                                    >
                                                        AgentRun #
                                                        {message.agent_run_id}
                                                    </Link>
                                                )}
                                            </div>

                                            <time className="font-mono text-2xs text-muted-foreground">
                                                {formatDate(message.created_at)}
                                            </time>
                                        </div>

                                        <p className="mt-3 text-sm leading-6 whitespace-pre-wrap text-foreground/90">
                                            {message.body}
                                        </p>

                                        {message.attachments.length > 0 && (
                                            <div className="mt-3 border-t border-border-subtle pt-3">
                                                <AttachmentList
                                                    attachments={
                                                        message.attachments
                                                    }
                                                />
                                            </div>
                                        )}
                                    </article>
                                ))}

                                {conversation.length === 0 && (
                                    <div className="rounded-xl border border-dashed border-border p-8 text-center">
                                        <MessageSquare className="mx-auto size-6 text-muted-foreground" />
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            No conversation messages recorded
                                            yet.
                                        </p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </section>

                    <aside className="min-w-0 space-y-4">
                        <Card className="panel-elevated border-border/70 bg-card/70 text-foreground">
                            <CardHeader>
                                <CardTitle>Ticket state</CardTitle>
                                <CardDescription>
                                    Persisted server-authoritative intake and
                                    triage evidence.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <dl className="grid gap-3 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                                    {[
                                        ['Status', humanize(ticket.status)],
                                        [
                                            'Validated category',
                                            humanize(ticket.category),
                                        ],
                                        ['Decision', humanize(ticket.decision)],
                                        [
                                            'Requester urgency',
                                            humanize(ticket.requester_urgency),
                                        ],
                                        [
                                            'AI suggested priority',
                                            humanize(
                                                ticket.ai_suggested_priority,
                                            ),
                                        ],
                                        [
                                            'Final priority',
                                            humanize(ticket.final_priority),
                                        ],
                                        [
                                            'Triage confidence',
                                            ticket.triage_confidence === null
                                                ? '—'
                                                : `${Math.round(
                                                      ticket.triage_confidence *
                                                          100,
                                                  )}%`,
                                        ],
                                        [
                                            'Awaiting response until',
                                            formatDate(
                                                ticket.awaiting_response_until,
                                            ),
                                        ],
                                    ].map(([label, value]) => (
                                        <div
                                            key={label}
                                            className="tile-inset px-3 py-2.5"
                                        >
                                            <dt className="font-mono text-2xs text-muted-foreground uppercase">
                                                {label}
                                            </dt>
                                            <dd className="mt-1 text-sm font-medium text-foreground">
                                                {value}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>

                                <div className="mt-3 rounded-lg border border-border-subtle bg-foreground/[0.025] p-3">
                                    <p className="font-mono text-2xs text-muted-foreground uppercase">
                                        Escalation
                                    </p>
                                    <div className="mt-2 flex items-center gap-2 text-sm text-foreground">
                                        {ticket.status === 'escalated' ? (
                                            <ShieldAlert className="size-4 text-destructive-foreground" />
                                        ) : (
                                            <CheckCircle2 className="size-4 text-success-foreground" />
                                        )}
                                        {ticket.status === 'escalated'
                                            ? 'Operator decision required'
                                            : 'No active escalation'}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {ticket.converted_task && (
                            <Card className="panel-elevated border-success/20 bg-success/5 text-foreground">
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-sm">
                                        <CheckCircle2 className="size-4 text-success-foreground" />
                                        Converted Task
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <Link
                                        href={
                                            showTask({
                                                project: project.id,
                                                task: ticket.converted_task.id,
                                            }).url
                                        }
                                        className="block rounded-lg border border-success/15 bg-background/30 p-3 transition hover:border-success/30"
                                    >
                                        <p className="font-mono text-xs text-success-foreground">
                                            {ticket.converted_task.key}
                                        </p>
                                        <p className="mt-1 text-sm font-medium text-foreground">
                                            {ticket.converted_task.title}
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {humanize(
                                                ticket.converted_task.status,
                                            )}
                                        </p>
                                    </Link>
                                </CardContent>
                            </Card>
                        )}

                        <Card className="panel-elevated border-border/70 bg-card/70 text-foreground">
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-sm">
                                    <FileText className="size-4 text-primary" />
                                    Attachments
                                </CardTitle>
                                <CardDescription>
                                    Metadata only; uploads remain untrusted and
                                    outside managed repositories.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <AttachmentList attachments={attachments} />
                            </CardContent>
                        </Card>

                        <MessageComposer
                            project={project}
                            ticket={ticket}
                            messageType="public_reply"
                        />

                        <MessageComposer
                            project={project}
                            ticket={ticket}
                            messageType="internal_note"
                        />

                        <div className="panel-elevated flex items-start gap-2 p-3 text-xs text-muted-foreground">
                            <Clock3 className="mt-0.5 size-3.5 shrink-0 text-primary" />
                            <span>
                                Requester timeout, closure, reopen, escalation,
                                and Task conversion remain AIOS-owned workflow
                                decisions.
                            </span>
                        </div>
                    </aside>
                </div>
            </div>
        </>
    );
}
