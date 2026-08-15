import { Form, Head, Link, usePoll } from '@inertiajs/react';
import {
    Activity,
    ArrowLeft,
    CheckCircle2,
    FileUp,
    GitCommitHorizontal,
    Pause,
    Play,
    RotateCcw,
} from 'lucide-react';
import { lazy, Suspense, useState, useSyncExternalStore } from 'react';
import {
    index,
    requeueTask,
    showAgentRun,
    showTask,
    updateStatus,
} from '@/actions/App/Http/Controllers/ProjectController';
import type { OfficeWorker } from '@/components/agent-office';
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
import { AgentsPanel } from '@/pages/projects/agents-panel';
import type {
    Agent,
    HarnessCapabilities,
    Worker,
} from '@/pages/projects/agents-panel';
import { SkillsPanel } from '@/pages/projects/skills-panel';
import type { Skill } from '@/pages/projects/skills-panel';
import { store as storeRoadmap } from '@/routes/projects/roadmaps';

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
    token_usage: number;
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
    tasks: Task[];
    token_usage_total: number;
    token_observability: Record<
        string,
        {
            rolling_average: number | null;
            baseline_average: number | null;
            change_percentage: number | null;
            run_count: number;
            warning_threshold: number;
        }
    >;
    harness_usage: Record<string, HarnessUsage>;
    git_evidence: GitEvidence | null;
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

type Tab = 'overview' | 'agents' | 'skills' | 'tasks' | 'activity';

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
            return harness.replaceAll('_', ' ');
    }
}

