import { Form, Head, Link, usePoll } from '@inertiajs/react';
import { ArrowLeft, Bot, MessageSquare, Send } from 'lucide-react';
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
    RecentRunActivityCard,
    RunHealthCard,
    RunSummaryMetrics,
    useAutoScrollConsole,
} from '@/components/agent-run-console';
import type { AgentRun } from '@/components/agent-run-console';
import {
    ContextBudgetEvidence,
    type ContextBudgetSnapshot,
} from '@/components/context-budget-evidence';
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
import { Label } from '@/components/ui/label';

type Project = {
    id: number;
    name: string;
    path: string;
};

type Message = {
    id: number;
    body: string;
    delivered_at: string | null;
    created_at: string;
    user: {
        id: number;
        name: string;
    };
};

function humanize(value: string): string {
    return value.replaceAll('_', ' ');
}

function statusBadgeClasses(status: string): string {
    if (status === 'running') {
        return 'status-glow-pulse border-primary/30 bg-primary/10 text-primary';
    }

    if (status === 'completed') {
        return 'border-success/30 bg-success/10 text-success-foreground';
    }

    if (status === 'failed') {
        return 'border-destructive/30 bg-destructive/10 text-destructive-foreground';
    }

    return 'border-border bg-card text-muted-foreground';
}

