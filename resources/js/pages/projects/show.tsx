import { Form, Head, Link, usePoll } from '@inertiajs/react';
import {
    ArrowLeft,
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
import {
    AgentsPanel
    
    
    
} from '@/pages/projects/agents-panel';
import type {Agent, HarnessCapabilities, Worker} from '@/pages/projects/agents-panel';
import { SkillsPanel  } from '@/pages/projects/skills-panel';
import type {Skill} from '@/pages/projects/skills-panel';
import { store as storeRoadmap } from '@/routes/projects/roadmaps';

const LazyAgentOffice = lazy(() =>
    import('@/components/agent-office').then(({ AgentOffice }) => ({
        default: AgentOffice,
    })),
);

function ClientAgentOffice({
    projectId,
    workers,
}: {
    projectId: number;
    workers: OfficeWorker[];
}) {
    const isClient = useSyncExternalStore(
        () => () => undefined,
        () => true,
        () => false,
    );

    if (!isClient) {
        return (
            <div className="grid min-h-[calc(100svh-8.5rem)] place-items-center rounded-2xl border border-slate-700/70 bg-slate-950 px-6 text-center text-slate-300 shadow-2xl">
                Loading the live office…
            </div>
        );
    }

    return (
        <Suspense
            fallback={
                <div className="grid min-h-[calc(100svh-8.5rem)] place-items-center rounded-2xl border border-slate-700/70 bg-slate-950 px-6 text-center text-slate-300 shadow-2xl">
                    Loading the live office…
                </div>
            }
        >
            <LazyAgentOffice projectId={projectId} workers={workers} />
        </Suspense>
    );
}

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
    status: string;
    attempt_number: number | null;
    token_usage: number | null;
    exit_code: number | null;
    started_at: string;
    failure_reason: string | null;
};
type Project = {
    id: number;
    name: string;
    path: string;
    status: string;
    git_status: string;
    git_head_sha: string | null;
    roadmaps: { id: number; original_filename: string; status: string }[];
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
    recent_agent_runs: AgentRun[];
    audit_events: { id: number; event_type: string; occurred_at: string }[];
    agents: Agent[];
    skills: Skill[];
    workers: Worker[];
};

function formatTokens(tokens: number): string {
    return new Intl.NumberFormat().format(tokens);
}

