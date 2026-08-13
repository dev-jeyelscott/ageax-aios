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
type AgentRun = {
    id: number;
    role: string;
    status: string;
    attempt_number: number | null;
    live_output: string | null;
    transcript: string | null;
    exit_code: number | null;
    token_usage: number | null;
    started_at: string | null;
    finished_at: string | null;
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
    const consoleRef = useRef<HTMLPreElement>(null);
    const live = agentRun.status === 'running';
    const isProjectManager = agentRun.role === 'project_manager';

    useEffect(() => {
        if (live && consoleRef.current) {
            consoleRef.current.scrollTop = consoleRef.current.scrollHeight;
        }
    }, [agentRun.live_output, live]);

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
                        <CardTitle>Agent console</CardTitle>
                        <CardDescription>
                            {live
                                ? 'Live output. Refreshes every two seconds.'
                                : 'Captured execution output.'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <pre
                            ref={consoleRef}
                            className="max-h-[36rem] overflow-auto rounded-md bg-zinc-950 p-4 font-mono text-xs leading-5 whitespace-pre-wrap text-zinc-100"
                        >
                            {agentRun.live_output ??
                                agentRun.transcript ??
                                'No output has reached AIOS yet.'}
                        </pre>
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