function formatDateTime(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

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
        {
            only: ['agent_run', 'project_manager_messages'],
        },
        {
            mode: 'rest',
        },
    );

    const live = isAgentRunLive(agentRun);
    const isProjectManager = agentRun.role === 'project_manager';
    const consoleRef = useAutoScrollConsole(live, agentRun.agent_messages);
    const roleLabel = humanize(agentRun.role);
    const runTitle = `${roleLabel} run`;
    const contextBudgetSnapshot = (
        agentRun as AgentRun & {
            context_budget_snapshot?: ContextBudgetSnapshot | null;
        }
    ).context_budget_snapshot ?? null;

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
                        <h1 className="truncate text-base font-semibold text-foreground capitalize">
                            {runTitle}
                        </h1>
                        <span className="hidden font-mono text-2xs tracking-[0.14em] text-primary uppercase sm:inline">
                            Run #{agentRun.id}
                        </span>
                    </div>
                    <p className="mt-0.5 truncate font-mono text-2xs text-muted-foreground">
                        {project.name}
                    </p>
                </div>
            </div>
        </div>,
    );

    return (
        <>
            <Head title={runTitle} />

            <div className="dark relative min-h-full w-full overflow-hidden bg-background text-foreground">
                <div className="pointer-events-none absolute inset-0 bg-[linear-gradient(color-mix(in_oklch,var(--primary)_4%,transparent)_1px,transparent_1px),linear-gradient(90deg,color-mix(in_oklch,var(--primary)_4%,transparent)_1px,transparent_1px)] bg-size-[32px_32px]" />
                <div className="pointer-events-none absolute -top-32 left-1/4 size-80 rounded-full bg-primary/8 blur-3xl" />
                <div className="pointer-events-none absolute right-0 bottom-0 size-80 rounded-full bg-secondary/20 blur-3xl" />

                <div className="relative flex w-full flex-col gap-3 px-3 py-3 md:px-4 md:py-4">
                    <header className="panel-elevated relative overflow-hidden px-4 py-4 md:px-5">
                        <div className="glow-line-accent" />

                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2 font-mono text-2xs text-muted-foreground">
                                    <Link
                                        href={showProject(project.id).url}
                                        className="transition hover:text-primary"
                                    >
                                        {project.name}
                                    </Link>
                                    <span>/</span>
                                    <span>runs</span>
                                    <span>/</span>
                                    <span className="text-primary">
                                        run_{agentRun.id}
                                    </span>
                                </div>

                                <div className="mt-3 flex min-w-0 items-center gap-3">
                                    <div className="grid size-10 shrink-0 place-items-center rounded-xl border border-primary/20 bg-primary/10 text-primary shadow-glow-sm">
                                        <Bot className="size-5" />
                                    </div>
                                    <div className="min-w-0">
                                        <h2 className="truncate text-xl font-semibold tracking-tight text-foreground capitalize md:text-2xl">
                                            {runTitle}
                                        </h2>
                                        <p className="mt-1 truncate text-xs text-muted-foreground">
                                            {agentRun.task
                                                ? `${agentRun.task.key}: ${agentRun.task.title}`
                                                : 'Project-level execution'}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div className="flex flex-col items-end gap-1.5">
                                <Badge
                                    variant="outline"
                                    className={`px-3 py-1 font-mono text-2xs uppercase ${statusBadgeClasses(
                                        agentRun.status,
                                    )}`}
                                >
                                    {agentRun.status}
                                </Badge>
                                <p className="font-mono text-2xs text-muted-foreground">
                                    Started{' '}
                                    {formatDateTime(agentRun.started_at)}
                                </p>
                            </div>
                        </div>
                    </header>

                    <RunSummaryMetrics agentRun={agentRun} />

                    <div className="grid min-w-0 gap-3 xl:grid-cols-[minmax(0,1fr)_20rem]">
                        <main className="min-w-0 space-y-3">
                            <AgentMessagesCard
                                agentRun={agentRun}
                                live={live}
                                consoleRef={consoleRef}
                            />

                            <div className="grid min-w-0 gap-3 lg:grid-cols-[minmax(0,0.85fr)_minmax(0,1.15fr)]">
                                <ExecutionDetailsCard agentRun={agentRun} />
                                <ConfigurationEvidenceCard
                                    agentRun={agentRun}
                                />
                            </div>

                            <ContextCostCard agentRun={agentRun} />
                            <ContextBudgetEvidence
                                snapshot={contextBudgetSnapshot}
                            />

                            {isProjectManager && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2">
                                            <MessageSquare className="size-4 text-primary" />
                                            Project Manager messages
                                        </CardTitle>
                                        <CardDescription>
                                            Operator instructions persisted for
                                            Project Manager execution.
                                        </CardDescription>
                                    </CardHeader>

                                    <CardContent className="grid gap-2">
                                        {messages.length === 0 && (
                                            <div className="rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
                                                No messages sent.
                                            </div>
                                        )}

                                        {messages.map((message) => (
                                            <div
                                                key={message.id}
                                                className="rounded-lg border border-border-subtle bg-foreground/[0.025] p-3"
                                            >
                                                <div className="mb-2 flex flex-wrap items-center justify-between gap-3">
                                                    <div>
                                                        <p className="text-xs font-medium text-foreground">
                                                            {message.user.name}
                                                        </p>
                                                        <time className="font-mono text-2xs text-muted-foreground">
                                                            {formatDateTime(
                                                                message.created_at,
                                                            )}
                                                        </time>
                                                    </div>

                                                    <Badge
                                                        variant="outline"
                                                        className={
                                                            message.delivered_at
                                                                ? 'border-success/30 bg-success/10 font-mono text-2xs text-success-foreground'
                                                                : 'border-warning/30 bg-warning/10 font-mono text-2xs text-warning-foreground'
                                                        }
                                                    >
                                                        {message.delivered_at
                                                            ? 'delivered'
                                                            : 'pending'}
                                                    </Badge>
                                                </div>

                                                <p className="text-sm leading-6 whitespace-pre-wrap text-foreground/90">
                                                    {message.body}
                                                </p>
                                            </div>
                                        ))}
                                    </CardContent>
                                </Card>
                            )}
                        </main>

                        <aside className="min-w-0 space-y-3 xl:sticky xl:top-3 xl:self-start">
                            <RunHealthCard agentRun={agentRun} />
                            <RecentRunActivityCard agentRun={agentRun} />

                            {isProjectManager && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2">
                                            <Send className="size-4 text-secondary-foreground" />
                                            Operator channel
                                        </CardTitle>
                                        <CardDescription>
                                            Delivered with the next Project
                                            Manager execution.
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
                                                            className="w-full resize-y rounded-lg border border-border bg-background/60 p-3 text-sm text-foreground transition outline-none placeholder:text-muted-foreground focus:border-primary/40 focus:ring-2 focus:ring-primary/10"
                                                            placeholder="Add roadmap context, a correction, or a question."
                                                        />
                                                        <InputError
                                                            message={
                                                                errors.body
                                                            }
                                                        />
                                                    </div>

                                                    <Button
                                                        disabled={processing}
                                                        className="w-full"
                                                    >
                                                        <Send className="size-4" />
                                                        {processing
                                                            ? 'Sending…'
                                                            : 'Send message'}
                                                    </Button>
                                                </>
                                            )}
                                        </Form>
                                    </CardContent>
                                </Card>
                            )}
                        </aside>
                    </div>
                </div>
            </div>
        </>
    );
}