function ClientAgentOffice({
    project,
    currentTask,
    completedTasks,
}: {
    project: Project;
    currentTask: Task | undefined;
    completedTasks: number;
}) {
    const isClient = useSyncExternalStore(
        () => () => undefined,
        () => true,
        () => false,
    );

    if (!isClient) {
        return (
            <div className="grid h-full min-h-80 place-items-center rounded-2xl border border-cyan-300/15 bg-slate-950 px-6 text-center text-slate-400">
                Loading live office…
            </div>
        );
    }

    return (
        <Suspense
            fallback={
                <div className="grid h-full min-h-80 place-items-center rounded-2xl border border-cyan-300/15 bg-slate-950 px-6 text-center text-slate-400">
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
                currentTask={currentTask ?? null}
                taskProgress={{
                    completed: completedTasks,
                    total: project.tasks.length,
                }}
            />
        </Suspense>
    );
}

function OpsPanel({
    title,
    eyebrow,
    children,
}: {
    title: string;
    eyebrow: string;
    children: React.ReactNode;
}) {
    return (
        <section className="relative overflow-hidden rounded-xl border border-slate-700/70 bg-slate-950/75 p-2.5 shadow-panel">
            <div className="glow-edge pointer-events-none absolute inset-x-6 top-0 h-px bg-gradient-to-r from-transparent via-violet-300/40 to-transparent" />
            <p className="font-mono text-[9px] tracking-[0.16em] text-violet-300 uppercase">
                {eyebrow}
            </p>
            <h3 className="mt-0.5 text-sm font-semibold text-slate-100">
                {title}
            </h3>
            <div className="mt-2">{children}</div>
        </section>
    );
}

function RoadmapPanel({
    project,
    currentTask,
    completedTasks,
}: {
    project: Project;
    currentTask: Task | undefined;
    completedTasks: number;
}) {
    const latestRoadmap = project.roadmaps[0];
    const progress =
        project.tasks.length === 0
            ? 0
            : Math.round((completedTasks / project.tasks.length) * 100);

    return (
        <OpsPanel title="Roadmap progress" eyebrow="Planning">
            <div className="flex items-end justify-between gap-3">
                <div>
                    <p className="text-2xl font-semibold text-cyan-200">
                        {progress}%
                    </p>
                    <p className="mt-0.5 text-[10px] text-slate-500">
                        {completedTasks} of {project.tasks.length} tasks
                        complete
                    </p>
                </div>
                {latestRoadmap && (
                    <Badge
                        variant="outline"
                        className="border-cyan-300/20 bg-cyan-400/5 font-mono text-[9px] text-cyan-200"
                    >
                        {latestRoadmap.status}
                    </Badge>
                )}
            </div>

            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-800">
                <div
                    className="h-full rounded-full bg-gradient-to-r from-cyan-400 via-blue-400 to-violet-400 transition-[width]"
                    style={{ width: `${progress}%` }}
                />
            </div>

            <div className="mt-2 min-w-0 rounded-lg border border-white/5 bg-white/[0.02] p-2">
                <p className="font-mono text-[9px] text-slate-500 uppercase">
                    Current operation
                </p>
                {currentTask ? (
                    <Link
                        href={
                            showTask({
                                project: project.id,
                                task: currentTask.id,
                            }).url
                        }
                        className="mt-0.5 block truncate text-xs font-medium text-slate-200 hover:text-cyan-200"
                    >
                        {currentTask.key}: {currentTask.title}
                    </Link>
                ) : (
                    <p className="mt-0.5 text-xs text-slate-400">
                        {project.tasks.length > 0
                            ? 'No unfinished task.'
                            : 'Upload a roadmap to begin planning.'}
                    </p>
                )}
            </div>

            {latestRoadmap && (
                <p
                    className="mt-1.5 truncate text-[10px] text-slate-500"
                    title={latestRoadmap.original_filename}
                >
                    {latestRoadmap.original_filename}
                </p>
            )}

            <Form
                {...storeRoadmap.form(project.id)}
                encType="multipart/form-data"
                className="mt-1.5"
            >
                {({ errors, processing }) => (
                    <>
                        <label className="flex cursor-pointer items-center justify-center gap-2 rounded-lg border border-cyan-300/15 bg-cyan-400/5 px-2.5 py-1.5 text-[11px] font-medium text-cyan-200 transition hover:border-cyan-300/30 hover:bg-cyan-400/10">
                            <input
                                name="roadmap"
                                type="file"
                                accept=".md,.txt,text/markdown,text/plain"
                                required
                                disabled={processing}
                                className="sr-only"
                            />
                            <FileUp className="size-3.5" />
                            {processing ? 'Uploading…' : 'Upload roadmap'}
                        </label>
                        <InputError message={errors.roadmap} />
                    </>
                )}
            </Form>
        </OpsPanel>
    );
}

function GitEvidencePanel({ project }: { project: Project }) {
    const evidence = project.git_evidence;
    const checks = Object.entries(
        evidence?.validation_results?.checks ?? {},
    ).slice(0, 4);

    return (
        <OpsPanel title="Git evidence" eyebrow="Repository">
            {evidence ? (
                <>
                    <div className="flex items-center justify-between gap-2">
                        <Link
                            href={
                                showTask({
                                    project: project.id,
                                    task: evidence.task.id,
                                }).url
                            }
                            className="min-w-0 truncate text-xs font-medium text-slate-200 hover:text-cyan-200"
                        >
                            {evidence.task.key}
                        </Link>
                        <Badge
                            variant="outline"
                            className="border-slate-700 bg-slate-900 font-mono text-[9px] text-slate-300"
                        >
                            Attempt {evidence.attempt_number}
                        </Badge>
                    </div>

                    <dl className="mt-3 grid grid-cols-3 gap-1.5">
                        {[
                            ['Base', evidence.base_sha],
                            ['Head', evidence.head_sha],
                            ['Commit', evidence.commit_sha],
                        ].map(([label, value]) => (
                            <div
                                key={label}
                                className="min-w-0 rounded-md border border-white/5 bg-white/[0.02] p-2"
                            >
                                <dt className="font-mono text-[8px] text-slate-600 uppercase">
                                    {label}
                                </dt>
                                <dd
                                    className="mt-1 truncate font-mono text-[9px] text-cyan-200"
                                    title={value ?? undefined}
                                >
                                    {shortSha(value)}
                                </dd>
                            </div>
                        ))}
                    </dl>

                    <div className="mt-3 rounded-lg border border-white/5 bg-white/[0.02] p-2.5">
                        <div className="flex items-center justify-between gap-2">
                            <p className="font-mono text-[9px] text-slate-500 uppercase">
                                Changed files
                            </p>
                            <span className="text-[10px] text-slate-500">
                                {evidence.changed_files?.length ?? 0}
                            </span>
                        </div>

                        <div className="mt-1.5 grid gap-1">
                            {evidence.changed_files?.slice(0, 3).map((file) => (
                                <p
                                    key={file}
                                    title={file}
                                    className="truncate font-mono text-[9px] text-slate-400"
                                >
                                    {file}
                                </p>
                            ))}
                            {!evidence.changed_files?.length && (
                                <p className="text-[10px] text-slate-600">
                                    No changed-file evidence recorded.
                                </p>
                            )}
                            {(evidence.changed_files?.length ?? 0) > 3 && (
                                <p className="text-[9px] text-slate-600">
                                    +{(evidence.changed_files?.length ?? 0) - 3}{' '}
                                    more
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="mt-2">
                        <div className="flex items-center justify-between">
                            <p className="font-mono text-[9px] text-slate-500 uppercase">
                                Validation
                            </p>
                            <span
                                className={`text-[10px] ${
                                    evidence.validation_results?.passed === true
                                        ? 'text-emerald-300'
                                        : evidence.validation_results
                                                ?.passed === false
                                          ? 'text-rose-300'
                                          : 'text-slate-500'
                                }`}
                            >
                                {evidence.validation_results?.passed === true
                                    ? 'passed'
                                    : evidence.validation_results?.passed ===
                                        false
                                      ? 'failed'
                                      : 'not recorded'}
                            </span>
                        </div>

                        {checks.length > 0 && (
                            <div className="mt-1.5 grid grid-cols-2 gap-1">
                                {checks.map(([name, passed]) => (
                                    <div
                                        key={name}
                                        className="flex min-w-0 items-center gap-1.5 rounded-md bg-white/[0.02] px-2 py-1"
                                    >
                                        <span
                                            className={`size-1.5 shrink-0 rounded-full ${
                                                passed
                                                    ? 'bg-emerald-400'
                                                    : 'bg-rose-400'
                                            }`}
                                        />
                                        <span className="truncate font-mono text-[8px] text-slate-500">
                                            {name.replaceAll('_', ' ')}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </>
            ) : (
                <div className="rounded-lg border border-dashed border-slate-700 p-3 text-center">
                    <GitCommitHorizontal className="mx-auto size-5 text-slate-600" />
                    <p className="mt-2 text-xs text-slate-500">
                        No task-attempt Git evidence yet.
                    </p>
                    {project.git_head_sha && (
                        <p
                            className="mt-1 truncate font-mono text-[9px] text-slate-600"
                            title={project.git_head_sha}
                        >
                            Repository HEAD · {shortSha(project.git_head_sha)}
                        </p>
                    )}
                </div>
            )}
        </OpsPanel>
    );
}

function ProviderUsage({
    label,
    usage,
}: {
    label: string;
    usage: HarnessUsage;
}) {
    return (
        <div className="rounded-lg border border-white/5 bg-white/[0.02] p-2.5">
            <div className="flex items-center justify-between">
                <p className="text-xs font-medium text-slate-200">{label}</p>
                <span className="font-mono text-[9px] text-slate-500">
                    {usage.run_count} runs
                </span>
            </div>
            <p className="mt-1 font-mono text-[10px] text-cyan-300">
                {formatTokens(usage.token_usage)} tokens
            </p>
        </div>
    );
}

function HarnessUsagePanel({ project }: { project: Project }) {
    const zeroUsage: HarnessUsage = {
        run_count: 0,
        token_usage: 0,
    };

    const legacy = project.harness_usage.legacy;

    return (
        <OpsPanel title="Codex / Claude usage" eyebrow="Execution">
            <div className="flex items-end justify-between gap-3">
                <div>
                    <p className="font-mono text-[9px] text-slate-500 uppercase">
                        Total observed
                    </p>
                    <p className="mt-1 text-lg font-semibold text-violet-200">
                        {formatTokens(project.token_usage_total)}
                    </p>
                </div>
                <Activity className="size-4 text-violet-300" />
            </div>

            <div className="mt-3 grid gap-1.5">
                <ProviderUsage
                    label="Codex"
                    usage={project.harness_usage.codex ?? zeroUsage}
                />
                <ProviderUsage
                    label="Claude Code"
                    usage={project.harness_usage.claude_code ?? zeroUsage}
                />
                {legacy && (
                    <ProviderUsage label="Legacy / unknown" usage={legacy} />
                )}
            </div>

            <div className="mt-3 border-t border-white/5 pt-2">
                <p className="font-mono text-[9px] text-slate-600 uppercase">
                    Rolling role averages
                </p>
                <div className="mt-1.5 grid gap-1">
                    {Object.entries(project.token_observability)
                        .slice(0, 3)
                        .map(([role, usage]) => (
                            <div
                                key={role}
                                className="flex items-center justify-between gap-2 text-[9px]"
                            >
                                <span className="truncate text-slate-500">
                                    {role.replaceAll('_', ' ')}
                                </span>
                                <span className="shrink-0 font-mono text-slate-400">
                                    {usage.rolling_average === null
                                        ? 'no runs'
                                        : `${formatTokens(usage.rolling_average)} avg`}
                                </span>
                            </div>
                        ))}
                </div>
            </div>
        </OpsPanel>
    );
}

function OverviewDashboard({
    project,
    currentTask,
    completedTasks,
}: {
    project: Project;
    currentTask: Task | undefined;
    completedTasks: number;
}) {
    return (
        <div className="grid h-full min-h-0 gap-3 lg:grid-cols-[minmax(0,7fr)_minmax(18rem,3fr)]">
            <div className="min-h-0">
                <ClientAgentOffice
                    project={project}
                    currentTask={currentTask}
                    completedTasks={completedTasks}
                />
            </div>

            <aside className="min-h-0 space-y-3 overflow-y-auto pr-1">
                <RoadmapPanel
                    project={project}
                    currentTask={currentTask}
                    completedTasks={completedTasks}
                />
                <GitEvidencePanel project={project} />
                <HarnessUsagePanel project={project} />
            </aside>
        </div>
    );
}

function TasksPanel({ project }: { project: Project }) {
    return (
        <Card className="border-slate-700/70 bg-slate-950/75 text-slate-100">
            <CardHeader>
                <CardTitle>Ordered tasks</CardTitle>
                <CardDescription>
                    Serial task ordering remains controlled by AIOS.
                </CardDescription>
            </CardHeader>

            <CardContent className="grid gap-2.5">
                {project.tasks.map((task) => (
                    <div
                        key={task.id}
                        className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-800 bg-slate-900/45 p-3"
                    >
                        <div className="min-w-0">
                            <Link
                                href={
                                    showTask({
                                        project: project.id,
                                        task: task.id,
                                    }).url
                                }
                                className="font-medium text-slate-100 hover:text-cyan-200"
                            >
                                {task.key}: {task.title}
                            </Link>
                            <p className="mt-1 text-xs text-slate-500">
                                Attempt {task.attempts[0]?.number ?? '—'} ·
                                Review {task.reviews[0]?.status ?? '—'}
                            </p>
                        </div>

                        <div className="flex items-center gap-2">
                            <Badge
                                variant={
                                    task.status === 'done'
                                        ? 'default'
                                        : 'secondary'
                                }
                            >
                                {task.status}
                            </Badge>

                            {task.status === 'blocked' && (
                                <Form
                                    {...requeueTask.form({
                                        project: project.id,
                                        task: task.id,
                                    })}
                                >
                                    {({ processing }) => (
                                        <Button
                                            size="sm"
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            <RotateCcw />
                                            Retry
                                        </Button>
                                    )}
                                </Form>
                            )}
                        </div>
                    </div>
                ))}

                {project.tasks.length === 0 && (
                    <p className="py-8 text-center text-sm text-slate-500">
                        Upload a roadmap to generate implementation tasks.
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

function RecentActivityPanel({ project }: { project: Project }) {
    return (
        <div className="grid gap-3 xl:grid-cols-2">
            <Card className="border-slate-700/70 bg-slate-950/75 text-slate-100">
                <CardHeader>
                    <CardTitle>Agent executions</CardTitle>
                    <CardDescription>
                        Latest persisted execution and token evidence.
                    </CardDescription>
                </CardHeader>

                <CardContent className="grid gap-2">
                    {project.recent_agent_runs.map((run) => {
                        const failed =
                            run.status === 'failed' ||
                            (run.exit_code !== null && run.exit_code !== 0);

                        return (
                            <Link
                                key={run.id}
                                href={
                                    showAgentRun({
                                        project: project.id,
                                        run: run.id,
                                    }).url
                                }
                                className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-800 bg-slate-900/45 p-3 transition hover:border-cyan-300/20"
                            >
                                <div>
                                    <p className="text-sm font-medium text-slate-200 capitalize">
                                        {run.role.replaceAll('_', ' ')}
                                    </p>
                                    <p className="mt-1 text-xs text-slate-500">
                                        {harnessLabel(run.harness)} · Attempt{' '}
                                        {run.attempt_number ?? '—'} ·{' '}
                                        {run.token_usage === null
                                            ? 'Token usage unavailable'
                                            : `${formatTokens(run.token_usage)} tokens`}
                                    </p>
                                </div>

                                <Badge
                                    variant={
                                        failed ? 'destructive' : 'secondary'
                                    }
                                >
                                    {failed
                                        ? `error${run.exit_code === null ? '' : ` · exit ${run.exit_code}`}`
                                        : run.status}
                                </Badge>

                                {run.failure_reason && (
                                    <p className="w-full text-xs text-rose-300">
                                        {run.failure_reason}
                                    </p>
                                )}
                            </Link>
                        );
                    })}

                    {project.recent_agent_runs.length === 0 && (
                        <p className="py-6 text-center text-sm text-slate-500">
                            No agent executions recorded yet.
                        </p>
                    )}
                </CardContent>
            </Card>

            <Card className="border-slate-700/70 bg-slate-950/75 text-slate-100">
                <CardHeader>
                    <CardTitle>Audit trail</CardTitle>
                    <CardDescription>
                        Recent durable project activity recorded by AIOS.
                    </CardDescription>
                </CardHeader>

                <CardContent className="grid gap-1.5">
                    {project.audit_events.map((event) => (
                        <div
                            key={event.id}
                            className="flex items-center justify-between gap-4 rounded-lg border border-slate-800 bg-slate-900/45 px-3 py-2.5"
                        >
                            <div className="flex min-w-0 items-center gap-2">
                                <CheckCircle2 className="size-3.5 shrink-0 text-emerald-300" />
                                <span className="truncate text-xs text-slate-300">
                                    {event.event_type}
                                </span>
                            </div>
                            <time className="shrink-0 font-mono text-[9px] text-slate-600">
                                {new Date(event.occurred_at).toLocaleString()}
                            </time>
                        </div>
                    ))}

                    {project.audit_events.length === 0 && (
                        <p className="py-6 text-center text-sm text-slate-500">
                            No audit activity recorded.
                        </p>
                    )}
                </CardContent>
            </Card>
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
    usePoll(
        2_000,
        {
            only: ['project'],
            preserveErrors: true,
        },
        { mode: 'rest' },
    );

    const currentTask = project.tasks.find(
        (task) => !['done', 'cancelled'].includes(task.status),
    );
    const completedTasks = project.tasks.filter(
        (task) => task.status === 'done',
    ).length;
    const [tab, setTab] = useState<Tab>('overview');

    const tabs: { value: Tab; label: string }[] = [
        { value: 'overview', label: 'Overview' },
        { value: 'agents', label: 'Agents' },
        { value: 'skills', label: 'Skills' },
        { value: 'tasks', label: 'Tasks' },
        { value: 'activity', label: 'Recent Activity' },
    ];

    useAppHeaderSlot(
        <div className="flex min-w-0 flex-1 flex-wrap items-center justify-between gap-3">
            <div className="flex min-w-0 items-center gap-3">
                <Link
                    href={index().url}
                    aria-label="Back to projects"
                    className="grid size-8 shrink-0 place-items-center rounded-lg border border-slate-800 bg-slate-900/60 text-slate-500 transition hover:border-cyan-300/30 hover:text-cyan-200"
                >
                    <ArrowLeft className="size-4" />
                </Link>

                <div className="min-w-0">
                    <div className="flex min-w-0 items-center gap-2">
                        <h1 className="truncate text-base font-semibold text-white">
                            {project.name}
                        </h1>
                        <span className="hidden font-mono text-[9px] tracking-[0.14em] text-cyan-400 uppercase sm:inline">
                            AIOS project
                        </span>
                    </div>
                    <p className="mt-0.5 truncate font-mono text-[9px] text-slate-600">
                        {project.path}
                    </p>
                </div>
            </div>

            <div className="flex items-center gap-1.5">
                <Badge
                    variant="outline"
                    className="border-emerald-300/20 bg-emerald-400/5 font-mono text-[9px] text-emerald-300"
                >
                    {project.status}
                </Badge>
                <Badge
                    variant="outline"
                    className="hidden border-violet-300/20 bg-violet-400/5 font-mono text-[9px] text-violet-200 sm:inline-flex"
                >
                    Git · {project.git_status}
                </Badge>

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
                                className="h-7 border-slate-700 bg-slate-900/50 px-2 text-[10px]"
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
            <Head title={project.name} />

            <div className="dark relative flex w-full flex-col overflow-hidden bg-[#020711] text-slate-100 lg:h-[calc(100svh-4rem)]">
                <div className="pointer-events-none absolute inset-0 bg-[linear-gradient(rgba(56,189,248,0.025)_1px,transparent_1px),linear-gradient(90deg,rgba(56,189,248,0.025)_1px,transparent_1px)] bg-[size:32px_32px]" />
                <div className="pointer-events-none absolute -top-32 left-1/4 size-72 rounded-full bg-cyan-500/5 blur-3xl" />
                <div className="pointer-events-none absolute right-0 bottom-0 size-72 rounded-full bg-violet-500/5 blur-3xl" />

                <div className="relative flex min-h-0 flex-1 flex-col gap-2.5 px-3 py-3 md:px-4">
                    <nav
                        role="tablist"
                        aria-label="Project sections"
                        className="flex shrink-0 gap-1 overflow-x-auto rounded-xl border border-slate-800/80 bg-slate-950/60 p-1"
                    >
                        {tabs.map(({ value, label }) => (
                            <button
                                key={value}
                                type="button"
                                role="tab"
                                aria-selected={tab === value}
                                onClick={() => setTab(value)}
                                className={`shrink-0 rounded-lg px-3 py-1.5 text-[11px] font-medium transition ${
                                    tab === value
                                        ? 'glow-border border border-cyan-300/20 bg-cyan-400/10 text-cyan-100'
                                        : 'border border-transparent text-slate-500 hover:bg-white/[0.03] hover:text-slate-300'
                                }`}
                            >
                                {label}
                            </button>
                        ))}
                    </nav>

                    <main className="min-h-0 flex-1">
                        {tab === 'overview' && (
                            <OverviewDashboard
                                project={project}
                                currentTask={currentTask}
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
