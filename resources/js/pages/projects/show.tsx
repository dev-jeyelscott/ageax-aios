import { Form, Head, Link, usePage, usePoll } from '@inertiajs/react';
import {
    Activity,
    ArrowLeft,
    ArrowRight,
    Bot,
    CheckCircle2,
    CircleDot,
    ClipboardCheck,
    Clock,
    Cpu,
    FileUp,
    GitBranch,
    Pause,
    Play,
    RotateCcw,
    ShieldCheck,
    Workflow,
} from 'lucide-react';
import { lazy, Suspense, useState, useSyncExternalStore } from 'react';
import {
    index,
    requeueRoadmap,
    requestReconciliation,
    show as showProject,
    showAgentRun,
    showTask,
    updateStatus,
} from '@/actions/App/Http/Controllers/ProjectController';
import type { OfficeWorkflow, OfficeWorker } from '@/components/agent-office';
import { useAppHeaderSlot } from '@/components/app-header-slot';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { AgentsPanel } from '@/pages/projects/agents-panel';
import type {
    Agent,
    HarnessCapabilities,
    Worker,
} from '@/pages/projects/agents-panel';
import { SkillsPanel } from '@/pages/projects/skills-panel';
import type { Skill } from '@/pages/projects/skills-panel';
import { TasksPanel as ProjectTasksPanel } from '@/pages/projects/tasks-panel';
import { store as storeRoadmap } from '@/routes/projects/roadmaps';
import './project-workflow.css';

const LazyAgentOffice = lazy(() =>
    import('@/components/agent-office').then(({ AgentOffice }) => ({
        default: AgentOffice,
    })),
);

type Task = {
    id: number;
    key: string;
    title: string;
    status: string;
    attempts: { number: number }[];
    reviews: { status: string }[];
};

type AgentRun = {
    id: number;
    role: string;
    harness: string | null;
    status: string;
    attempt_number: number | null;
    token_usage: number | null;
    exit_code: number | null;
    started_at: string;
    finished_at: string | null;
    failure_reason: string | null;
};

type GitEvidence = {
    task: {
        id: number;
        key: string;
        title: string;
    };
    attempt_number: number;
    status: string;
    base_sha: string | null;
    head_sha: string | null;
    commit_sha: string | null;
    changed_files: string[] | null;
    validation_results: {
        passed?: boolean;
        checks?: Record<string, boolean>;
    } | null;
};

type HarnessUsage = {
    run_count: number;
    token_usage: number | null;
    known_token_usage: number;
    token_usage_run_count: number;
    average_tokens_per_run: number | null;
    legacy_incomplete_run_count: number;
    legacy_token_usage: number;
    configurations: {
        model: string | null;
        reasoning_setting: string | null;
        run_count: number;
        token_usage: number;
    }[];
};

type TokenUsageEvidence = {
    window: { key: '24h' | '7d' | 'all'; label: string };
    run_count: number;
    token_usage_run_count: number;
    total_processed_tokens: number;
    average_tokens_per_run: number | null;
    legacy_incomplete_run_count: number;
    legacy_token_usage: number;
};

type TokenObservation = {
    rolling_average: number | null;
    baseline_average: number | null;
    change_percentage: number | null;
    run_count: number;
    warning_threshold: number;
};

type ReconciliationRun = {
    id: number;
    status: string;
    trigger: string;
    baseline_sha: string | null;
    evaluated_head_sha: string | null;
    working_tree_dirty: boolean;
    started_at: string | null;
    finished_at: string | null;
    failure_reason: string | null;
    summary_counts: {
        new_functionality: number;
        changed_functionality: number;
        removed_functionality: number;
        documentation_drift: number;
    } | null;
    mechanical_result: {
        created: string[];
        changed: { path: string; sections: string[] }[];
        unchanged: string[];
    } | null;
};

type Reconciliation = {
    latest: ReconciliationRun | null;
    active: boolean;
};

type Project = {
    id: number;
    name: string;
    path: string;
    status: string;
    git_status: string;
    git_head_sha: string | null;
    roadmaps: {
        id: number;
        original_filename: string;
        status: string;
    }[];
    office_workers: OfficeWorker[];
    office_workflow: OfficeWorkflow | null;
    tasks: Task[];
    token_usage_total: number;
    token_usage_evidence: TokenUsageEvidence;
    token_observability: Record<string, TokenObservation>;
    harness_usage: Record<string, HarnessUsage>;
    git_evidence: GitEvidence | null;
    reconciliation: Reconciliation;
    recent_agent_runs: AgentRun[];
    audit_events: {
        id: number;
        event_type: string;
        occurred_at: string;
    }[];
    agents: Agent[];
    skills: Skill[];
    workers: Worker[];
};

type Tab = 'overview' | 'workflow' | 'agents' | 'skills' | 'tasks' | 'activity';

const tabs: { value: Tab; label: string }[] = [
    { value: 'overview', label: 'Overview' },
    { value: 'workflow', label: 'AI Workflow' },
    { value: 'agents', label: 'Agents' },
    { value: 'skills', label: 'Skills' },
    { value: 'tasks', label: 'Tasks' },
    { value: 'activity', label: 'Recent Activity' },
];

const taskAttentionStatuses = new Set([
    'blocked',
    'failed',
    'interrupted',
    'changes_required',
]);

function resolveProjectTab(url: string): Tab {
    const withoutHash = url.split('#')[0] ?? '';
    const [path = '', query = ''] = withoutHash.split('?');
    const normalizedPath = path.replace(/\/+$/, '');

    if (normalizedPath.endsWith('/workflow')) {
        return 'workflow';
    }

    const value = new URLSearchParams(query).get('tab');

    return tabs.some((tab) => tab.value === value && tab.value !== 'workflow')
        ? (value as Tab)
        : 'overview';
}

function formatTokens(tokens: number): string {
    return new Intl.NumberFormat().format(tokens);
}

function shortSha(value: string | null): string {
    return value ? value.slice(0, 10) : '—';
}

function harnessLabel(harness: string | null): string {
    switch (harness) {
        case 'codex':
            return 'Codex';
        case 'claude_code':
            return 'Claude Code';
        case null:
            return 'Legacy';
        default:
            return humanize(harness);
    }
}

