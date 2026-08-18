import { Form, Head, Link, usePoll } from '@inertiajs/react';
import {
    Activity,
    ArrowLeft,
    CheckCircle2,
    CircleDot,
    Clock,
    Cpu,
    FileUp,
    GitCommitHorizontal,
    Pause,
    Play,
    RotateCcw,
    ShieldCheck,
} from 'lucide-react';
import { lazy, Suspense, useState, useSyncExternalStore } from 'react';
import {
    index,
    requeueRoadmap,
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
    office_workflow: OfficeWorkflow | null;
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
        <section className="panel-elevated relative overflow-hidden p-2.5">
            <div className="glow-edge glow-line-secondary" />
            <p className="font-mono text-2xs tracking-[0.16em] text-secondary-foreground uppercase">
                {eyebrow}
            </p>
            <h3 className="mt-0.5 text-sm font-semibold text-foreground">
                {title}
            </h3>
            <div className="mt-2">{children}</div>
        </section>
    );
}

function RoadmapPanel({
    project,
    roadmapTask,
    completedTasks,
}: {
    project: Project;
    roadmapTask: Task | undefined;
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
                    <p className="text-2xl font-semibold text-primary">
                        {progress}%
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {completedTasks} of {project.tasks.length} tasks
                        complete
                    </p>
                </div>

                {latestRoadmap && (
                    <Badge
                        variant="outline"
                        className="border-primary/20 bg-primary/5 font-mono text-2xs text-primary"
                    >
                        {latestRoadmap.status}
                    </Badge>
                )}
            </div>

            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                <div
                    className="progress-flow h-full rounded-full transition-[width]"
                    style={{ width: `${progress}%` }}
                />
            </div>

            <div className="mt-2 min-w-0 rounded-lg border border-border-subtle bg-foreground/2 p-2">
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
                        className="mt-0.5 block truncate text-xs font-medium text-foreground hover:text-primary"
                    >
                        {roadmapTask.key}: {roadmapTask.title}
                    </Link>
                ) : (
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {project.tasks.length > 0
                            ? 'No unfinished task.'
                            : 'Upload a roadmap to begin planning.'}
                    </p>
                )}
            </div>

            {latestRoadmap && (
                <p
                    className="mt-1.5 truncate text-xs text-muted-foreground"
                    title={latestRoadmap.original_filename}
                >
                    {latestRoadmap.original_filename}
                </p>
            )}

            {latestRoadmap && latestRoadmap.status === 'blocked' && (
                <Form
                    {...requeueRoadmap.form({
                        project: project.id,
                        roadmap: latestRoadmap.id,
                    })}
                    className="mt-1.5"
                >
                    {({ processing }) => (
                        <Button
                            size="sm"
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            className="w-full border-destructive/25 bg-destructive/10 text-destructive-foreground hover:bg-destructive/15"
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
                className="mt-1.5"
            >
                {({ errors, processing }) => (
                    <>
                        <label className="flex cursor-pointer items-center justify-center gap-2 rounded-lg border border-primary/15 bg-primary/5 px-2.5 py-1.5 text-xs font-medium text-primary transition hover:border-primary/30 hover:bg-primary/10">
                            <input
                                name="roadmap"
                                type="file"
                                accept=".md,.txt,text/markdown,text/plain"
                                required
                                disabled={processing}
                                className="sr-only"
                                onChange={(event) => {
                                    if (event.currentTarget.files?.length) {
                                        event.currentTarget.form?.requestSubmit();
                                    }
                                }}
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
                            className="min-w-0 truncate text-xs font-medium text-foreground hover:text-primary"
                        >
                            {evidence.task.key}
                        </Link>

                        <Badge
                            variant="outline"
                            className="border-border bg-card font-mono text-2xs text-muted-foreground"
                        >
                            Attempt {evidence.attempt_number}
                        </Badge>
                    </div>

                    <dl className="mt-2 grid grid-cols-3 gap-1.5">
                        {[
                            ['Base', evidence.base_sha],
                            ['Head', evidence.head_sha],
                            ['Commit', evidence.commit_sha],
                        ].map(([label, value]) => (
                            <div
                                key={label}
                                className="min-w-0 rounded-md border border-border-subtle bg-foreground/2 p-1.5"
                            >
                                <dt className="font-mono text-2xs text-muted-foreground uppercase">
                                    {label}
                                </dt>
                                <dd
                                    className="mt-0.5 truncate font-mono text-2xs text-primary"
                                    title={value ?? undefined}
                                >
                                    {shortSha(value)}
                                </dd>
                            </div>
                        ))}
                    </dl>

                    <div className="mt-2 rounded-lg border border-border-subtle bg-foreground/2 p-2">
                        <div className="flex items-center justify-between gap-2">
                            <p className="font-mono text-2xs text-muted-foreground uppercase">
                                Changed files
                            </p>
                            <span className="text-xs text-muted-foreground">
                                {evidence.changed_files?.length ?? 0}
                            </span>
                        </div>

                        <div className="mt-1 grid gap-1">
                            {evidence.changed_files?.slice(0, 2).map((file) => (
                                <p
                                    key={file}
                                    title={file}
                                    className="truncate font-mono text-2xs text-muted-foreground"
                                >
                                    {file}
                                </p>
                            ))}

                            {!evidence.changed_files?.length && (
                                <p className="text-xs text-muted-foreground">
                                    No changed-file evidence recorded.
                                </p>
                            )}

                            {(evidence.changed_files?.length ?? 0) > 2 && (
                                <p className="text-2xs text-muted-foreground">
                                    +{(evidence.changed_files?.length ?? 0) - 2}{' '}
                                    more
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="mt-1.5">
                        <div className="flex items-center justify-between">
                            <p className="font-mono text-2xs text-muted-foreground uppercase">
                                Validation
                            </p>

                            <span
                                className={`text-xs ${
                                    evidence.validation_results?.passed === true
                                        ? 'text-success-foreground'
                                        : evidence.validation_results
                                                ?.passed === false
                                          ? 'text-destructive-foreground'
                                          : 'text-muted-foreground'
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
                            <div className="mt-1 grid grid-cols-2 gap-1">
                                {checks.map(([name, passed]) => (
                                    <div
                                        key={name}
                                        className="flex min-w-0 items-center gap-1.5 rounded-md bg-foreground/2 px-2 py-1"
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
                        )}
                    </div>
                </>
            ) : (
                <div className="rounded-lg border border-dashed border-border p-2.5 text-center">
                    <GitCommitHorizontal className="mx-auto size-5 text-muted-foreground" />
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        No task-attempt Git evidence yet.
                    </p>

                    {project.git_head_sha && (
                        <p
                            className="mt-1 truncate font-mono text-2xs text-muted-foreground"
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
        <div className="rounded-lg border border-border-subtle bg-foreground/2 p-2">
            <div className="flex items-center justify-between">
                <p className="text-xs font-medium text-foreground">{label}</p>
                <span className="font-mono text-2xs text-muted-foreground">
                    {usage.run_count} runs
                </span>
            </div>

            <p className="mt-0.5 font-mono text-xs text-primary">
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
                    <p className="font-mono text-2xs text-muted-foreground uppercase">
                        Total observed
                    </p>
                    <p className="mt-0.5 text-lg font-semibold text-secondary-foreground">
                        {formatTokens(project.token_usage_total)}
                    </p>
                </div>

                <Activity className="size-4 text-secondary-foreground" />
            </div>

            <div className="mt-2 grid gap-1.5">
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

            <div className="mt-2 border-t border-border-subtle pt-1.5">
                <p className="font-mono text-2xs text-muted-foreground uppercase">
                    Rolling role averages
                </p>

                <div className="mt-1 grid gap-1">
                    {Object.entries(project.token_observability)
                        .slice(0, 2)
                        .map(([role, usage]) => (
                            <div
                                key={role}
                                className="flex items-center justify-between gap-2 text-2xs"
                            >
                                <span className="truncate text-muted-foreground">
                                    {role.replaceAll('_', ' ')}
                                </span>
                                <span className="shrink-0 font-mono text-2xs text-muted-foreground">
                                    {usage.rolling_average === null
                                        ? 'no runs'
                                        : `${formatTokens(
                                              usage.rolling_average,
                                          )} avg`}
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
    roadmapTask,
    completedTasks,
}: {
    project: Project;
    roadmapTask: Task | undefined;
    completedTasks: number;
}) {
    return (
        <div className="grid h-full min-h-0 gap-2.5 lg:grid-cols-[minmax(0,7fr)_minmax(18rem,3fr)]">
            <div className="min-h-0">
                <ClientAgentOffice
                    project={project}
                    completedTasks={completedTasks}
                />
            </div>

            <aside className="min-h-0 space-y-2 overflow-y-auto pr-1">
                <RoadmapPanel
                    project={project}
                    roadmapTask={roadmapTask}
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

    const activeWorkerCount = project.office_workers.filter((worker) =>
        ['working', 'recovering'].includes(worker.status),
    ).length;

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
                        detail="Persisted execution usage"
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
                                {visibleAuditEvents.map((event, index) => (
                                    <div
                                        key={event.id}
                                        className="relative grid grid-cols-[2rem_minmax(0,1fr)] gap-2.5 pb-2 last:pb-0"
                                    >
                                        <div className="relative flex justify-center">
                                            <div className="z-10 grid size-7 place-items-center rounded-lg border border-primary/20 bg-primary/5 text-primary shadow-glow-sm">
                                                <CheckCircle2 className="size-3.5" />
                                            </div>

                                            {index <
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
    usePoll(
        2_000,
        {
            only: ['project'],
            preserveErrors: true,
        },
        { mode: 'rest' },
    );

    const roadmapTask = project.tasks.find(
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
            <Head title={project.name} />

            <div className="dark relative flex w-full flex-col overflow-hidden bg-background text-foreground lg:h-[calc(100svh-4rem)]">
                <div className="pointer-events-none absolute inset-0 bg-[linear-gradient(color-mix(in_oklch,var(--primary)_4%,transparent)_1px,transparent_1px),linear-gradient(90deg,color-mix(in_oklch,var(--primary)_4%,transparent)_1px,transparent_1px)] bg-size-[32px_32px]" />
                <div className="pointer-events-none absolute -top-32 left-1/4 size-72 rounded-full bg-primary/8 blur-3xl" />
                <div className="pointer-events-none absolute right-0 bottom-0 size-72 rounded-full bg-secondary/20 blur-3xl" />

                <div className="relative flex min-h-0 flex-1 flex-col gap-2.5 px-3 py-3 md:px-4">
                    <nav
                        role="tablist"
                        aria-label="Project sections"
                        className="flex shrink-0 gap-1 overflow-x-auto rounded-xl border border-border/80 bg-background/60 p-1"
                    >
                        {tabs.map(({ value, label }) => (
                            <button
                                key={value}
                                type="button"
                                role="tab"
                                aria-selected={tab === value}
                                onClick={() => setTab(value)}
                                className={`shrink-0 rounded-lg px-3 py-1.5 text-xs font-medium transition ${
                                    tab === value
                                        ? 'glow-border border border-primary/20 bg-primary/10 text-primary/80'
                                        : 'border border-transparent text-muted-foreground hover:bg-foreground/3 hover:text-muted-foreground'
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
                                roadmapTask={roadmapTask}
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
