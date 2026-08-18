import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Filter,
    MessageSquarePlus,
    Paperclip,
    TicketCheck,
} from 'lucide-react';
import {
    index as projectsIndex,
    show as showProject,
} from '@/actions/App/Http/Controllers/ProjectController';
import {
    index as ticketIndex,
    show as showTicket,
    store as storeTicket,
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const ACCEPTED_ATTACHMENTS =
    '.txt,.md,.log,.csv,.json,.pdf,.png,.jpg,.jpeg,.gif,.webp';

type Project = {
    id: number;
    name: string;
    path: string;
};

type TicketSummary = {
    id: number;
    key: string;
    title: string;
    status: string;
    category: string | null;
    requester_category: string | null;
    requester_urgency: string | null;
    ai_suggested_priority: string | null;
    final_priority: string | null;
    decision: string | null;
    awaiting_response_until: string | null;
    created_at: string | null;
};

type Filters = {
    status: string | null;
    category: string | null;
    priority: string | null;
};

type Options = {
    statuses: string[];
    categories: string[];
    priorities: string[];
    requester_categories: string[];
    requester_urgencies: string[];
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

export default function TicketsIndex({
    project,
    tickets,
    filters,
    options,
}: {
    project: Project;
    tickets: TicketSummary[];
    filters: Filters;
    options: Options;
}) {
    useAppHeaderSlot(
        <div className="flex min-w-0 flex-1 flex-wrap items-center justify-between gap-3">
            <div className="flex min-w-0 items-center gap-3">
                <Link
                    href={showProject(project.id).url}
                    aria-label={`Back to ${project.name}`}
                    className="grid size-8 shrink-0 place-items-center rounded-lg border border-border bg-card/60 text-muted-foreground transition hover:border-primary/30 hover:text-primary"
                >
                    <ArrowLeft className="size-4" />
                </Link>

                <div className="min-w-0">
                    <div className="flex min-w-0 items-center gap-2">
                        <h1 className="truncate text-base font-semibold text-foreground">
                            {project.name}
                        </h1>
                        <span className="hidden font-mono text-2xs tracking-[0.14em] text-primary uppercase sm:inline">
                            Tickets
                        </span>
                    </div>
                    <p className="mt-0.5 truncate font-mono text-2xs text-muted-foreground">
                        {project.path}
                    </p>
                </div>
            </div>

            <Link
                href={projectsIndex().url}
                className="text-xs text-muted-foreground transition hover:text-primary"
            >
                All projects
            </Link>
        </div>,
    );

    return (
        <>
            <Head title={`${project.name} Tickets`} />

            <div className="relative min-h-full overflow-hidden">
                <AppBackground contained />

                <div className="relative z-10 grid w-full gap-4 p-4 sm:p-6 lg:p-8 xl:grid-cols-[minmax(0,1.35fr)_minmax(22rem,0.65fr)]">
                    <section className="min-w-0 space-y-4">
                        <div className="panel-elevated relative overflow-hidden p-5">
                            <div className="glow-line-accent" />
                            <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                                <div>
                                    <div className="flex items-center gap-2 font-mono text-2xs tracking-[0.14em] text-primary uppercase">
                                        <TicketCheck className="size-3.5" />
                                        Internal intake
                                    </div>
                                    <h2 className="mt-2 text-xl font-semibold tracking-tight text-foreground">
                                        Project tickets
                                    </h2>
                                    <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                                        Durable requests remain separate from
                                        executable Tasks until AIOS validates
                                        conversion.
                                    </p>
                                </div>

                                <span className="rounded-lg border border-primary/15 bg-primary/5 px-3 py-2 font-mono text-2xs text-primary">
                                    {tickets.length} shown
                                </span>
                            </div>
                        </div>

                        <Card className="panel-elevated overflow-hidden border-border/70 bg-card/70 text-foreground">
                            <CardHeader className="border-b border-border-subtle pb-4">
                                <div className="flex items-center gap-2">
                                    <Filter className="size-4 text-primary" />
                                    <div>
                                        <CardTitle>Filters</CardTitle>
                                        <CardDescription>
                                            Filtering is server-side and
                                            project-scoped.
                                        </CardDescription>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent className="pt-4">
                                <Form
                                    action={ticketIndex(project.id).url}
                                    method="get"
                                    className="grid gap-3 md:grid-cols-[repeat(3,minmax(0,1fr))_auto_auto] md:items-end"
                                >
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="filter-status">
                                            Status
                                        </Label>
                                        <select
                                            id="filter-status"
                                            name="status"
                                            defaultValue={filters.status ?? ''}
                                            className="h-10 rounded-md border border-border bg-surface-recessed px-3 text-sm text-foreground outline-none focus:border-primary/40"
                                        >
                                            <option value="">
                                                All statuses
                                            </option>
                                            {options.statuses.map((status) => (
                                                <option
                                                    key={status}
                                                    value={status}
                                                >
                                                    {humanize(status)}
                                                </option>
                                            ))}
                                        </select>
                                    </div>

                                    <div className="grid gap-1.5">
                                        <Label htmlFor="filter-category">
                                            Validated category
                                        </Label>
                                        <select
                                            id="filter-category"
                                            name="category"
                                            defaultValue={
                                                filters.category ?? ''
                                            }
                                            className="h-10 rounded-md border border-border bg-surface-recessed px-3 text-sm text-foreground outline-none focus:border-primary/40"
                                        >
                                            <option value="">
                                                All categories
                                            </option>
                                            {options.categories.map(
                                                (category) => (
                                                    <option
                                                        key={category}
                                                        value={category}
                                                    >
                                                        {humanize(category)}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </div>

                                    <div className="grid gap-1.5">
                                        <Label htmlFor="filter-priority">
                                            Final priority
                                        </Label>
                                        <select
                                            id="filter-priority"
                                            name="priority"
                                            defaultValue={
                                                filters.priority ?? ''
                                            }
                                            className="h-10 rounded-md border border-border bg-surface-recessed px-3 text-sm text-foreground outline-none focus:border-primary/40"
                                        >
                                            <option value="">
                                                All priorities
                                            </option>
                                            {options.priorities.map(
                                                (priority) => (
                                                    <option
                                                        key={priority}
                                                        value={priority}
                                                    >
                                                        {humanize(priority)}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </div>

                                    <Button type="submit" variant="outline">
                                        Apply
                                    </Button>

                                    <Button variant="ghost" asChild>
                                        <Link
                                            href={ticketIndex(project.id).url}
                                        >
                                            Clear
                                        </Link>
                                    </Button>
                                </Form>
                            </CardContent>
                        </Card>

                        <div className="grid gap-3">
                            {tickets.map((ticket) => {
                                const displayedPriority =
                                    ticket.final_priority ??
                                    ticket.ai_suggested_priority;

                                return (
                                    <Link
                                        key={ticket.id}
                                        href={
                                            showTicket({
                                                project: project.id,
                                                ticket: ticket.id,
                                            }).url
                                        }
                                        className="panel-elevated group relative overflow-hidden p-4 transition hover:border-primary/30 hover:bg-card/80"
                                    >
                                        <div className="glow-line-accent opacity-60 transition group-hover:opacity-100" />
                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-mono text-xs text-primary">
                                                        {ticket.key}
                                                    </span>
                                                    <Badge
                                                        variant="outline"
                                                        className={statusClasses(
                                                            ticket.status,
                                                        )}
                                                    >
                                                        {humanize(
                                                            ticket.status,
                                                        )}
                                                    </Badge>
                                                    {ticket.category && (
                                                        <Badge variant="secondary">
                                                            {humanize(
                                                                ticket.category,
                                                            )}
                                                        </Badge>
                                                    )}
                                                </div>

                                                <h3 className="mt-2 truncate text-base font-semibold text-foreground transition group-hover:text-primary">
                                                    {ticket.title}
                                                </h3>

                                                <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                                    <span>
                                                        Requester hint:{' '}
                                                        {humanize(
                                                            ticket.requester_category,
                                                        )}
                                                    </span>
                                                    <span>
                                                        Urgency:{' '}
                                                        {humanize(
                                                            ticket.requester_urgency,
                                                        )}
                                                    </span>
                                                    <span>
                                                        Priority:{' '}
                                                        {humanize(
                                                            displayedPriority,
                                                        )}
                                                    </span>
                                                </div>
                                            </div>

                                            <div className="shrink-0 text-left sm:text-right">
                                                <p className="font-mono text-2xs text-muted-foreground uppercase">
                                                    Submitted
                                                </p>
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    {formatDate(
                                                        ticket.created_at,
                                                    )}
                                                </p>
                                                {ticket.awaiting_response_until && (
                                                    <p className="mt-1 text-xs text-warning-foreground">
                                                        Awaiting until{' '}
                                                        {formatDate(
                                                            ticket.awaiting_response_until,
                                                        )}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    </Link>
                                );
                            })}

                            {tickets.length === 0 && (
                                <div className="panel-elevated border-dashed p-10 text-center">
                                    <TicketCheck className="mx-auto size-7 text-muted-foreground" />
                                    <p className="mt-3 text-sm font-medium text-foreground">
                                        No tickets match these filters.
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Clear filters or submit a new project
                                        request.
                                    </p>
                                </div>
                            )}
                        </div>
                    </section>

                    <aside className="min-w-0">
                        <Card className="panel-elevated sticky top-4 overflow-hidden border-primary/15 bg-card/75 text-foreground">
                            <div className="glow-line-accent" />
                            <CardHeader>
                                <div className="flex items-center gap-2">
                                    <div className="grid size-9 place-items-center rounded-lg border border-primary/20 bg-primary/8 text-primary">
                                        <MessageSquarePlus className="size-4" />
                                    </div>
                                    <div>
                                        <CardTitle>Submit a ticket</CardTitle>
                                        <CardDescription>
                                            Intake only. Submission does not
                                            create a Task.
                                        </CardDescription>
                                    </div>
                                </div>
                            </CardHeader>

                            <CardContent>
                                <Form
                                    {...storeTicket.form(project.id)}
                                    encType="multipart/form-data"
                                    className="grid gap-4"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <div className="grid gap-1.5">
                                                <Label htmlFor="title">
                                                    Title
                                                </Label>
                                                <Input
                                                    id="title"
                                                    name="title"
                                                    required
                                                    maxLength={255}
                                                    disabled={processing}
                                                    placeholder="Short request summary"
                                                />
                                                <InputError
                                                    message={errors.title}
                                                />
                                            </div>

                                            <div className="grid gap-1.5">
                                                <Label htmlFor="description">
                                                    Description
                                                </Label>
                                                <textarea
                                                    id="description"
                                                    name="description"
                                                    required
                                                    maxLength={20000}
                                                    disabled={processing}
                                                    rows={5}
                                                    className="min-h-28 rounded-md border border-border bg-surface-recessed px-3 py-2 text-sm text-foreground outline-none placeholder:text-muted-foreground focus:border-primary/40"
                                                    placeholder="Describe the request and relevant context."
                                                />
                                                <InputError
                                                    message={errors.description}
                                                />
                                            </div>

                                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                                                <div className="grid gap-1.5">
                                                    <Label htmlFor="requester_category">
                                                        Category hint
                                                    </Label>
                                                    <select
                                                        id="requester_category"
                                                        name="requester_category"
                                                        defaultValue="not_sure"
                                                        disabled={processing}
                                                        className="h-10 rounded-md border border-border bg-surface-recessed px-3 text-sm text-foreground outline-none focus:border-primary/40"
                                                    >
                                                        {options.requester_categories.map(
                                                            (category) => (
                                                                <option
                                                                    key={
                                                                        category
                                                                    }
                                                                    value={
                                                                        category
                                                                    }
                                                                >
                                                                    {humanize(
                                                                        category,
                                                                    )}
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                    <InputError
                                                        message={
                                                            errors.requester_category
                                                        }
                                                    />
                                                </div>

                                                <div className="grid gap-1.5">
                                                    <Label htmlFor="requester_urgency">
                                                        Impact / urgency
                                                    </Label>
                                                    <select
                                                        id="requester_urgency"
                                                        name="requester_urgency"
                                                        defaultValue=""
                                                        disabled={processing}
                                                        className="h-10 rounded-md border border-border bg-surface-recessed px-3 text-sm text-foreground outline-none focus:border-primary/40"
                                                    >
                                                        <option value="">
                                                            Not specified
                                                        </option>
                                                        {options.requester_urgencies.map(
                                                            (urgency) => (
                                                                <option
                                                                    key={
                                                                        urgency
                                                                    }
                                                                    value={
                                                                        urgency
                                                                    }
                                                                >
                                                                    {humanize(
                                                                        urgency,
                                                                    )}
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                    <InputError
                                                        message={
                                                            errors.requester_urgency
                                                        }
                                                    />
                                                </div>
                                            </div>

                                            {[
                                                {
                                                    name: 'expected_behavior',
                                                    label: 'Expected behavior',
                                                    rows: 3,
                                                },
                                                {
                                                    name: 'actual_behavior',
                                                    label: 'Actual behavior',
                                                    rows: 3,
                                                },
                                                {
                                                    name: 'reproduction_steps',
                                                    label: 'Reproduction steps',
                                                    rows: 4,
                                                },
                                                {
                                                    name: 'environment_version',
                                                    label: 'Environment / version',
                                                    rows: 2,
                                                },
                                            ].map((field) => (
                                                <div
                                                    key={field.name}
                                                    className="grid gap-1.5"
                                                >
                                                    <Label htmlFor={field.name}>
                                                        {field.label}{' '}
                                                        <span className="text-muted-foreground">
                                                            optional
                                                        </span>
                                                    </Label>
                                                    <textarea
                                                        id={field.name}
                                                        name={field.name}
                                                        rows={field.rows}
                                                        disabled={processing}
                                                        className="rounded-md border border-border bg-surface-recessed px-3 py-2 text-sm text-foreground outline-none placeholder:text-muted-foreground focus:border-primary/40"
                                                    />
                                                    <InputError
                                                        message={
                                                            errors[field.name]
                                                        }
                                                    />
                                                </div>
                                            ))}

                                            <div className="grid gap-1.5">
                                                <Label htmlFor="attachments">
                                                    Attachments{' '}
                                                    <span className="text-muted-foreground">
                                                        optional
                                                    </span>
                                                </Label>
                                                <label className="flex cursor-pointer items-center gap-2 rounded-md border border-dashed border-primary/20 bg-primary/5 px-3 py-3 text-xs text-muted-foreground transition hover:border-primary/35">
                                                    <Paperclip className="size-4 text-primary" />
                                                    <span>
                                                        Up to 5 safe files, 5 MB
                                                        each
                                                    </span>
                                                    <input
                                                        id="attachments"
                                                        name="attachments[]"
                                                        type="file"
                                                        multiple
                                                        accept={
                                                            ACCEPTED_ATTACHMENTS
                                                        }
                                                        disabled={processing}
                                                        className="sr-only"
                                                    />
                                                </label>
                                                <InputError
                                                    message={errors.attachments}
                                                />
                                                <InputError
                                                    message={
                                                        errors['attachments.0']
                                                    }
                                                />
                                            </div>

                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                className="w-full shadow-glow-sm"
                                            >
                                                <MessageSquarePlus className="size-4" />
                                                {processing
                                                    ? 'Submitting…'
                                                    : 'Submit ticket'}
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </CardContent>
                        </Card>
                    </aside>
                </div>
            </div>
        </>
    );
}
