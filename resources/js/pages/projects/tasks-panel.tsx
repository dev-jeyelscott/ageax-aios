import { Form, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowUpRight,
    CheckCircle2,
    CircleDashed,
    ListFilter,
    RotateCcw,
    Search,
    ShieldAlert,
    Sparkles,
    TimerReset,
    Workflow,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    requeueTask,
    showTask,
} from '@/actions/App/Http/Controllers/ProjectController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

export type ProjectTaskSummary = {
    id: number;
    key: string;
    title: string;
    status: string;
    attempts: { number: number }[];
    reviews: { status: string }[];
};

type Props = {
    projectId: number;
    tasks: ProjectTaskSummary[];
};

type FilterGroup = 'all' | 'queued' | 'active' | 'review' | 'done' | 'attention';
type OwnerFilter = 'all' | 'aios' | 'coder' | 'reviewer';

type DecoratedTask = {
    id: number;
    key: string;
    title: string;
    status: string;
    group: FilterGroup;
    queueOrder: number;
    latestAttempt: number | null;
    latestReview: string | null;
    owner: 'aios' | 'coder' | 'reviewer';
    ownerLabel: string;
    urgencyLabel: 'normal' | 'active' | 'attention' | 'resolved';
    subtitle: string;
};

function normalizeStatus(status: string): string {
    return status.replaceAll('_', ' ');
}

function getTaskGroup(task: ProjectTaskSummary): FilterGroup {
    const status = task.status;

    if (status === 'done') {
        return 'done';
    }

    if (['blocked', 'failed', 'changes_required'].includes(status)) {
        return 'attention';
    }

    if (['ready_for_review', 'reviewing'].includes(status)) {
        return 'review';
    }

    if (['coding', 'validating', 'in_progress'].includes(status)) {
        return 'active';
    }

    return 'queued';
}

function getOwner(task: ProjectTaskSummary): {
    owner: 'aios' | 'coder' | 'reviewer';
    label: string;
} {
    const group = getTaskGroup(task);

    if (group === 'review') {
        return { owner: 'reviewer', label: 'Reviewer lane' };
    }

    if (group === 'active' || group === 'attention') {
        return { owner: 'coder', label: 'Coder lane' };
    }

    if (task.status === 'done' && task.reviews[0]?.status === 'approved') {
        return { owner: 'reviewer', label: 'Approved' };
    }

    return { owner: 'aios', label: 'AIOS queue' };
}

function getUrgency(group: FilterGroup): DecoratedTask['urgencyLabel'] {
    if (group === 'attention') {
        return 'attention';
    }

    if (group === 'active' || group === 'review') {
        return 'active';
    }

    if (group === 'done') {
        return 'resolved';
    }

    return 'normal';
}

function badgeClassForGroup(group: FilterGroup): string {
    switch (group) {
        case 'active':
            return 'border-primary/30 bg-primary/10 text-primary';
        case 'review':
            return 'border-secondary/30 bg-secondary/10 text-secondary-foreground';
        case 'attention':
            return 'border-destructive/30 bg-destructive/10 text-destructive-foreground';
        case 'done':
            return 'border-success/30 bg-success/10 text-success-foreground';
        default:
            return 'border-border/80 bg-card/70 text-muted-foreground';
    }
}

function badgeClassForOwner(owner: DecoratedTask['owner']): string {
    switch (owner) {
        case 'coder':
            return 'border-primary/25 bg-primary/8 text-primary';
        case 'reviewer':
            return 'border-secondary/25 bg-secondary/10 text-secondary-foreground';
        default:
            return 'border-border/80 bg-card/70 text-muted-foreground';
    }
}

function badgeClassForUrgency(urgency: DecoratedTask['urgencyLabel']): string {
    switch (urgency) {
        case 'attention':
            return 'border-destructive/30 bg-destructive/10 text-destructive-foreground';
        case 'active':
            return 'border-warning/30 bg-warning/10 text-warning-foreground';
        case 'resolved':
            return 'border-success/30 bg-success/10 text-success-foreground';
        default:
            return 'border-border/80 bg-card/70 text-muted-foreground';
    }
}

function summaryValue(tasks: ProjectTaskSummary[], group: FilterGroup): number {
    return tasks.filter((task) => getTaskGroup(task) === group).length;
}

