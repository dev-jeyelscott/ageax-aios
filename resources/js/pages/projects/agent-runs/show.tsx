import { Form, Head, Link, usePoll } from '@inertiajs/react';
import { ArrowLeft, Send } from 'lucide-react';
import { useEffect, useRef } from 'react';
import {
    show as showProject,
    storeProjectManagerMessage,
} from '@/actions/App/Http/Controllers/ProjectController';
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

type Project = { id: number; name: string; path: string };
type Message = {
    id: number;
    body: string;
    delivered_at: string | null;
    created_at: string;
    user: { id: number; name: string };
};
type ConfigurationSnapshotAgent = {
    id: number;
    name: string;
    role: string;
    harness: string;
    model: string | null;
    reasoning_setting: string | null;
    configuration_version: number;
};
type ConfigurationSnapshotSkill = {
    id: number;
    slug: string;
    name: string;
    version: number;
    position: number;
};
type ConfigurationSnapshot = {
    context_schema_version: number;
    context_hash: string;
    agent: ConfigurationSnapshotAgent;
    skills: ConfigurationSnapshotSkill[];
};
type AgentRun = {
    id: number;
    role: string;
    status: string;
    attempt_number: number | null;
    agent_messages: string[];
    exit_code: number | null;
    token_usage: number | null;
    started_at: string | null;
    finished_at: string | null;
    harness: string | null;
    external_run_id: string | null;
    context_schema_version: number | null;
    configuration_snapshot: ConfigurationSnapshot | null;
    task: { key: string; title: string } | null;
    worker: {
        role: string;
        status: string;
        last_heartbeat_at: string | null;
    } | null;
};