function humanize(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replaceAll('.', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

function formatRunDuration(
    startedAt: string,
    finishedAt: string | null,
): string {
    if (finishedAt === null) {
        return 'In progress';
    }

    const started = new Date(startedAt).getTime();
    const finished = new Date(finishedAt).getTime();

    if (!Number.isFinite(started) || !Number.isFinite(finished)) {
        return '—';
    }

    const totalSeconds = Math.max(0, Math.round((finished - started) / 1_000));

    if (totalSeconds < 60) {
        return `${totalSeconds}s`;
    }

    const totalMinutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    if (totalMinutes < 60) {
        return `${totalMinutes}m ${seconds.toString().padStart(2, '0')}s`;
    }

    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    return `${hours}h ${minutes.toString().padStart(2, '0')}m`;
}

function isFailedRun(run: AgentRun): boolean {
    return (
        run.status === 'failed' ||
        (run.exit_code !== null && run.exit_code !== 0)
    );
}

function isWorkerActive(worker: OfficeWorker): boolean {
    return ['working', 'recovering'].includes(worker.status);
}

function isWorkerBlocked(worker: OfficeWorker): boolean {
    return (
        ['blocked', 'failed', 'interrupted'].includes(worker.status) ||
        worker.run?.status === 'failed' ||
        Boolean(worker.run?.failure_reason) ||
        Boolean(
            worker.task &&
            ['blocked', 'failed', 'interrupted'].includes(worker.task.status),
        )
    );
}

function nextStageLabel(project: Project): string {
    const workflow =
        project.office_workflow?.mode === 'current'
            ? project.office_workflow
            : null;

    if (project.status !== 'running') {
        return 'Paused';
    }

    if (!workflow) {
        return 'AIOS scheduler';
    }

    switch (workflow.role) {
        case 'project_manager':
            return 'Coder';
        case 'coder':
            return 'Reviewer';
        case 'reviewer':
            return 'AIOS decision';
        default:
            return 'AIOS scheduler';
    }
}

function roleVisual(role: string): {
    icon: string;
    rail: string;
    badge: string;
} {
    switch (role) {
        case 'coder':
            return {
                icon: 'border-success/25 bg-success/10 text-success-foreground',
                rail: 'bg-success',
                badge: 'border-success/20 bg-success/5 text-success-foreground',
            };
        case 'reviewer':
            return {
                icon: 'border-secondary/60 bg-secondary/20 text-secondary-foreground',
                rail: 'bg-secondary-foreground',
                badge: 'border-secondary/60 bg-secondary/15 text-secondary-foreground',
            };
        case 'project_manager':
            return {
                icon: 'border-primary/25 bg-primary/10 text-primary',
                rail: 'bg-primary',
                badge: 'border-primary/20 bg-primary/5 text-primary',
            };
        default:
            return {
                icon: 'border-border bg-card text-muted-foreground',
                rail: 'bg-muted-foreground',
                badge: 'border-border bg-card text-muted-foreground',
            };
    }
}

function ClientAgentOffice({
    project,
    completedTasks,
}: {
    project: Project;
    completedTasks: number;
}) {
    const isClient = useSyncExternalStore(
        () => () => undefined,
        () => true,
        () => false,
    );

    if (!isClient) {
        return (
            <div className="grid h-full min-h-80 place-items-center rounded-2xl border border-primary/15 bg-background px-6 text-center text-muted-foreground">
                Loading live office…
            </div>
        );
    }

    return (
        <Suspense
            fallback={
                <div className="grid h-full min-h-80 place-items-center rounded-2xl border border-primary/15 bg-background px-6 text-center text-muted-foreground">
                    Loading live office…
                </div>
            }
        >
            <LazyAgentOffice
                projectId={project.id}
                projectName={project.name}
                projectStatus={project.status}
                gitStatus={project.git_status}
                workers={project.office_workers}
                agents={project.agents}
                workerBindings={project.workers}
                workflow={project.office_workflow}
                taskProgress={{
                    completed: completedTasks,
                    total: project.tasks.length,
                }}
            />
        </Suspense>
    );
}

function OverviewCard({
    title,
    eyebrow,
    icon,
    children,
    className = '',
}: {
    title: string;
    eyebrow: string;
    icon: React.ReactNode;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <section
            className={`overview-motion-card panel-elevated relative min-w-0 overflow-hidden p-3 ${className}`}
        >
            <div className="glow-edge glow-line-accent opacity-60" />
            <div className="flex items-center gap-2">
                <span className="text-primary">{icon}</span>
                <div className="min-w-0">
                    <p className="font-mono text-2xs tracking-[0.14em] text-primary uppercase">
                        {eyebrow}
                    </p>
                    <h3 className="mt-0.5 truncate text-sm font-semibold text-foreground">
                        {title}
                    </h3>
                </div>
            </div>
            <div className="mt-3 min-w-0">{children}</div>
        </section>
    );
}

function OverviewMetric({
    label,
    value,
    detail,
    tone = 'primary',
    pulse = false,
}: {
    label: string;
    value: string;
    detail: string;
    tone?: 'primary' | 'success' | 'warning' | 'destructive';
    pulse?: boolean;
}) {
    const toneClass = {
        primary: 'border-primary/20 bg-primary/5 text-primary',
        success: 'border-success/20 bg-success/5 text-success-foreground',
        warning: 'border-warning/20 bg-warning/5 text-warning-foreground',
        destructive:
            'border-destructive/20 bg-destructive/5 text-destructive-foreground',
    }[tone];

    const dotClass = {
        primary: 'bg-primary',
        success: 'bg-success',
        warning: 'bg-warning',
        destructive: 'bg-destructive',
    }[tone];

    return (
        <div className="overview-motion-card tile-inset relative min-w-0 overflow-hidden px-3 py-2.5">
            <div className="flex items-start gap-2.5">
                <span
                    className={`mt-1.5 size-2 shrink-0 rounded-full ${dotClass} ${
                        pulse ? 'status-glow-pulse' : ''
                    }`}
                />
                <div className="min-w-0">
                    <p className="font-mono text-2xs tracking-[0.1em] text-muted-foreground uppercase">
                        {label}
                    </p>
                    <p className="mt-0.5 truncate text-base font-semibold text-foreground">
                        {value}
                    </p>
                    <p
                        className={`mt-1 inline-flex max-w-full truncate rounded-full border px-2 py-0.5 font-mono text-[10px] ${toneClass}`}
                    >
                        {detail}
                    </p>
                </div>
            </div>
        </div>
    );
}

function RoadmapOverviewCard({
    project,
    roadmapTask,
    completedTasks,
}: {
    project: Project;
    roadmapTask: Task | undefined;
    completedTasks: number;
}) {
    const roadmap = project.roadmaps[0];
    const progress =
        project.tasks.length === 0
            ? 0
            : Math.round((completedTasks / project.tasks.length) * 100);

    return (
        <OverviewCard
            title="Roadmap progress"
            eyebrow="Planning"
            icon={<Workflow className="size-4" />}
            className="xl:col-span-2"
        >
            <div className="grid gap-3 md:grid-cols-[minmax(0,1.2fr)_minmax(15rem,0.8fr)]">
                <div className="min-w-0">
                    <div className="flex items-end justify-between gap-3">
                        <div>
                            <p className="text-3xl font-semibold tracking-tight text-primary">
                                {progress}%
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {completedTasks} of {project.tasks.length} tasks
                                complete
                            </p>
                        </div>

                        <Badge
                            variant="outline"
                            className="border-primary/20 bg-primary/5 font-mono text-2xs text-primary"
                        >
                            {roadmap ? humanize(roadmap.status) : 'No roadmap'}
                        </Badge>
                    </div>

                    <div className="mt-3 h-2 overflow-hidden rounded-full bg-muted">
                        <div
                            className="progress-flow h-full rounded-full transition-[width] duration-500"
                            style={{ width: `${progress}%` }}
                        />
                    </div>

                    <div className="mt-3 min-w-0 rounded-lg border border-border-subtle bg-foreground/2 px-3 py-2">
                        <p className="font-mono text-2xs text-muted-foreground uppercase">
                            Next unfinished task
                        </p>

                        {roadmapTask ? (
                            <Link
                                href={
                                    showTask({
                                        project: project.id,
                                        task: roadmapTask.id,
                                    }).url
                                }
                                className="mt-1 block truncate text-sm font-medium text-foreground transition hover:text-primary"
                            >
                                <span className="font-mono text-primary">
                                    {roadmapTask.key}
                                </span>{' '}
                                · {roadmapTask.title}
                            </Link>
                        ) : (
                            <p className="mt-1 text-xs text-muted-foreground">
                                {project.tasks.length > 0
                                    ? 'All recorded tasks are complete.'
                                    : 'Upload a roadmap to begin planning.'}
                            </p>
                        )}
                    </div>
                </div>

                <div className="flex min-w-0 flex-col justify-between rounded-lg border border-border-subtle bg-background/35 p-3">
                    <div className="min-w-0">
                        <p className="font-mono text-2xs text-muted-foreground uppercase">
                            Current roadmap
                        </p>
                        <p
                            className="mt-1 truncate text-xs text-foreground"
                            title={roadmap?.original_filename}
                        >
                            {roadmap?.original_filename ?? 'Not recorded'}
                        </p>
                    </div>

                    <div className="mt-3 flex flex-wrap gap-2">
                        {roadmap?.status === 'blocked' && (
                            <Form
                                {...requeueRoadmap.form({
                                    project: project.id,
                                    roadmap: roadmap.id,
                                })}
                            >
                                {({ processing }) => (
                                    <Button
                                        size="sm"
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                        className="h-8 border-destructive/25 bg-destructive/10 text-xs text-destructive-foreground"
                                    >
                                        <RotateCcw className="size-3.5" />
                                        Retry roadmap
                                    </Button>
                                )}
                            </Form>
                        )}

                        <Form
                            {...storeRoadmap.form(project.id)}
                            encType="multipart/form-data"
                        >
                            {({ errors, processing }) => (
                                <div>
                                    <label className="flex h-8 cursor-pointer items-center gap-2 rounded-md border border-primary/20 bg-primary/5 px-3 text-xs font-medium text-primary transition hover:border-primary/35 hover:bg-primary/10">
                                        <input
                                            name="roadmap"
                                            type="file"
                                            accept=".md,.txt,text/markdown,text/plain"
                                            required
                                            disabled={processing}
                                            className="sr-only"
                                            onChange={(event) => {
                                                if (
                                                    event.currentTarget.files
                                                        ?.length
                                                ) {
                                                    event.currentTarget.form?.requestSubmit();
                                                }
                                            }}
                                        />
                                        <FileUp className="size-3.5" />
                                        {processing
                                            ? 'Uploading…'
                                            : roadmap
                                              ? 'Upload new roadmap'
                                              : 'Upload roadmap'}
                                    </label>
                                    <InputError message={errors.roadmap} />
                                </div>
                            )}
                        </Form>
                    </div>
                </div>
            </div>
        </OverviewCard>
    );
}

function CurrentOperationOverviewCard({ project }: { project: Project }) {
    const workflow =
        project.office_workflow?.mode === 'current'
            ? project.office_workflow
            : null;
    const worker = workflow
        ? project.office_workers.find(
              (candidate) => candidate.id === workflow.worker_id,
          )
        : undefined;
    const activeRole = workflow ? humanize(workflow.role) : 'No active stage';
    const message =
        worker?.run?.latest_message ??
        worker?.run?.failure_reason ??
        (workflow?.task
            ? `${workflow.task.key} · ${workflow.task.title}`
            : 'Waiting for the next eligible durable operation.');

    return (
        <OverviewCard
            title="Current operation"
            eyebrow="Live execution"
            icon={<Activity className="size-4" />}
        >
            <div className="flex items-center justify-between gap-3">
                <div className="min-w-0">
                    <p className="font-mono text-2xs text-muted-foreground uppercase">
                        Active stage
                    </p>
                    <p className="mt-1 truncate text-lg font-semibold text-foreground">
                        {activeRole}
                    </p>
                </div>

                <Badge
                    variant="outline"
                    className={`shrink-0 gap-1.5 rounded-full font-mono text-2xs ${
                        workflow
                            ? 'border-primary/25 bg-primary/5 text-primary'
                            : 'border-border bg-card text-muted-foreground'
                    }`}
                >
                    <span
                        className={`size-1.5 rounded-full ${
                            workflow
                                ? 'status-glow-pulse bg-primary'
                                : 'bg-muted-foreground'
                        }`}
                    />
                    {workflow ? 'Active' : 'Idle'}
                </Badge>
            </div>

            <p className="mt-3 line-clamp-3 min-h-12 text-xs leading-relaxed text-muted-foreground">
                {message}
            </p>

            <div className="mt-3 grid grid-cols-2 gap-2 border-t border-border-subtle pt-3">
                <div>
                    <p className="font-mono text-2xs text-muted-foreground uppercase">
                        Next stage
                    </p>
                    <p className="mt-1 text-xs font-medium text-foreground">
                        {nextStageLabel(project)}
                    </p>
                </div>
                <div>
                    <p className="font-mono text-2xs text-muted-foreground uppercase">
                        Active run
                    </p>
                    <p className="mt-1 font-mono text-xs text-primary">
                        {worker?.run ? `#${worker.run.id}` : 'Not recorded'}
                    </p>
                </div>
            </div>
        </OverviewCard>
    );
}

function TaskFlowCard({ project }: { project: Project }) {
    const statuses = project.tasks.reduce<Record<string, number>>(
        (counts, task) => {
            counts[task.status] = (counts[task.status] ?? 0) + 1;

            return counts;
        },
        {},
    );

    const rows = [
        ['Done', statuses.done ?? 0, 'bg-success'],
        [
            'In progress',
            (statuses.coding ?? 0) +
                (statuses.validating ?? 0) +
                (statuses.reviewing ?? 0),
            'bg-primary',
        ],
        [
            'Ready for review',
            statuses.ready_for_review ?? 0,
            'bg-secondary-foreground',
        ],
        [
            'Attention',
            project.tasks.filter((task) =>
                taskAttentionStatuses.has(task.status),
            ).length,
            'bg-destructive',
        ],
        ['Queued', statuses.queued ?? 0, 'bg-muted-foreground'],
    ] as const;

    return (
        <OverviewCard
            title="Task flow"
            eyebrow="Delivery state"
            icon={<CircleDot className="size-4" />}
        >
            <div className="grid gap-2">
                {rows.map(([label, count, dotClass]) => (
                    <div
                        key={label}
                        className="flex items-center justify-between gap-3 rounded-lg border border-border-subtle bg-foreground/2 px-3 py-2"
                    >
                        <div className="flex min-w-0 items-center gap-2">
                            <span
                                className={`size-1.5 shrink-0 rounded-full ${dotClass}`}
                            />
                            <span className="truncate text-xs text-muted-foreground">
                                {label}
                            </span>
                        </div>
                        <span className="font-mono text-xs font-semibold text-foreground">
                            {count}
                        </span>
                    </div>
                ))}
            </div>
        </OverviewCard>
    );
}

function GitValidationOverviewCard({ project }: { project: Project }) {
    const evidence = project.git_evidence;
    const validation = evidence?.validation_results?.passed;
    const checks = Object.entries(
        evidence?.validation_results?.checks ?? {},
    ).slice(0, 4);

    return (
        <OverviewCard
            title="Repository & validation"
            eyebrow="Verifiable evidence"
            icon={<GitBranch className="size-4" />}
            className="xl:col-span-2"
        >
            <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
                <div className="min-w-0">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="font-mono text-2xs text-muted-foreground uppercase">
                                Working tree
                            </p>
                            <p
                                className={`mt-1 text-base font-semibold ${
                                    project.git_status === 'clean'
                                        ? 'text-success-foreground'
                                        : 'text-warning-foreground'
                                }`}
                            >
                                {humanize(project.git_status)}
                            </p>
                        </div>

                        {evidence && (
                            <Link
                                href={
                                    showTask({
                                        project: project.id,
                                        task: evidence.task.id,
                                    }).url
                                }
                                className="font-mono text-2xs text-primary transition hover:text-primary/80"
                            >
                                {evidence.task.key} · Attempt #
                                {evidence.attempt_number}
                            </Link>
                        )}
                    </div>

                    <dl className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                        {[
                            ['Base', evidence?.base_sha ?? null],
                            [
                                'Head',
                                evidence?.head_sha ??
                                    project.git_head_sha ??
                                    null,
                            ],
                            ['Commit', evidence?.commit_sha ?? null],
                            [
                                'Changes',
                                evidence?.changed_files
                                    ? evidence.changed_files.length.toString()
                                    : null,
                            ],
                        ].map(([label, value]) => (
                            <div
                                key={label}
                                className="min-w-0 rounded-lg border border-border-subtle bg-foreground/2 px-2 py-2"
                            >
                                <dt className="font-mono text-2xs text-muted-foreground uppercase">
                                    {label}
                                </dt>
                                <dd
                                    className="mt-1 truncate font-mono text-2xs text-primary"
                                    title={value ?? undefined}
                                >
                                    {label === 'Changes'
                                        ? (value ?? '—')
                                        : shortSha(value)}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </div>

                <div className="min-w-0 border-t border-border-subtle pt-3 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-3">
                    <div className="flex items-center justify-between gap-3">
                        <p className="font-mono text-2xs text-muted-foreground uppercase">
                            Validation state
                        </p>
                        <Badge
                            variant="outline"
                            className={`font-mono text-2xs ${
                                validation === true
                                    ? 'border-success/25 bg-success/5 text-success-foreground'
                                    : validation === false
                                      ? 'border-destructive/25 bg-destructive/5 text-destructive-foreground'
                                      : 'border-border bg-card text-muted-foreground'
                            }`}
                        >
                            {validation === true
                                ? 'Passed'
                                : validation === false
                                  ? 'Failed'
                                  : 'Not recorded'}
                        </Badge>
                    </div>

                    {checks.length > 0 ? (
                        <div className="mt-3 grid grid-cols-2 gap-2">
                            {checks.map(([name, passed]) => (
                                <div
                                    key={name}
                                    className="flex min-w-0 items-center gap-2 rounded-lg border border-border-subtle bg-foreground/2 px-2.5 py-2"
                                >
                                    <span
                                        className={`size-1.5 shrink-0 rounded-full ${
                                            passed
                                                ? 'bg-success'
                                                : 'bg-destructive'
                                        }`}
                                    />
                                    <span className="truncate font-mono text-2xs text-muted-foreground">
                                        {name.replaceAll('_', ' ')}
                                    </span>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="mt-3 text-xs text-muted-foreground">
                            No deterministic validation checks recorded for the
                            latest task attempt.
                        </p>
                    )}
                </div>
            </div>
        </OverviewCard>
    );
}

function ReconciliationOverviewCard({ project }: { project: Project }) {
    const reconciliation = project.reconciliation;
    const latest = reconciliation.latest;
    const status = latest?.status ?? null;
    const summary = latest?.summary_counts;
    const topologyChanges = latest?.mechanical_result
        ? latest.mechanical_result.created.length +
          latest.mechanical_result.changed.length
        : null;

    const toneClass: Record<string, string> = {
        completed: 'border-success/25 bg-success/5 text-success-foreground',
        skipped_no_change: 'border-primary/20 bg-primary/5 text-primary',
        failed: 'border-destructive/25 bg-destructive/10 text-destructive-foreground',
        running: 'border-warning/25 bg-warning/5 text-warning-foreground',
        queued: 'border-warning/25 bg-warning/5 text-warning-foreground',
    };

    return (
        <OverviewCard
            title="Reconciliation audit"
            eyebrow="Durable status review"
            icon={<ClipboardCheck className="size-4" />}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="font-mono text-2xs text-muted-foreground uppercase">
                        Latest run
                    </p>
                    <p className="mt-1 truncate text-xs text-foreground">
                        {latest
                            ? `${humanize(latest.trigger)} · ${shortSha(latest.evaluated_head_sha)}`
                            : 'No reconciliation run yet.'}
                    </p>
                    {latest && (
                        <p className="mt-0.5 truncate text-2xs text-muted-foreground">
                            {latest.finished_at
                                ? new Date(latest.finished_at).toLocaleString()
                                : latest.started_at
                                  ? new Date(latest.started_at).toLocaleString()
                                  : 'Queued'}
                        </p>
                    )}
                </div>
                <Badge
                    variant="outline"
                    className={`shrink-0 font-mono text-2xs ${
                        toneClass[status ?? ''] ??
                        'border-border bg-card text-muted-foreground'
                    }`}
                >
                    {status ? humanize(status) : 'Never run'}
                </Badge>
            </div>

            {status === 'failed' && latest?.failure_reason && (
                <p
                    className="mt-2 truncate text-2xs text-destructive-foreground"
                    title={latest.failure_reason}
                >
                    {latest.failure_reason}
                </p>
            )}

            {summary && (
                <dl className="mt-3 grid grid-cols-2 gap-2">
                    {(
                        [
                            ['New', summary.new_functionality],
                            ['Changed', summary.changed_functionality],
                            ['Removed', summary.removed_functionality],
                            ['Doc drift', summary.documentation_drift],
                        ] as const
                    ).map(([label, value]) => (
                        <div
                            key={label}
                            className="min-w-0 rounded-lg border border-border-subtle bg-foreground/2 px-2 py-2"
                        >
                            <dt className="font-mono text-2xs text-muted-foreground uppercase">
                                {label}
                            </dt>
                            <dd className="mt-1 text-xs text-foreground">
                                {value}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}

            {topologyChanges !== null && (
                <p className="mt-3 text-2xs text-muted-foreground">
                    Obsidian navigation: {topologyChanges} file
                    {topologyChanges === 1 ? '' : 's'} created or updated.
                </p>
            )}

            {latest?.working_tree_dirty && (
                <p className="mt-3 text-2xs text-warning-foreground">
                    Working tree was dirty at evaluation time; uncommitted
                    changes were excluded from this evidence.
                </p>
            )}

            <div className="mt-3">
                <Form {...requestReconciliation.form(project.id)}>
                    {({ processing }) => (
                        <Button
                            size="sm"
                            type="submit"
                            variant="outline"
                            disabled={processing || reconciliation.active}
                            className="h-8 border-primary/20 bg-primary/5 text-xs text-primary"
                        >
                            <ClipboardCheck className="size-3.5" />
                            {reconciliation.active
                                ? 'Reviewing…'
                                : 'Review project'}
                        </Button>
                    )}
                </Form>
            </div>
        </OverviewCard>
    );
}

function HarnessUsageOverviewCard({ project }: { project: Project }) {
    const entries = ['claude_code', 'codex']
        .map((harness) => ({
            harness,
            usage: project.harness_usage[harness] ?? {
                run_count: 0,
                token_usage: null,
                known_token_usage: 0,
                token_usage_run_count: 0,
                average_tokens_per_run: null,
                legacy_incomplete_run_count: 0,
                legacy_token_usage: 0,
                configurations: [],
            },
        }))
        .concat(
            Object.entries(project.harness_usage)
                .filter(
                    ([harness]) => !['claude_code', 'codex'].includes(harness),
                )
                .map(([harness, usage]) => ({ harness, usage })),
        );

    const maxUsage = Math.max(
        1,
        ...entries.map(({ usage }) => usage.known_token_usage),
    );

    return (
        <OverviewCard
            title="Execution & token usage"
            eyebrow="Observability"
            icon={<Cpu className="size-4" />}
        >
            <div className="mb-3 flex flex-wrap items-center gap-1.5">
                {(['24h', '7d', 'all'] as const).map((window) => (
                    <Link
                        key={window}
                        href={
                            showProject(
                                { project: project.id },
                                { query: { usage_window: window } },
                            ).url
                        }
                        preserveScroll
                        className={`rounded-md px-2 py-1 font-mono text-2xs ${project.token_usage_evidence.window.key === window ? 'bg-primary/15 text-primary' : 'text-muted-foreground hover:bg-muted'}`}
                    >
                        {window === 'all' ? 'All time' : window}
                    </Link>
                ))}
                <span className="text-2xs text-muted-foreground">
                    {project.token_usage_evidence.window.label}
                </span>
            </div>
            <div className="flex items-end justify-between gap-3">
                <div>
                    <p className="font-mono text-2xs text-muted-foreground uppercase">
                        Recorded processed tokens
                    </p>
                    <p className="mt-1 text-2xl font-semibold text-foreground">
                        {formatTokens(project.token_usage_total)}
                    </p>
                </div>
                <span className="font-mono text-2xs text-primary">tokens</span>
            </div>

            <div className="mt-3 grid gap-2">
                {entries.map(({ harness, usage }) => (
                    <div key={harness}>
                        <div className="flex items-center justify-between gap-2">
                            <span className="text-xs text-muted-foreground">
                                {harnessLabel(harness)}
                            </span>
                            <span className="font-mono text-2xs text-foreground">
                                {usage.token_usage === null
                                    ? 'Unavailable'
                                    : formatTokens(usage.token_usage)}{' '}
                                · {usage.run_count} runs
                            </span>
                        </div>
                        {usage.token_usage !== null && (
                            <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted">
                                <div
                                    className="progress-flow h-full rounded-full transition-[width] duration-500"
                                    style={{
                                        width: `${Math.round(
                                            (usage.known_token_usage /
                                                maxUsage) *
                                                100,
                                        )}%`,
                                    }}
                                />
                            </div>
                        )}
                        <p className="mt-1 text-2xs text-muted-foreground">
                            Avg{' '}
                            {usage.average_tokens_per_run === null
                                ? 'unavailable'
                                : formatTokens(
                                      usage.average_tokens_per_run,
                                  )}{' '}
                            / recorded run
                        </p>
                        {usage.token_usage !== null &&
                            usage.configurations.map((configuration) => (
                                <p
                                    key={`${configuration.model}-${configuration.reasoning_setting}`}
                                    className="mt-0.5 text-2xs text-muted-foreground"
                                >
                                    {configuration.model ??
                                        'Immutable config unavailable'}{' '}
                                    ·{' '}
                                    {configuration.reasoning_setting ??
                                        'default'}
                                    : {formatTokens(configuration.token_usage)}{' '}
                                    / {configuration.run_count} runs
                                </p>
                            ))}
                    </div>
                ))}
            </div>

            <p className="mt-3 text-2xs text-muted-foreground">
                Usage recorded for{' '}
                {project.token_usage_evidence.token_usage_run_count} of{' '}
                {project.token_usage_evidence.run_count} executions in this
                evidence window. Raw aggregates are informational only, not
                efficiency rankings.
                {project.token_usage_evidence.legacy_incomplete_run_count > 0 &&
                    ` ${project.token_usage_evidence.legacy_incomplete_run_count} legacy/incomplete runs (${formatTokens(project.token_usage_evidence.legacy_token_usage)} legacy tokens) are excluded from processed-token totals.`}
            </p>

            <div className="mt-3 border-t border-border-subtle pt-3">
                <p className="font-mono text-2xs text-muted-foreground uppercase">
                    Rolling role averages
                </p>
                <div className="mt-2 grid gap-1.5">
                    {['project_manager', 'coder', 'reviewer'].map((role) => {
                        const observation = project.token_observability[role];

                        return (
                            <div
                                key={role}
                                className="flex items-center justify-between gap-2 text-xs"
                            >
                                <span className="truncate text-muted-foreground">
                                    {humanize(role)}
                                </span>
                                <span className="shrink-0 font-mono text-2xs text-foreground">
                                    {observation?.rolling_average === null
                                        ? 'No runs'
                                        : observation?.rolling_average !==
                                            undefined
                                          ? `${formatTokens(
                                                observation.rolling_average,
                                            )} avg`
                                          : 'Not recorded'}
                                </span>
                            </div>
                        );
                    })}
                </div>
            </div>
        </OverviewCard>
    );
}

function WorkerStateCard({ project }: { project: Project }) {
    return (
        <OverviewCard
            title="Workflow agents"
            eyebrow="Worker state"
            icon={<Bot className="size-4" />}
        >
            <div className="grid gap-2">
                {['project_manager', 'coder', 'reviewer'].map((role) => {
                    const worker = project.office_workers.find(
                        (candidate) => candidate.role === role,
                    );
                    const blocked = worker ? isWorkerBlocked(worker) : false;
                    const active = worker ? isWorkerActive(worker) : false;

                    return (
                        <div
                            key={role}
                            className={`rounded-lg border px-3 py-2 transition ${
                                active
                                    ? 'border-primary/30 bg-primary/5 shadow-glow-sm'
                                    : blocked
                                      ? 'border-destructive/25 bg-destructive/5'
                                      : 'border-border-subtle bg-foreground/2'
                            }`}
                        >
                            <div className="flex items-center justify-between gap-2">
                                <span className="truncate text-xs font-medium text-foreground">
                                    {humanize(role)}
                                </span>
                                <span
                                    className={`size-1.5 shrink-0 rounded-full ${
                                        blocked
                                            ? 'bg-destructive'
                                            : active
                                              ? 'status-glow-pulse bg-primary'
                                              : 'bg-muted-foreground'
                                    }`}
                                />
                            </div>
                            <p className="mt-1 truncate font-mono text-2xs text-muted-foreground">
                                {worker
                                    ? humanize(worker.status)
                                    : 'Not recorded'}
                                {worker?.run ? ` · Run #${worker.run.id}` : ''}
                            </p>
                        </div>
                    );
                })}
            </div>
        </OverviewCard>
    );
}

function RecentSignalsCard({ project }: { project: Project }) {
    const events = project.audit_events.slice(0, 6);

    return (
        <OverviewCard
            title="Recent project signals"
            eyebrow="Durable activity"
            icon={<Activity className="size-4" />}
            className="xl:col-span-2"
        >
            {events.length > 0 ? (
                <div className="grid gap-1.5 sm:grid-cols-2">
                    {events.map((event) => (
                        <div
                            key={event.id}
                            className="group flex min-w-0 items-center gap-2.5 rounded-lg border border-border-subtle bg-foreground/2 px-3 py-2 transition hover:border-primary/20 hover:bg-primary/5"
                        >
                            <span className="grid size-6 shrink-0 place-items-center rounded-md border border-primary/15 bg-primary/5 text-primary">
                                <CheckCircle2 className="size-3" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <p
                                    className="truncate font-mono text-2xs text-foreground"
                                    title={event.event_type}
                                >
                                    {event.event_type}
                                </p>
                                <time
                                    dateTime={event.occurred_at}
                                    className="mt-0.5 block truncate font-mono text-[10px] text-muted-foreground"
                                >
                                    {new Date(
                                        event.occurred_at,
                                    ).toLocaleString()}
                                </time>
                            </div>
                        </div>
                    ))}
                </div>
            ) : (
                <p className="text-xs text-muted-foreground">
                    No recent audit evidence recorded.
                </p>
            )}
        </OverviewCard>
    );
}

function OverviewDashboard({
    project,
    roadmapTask,
    completedTasks,
}: {
    project: Project;
    roadmapTask: Task | undefined;
    completedTasks: number;
}) {
    const activeWorkers = project.office_workers.filter(isWorkerActive).length;
    const blockedWorkers =
        project.office_workers.filter(isWorkerBlocked).length;
    const failedRuns = project.recent_agent_runs.filter(isFailedRun).length;
    const validationFailed =
        project.git_evidence?.validation_results?.passed === false;
    const hasAttention =
        blockedWorkers > 0 ||
        failedRuns > 0 ||
        validationFailed ||
        project.git_status !== 'clean';
    const roadmapProgress =
        project.tasks.length === 0
            ? 0
            : Math.round((completedTasks / project.tasks.length) * 100);
    const currentWorkflow =
        project.office_workflow?.mode === 'current'
            ? project.office_workflow
            : null;

    return (
        <div
            data-project-overview-dashboard="true"
            className="h-full min-h-0 overflow-y-auto pr-1"
        >
            <div className="grid gap-3 pb-1">
                <section className="overview-motion-card panel-elevated relative overflow-hidden p-3">
                    <div className="glow-edge glow-line-accent" />
                    <div className="pointer-events-none absolute -top-20 right-0 size-56 rounded-full bg-primary/5 blur-3xl" />
                    <div className="relative flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <div className="flex items-center gap-2">
                                <Activity className="size-4 text-primary" />
                                <p className="font-mono text-2xs tracking-[0.16em] text-primary uppercase">
                                    Project operations
                                </p>
                            </div>
                            <h2 className="mt-1 text-base font-semibold text-foreground">
                                Operational overview
                            </h2>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Durable project state, progress, validation and
                                execution evidence without duplicating the live
                                workflow canvas.
                            </p>
                        </div>

                        <div
                            className={`flex items-center gap-2 rounded-full border px-3 py-1.5 font-mono text-2xs ${
                                hasAttention
                                    ? 'border-warning/25 bg-warning/5 text-warning-foreground'
                                    : 'border-success/25 bg-success/5 text-success-foreground'
                            }`}
                        >
                            <span
                                className={`size-1.5 rounded-full ${
                                    hasAttention
                                        ? 'status-glow-pulse bg-warning'
                                        : 'status-glow-pulse bg-success'
                                }`}
                            />
                            {hasAttention
                                ? 'Attention required'
                                : 'Systems nominal'}
                        </div>
                    </div>

                    <div className="relative mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                        <OverviewMetric
                            label="Project state"
                            value={humanize(project.status)}
                            detail={`Repository ${humanize(
                                project.git_status,
                            )}`}
                            tone={
                                project.status === 'running'
                                    ? 'success'
                                    : 'warning'
                            }
                            pulse={project.status === 'running'}
                        />
                        <OverviewMetric
                            label="Roadmap"
                            value={`${roadmapProgress}%`}
                            detail={`${completedTasks} / ${project.tasks.length} tasks done`}
                            tone="primary"
                            pulse={roadmapProgress > 0 && roadmapProgress < 100}
                        />
                        <OverviewMetric
                            label="Active stage"
                            value={
                                currentWorkflow
                                    ? humanize(currentWorkflow.role)
                                    : 'Scheduler'
                            }
                            detail={`${activeWorkers} active worker${
                                activeWorkers === 1 ? '' : 's'
                            }`}
                            tone={currentWorkflow ? 'primary' : 'success'}
                            pulse={currentWorkflow !== null}
                        />
                        <OverviewMetric
                            label="Health"
                            value={hasAttention ? 'Needs attention' : 'Healthy'}
                            detail={`${blockedWorkers} blocked · ${failedRuns} recent failures`}
                            tone={hasAttention ? 'warning' : 'success'}
                            pulse={hasAttention}
                        />
                    </div>
                </section>

                <div className="grid gap-3 xl:grid-cols-4">
                    <RoadmapOverviewCard
                        project={project}
                        roadmapTask={roadmapTask}
                        completedTasks={completedTasks}
                    />
                    <CurrentOperationOverviewCard project={project} />
                    <TaskFlowCard project={project} />
                    <GitValidationOverviewCard project={project} />
                    <ReconciliationOverviewCard project={project} />
                    <HarnessUsageOverviewCard project={project} />
                    <WorkerStateCard project={project} />
                    <RecentSignalsCard project={project} />
                </div>
            </div>
        </div>
    );
}

function WorkflowDashboard({
    project,
    completedTasks,
}: {
    project: Project;
    completedTasks: number;
}) {
    return (
        <div
            data-project-ai-workflow="true"
            className="ai-workflow-fullscreen h-full min-h-0"
        >
            <ClientAgentOffice
                project={project}
                completedTasks={completedTasks}
            />
        </div>
    );
}

function TasksPanel({ project }: { project: Project }) {
    return (
        <ProjectTasksPanel
            projectId={project.id}
            tasks={project.tasks}
            gitEvidence={project.git_evidence}
            repositoryHeadSha={project.git_head_sha}
        />
    );
}

function ActivityMetric({
    icon,
    label,
    value,
    detail,
}: {
    icon: React.ReactNode;
    label: string;
    value: string;
    detail: string;
}) {
    return (
        <div className="tile-inset relative overflow-hidden px-3 py-2.5">
            <div className="glow-line-accent opacity-40" />
            <div className="flex items-start gap-2.5">
                <div className="grid size-8 shrink-0 place-items-center rounded-lg border border-primary/15 bg-primary/5 text-primary">
                    {icon}
                </div>

                <div className="min-w-0">
                    <p className="font-mono text-2xs tracking-[0.12em] text-muted-foreground uppercase">
                        {label}
                    </p>
                    <p className="mt-0.5 truncate text-base font-semibold text-foreground capitalize">
                        {value}
                    </p>
                    <p className="mt-0.5 truncate text-2xs text-muted-foreground">
                        {detail}
                    </p>
                </div>
            </div>
        </div>
    );
}

function RecentActivityPanel({ project }: { project: Project }) {
    const [runRoleFilter, setRunRoleFilter] = useState('all');
    const [auditScopeFilter, setAuditScopeFilter] = useState('all');

    const recentRunRoles = Array.from(
        new Set(project.recent_agent_runs.map((run) => run.role)),
    );
    const auditScopes = Array.from(
        new Set(
            project.audit_events.map(
                (event) => event.event_type.split('.')[0] ?? event.event_type,
            ),
        ),
    );
    const visibleRuns =
        runRoleFilter === 'all'
            ? project.recent_agent_runs
            : project.recent_agent_runs.filter(
                  (run) => run.role === runRoleFilter,
              );
    const visibleAuditEvents =
        auditScopeFilter === 'all'
            ? project.audit_events
            : project.audit_events.filter(
                  (event) =>
                      (event.event_type.split('.')[0] ?? event.event_type) ===
                      auditScopeFilter,
              );
    const failedRunCount = project.recent_agent_runs.filter(isFailedRun).length;
    const activeWorkerCount =
        project.office_workers.filter(isWorkerActive).length;

    return (
        <div className="grid gap-3">
            <section className="panel-elevated relative overflow-hidden p-3">
                <div className="glow-edge glow-line-accent" />
                <div className="pointer-events-none absolute -top-20 right-0 size-52 rounded-full bg-primary/5 blur-3xl" />
                <div className="pointer-events-none absolute -bottom-24 left-1/3 size-44 rounded-full bg-secondary/15 blur-3xl" />

                <div className="relative flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <div className="flex items-center gap-2">
                            <Activity className="size-4 text-primary" />
                            <p className="font-mono text-2xs tracking-[0.16em] text-primary uppercase">
                                Activity intelligence
                            </p>
                        </div>
                        <h2 className="mt-1 text-base font-semibold text-foreground">
                            Execution & audit command center
                        </h2>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            Live project state backed by persisted AIOS
                            execution and audit evidence.
                        </p>
                    </div>

                    <div
                        className={`flex items-center gap-2 rounded-full border px-3 py-1.5 font-mono text-2xs ${
                            project.status === 'running'
                                ? 'border-success/20 bg-success/5 text-success-foreground'
                                : 'border-warning/20 bg-warning/5 text-warning-foreground'
                        }`}
                    >
                        <span
                            className={`size-1.5 rounded-full ${
                                project.status === 'running'
                                    ? 'status-glow-pulse bg-success'
                                    : 'bg-warning'
                            }`}
                        />
                        {project.status === 'running'
                            ? 'System operational'
                            : humanize(project.status)}
                    </div>
                </div>

                <div className="relative mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <ActivityMetric
                        icon={<ShieldCheck className="size-4" />}
                        label="Project state"
                        value={humanize(project.status)}
                        detail={`Repository ${humanize(project.git_status)}`}
                    />
                    <ActivityMetric
                        icon={<Cpu className="size-4" />}
                        label="Active workers"
                        value={`${activeWorkerCount} / ${project.office_workers.length}`}
                        detail="Working or recovering"
                    />
                    <ActivityMetric
                        icon={<CircleDot className="size-4" />}
                        label="Recent executions"
                        value={project.recent_agent_runs.length.toString()}
                        detail={
                            failedRunCount === 0
                                ? 'No recent failures'
                                : `${failedRunCount} require attention`
                        }
                    />
                    <ActivityMetric
                        icon={<Activity className="size-4" />}
                        label="Observed tokens"
                        value={formatTokens(project.token_usage_total)}
                        detail="Recorded execution usage"
                    />
                </div>
            </section>

            <div className="grid min-h-0 gap-3 xl:grid-cols-[minmax(0,1.08fr)_minmax(0,0.92fr)]">
                <section className="panel-elevated relative min-w-0 overflow-hidden">
                    <div className="glow-line-accent" />

                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border-subtle px-3 py-3">
                        <div>
                            <div className="flex items-center gap-2">
                                <Cpu className="size-4 text-primary" />
                                <h3 className="text-sm font-semibold text-foreground">
                                    Agent executions
                                </h3>
                            </div>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Latest persisted runs across configured workflow
                                roles.
                            </p>
                        </div>

                        <div className="flex items-center gap-2">
                            <label
                                htmlFor="activity-role-filter"
                                className="sr-only"
                            >
                                Filter executions by role
                            </label>
                            <select
                                id="activity-role-filter"
                                value={runRoleFilter}
                                onChange={(event) =>
                                    setRunRoleFilter(event.target.value)
                                }
                                className="h-8 rounded-lg border border-border bg-surface-recessed px-2.5 font-mono text-2xs text-muted-foreground transition outline-none focus:border-primary/40 focus:ring-1 focus:ring-primary/20"
                            >
                                <option value="all">All agents</option>
                                {recentRunRoles.map((role) => (
                                    <option key={role} value={role}>
                                        {humanize(role)}
                                    </option>
                                ))}
                            </select>

                            <span className="rounded-lg border border-border-subtle bg-foreground/2 px-2 py-1.5 font-mono text-2xs text-muted-foreground">
                                {visibleRuns.length} shown
                            </span>
                        </div>
                    </div>

                    <div className="grid gap-2 p-3">
                        {visibleRuns.map((run) => {
                            const failed = isFailedRun(run);
                            const visual = roleVisual(run.role);

                            return (
                                <Link
                                    key={run.id}
                                    href={
                                        showAgentRun({
                                            project: project.id,
                                            run: run.id,
                                        }).url
                                    }
                                    className="group relative overflow-hidden rounded-xl border border-border-subtle bg-surface-recessed p-3 transition hover:border-primary/25 hover:bg-card/70"
                                >
                                    <div
                                        className={`absolute inset-y-2 left-0 w-px rounded-full ${visual.rail}`}
                                    />

                                    <div className="flex min-w-0 items-start gap-3">
                                        <div
                                            className={`grid size-9 shrink-0 place-items-center rounded-lg border ${visual.icon}`}
                                        >
                                            <Cpu className="size-4" />
                                        </div>

                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-start justify-between gap-2">
                                                <div className="min-w-0">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <p className="text-sm font-semibold text-foreground transition group-hover:text-primary">
                                                            {humanize(run.role)}
                                                        </p>
                                                        <Badge
                                                            variant="outline"
                                                            className={`font-mono text-2xs ${visual.badge}`}
                                                        >
                                                            {harnessLabel(
                                                                run.harness,
                                                            )}
                                                        </Badge>
                                                    </div>
                                                    <p className="mt-1 font-mono text-2xs text-muted-foreground">
                                                        Attempt{' '}
                                                        {run.attempt_number ??
                                                            '—'}{' '}
                                                        · Run #{run.id}
                                                    </p>
                                                </div>

                                                <Badge
                                                    variant="outline"
                                                    className={`shrink-0 font-mono text-2xs ${
                                                        failed
                                                            ? 'border-destructive/25 bg-destructive/10 text-destructive-foreground'
                                                            : run.status ===
                                                                'completed'
                                                              ? 'border-success/25 bg-success/10 text-success-foreground'
                                                              : run.status ===
                                                                  'running'
                                                                ? 'border-primary/25 bg-primary/10 text-primary'
                                                                : 'border-border bg-card text-muted-foreground'
                                                    }`}
                                                >
                                                    {failed
                                                        ? 'Error'
                                                        : humanize(run.status)}
                                                </Badge>
                                            </div>

                                            <div className="mt-2 flex flex-wrap items-center gap-1.5">
                                                <span className="flex items-center gap-1.5 rounded-md border border-border-subtle bg-foreground/2 px-2 py-1 font-mono text-2xs text-muted-foreground">
                                                    <Activity className="size-3 text-primary" />
                                                    {run.token_usage === null
                                                        ? 'Tokens unavailable'
                                                        : `${formatTokens(
                                                              run.token_usage,
                                                          )} tokens`}
                                                </span>
                                                <span className="flex items-center gap-1.5 rounded-md border border-border-subtle bg-foreground/2 px-2 py-1 font-mono text-2xs text-muted-foreground">
                                                    <Clock className="size-3 text-secondary-foreground" />
                                                    {formatRunDuration(
                                                        run.started_at,
                                                        run.finished_at,
                                                    )}
                                                </span>
                                                <time
                                                    className="rounded-md border border-border-subtle bg-foreground/2 px-2 py-1 font-mono text-2xs text-muted-foreground"
                                                    dateTime={run.started_at}
                                                >
                                                    {new Date(
                                                        run.started_at,
                                                    ).toLocaleString()}
                                                </time>
                                            </div>

                                            {run.failure_reason && (
                                                <div className="mt-2 rounded-lg border border-destructive/20 bg-destructive/5 px-2.5 py-2 text-xs text-destructive-foreground">
                                                    {run.failure_reason}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </Link>
                            );
                        })}

                        {visibleRuns.length === 0 && (
                            <div className="rounded-xl border border-dashed border-border p-8 text-center">
                                <Cpu className="mx-auto size-5 text-muted-foreground" />
                                <p className="mt-2 text-sm text-muted-foreground">
                                    No executions match this filter.
                                </p>
                            </div>
                        )}
                    </div>

                    <div className="border-t border-border-subtle px-3 py-2">
                        <p className="font-mono text-2xs text-muted-foreground">
                            Persisted execution history · newest first
                        </p>
                    </div>
                </section>

                <section className="panel-elevated relative min-w-0 overflow-hidden">
                    <div className="glow-line-secondary" />

                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border-subtle px-3 py-3">
                        <div>
                            <div className="flex items-center gap-2">
                                <ShieldCheck className="size-4 text-secondary-foreground" />
                                <h3 className="text-sm font-semibold text-foreground">
                                    Audit trail
                                </h3>
                            </div>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Recent durable system activity recorded by AIOS.
                            </p>
                        </div>

                        <div className="flex items-center gap-2">
                            <label
                                htmlFor="audit-scope-filter"
                                className="sr-only"
                            >
                                Filter audit events by scope
                            </label>
                            <select
                                id="audit-scope-filter"
                                value={auditScopeFilter}
                                onChange={(event) =>
                                    setAuditScopeFilter(event.target.value)
                                }
                                className="h-8 rounded-lg border border-border bg-surface-recessed px-2.5 font-mono text-2xs text-muted-foreground transition outline-none focus:border-secondary-foreground/40 focus:ring-1 focus:ring-secondary-foreground/20"
                            >
                                <option value="all">All events</option>
                                {auditScopes.map((scope) => (
                                    <option key={scope} value={scope}>
                                        {humanize(scope)}
                                    </option>
                                ))}
                            </select>

                            <span className="rounded-lg border border-border-subtle bg-foreground/2 px-2 py-1.5 font-mono text-2xs text-muted-foreground">
                                {visibleAuditEvents.length} shown
                            </span>
                        </div>
                    </div>

                    <div className="p-3">
                        {visibleAuditEvents.length > 0 ? (
                            <div className="grid">
                                {visibleAuditEvents.map((event, eventIndex) => (
                                    <div
                                        key={event.id}
                                        className="relative grid grid-cols-[2rem_minmax(0,1fr)] gap-2.5 pb-2 last:pb-0"
                                    >
                                        <div className="relative flex justify-center">
                                            <div className="z-10 grid size-7 place-items-center rounded-lg border border-primary/20 bg-primary/5 text-primary shadow-glow-sm">
                                                <CheckCircle2 className="size-3.5" />
                                            </div>
                                            {eventIndex <
                                                visibleAuditEvents.length -
                                                    1 && (
                                                <div className="absolute top-7 bottom-0 w-px bg-gradient-to-b from-primary/40 via-border to-transparent" />
                                            )}
                                        </div>

                                        <div className="panel-recessed min-w-0 px-3 py-2.5 transition hover:border-primary/20">
                                            <div className="flex flex-wrap items-start justify-between gap-2">
                                                <div className="min-w-0">
                                                    <p
                                                        className="truncate font-mono text-xs text-foreground"
                                                        title={event.event_type}
                                                    >
                                                        {event.event_type}
                                                    </p>
                                                    <p className="mt-1 text-2xs text-muted-foreground">
                                                        {humanize(
                                                            event.event_type,
                                                        )}
                                                    </p>
                                                </div>
                                                <time
                                                    dateTime={event.occurred_at}
                                                    className="shrink-0 font-mono text-2xs text-muted-foreground"
                                                >
                                                    {new Date(
                                                        event.occurred_at,
                                                    ).toLocaleString()}
                                                </time>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="rounded-xl border border-dashed border-border p-8 text-center">
                                <ShieldCheck className="mx-auto size-5 text-muted-foreground" />
                                <p className="mt-2 text-sm text-muted-foreground">
                                    No audit events match this filter.
                                </p>
                            </div>
                        )}
                    </div>

                    <div className="border-t border-border-subtle px-3 py-2">
                        <p className="font-mono text-2xs text-muted-foreground">
                            Append-only operational evidence · newest first
                        </p>
                    </div>
                </section>
            </div>
        </div>
    );
}

export default function ProjectShow({
    project,
    harness_capabilities: harnessCapabilities,
}: {
    project: Project;
    harness_capabilities: HarnessCapabilities;
}) {
    const { url } = usePage();

    usePoll(
        2_000,
        {
            only: ['project'],
            preserveErrors: true,
            preserveUrl: true,
        },
        { mode: 'rest' },
    );

    const roadmapTask = project.tasks.find(
        (task) => !['done', 'cancelled'].includes(task.status),
    );
    const completedTasks = project.tasks.filter(
        (task) => task.status === 'done',
    ).length;
    const tab = resolveProjectTab(url);

    useAppHeaderSlot(
        <div className="flex min-w-0 flex-1 flex-wrap items-center justify-between gap-3">
            <div className="flex min-w-0 items-center gap-3">
                <Link
                    href={index().url}
                    aria-label="Back to projects"
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
                            AIOS project
                        </span>
                    </div>
                    <p className="mt-0.5 truncate font-mono text-2xs text-muted-foreground">
                        {project.path}
                    </p>
                </div>
            </div>

            <div className="flex items-center gap-1.5">
                {tab === 'overview' && (
                    <span className="hidden items-center gap-1.5 rounded-full border border-primary/15 bg-primary/5 px-2.5 py-1 font-mono text-2xs text-primary lg:flex">
                        <ArrowRight className="size-3" />
                        AI Workflow has its own workspace
                    </span>
                )}

                <Form {...updateStatus.form(project.id)}>
                    {({ processing }) => (
                        <>
                            <input
                                name="status"
                                type="hidden"
                                value={
                                    project.status === 'running'
                                        ? 'paused'
                                        : 'running'
                                }
                            />
                            <Button
                                size="sm"
                                type="submit"
                                variant="outline"
                                disabled={
                                    processing || project.status === 'stopping'
                                }
                                className="h-7 border-border bg-card/50 px-2 text-xs"
                            >
                                {project.status === 'running' ? (
                                    <Pause className="size-3" />
                                ) : (
                                    <Play className="size-3" />
                                )}
                                {project.status === 'running'
                                    ? 'Pause'
                                    : project.status === 'stopping'
                                      ? 'Pending'
                                      : 'Resume'}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </div>,
    );

    return (
        <>
            <Head
                title={
                    tab === 'workflow'
                        ? `${project.name} · AI Workflow`
                        : project.name
                }
            />

            <div className="dark relative flex h-full min-h-0 w-full flex-col overflow-hidden bg-background text-foreground">
                <div className="pointer-events-none absolute inset-0 bg-[linear-gradient(color-mix(in_oklch,var(--primary)_4%,transparent)_1px,transparent_1px),linear-gradient(90deg,color-mix(in_oklch,var(--primary)_4%,transparent)_1px,transparent_1px)] bg-size-[32px_32px]" />
                <div className="pointer-events-none absolute -top-32 left-1/4 size-72 rounded-full bg-primary/8 blur-3xl" />
                <div className="pointer-events-none absolute right-0 bottom-0 size-72 rounded-full bg-secondary/20 blur-3xl" />

                <div className="relative flex min-h-0 flex-1 flex-col px-3 py-3 md:px-4">
                    <main className="min-h-0 flex-1">
                        {tab === 'overview' && (
                            <OverviewDashboard
                                project={project}
                                roadmapTask={roadmapTask}
                                completedTasks={completedTasks}
                            />
                        )}

                        {tab === 'workflow' && (
                            <WorkflowDashboard
                                project={project}
                                completedTasks={completedTasks}
                            />
                        )}

                        {tab === 'agents' && (
                            <div className="h-full overflow-y-auto pr-1">
                                <AgentsPanel
                                    projectId={project.id}
                                    agents={project.agents}
                                    skills={project.skills}
                                    workers={project.workers}
                                    harnessCapabilities={harnessCapabilities}
                                />
                            </div>
                        )}

                        {tab === 'skills' && (
                            <div className="h-full overflow-y-auto pr-1">
                                <SkillsPanel
                                    projectId={project.id}
                                    skills={project.skills}
                                />
                            </div>
                        )}

                        {tab === 'tasks' && (
                            <div className="h-full overflow-y-auto pr-1">
                                <TasksPanel project={project} />
                            </div>
                        )}

                        {tab === 'activity' && (
                            <div className="h-full overflow-y-auto pr-1">
                                <RecentActivityPanel project={project} />
                            </div>
                        )}
                    </main>
                </div>
            </div>
        </>
    );
}