type Tab = 'overview' | 'agents' | 'skills';

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
    const latestRoadmap = project.roadmaps[0];
    const [tab, setTab] = useState<Tab>('overview');

    return (
        <>
            <Head title={project.name} />
            <div className="flex w-full flex-col gap-5 px-3 py-4 md:px-5 md:py-6 2xl:px-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <Link
                            href={index().url}
                            className="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                        >
                            <ArrowLeft className="size-4" />
                            Projects
                        </Link>
                        <h1 className="text-2xl font-semibold">
                            {project.name}
                        </h1>
                        <p className="text-muted-foreground">{project.path}</p>
                    </div>
                    <div className="flex gap-2">
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
                                            processing ||
                                            project.status === 'stopping'
                                        }
                                    >
                                        {project.status === 'running' ? (
                                            <Pause />
                                        ) : (
                                            <Play />
                                        )}
                                        {project.status === 'running'
                                            ? 'Pause'
                                            : project.status === 'stopping'
                                              ? 'Pause pending'
                                              : 'Resume'}
                                    </Button>
                                </>
                            )}
                        </Form>
                        <Badge>{project.status}</Badge>
                        <Badge variant="outline">
                            Git: {project.git_status}
                        </Badge>
                    </div>
                </div>
                <div
                    role="tablist"
                    className="flex gap-1 border-b"
                    aria-label="Project sections"
                >
                    {(
                        [
                            ['overview', 'Overview'],
                            ['agents', `Agents (${project.agents.length})`],
                            ['skills', `Skills (${project.skills.length})`],
                        ] as [Tab, string][]
                    ).map(([value, label]) => (
                        <button
                            key={value}
                            type="button"
                            role="tab"
                            aria-selected={tab === value}
                            onClick={() => setTab(value)}
                            className={`border-b-2 px-3 py-2 text-sm font-medium ${
                                tab === value
                                    ? 'border-primary text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            {label}
                        </button>
                    ))}
                </div>
                {tab === 'agents' && (
                    <AgentsPanel
                        projectId={project.id}
                        agents={project.agents}
                        skills={project.skills}
                        workers={project.workers}
                        harnessCapabilities={harnessCapabilities}
                    />
                )}
                {tab === 'skills' && (
                    <SkillsPanel
                        projectId={project.id}
                        skills={project.skills}
                    />
                )}
                {tab === 'overview' && (
                    <>
                <ClientAgentOffice
                    projectId={project.id}
                    workers={project.office_workers}
                />
                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle>Roadmap</CardTitle>
                            <CardDescription>
                                Upload Markdown or plain text for structured PM
                                decomposition.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Form
                                {...storeRoadmap.form(project.id)}
                                encType="multipart/form-data"
                                className="flex flex-wrap gap-3"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <input
                                            name="roadmap"
                                            type="file"
                                            accept=".md,.txt,text/markdown,text/plain"
                                            required
                                        />
                                        <InputError message={errors.roadmap} />
                                        <Button disabled={processing}>
                                            <FileUp />
                                            Upload roadmap
                                        </Button>
                                    </>
                                )}
                            </Form>
                            {latestRoadmap && (
                                <p className="mt-3 text-sm text-muted-foreground">
                                    {latestRoadmap.original_filename} ·{' '}
                                    <span className="capitalize">
                                        {latestRoadmap.status}
                                    </span>
                                </p>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Git evidence</CardTitle>
                        </CardHeader>
                        <CardContent className="flex items-center gap-2 text-sm text-muted-foreground">
                            <GitCommitHorizontal className="size-4" />
                            {project.git_head_sha ??
                                'No implementation commit yet'}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Codex usage</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm text-muted-foreground">
                            {formatTokens(project.token_usage_total)} tokens
                            <div className="mt-2 grid gap-1 text-xs">
                                {Object.entries(
                                    project.token_observability,
                                ).map(([role, usage]) => (
                                    <span key={role}>
                                        {role}:{' '}
                                        {usage.rolling_average === null
                                            ? 'no runs'
                                            : `${formatTokens(usage.rolling_average)} avg / ${formatTokens(usage.warning_threshold)} warning`}
                                        {usage.change_percentage === null
                                            ? ''
                                            : ` (${usage.change_percentage > 0 ? '+' : ''}${usage.change_percentage}% vs baseline)`}
                                    </span>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>Current workflow</CardTitle>
                    </CardHeader>
                    <CardContent className="text-sm text-muted-foreground">
                        {currentTask ? (
                            <Link
                                href={
                                    showTask({
                                        project: project.id,
                                        task: currentTask.id,
                                    }).url
                                }
                                className="font-medium text-foreground hover:underline"
                            >
                                {currentTask.key}: {currentTask.title} ·{' '}
                                {currentTask.status}
                            </Link>
                        ) : project.tasks.length > 0 ? (
                            'All roadmap tasks are complete.'
                        ) : (
                            'Upload a roadmap to begin planning.'
                        )}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Ordered tasks</CardTitle>
                        <CardDescription>
                            The next task cannot be coded until the preceding
                            work is approved.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3">
                        {project.tasks.map((task) => (
                            <div
                                key={task.id}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
                            >
                                <div>
                                    <Link
                                        href={
                                            showTask({
                                                project: project.id,
                                                task: task.id,
                                            }).url
                                        }
                                        className="font-medium hover:underline"
                                    >
                                        {task.key}: {task.title}
                                    </Link>
                                    <p className="text-sm text-muted-foreground">
                                        Attempt{' '}
                                        {task.attempts[0]?.number ?? '—'} ·
                                        Review {task.reviews[0]?.status ?? '—'}
                                    </p>
                                </div>
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
                        ))}
                        {project.tasks.length === 0 && (
                            <p className="py-6 text-center text-muted-foreground">
                                Upload a roadmap to generate implementation
                                tasks.
                            </p>
                        )}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Recent agent activity</CardTitle>
                        <CardDescription>
                            Latest executions, token usage, and failures.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-2 text-sm">
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
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3"
                                >
                                    <div>
                                        <p className="font-medium capitalize">
                                            {run.role.replace('_', ' ')} ·
                                            Attempt {run.attempt_number ?? '—'}
                                        </p>
                                        <p className="text-muted-foreground">
                                            {run.token_usage === null
                                                ? 'Token usage unavailable'
                                                : `${formatTokens(run.token_usage)} tokens`}
                                        </p>
                                    </div>
                                    <div className="flex flex-col items-end gap-1">
                                        <Badge
                                            variant={
                                                failed
                                                    ? 'destructive'
                                                    : 'secondary'
                                            }
                                        >
                                            {failed
                                                ? `error${run.exit_code === null ? '' : ` · exit ${run.exit_code}`}`
                                                : run.status}
                                        </Badge>
                                        {run.failure_reason && (
                                            <span className="max-w-xs text-right text-xs text-destructive">
                                                {run.failure_reason}
                                            </span>
                                        )}
                                    </div>
                                </Link>
                            );
                        })}
                        {project.recent_agent_runs.length === 0 && (
                            <p className="text-muted-foreground">
                                No agent executions recorded yet.
                            </p>
                        )}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Recent activity</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-2 text-sm">
                        {project.audit_events.map((event) => (
                            <div
                                key={event.id}
                                className="flex justify-between gap-4"
                            >
                                <span>{event.event_type}</span>
                                <time className="text-muted-foreground">
                                    {new Date(
                                        event.occurred_at,
                                    ).toLocaleString()}
                                </time>
                            </div>
                        ))}
                    </CardContent>
                </Card>
                    </>
                )}
            </div>
        </>
    );
}

ProjectShow.layout = {
    breadcrumbs: [{ title: 'Projects', href: index().url }],
};