function iconForGroup(group: FilterGroup) {
    switch (group) {
        case 'done':
            return CheckCircle2;
        case 'attention':
            return ShieldAlert;
        case 'review':
            return Sparkles;
        case 'active':
            return Workflow;
        default:
            return CircleDashed;
    }
}

export function TasksPanel({ projectId, tasks }: Props) {
    const [query, setQuery] = useState('');
    const [groupFilter, setGroupFilter] = useState<FilterGroup>('all');
    const [ownerFilter, setOwnerFilter] = useState<OwnerFilter>('all');

    const orderedTasks = useMemo(
        () =>
            [...tasks].sort(
                (left, right) =>
                    Number(left.status === 'done') - Number(right.status === 'done'),
            ),
        [tasks],
    );

    const decoratedTasks = useMemo<DecoratedTask[]>(
        () =>
            orderedTasks.map((task, index) => {
                const group = getTaskGroup(task);
                const owner = getOwner(task);
                const latestAttempt = task.attempts[0]?.number ?? null;
                const latestReview = task.reviews[0]?.status ?? null;

                return {
                    id: task.id,
                    key: task.key,
                    title: task.title,
                    status: task.status,
                    group,
                    queueOrder: index + 1,
                    latestAttempt,
                    latestReview,
                    owner: owner.owner,
                    ownerLabel: owner.label,
                    urgencyLabel: getUrgency(group),
                    subtitle: [
                        latestAttempt === null ? 'No attempts yet' : `Attempt ${latestAttempt}`,
                        latestReview ? `Review ${normalizeStatus(latestReview)}` : 'Review pending',
                    ].join(' · '),
                };
            }),
        [orderedTasks],
    );

    const filteredTasks = useMemo(() => {
        const search = query.trim().toLowerCase();

        return decoratedTasks.filter((task) => {
            const matchesQuery =
                search.length === 0 ||
                task.key.toLowerCase().includes(search) ||
                task.title.toLowerCase().includes(search) ||
                normalizeStatus(task.status).toLowerCase().includes(search);

            const matchesGroup =
                groupFilter === 'all' ? true : task.group === groupFilter;

            const matchesOwner =
                ownerFilter === 'all' ? true : task.owner === ownerFilter;

            return matchesQuery && matchesGroup && matchesOwner;
        });
    }, [decoratedTasks, groupFilter, ownerFilter, query]);

    const activeCount =
        summaryValue(tasks, 'active') + summaryValue(tasks, 'queued');
    const reviewCount = summaryValue(tasks, 'review');
    const attentionCount = summaryValue(tasks, 'attention');
    const completedCount = summaryValue(tasks, 'done');

    const summaryCards = [
        {
            label: 'Total tasks',
            value: tasks.length,
            hint: 'Across ordered roadmap execution',
            group: 'all' as const,
            className: 'border-primary/20 bg-primary/6',
        },
        {
            label: 'Active queue',
            value: activeCount,
            hint: 'Queued, coding, or validating',
            group: 'active' as const,
            className: 'border-primary/18 bg-primary/5',
        },
        {
            label: 'In review',
            value: reviewCount,
            hint: 'Ready for or under review',
            group: 'review' as const,
            className: 'border-secondary/22 bg-secondary/10',
        },
        {
            label: 'Needs attention',
            value: attentionCount,
            hint: 'Blocked, failed, or changes required',
            group: 'attention' as const,
            className: 'border-destructive/18 bg-destructive/6',
        },
        {
            label: 'Completed',
            value: completedCount,
            hint: 'Done and preserved in history',
            group: 'done' as const,
            className: 'border-success/18 bg-success/6',
        },
    ];

    return (
        <div className="grid gap-3">
            <div className="grid gap-3 xl:grid-cols-5">
                {summaryCards.map((card) => {
                    const Icon =
                        card.group === 'all'
                            ? ListFilter
                            : iconForGroup(card.group === 'attention' ? 'attention' : card.group);

                    return (
                        <button
                            key={card.label}
                            type="button"
                            onClick={() =>
                                setGroupFilter(card.group === 'all' ? 'all' : card.group)
                            }
                            className={`panel-elevated relative overflow-hidden p-4 text-left transition hover:border-primary/25 ${card.className}`}
                        >
                            <div className="glow-line-accent" />
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="font-mono text-2xs tracking-[0.16em] text-muted-foreground uppercase">
                                        {card.label}
                                    </p>
                                    <p className="mt-2 text-2xl font-semibold text-foreground">
                                        {card.value}
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {card.hint}
                                    </p>
                                </div>

                                <div className="grid size-10 place-items-center rounded-xl border border-border/70 bg-card/70 text-primary shadow-glow-sm">
                                    <Icon className="size-4" />
                                </div>
                            </div>
                        </button>
                    );
                })}
            </div>

            <Card className="panel-elevated overflow-hidden border-border/70 bg-card/70 text-foreground">
                <CardHeader className="border-b border-border-subtle pb-4">
                    <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                        <div>
                            <p className="font-mono text-2xs tracking-[0.16em] text-primary uppercase">
                                Workflow command center
                            </p>
                            <CardTitle className="mt-1 text-xl">
                                Ordered tasks
                            </CardTitle>
                            <CardDescription className="mt-1 text-sm">
                                Serial task ordering remains controlled by AIOS.
                                This view only enhances visibility, filtering, and
                                execution context.
                            </CardDescription>
                        </div>

                        <div className="grid gap-2 md:grid-cols-[minmax(0,1.3fr)_repeat(2,minmax(0,0.7fr))] xl:w-[52rem]">
                            <label className="panel-recessed flex items-center gap-2 px-3 py-2">
                                <Search className="size-4 text-muted-foreground" />
                                <input
                                    value={query}
                                    onChange={(event) => setQuery(event.target.value)}
                                    placeholder="Search by task key, title, or status…"
                                    className="w-full bg-transparent text-sm text-foreground outline-none placeholder:text-muted-foreground"
                                />
                            </label>

                            <label className="panel-recessed flex items-center gap-2 px-3 py-2">
                                <ListFilter className="size-4 text-muted-foreground" />
                                <select
                                    value={groupFilter}
                                    onChange={(event) =>
                                        setGroupFilter(event.target.value as FilterGroup)
                                    }
                                    className="w-full bg-transparent text-sm text-foreground outline-none"
                                >
                                    <option value="all">All states</option>
                                    <option value="queued">Queued</option>
                                    <option value="active">Active</option>
                                    <option value="review">Review</option>
                                    <option value="attention">Needs attention</option>
                                    <option value="done">Completed</option>
                                </select>
                            </label>

                            <label className="panel-recessed flex items-center gap-2 px-3 py-2">
                                <TimerReset className="size-4 text-muted-foreground" />
                                <select
                                    value={ownerFilter}
                                    onChange={(event) =>
                                        setOwnerFilter(event.target.value as OwnerFilter)
                                    }
                                    className="w-full bg-transparent text-sm text-foreground outline-none"
                                >
                                    <option value="all">All lanes</option>
                                    <option value="aios">AIOS queue</option>
                                    <option value="coder">Coder lane</option>
                                    <option value="reviewer">Reviewer lane</option>
                                </select>
                            </label>
                        </div>
                    </div>
                </CardHeader>

                <CardContent className="p-0">
                    <div className="hidden grid-cols-[5rem_minmax(0,2.2fr)_minmax(0,0.9fr)_minmax(0,0.9fr)_minmax(0,0.8fr)_minmax(0,0.8fr)_auto] gap-3 border-b border-border-subtle px-5 py-3 text-2xs font-mono tracking-[0.16em] text-muted-foreground uppercase lg:grid">
                        <span>Order</span>
                        <span>Task</span>
                        <span>State</span>
                        <span>Lane</span>
                        <span>Urgency</span>
                        <span>Evidence</span>
                        <span className="text-right">Actions</span>
                    </div>

                    <div className="grid">
                        {filteredTasks.map((task) => (
                            <div
                                key={task.id}
                                className="border-b border-border-subtle px-4 py-4 transition hover:bg-foreground/[0.02] lg:px-5"
                            >
                                <div className="grid gap-4 lg:grid-cols-[5rem_minmax(0,2.2fr)_minmax(0,0.9fr)_minmax(0,0.9fr)_minmax(0,0.8fr)_minmax(0,0.8fr)_auto] lg:items-center">
                                    <div className="flex items-center gap-2">
                                        <div className="grid size-8 place-items-center rounded-lg border border-border/70 bg-card/70 font-mono text-xs text-primary">
                                            {task.queueOrder}
                                        </div>
                                        <div
                                            className={`size-2.5 rounded-full ${
                                                task.group === 'attention'
                                                    ? 'bg-destructive shadow-glow-sm'
                                                    : task.group === 'review'
                                                      ? 'bg-secondary-foreground shadow-glow-sm'
                                                      : task.group === 'active'
                                                        ? 'bg-primary shadow-glow-sm'
                                                        : task.group === 'done'
                                                          ? 'bg-success shadow-glow-sm'
                                                          : 'bg-muted-foreground'
                                            }`}
                                        />
                                    </div>

                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Link
                                                href={
                                                    showTask({
                                                        project: projectId,
                                                        task: task.id,
                                                    }).url
                                                }
                                                className="truncate text-sm font-semibold text-foreground transition hover:text-primary"
                                            >
                                                {task.key}: {task.title}
                                            </Link>

                                            {task.group === 'attention' && (
                                                <span className="inline-flex items-center gap-1 rounded-full border border-destructive/25 bg-destructive/10 px-2 py-0.5 text-2xs text-destructive-foreground">
                                                    <AlertTriangle className="size-3" />
                                                    attention
                                                </span>
                                            )}
                                        </div>

                                        <p className="mt-1 truncate text-xs text-muted-foreground">
                                            {task.subtitle}
                                        </p>
                                    </div>

                                    <div className="flex flex-wrap gap-2">
                                        <Badge
                                            variant="outline"
                                            className={badgeClassForGroup(task.group)}
                                        >
                                            {normalizeStatus(task.status)}
                                        </Badge>
                                    </div>

                                    <div className="flex flex-wrap gap-2">
                                        <Badge
                                            variant="outline"
                                            className={badgeClassForOwner(task.owner)}
                                        >
                                            {task.ownerLabel}
                                        </Badge>
                                    </div>

                                    <div className="flex flex-wrap gap-2">
                                        <Badge
                                            variant="outline"
                                            className={badgeClassForUrgency(task.urgencyLabel)}
                                        >
                                            {task.urgencyLabel}
                                        </Badge>
                                    </div>

                                    <div className="min-w-0">
                                        <p className="text-xs text-muted-foreground">
                                            {task.latestAttempt === null
                                                ? 'No attempt'
                                                : `Attempt ${task.latestAttempt}`}
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {task.latestReview
                                                ? `Review ${normalizeStatus(task.latestReview)}`
                                                : 'No review yet'}
                                        </p>
                                    </div>

                                    <div className="flex items-center justify-start gap-2 lg:justify-end">
                                        <Button
                                            asChild
                                            size="sm"
                                            variant="outline"
                                            className="border-border/80 bg-card/70"
                                        >
                                            <Link
                                                href={
                                                    showTask({
                                                        project: projectId,
                                                        task: task.id,
                                                    }).url
                                                }
                                            >
                                                Open
                                                <ArrowUpRight className="size-3.5" />
                                            </Link>
                                        </Button>

                                        {task.status === 'blocked' && (
                                            <Form
                                                {...requeueTask.form({
                                                    project: projectId,
                                                    task: task.id,
                                                })}
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        size="sm"
                                                        type="submit"
                                                        variant="outline"
                                                        disabled={processing}
                                                        className="border-destructive/25 bg-destructive/10 text-destructive-foreground hover:bg-destructive/15"
                                                    >
                                                        <RotateCcw className="size-3.5" />
                                                        Retry
                                                    </Button>
                                                )}
                                            </Form>
                                        )}
                                    </div>
                                </div>
                            </div>
                        ))}

                        {filteredTasks.length === 0 && (
                            <div className="px-6 py-14 text-center">
                                <div className="mx-auto grid size-12 place-items-center rounded-2xl border border-border/70 bg-card/70 text-primary">
                                    <Search className="size-5" />
                                </div>
                                <h3 className="mt-4 text-sm font-semibold text-foreground">
                                    No tasks matched the current filters
                                </h3>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Adjust the search term or lane/state filters to
                                    view more roadmap work.
                                </p>
                            </div>
                        )}

                        {tasks.length === 0 && (
                            <div className="px-6 py-16 text-center">
                                <div className="mx-auto grid size-12 place-items-center rounded-2xl border border-border/70 bg-card/70 text-primary">
                                    <Workflow className="size-5" />
                                </div>
                                <h3 className="mt-4 text-sm font-semibold text-foreground">
                                    No implementation tasks yet
                                </h3>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Upload and process a roadmap to generate the
                                    ordered execution queue.
                                </p>
                            </div>
                        )}
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
