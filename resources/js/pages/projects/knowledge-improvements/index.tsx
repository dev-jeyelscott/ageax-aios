import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    BookOpenCheck,
    Check,
    FlaskConical,
    Lightbulb,
    ShieldAlert,
    Sparkles,
    X,
} from 'lucide-react';
import { decide as decideCandidate } from '@/actions/App/Http/Controllers/KnowledgeImprovementController';
import { show as showProject } from '@/actions/App/Http/Controllers/ProjectController';
import { AppBackground } from '@/components/app-background';
import { useAppHeaderSlot } from '@/components/app-header-slot';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

const statusClasses: Record<string, string> = {
    pending: 'border-primary/25 bg-primary/10 text-primary',
    approved: 'border-success/25 bg-success/10 text-success-foreground',
    rejected:
        'border-destructive/25 bg-destructive/10 text-destructive-foreground',
    dismissed: 'border-border bg-card text-muted-foreground',
};

type Project = {
    id: number;
    name: string;
    path: string;
};

type EvidenceReference = {
    source_type: string;
    source_id: number;
    task_id?: number | null;
    task_key?: string | null;
    review_id?: number | null;
    task_attempt_id?: number | null;
    audit_event_id?: number | null;
    recovery_incident_id?: number | null;
    agent_run_id?: number | null;
};

