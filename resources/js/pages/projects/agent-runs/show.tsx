import { Form, Head, Link, usePoll } from '@inertiajs/react';
import { ArrowLeft, Send } from 'lucide-react';
import {
    show as showProject,
    storeProjectManagerMessage,
} from '@/actions/App/Http/Controllers/ProjectController';
import {
    AgentMessagesCard,
    ConfigurationEvidenceCard,
    ContextCostCard,
    ExecutionDetailsCard,
    isAgentRunLive,
    useAutoScrollConsole,
} from '@/components/agent-run-console';
import type { AgentRun } from '@/components/agent-run-console';
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
    const live = isAgentRunLive(agentRun);
    const isProjectManager = agentRun.role === 'project_manager';
    const consoleRef = useAutoScrollConsole(live, agentRun.agent_messages);

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

                <AgentMessagesCard
                    agentRun={agentRun}
                    live={live}
                    consoleRef={consoleRef}
                />

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <ExecutionDetailsCard agentRun={agentRun} />

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

                <ConfigurationEvidenceCard agentRun={agentRun} />

                <ContextCostCard agentRun={agentRun} />

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
