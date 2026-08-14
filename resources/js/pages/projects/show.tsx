import { Form, Head, Link, usePoll } from '@inertiajs/react';
import {
    ArrowLeft,
    FileUp,
    GitCommitHorizontal,
    Pause,
    Play,
    RotateCcw,
} from 'lucide-react';
import {
    index,
    requeueTask,
    showAgentRun,
    showTask,
    updateStatus,
} from '@/actions/App/Http/Controllers/ProjectController';
import { AgentOffice } from '@/components/agent-office';
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
import { store as storeRoadmap } from '@/routes/projects/roadmaps';

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
};

function formatTokens(tokens: number): string {
    return new Intl.NumberFormat().format(tokens);
}

export default function ProjectShow({ project }: { project: Project }) {
    usePoll(2_000, { only: ['project'] }, { mode: 'rest' });
    const currentTask = project.tasks.find(
        (task) => !['done', 'cancelled'].includes(task.status),
    );
    const latestRoadmap = project.roadmaps[0];

    return (
        <>
            <Head title={project.name} />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-8">
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
                <AgentOffice
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
                                    <Badge
                                        variant={
                                            failed ? 'destructive' : 'secondary'
                                        }
                                    >
                                        {failed
                                            ? `error${run.exit_code === null ? '' : ` · exit ${run.exit_code}`}`
                                            : run.status}
                                    </Badge>
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
            </div>
        </>
    );
}

ProjectShow.layout = {
    breadcrumbs: [{ title: 'Projects', href: index().url }],
};