type Candidate = {
    id: number;
    fingerprint: string;
    source_kind: string;
    failure_code: string;
    affected_role: string | null;
    affected_area: string | null;
    status: string;
    target_type: string;
    evidence_summary: string;
    proposed_change: string;
    evidence: EvidenceReference[];
    occurrence_count: number;
    confidence: string;
    reopen_after_occurrence: number | null;
    first_seen_at: string;
    last_seen_at: string;
    decided_at: string | null;
    applied_at: string | null;
    applied_skill_version: number | null;
    target_skill: {
        id: number;
        name: string;
        slug: string;
        version: number;
        enabled: boolean;
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

function evidenceLabel(reference: EvidenceReference): string {
    if (reference.task_key) {
        return `${humanize(reference.source_type)} · ${reference.task_key}`;
    }

    return `${humanize(reference.source_type)} #${reference.source_id}`;
}

export default function KnowledgeImprovementsIndex({
    project,
    candidates,
}: {
    project: Project;
    candidates: Candidate[];
}) {
    const page = usePage();
    const errors = page.props.errors as Record<string, string> | undefined;
    const pendingCount = candidates.filter(
        (candidate) => candidate.status === 'pending',
    ).length;

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
                            Knowledge Improvement Queue
                        </span>
                    </div>
                    <p className="mt-0.5 truncate font-mono text-2xs text-muted-foreground">
                        {project.path}
                    </p>
                </div>
            </div>

            <Badge
                variant="outline"
                className="border-primary/25 bg-primary/10 text-primary"
            >
                {pendingCount} pending
            </Badge>
        </div>,
    );

    return (
        <>
            <Head title={`${project.name} Knowledge Improvements`} />

            <div className="relative min-h-full overflow-hidden">
                <AppBackground contained />

                <div className="relative z-10 w-full space-y-4 p-4 sm:p-6 lg:p-8">
                    <section className="panel-elevated relative overflow-hidden p-5">
                        <div className="glow-line-accent" />
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                            <div>
                                <div className="flex items-center gap-2 font-mono text-2xs tracking-[0.14em] text-primary uppercase">
                                    <Sparkles className="size-3.5" />
                                    Durable learning review
                                </div>
                                <h2 className="mt-2 text-xl font-semibold tracking-tight text-foreground">
                                    Failure-to-Skill Promotion Queue
                                </h2>
                                <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                                    AIOS groups recurring durable failure
                                    evidence into proposals. Nothing changes a
                                    Skill, rule, test, or workflow until an
                                    operator explicitly approves it.
                                </p>
                            </div>

                            <div className="grid grid-cols-2 gap-2 text-xs sm:grid-cols-3">
                                <div className="rounded-lg border border-border bg-surface-recessed px-3 py-2">
                                    <div className="font-mono text-2xs text-muted-foreground uppercase">
                                        Candidates
                                    </div>
                                    <div className="mt-1 text-lg font-semibold text-foreground">
                                        {candidates.length}
                                    </div>
                                </div>
                                <div className="rounded-lg border border-warning/20 bg-warning/5 px-3 py-2">
                                    <div className="font-mono text-2xs text-primary uppercase">
                                        Pending
                                    </div>
                                    <div className="mt-1 text-lg font-semibold text-primary">
                                        {pendingCount}
                                    </div>
                                </div>
                                <div className="col-span-2 rounded-lg border border-primary/15 bg-primary/5 px-3 py-2 sm:col-span-1">
                                    <div className="font-mono text-2xs text-primary uppercase">
                                        Control
                                    </div>
                                    <div className="mt-1 text-xs font-medium text-foreground">
                                        Operator approval only
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    {candidates.length === 0 ? (
                        <section className="panel-elevated p-8 text-center">
                            <Lightbulb className="mx-auto size-8 text-muted-foreground" />
                            <h3 className="mt-3 font-semibold text-foreground">
                                No recurring pattern has crossed the threshold
                            </h3>
                            <p className="mx-auto mt-1 max-w-xl text-sm text-muted-foreground">
                                One-off failures remain evidence, but they are
                                not promoted into durable guidance.
                            </p>
                        </section>
                    ) : (
                        <div className="grid gap-4">
                            {candidates.map((candidate) => (
                                <article
                                    key={candidate.id}
                                    className="panel-elevated relative overflow-hidden p-5"
                                >
                                    <div className="glow-line-accent opacity-60" />

                                    <div className="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Badge
                                                    variant="outline"
                                                    className={
                                                        statusClasses[
                                                            candidate.status
                                                        ] ??
                                                        'border-border bg-card text-muted-foreground'
                                                    }
                                                >
                                                    {humanize(candidate.status)}
                                                </Badge>
                                                <Badge variant="secondary">
                                                    {humanize(
                                                        candidate.source_kind,
                                                    )}
                                                </Badge>
                                                <Badge variant="outline">
                                                    {candidate.occurrence_count}{' '}
                                                    occurrences
                                                </Badge>
                                                <Badge variant="outline">
                                                    {humanize(
                                                        candidate.confidence,
                                                    )}{' '}
                                                    confidence
                                                </Badge>
                                                <span className="font-mono text-2xs text-muted-foreground">
                                                    {candidate.fingerprint.slice(
                                                        0,
                                                        12,
                                                    )}
                                                </span>
                                            </div>

                                            <div className="mt-4 grid gap-3 md:grid-cols-3">
                                                <div className="rounded-lg border border-border-subtle bg-surface-recessed p-3">
                                                    <div className="font-mono text-2xs tracking-wide text-muted-foreground uppercase">
                                                        Failure code
                                                    </div>
                                                    <div className="mt-1 font-mono text-xs break-words text-foreground">
                                                        {candidate.failure_code}
                                                    </div>
                                                </div>
                                                <div className="rounded-lg border border-border-subtle bg-surface-recessed p-3">
                                                    <div className="font-mono text-2xs tracking-wide text-muted-foreground uppercase">
                                                        Role / area
                                                    </div>
                                                    <div className="mt-1 text-sm text-foreground">
                                                        {humanize(
                                                            candidate.affected_role,
                                                        )}{' '}
                                                        ·{' '}
                                                        {candidate.affected_area ??
                                                            '—'}
                                                    </div>
                                                </div>
                                                <div className="rounded-lg border border-border-subtle bg-surface-recessed p-3">
                                                    <div className="font-mono text-2xs tracking-wide text-muted-foreground uppercase">
                                                        Proposed target
                                                    </div>
                                                    <div className="mt-1 text-sm text-foreground">
                                                        {humanize(
                                                            candidate.target_type,
                                                        )}
                                                    </div>
                                                    {candidate.target_skill && (
                                                        <div className="mt-1 font-mono text-2xs text-primary">
                                                            {
                                                                candidate
                                                                    .target_skill
                                                                    .slug
                                                            }{' '}
                                                            · v
                                                            {
                                                                candidate
                                                                    .target_skill
                                                                    .version
                                                            }
                                                        </div>
                                                    )}
                                                </div>
                                            </div>

                                            <div className="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,0.45fr)]">
                                                <div>
                                                    <div className="flex items-center gap-2 text-sm font-medium text-foreground">
                                                        <BookOpenCheck className="size-4 text-primary" />
                                                        Proposed durable
                                                        guidance
                                                    </div>
                                                    <p className="mt-2 rounded-lg border border-primary/10 bg-primary/5 p-3 text-sm leading-relaxed text-foreground">
                                                        {
                                                            candidate.proposed_change
                                                        }
                                                    </p>
                                                    <p className="mt-2 text-xs text-muted-foreground">
                                                        {
                                                            candidate.evidence_summary
                                                        }
                                                    </p>
                                                </div>

                                                <div>
                                                    <div className="flex items-center gap-2 text-sm font-medium text-foreground">
                                                        <FlaskConical className="size-4 text-primary" />
                                                        Evidence
                                                    </div>
                                                    <div className="mt-2 space-y-1.5">
                                                        {candidate.evidence
                                                            .slice(-5)
                                                            .map(
                                                                (reference) => (
                                                                    <div
                                                                        key={`${reference.source_type}:${reference.source_id}`}
                                                                        className="rounded-md border border-border-subtle bg-surface-recessed px-2.5 py-2 font-mono text-2xs text-muted-foreground"
                                                                    >
                                                                        {evidenceLabel(
                                                                            reference,
                                                                        )}
                                                                    </div>
                                                                ),
                                                            )}
                                                    </div>
                                                </div>
                                            </div>

                                            <div className="mt-4 flex flex-wrap gap-x-5 gap-y-1 font-mono text-2xs text-muted-foreground">
                                                <span>
                                                    First:{' '}
                                                    {formatDate(
                                                        candidate.first_seen_at,
                                                    )}
                                                </span>
                                                <span>
                                                    Latest:{' '}
                                                    {formatDate(
                                                        candidate.last_seen_at,
                                                    )}
                                                </span>
                                                {candidate.applied_skill_version !==
                                                    null && (
                                                    <span className="text-success-foreground">
                                                        Applied Skill v
                                                        {
                                                            candidate.applied_skill_version
                                                        }
                                                    </span>
                                                )}
                                                {candidate.status !==
                                                    'pending' &&
                                                    candidate.reopen_after_occurrence !==
                                                        null && (
                                                        <span>
                                                            Reopens at{' '}
                                                            {
                                                                candidate.reopen_after_occurrence
                                                            }{' '}
                                                            occurrences
                                                        </span>
                                                    )}
                                            </div>
                                        </div>

                                        {candidate.status === 'pending' && (
                                            <div className="w-full shrink-0 rounded-xl border border-border bg-card/50 p-3 xl:w-64">
                                                <div className="flex items-center gap-2 font-mono text-2xs tracking-wide text-muted-foreground uppercase">
                                                    <ShieldAlert className="size-3.5" />
                                                    Operator decision
                                                </div>

                                                <div className="mt-3 grid gap-2">
                                                    <Form
                                                        action={
                                                            decideCandidate({
                                                                project:
                                                                    project.id,
                                                                candidate:
                                                                    candidate.id,
                                                            }).url
                                                        }
                                                        method="patch"
                                                    >
                                                        <input
                                                            type="hidden"
                                                            name="decision"
                                                            value="approved"
                                                        />
                                                        <Button
                                                            type="submit"
                                                            className="w-full"
                                                        >
                                                            <Check className="size-4" />
                                                            Approve
                                                        </Button>
                                                    </Form>

                                                    <Form
                                                        action={
                                                            decideCandidate({
                                                                project:
                                                                    project.id,
                                                                candidate:
                                                                    candidate.id,
                                                            }).url
                                                        }
                                                        method="patch"
                                                    >
                                                        <input
                                                            type="hidden"
                                                            name="decision"
                                                            value="rejected"
                                                        />
                                                        <Button
                                                            type="submit"
                                                            variant="outline"
                                                            className="w-full"
                                                        >
                                                            <X className="size-4" />
                                                            Reject
                                                        </Button>
                                                    </Form>

                                                    <Form
                                                        action={
                                                            decideCandidate({
                                                                project:
                                                                    project.id,
                                                                candidate:
                                                                    candidate.id,
                                                            }).url
                                                        }
                                                        method="patch"
                                                    >
                                                        <input
                                                            type="hidden"
                                                            name="decision"
                                                            value="dismissed"
                                                        />
                                                        <Button
                                                            type="submit"
                                                            variant="ghost"
                                                            className="w-full"
                                                        >
                                                            Dismiss for now
                                                        </Button>
                                                    </Form>
                                                </div>

                                                {candidate.target_type !==
                                                    'skill' && (
                                                    <p className="mt-3 text-xs leading-relaxed text-muted-foreground">
                                                        Approval records the
                                                        decision only.
                                                        Repository rules, tests,
                                                        and docs still require
                                                        the normal Task → Coder
                                                        → Git → validation →
                                                        Reviewer workflow.
                                                    </p>
                                                )}

                                                <InputError
                                                    message={errors?.decision}
                                                    className="mt-2"
                                                />
                                            </div>
                                        )}
                                    </div>
                                </article>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