export default function AgentRunShow({
    project,
    agent_run: agentRun,
    project_manager_messages: messages,
}: {
    project: Project;
    agent_run: AgentRun;
    project_manager_messages: Message[];
}) {
    usePoll(
        2_000,
        { only: ['agent_run', 'project_manager_messages'] },
        {
            mode: 'rest',
        },
    );
    const consoleRef = useRef<HTMLDivElement>(null);
    const live = agentRun.status === 'running';
    const isProjectManager = agentRun.role === 'project_manager';

    useEffect(() => {
        if (live && consoleRef.current) {
            consoleRef.current.scrollTop = consoleRef.current.scrollHeight;
        }
    }, [agentRun.agent_messages, live]);

    return (
        <>
            <Head title={`${agentRun.role.replace('_', ' ')} run`} />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-8">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <Link
                            href={showProject(project.id).url}
                            className="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                        >
                            <ArrowLeft className="size-4" /> {project.name}
                        </Link>
                        <h1 className="text-2xl font-semibold capitalize">
                            {agentRun.role.replace('_', ' ')} run
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {agentRun.task
                                ? `${agentRun.task.key}: ${agentRun.task.title}`
                                : 'Project-level execution'}
                        </p>
                    </div>
                    <Badge variant={live ? 'default' : 'secondary'}>
                        {agentRun.status}
                    </Badge>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Agent messages</CardTitle>
                        <CardDescription>
                            {live
                                ? 'Live updates. Refreshes every two seconds.'
                                : 'Captured agent updates.'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent
                        ref={consoleRef}
                        className="max-h-[36rem] overflow-auto"
                    >
                        <div className="grid gap-3">
                            {agentRun.agent_messages.map((message, index) => (
                                <p
                                    key={`${index}-${message}`}
                                    className="rounded-md border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm whitespace-pre-wrap text-emerald-950 dark:text-emerald-100"
                                >
                                    {message}
                                </p>
                            ))}
                            {agentRun.agent_messages.length === 0 && (
                                <p className="rounded-md border border-dashed p-3 text-sm text-muted-foreground">
                                    {live
                                        ? 'Waiting for the agent’s first update.'
                                        : 'This run did not produce an agent message.'}
                                </p>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Execution details</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-3 text-sm text-muted-foreground">
                            <p>Attempt: {agentRun.attempt_number ?? '—'}</p>
                            <p>Exit code: {agentRun.exit_code ?? 'Running'}</p>
                            <p>
                                Token usage:{' '}
                                {agentRun.token_usage?.toLocaleString() ??
                                    'Unavailable'}
                            </p>
                            <p>
                                Worker:{' '}
                                {agentRun.worker?.status ?? 'Not recorded'}
                            </p>
                        </CardContent>
                    </Card>

                    {isProjectManager && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Message Project Manager</CardTitle>
                                <CardDescription>
                                    Delivered with the next PM execution.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Form
                                    action={storeProjectManagerMessage(
                                        project.id,
                                    )}
                                    resetOnSuccess
                                    className="grid gap-3"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <div className="grid gap-2">
                                                <Label htmlFor="body">
                                                    Instruction
                                                </Label>
                                                <textarea
                                                    id="body"
                                                    name="body"
                                                    required
                                                    rows={5}
                                                    className="w-full rounded-md border bg-background p-3 text-sm"
                                                    placeholder="Add roadmap context, a correction, or a question."
                                                />
                                                <InputError
                                                    message={errors.body}
                                                />
                                            </div>
                                            <Button disabled={processing}>
                                                <Send /> Send to Project Manager
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </CardContent>
                        </Card>
                    )}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Configuration evidence</CardTitle>
                        <CardDescription>
                            {agentRun.configuration_snapshot
                                ? 'Immutable snapshot captured at run start. Later Agent or Skill edits never change this record.'
                                : 'This run predates immutable configuration snapshots.'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3 text-sm">
                        {agentRun.configuration_snapshot ? (
                            <>
                                <div className="grid gap-1 text-muted-foreground">
                                    <p>
                                        Agent:{' '}
                                        <span className="text-foreground">
                                            {
                                                agentRun.configuration_snapshot
                                                    .agent.name
                                            }
                                        </span>{' '}
                                        (v
                                        {
                                            agentRun.configuration_snapshot
                                                .agent.configuration_version
                                        }
                                        )
                                    </p>
                                    <p>
                                        Role:{' '}
                                        {agentRun.configuration_snapshot.agent.role.replace(
                                            '_',
                                            ' ',
                                        )}
                                    </p>
                                    <p>
                                        Harness:{' '}
                                        {agentRun.harness ??
                                            agentRun.configuration_snapshot
                                                .agent.harness}
                                    </p>
                                    <p>
                                        Model:{' '}
                                        {agentRun.configuration_snapshot.agent
                                            .model ?? 'Harness default'}
                                    </p>
                                    <p>
                                        Reasoning / effort:{' '}
                                        {agentRun.configuration_snapshot.agent
                                            .reasoning_setting ??
                                            'Model default'}
                                    </p>
                                    <p>
                                        Context schema version:{' '}
                                        {
                                            agentRun.configuration_snapshot
                                                .context_schema_version
                                        }
                                    </p>
                                    <p className="break-all">
                                        Context hash:{' '}
                                        {
                                            agentRun.configuration_snapshot
                                                .context_hash
                                        }
                                    </p>
                                    {agentRun.external_run_id && (
                                        <p>
                                            External run ID:{' '}
                                            {agentRun.external_run_id}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <p className="mb-1 text-xs font-medium text-muted-foreground">
                                        Skills applied (
                                        {
                                            agentRun.configuration_snapshot
                                                .skills.length
                                        }
                                        )
                                    </p>
                                    {agentRun.configuration_snapshot.skills
                                        .length === 0 ? (
                                        <p className="text-xs text-muted-foreground">
                                            No skills were assigned to this
                                            agent for this run.
                                        </p>
                                    ) : (
                                        <ul className="grid gap-1">
                                            {agentRun.configuration_snapshot.skills.map(
                                                (skill) => (
                                                    <li
                                                        key={skill.id}
                                                        className="flex items-center justify-between rounded-md border px-2 py-1 text-xs"
                                                    >
                                                        <span>
                                                            {skill.position + 1}
                                                            . {skill.name}
                                                        </span>
                                                        <Badge variant="outline">
                                                            v{skill.version}
                                                        </Badge>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    )}
                                </div>
                            </>
                        ) : (
                            <Badge variant="outline">Legacy run</Badge>
                        )}
                    </CardContent>
                </Card>

                {isProjectManager && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Project Manager messages</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-3">
                            {messages.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    No messages sent.
                                </p>
                            )}
                            {messages.map((message) => (
                                <div
                                    key={message.id}
                                    className="rounded-md border p-3 text-sm"
                                >
                                    <div className="mb-2 flex items-center justify-between gap-3 text-xs text-muted-foreground">
                                        <span>{message.user.name}</span>
                                        <Badge variant="outline">
                                            {message.delivered_at
                                                ? 'delivered'
                                                : 'pending'}
                                        </Badge>
                                    </div>
                                    <p className="whitespace-pre-wrap">
                                        {message.body}
                                    </p>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}
