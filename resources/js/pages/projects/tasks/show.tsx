import { Form, Head, Link, usePoll } from '@inertiajs/react';
import { ArrowLeft, Send } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    show as showProject,
    storeOperatorMessage,
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
type Task = {
    id: number;
    key: string;
    title: string;
    status: string;
    objective: string;
    acceptance_criteria: string[];
    scope: unknown;
    constraints: unknown;
    relevant_paths: string[] | null;
    verification_commands: string[] | null;
    implementation_prompt: string;
    context_capsule: { completion_evidence?: string | null };
    phase: { id: number; title: string } | null;
    dependencies: { id: number; key: string; title: string; status: string }[];
    attempts: Attempt[];
    reviews: Review[];
    runs: Run[];
    operator_messages: OperatorMessage[];
    audit_events: AuditEvent[];
};
type Attempt = {
    id: number;
    number: number;
    status: string;
    base_sha: string | null;
    head_sha: string | null;
    commit_sha: string | null;
    validation_results: unknown;
    started_at: string | null;
    finished_at: string | null;
};
type Review = {
    id: number;
    status: string;
    summary: string | null;
    completed_at: string | null;
    attempt: { id: number; number: number; commit_sha: string | null } | null;
    findings: Finding[];
};
type Finding = {
    id: number;
    severity: string;
    location: string | null;
    current_implementation: string;
    expected_implementation: string;
    why_incorrect: string;
    required_fix: string;
    verification_requirement: string;
    implementation_fix_context: string;
};
type Run = {
    id: number;
    role: string;
    status: string;
    attempt_number: number | null;
    live_output: string | null;
    transcript: string | null;
    exit_code: number | null;
    started_at: string | null;
    finished_at: string | null;
    token_usage: unknown;
};
type OperatorMessage = {
    id: number;
    recipient_role: string;
    body: string;
    delivered_at: string | null;
    created_at: string;
    user: { id: number; name: string };
};
type AuditEvent = { id: number; event_type: string; occurred_at: string };

function DisplayList({ items }: { items: string[] | null }) {
    if (!items || items.length === 0) {
        return <p className="text-sm text-muted-foreground">None recorded.</p>;
    }

    return (
        <ul className="list-disc space-y-1 pl-5 text-sm">
            {items.map((item) => (
                <li key={item}>{item}</li>
            ))}
        </ul>
    );
}

function JsonDetail({ value }: { value: unknown }) {
    if (!value || (Array.isArray(value) && value.length === 0)) {
        return <p className="text-sm text-muted-foreground">None recorded.</p>;
    }

    return (
        <pre className="overflow-x-auto rounded-md bg-muted p-3 text-xs whitespace-pre-wrap">
            {JSON.stringify(value, null, 2)}
        </pre>
    );
}

type AgentOutputEntry = {
    isAgentMessage: boolean;
    label?: string;
    labelClassName?: string;
    message: string;
    className: string;
};

function formatAgentOutput(
    output: string | null,
    agentRole: string,
): AgentOutputEntry[] {
    if (!output) {
        return [
            {
                isAgentMessage: false,
                message: 'No output has reached AIOS yet.',
                className: 'text-zinc-400',
            },
        ];
    }

    return output
        .split(/\r?\n/)
        .map((line): AgentOutputEntry => {
            if (line.startsWith('[stderr] ')) {
                return {
                    isAgentMessage: false,
                    label: 'error>',
                    labelClassName: 'text-rose-400',
                    message: line.slice(9),
                    className: 'text-rose-300',
                };
            }

            try {
                const event = JSON.parse(line) as {
                    type?: string;
                    message?: string;
                    item?: {
                        type?: string;
                        text?: string;
                        command?: string;
                        aggregated_output?: string;
                    };
                };
                const item = event.item;

                if (item?.type === 'agent_message' && item.text) {
                    return {
                        isAgentMessage: true,
                        label: `${agentRole.replace('_', ' ')}>`,
                        labelClassName: 'text-emerald-400',
                        message: item.text,
                        className: 'text-emerald-200',
                    };
                }

                if (item?.type === 'reasoning' && item.text) {
                    return {
                        isAgentMessage: false,
                        label: 'thinking>',
                        labelClassName: 'text-sky-400',
                        message: item.text,
                        className: 'text-sky-200',
                    };
                }

                if (item?.type === 'command_execution') {
                    const command = item.command ? `$ ${item.command}` : '';
                    const result = item.aggregated_output
                        ? `\n${item.aggregated_output}`
                        : '';

                    return {
                        isAgentMessage: false,
                        message: `${command}${result}`.trim(),
                        className: 'text-amber-200',
                    };
                }

                if (event.type === 'error' && event.message) {
                    return {
                        isAgentMessage: false,
                        label: 'error>',
                        labelClassName: 'text-rose-400',
                        message: event.message,
                        className: 'text-rose-300',
                    };
                }
            } catch {
                return {
                    isAgentMessage: false,
                    message: line,
                    className: 'text-zinc-100',
                };
            }

            return {
                isAgentMessage: false,
                message: line,
                className: 'text-zinc-100',
            };
        })
        .filter((entry) => entry.message !== '');
}

function AgentConsoleOutput({
    output,
    agentRole,
    showTechnicalOutput,
}: {
    output: string | null;
    agentRole: string;
    showTechnicalOutput: boolean;
}) {
    const entries = formatAgentOutput(output, agentRole).filter(
        (entry) => showTechnicalOutput || entry.isAgentMessage,
    );

    return entries.map((entry, index) => (
        <span
            key={`${entry.label ?? 'output'}-${index}`}
            className={entry.className}
        >
            {entry.label && (
                <span className={`font-semibold ${entry.labelClassName}`}>
                    {entry.label}{' '}
                </span>
            )}
            {entry.message}
            {index < entries.length - 1 && '\n\n'}
        </span>
    ));
}

export default function TaskShow({
    project,
    task,
}: {
    project: Project;
    task: Task;
}) {
    usePoll(2_000, { only: ['task'] }, { mode: 'rest' });
    const consoleRef = useRef<HTMLPreElement>(null);
    const [showTechnicalOutput, setShowTechnicalOutput] = useState(false);

    const activeRun = task.runs.find((run) => run.status === 'running');
    const liveRun = activeRun?.live_output
        ? activeRun
        : (task.runs.find((run) => run.live_output || run.transcript) ??
          activeRun ??
          task.runs[0]);
    const isLiveOutput = liveRun?.id === activeRun?.id;
    const hasTechnicalOutput = liveRun
        ? formatAgentOutput(
              liveRun.live_output ?? liveRun.transcript,
              liveRun.role,
          ).some((entry) => !entry.isAgentMessage)
        : false;

    useEffect(() => {
        if (!isLiveOutput || !consoleRef.current) {
            return;
        }

        consoleRef.current.scrollTop = consoleRef.current.scrollHeight;
    }, [isLiveOutput, liveRun?.id, liveRun?.live_output]);

    return (
        <>
            <Head title={`${task.key}: ${task.title}`} />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-8">
                <div>
                    <Link
                        href={showProject(project.id).url}
                        className="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" /> {project.name}
                    </Link>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-sm text-muted-foreground">
                                {task.key}
                            </p>
                            <h1 className="text-2xl font-semibold">
                                {task.title}
                            </h1>
                            {task.phase && (
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Phase: {task.phase.title}
                                </p>
                            )}
                        </div>
                        <Badge
                            variant={
                                task.status === 'done' ? 'default' : 'secondary'
                            }
                        >
                            {task.status}
                        </Badge>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Agent console</CardTitle>
                        <CardDescription>
                            {isLiveOutput && liveRun
                                ? `Live ${liveRun.role.replace('_', ' ')} output. Refreshes every two seconds.`
                                : 'Latest captured agent output.'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {liveRun ? (
                            <div className="grid gap-3">
                                <div className="flex items-center justify-between gap-2">
                                    <Badge
                                        variant={
                                            liveRun.status === 'running'
                                                ? 'default'
                                                : 'outline'
                                        }
                                    >
                                        {liveRun.status}
                                    </Badge>
                                    <span className="text-xs text-muted-foreground">
                                        {liveRun.role.replace('_', ' ')}
                                    </span>
                                </div>
                                {hasTechnicalOutput && (
                                    <div>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                setShowTechnicalOutput(
                                                    (showing) => !showing,
                                                )
                                            }
                                        >
                                            {showTechnicalOutput
                                                ? 'Hide technical output'
                                                : 'Show technical output'}
                                        </Button>
                                    </div>
                                )}
                                <pre
                                    ref={consoleRef}
                                    className="max-h-[32rem] overflow-auto rounded-md bg-zinc-950 p-4 font-mono text-xs leading-5 whitespace-pre-wrap text-zinc-100"
                                >
                                    <AgentConsoleOutput
                                        output={
                                            liveRun.live_output ??
                                            liveRun.transcript
                                        }
                                        agentRole={liveRun.role}
                                        showTechnicalOutput={
                                            showTechnicalOutput
                                        }
                                    />
                                </pre>
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No agent run has started for this task.
                            </p>
                        )}
                    </CardContent>
                </Card>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="grid gap-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Task details</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-5">
                                <div>
                                    <h2 className="mb-2 font-medium">
                                        Objective
                                    </h2>
                                    <p className="text-sm leading-6 text-muted-foreground">
                                        {task.objective}
                                    </p>
                                </div>
                                <div>
                                    <h2 className="mb-2 font-medium">
                                        Acceptance criteria
                                    </h2>
                                    <DisplayList
                                        items={task.acceptance_criteria}
                                    />
                                </div>
                                {task.status === 'done' &&
                                    task.context_capsule
                                        .completion_evidence && (
                                        <div>
                                            <h2 className="mb-2 font-medium">
                                                Existing implementation evidence
                                            </h2>
                                            <p className="text-sm leading-6 text-muted-foreground">
                                                {
                                                    task.context_capsule
                                                        .completion_evidence
                                                }
                                            </p>
                                        </div>
                                    )}
                                <div>
                                    <h2 className="mb-2 font-medium">Scope</h2>
                                    <JsonDetail value={task.scope} />
                                </div>
                                <div>
                                    <h2 className="mb-2 font-medium">
                                        Constraints
                                    </h2>
                                    <JsonDetail value={task.constraints} />
                                </div>
                                <div>
                                    <h2 className="mb-2 font-medium">
                                        Relevant paths
                                    </h2>
                                    <DisplayList items={task.relevant_paths} />
                                </div>
                                <div>
                                    <h2 className="mb-2 font-medium">
                                        Verification commands
                                    </h2>
                                    <DisplayList
                                        items={task.verification_commands}
                                    />
                                </div>
                                <div>
                                    <h2 className="mb-2 font-medium">
                                        Dependencies
                                    </h2>
                                    {task.dependencies.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            No dependencies.
                                        </p>
                                    ) : (
                                        <div className="flex flex-wrap gap-2">
                                            {task.dependencies.map(
                                                (dependency) => (
                                                    <Badge
                                                        key={dependency.id}
                                                        variant="outline"
                                                    >
                                                        {dependency.key}:{' '}
                                                        {dependency.status}
                                                    </Badge>
                                                ),
                                            )}
                                        </div>
                                    )}
                                </div>
                                <div>
                                    <h2 className="mb-2 font-medium">
                                        Implementation prompt
                                    </h2>
                                    <pre className="overflow-x-auto rounded-md bg-muted p-3 text-xs whitespace-pre-wrap">
                                        {task.implementation_prompt}
                                    </pre>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Attempts and reviews</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-5">
                                {task.attempts.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        No implementation attempts yet.
                                    </p>
                                )}
                                {task.attempts.map((attempt) => (
                                    <div
                                        key={attempt.id}
                                        className="rounded-lg border p-3 text-sm"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <span className="font-medium">
                                                Attempt {attempt.number}
                                            </span>
                                            <Badge variant="outline">
                                                {attempt.status}
                                            </Badge>
                                        </div>
                                        <p className="mt-2 break-all text-muted-foreground">
                                            Base: {attempt.base_sha ?? '—'} ·
                                            Commit: {attempt.commit_sha ?? '—'}
                                        </p>
                                        <div className="mt-3">
                                            <span className="font-medium">
                                                Validation
                                            </span>
                                            <JsonDetail
                                                value={
                                                    attempt.validation_results
                                                }
                                            />
                                        </div>
                                    </div>
                                ))}
                                {task.reviews.map((review) => (
                                    <div
                                        key={review.id}
                                        className="rounded-lg border p-3 text-sm"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <span className="font-medium">
                                                Review for attempt{' '}
                                                {review.attempt?.number ?? '—'}
                                            </span>
                                            <Badge variant="outline">
                                                {review.status}
                                            </Badge>
                                        </div>
                                        {review.summary && (
                                            <p className="mt-2 text-muted-foreground">
                                                {review.summary}
                                            </p>
                                        )}
                                        {review.findings.map((finding) => (
                                            <div
                                                key={finding.id}
                                                className="mt-3 rounded-md bg-muted p-3"
                                            >
                                                <p className="font-medium">
                                                    {finding.severity}{' '}
                                                    {finding.location
                                                        ? `· ${finding.location}`
                                                        : ''}
                                                </p>
                                                <p className="mt-1 text-muted-foreground">
                                                    {finding.required_fix}
                                                </p>
                                                <p className="mt-2 text-xs">
                                                    Verify:{' '}
                                                    {
                                                        finding.verification_requirement
                                                    }
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    </div>

                    <div className="grid content-start gap-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Message an agent</CardTitle>
                                <CardDescription>
                                    Saved for the selected role’s next fresh
                                    execution. It cannot interrupt a currently
                                    running session.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Form
                                    {...storeOperatorMessage.form({
                                        project: project.id,
                                        task: task.id,
                                    })}
                                    className="grid gap-3"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <div className="grid gap-2">
                                                <Label htmlFor="recipient_role">
                                                    Recipient
                                                </Label>
                                                <select
                                                    id="recipient_role"
                                                    name="recipient_role"
                                                    defaultValue="coder"
                                                    className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                                >
                                                    <option value="coder">
                                                        Coder
                                                    </option>
                                                    <option value="reviewer">
                                                        Reviewer
                                                    </option>
                                                </select>
                                                <InputError
                                                    message={
                                                        errors.recipient_role
                                                    }
                                                />
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="body">
                                                    Instruction
                                                </Label>
                                                <textarea
                                                    id="body"
                                                    name="body"
                                                    required
                                                    maxLength={4000}
                                                    rows={6}
                                                    placeholder="Add context, a correction, or a question for the next agent run."
                                                    className="rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                                />
                                                <InputError
                                                    message={errors.body}
                                                />
                                            </div>
                                            <p className="text-xs text-muted-foreground">
                                                Do not paste credentials or
                                                secrets.
                                            </p>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                <Send /> Send instruction
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Agent instructions</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3">
                                {task.operator_messages.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        No instructions sent.
                                    </p>
                                )}
                                {task.operator_messages.map((message) => (
                                    <div
                                        key={message.id}
                                        className="rounded-lg border p-3 text-sm"
                                    >
                                        <div className="mb-2 flex items-center justify-between gap-2">
                                            <Badge variant="outline">
                                                {message.recipient_role}
                                            </Badge>
                                            <span className="text-xs text-muted-foreground">
                                                {message.delivered_at
                                                    ? 'delivered'
                                                    : 'pending'}
                                            </span>
                                        </div>
                                        <p className="whitespace-pre-wrap">
                                            {message.body}
                                        </p>
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            {message.user.name} ·{' '}
                                            {new Date(
                                                message.created_at,
                                            ).toLocaleString()}
                                        </p>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Execution history</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3 text-sm">
                                {task.runs.length === 0 && (
                                    <p className="text-muted-foreground">
                                        No agent runs recorded.
                                    </p>
                                )}
                                {task.runs.map((run) => (
                                    <div
                                        key={run.id}
                                        className="rounded-lg border p-3"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="capitalize">
                                                {run.role.replace('_', ' ')}
                                            </span>
                                            <Badge variant="outline">
                                                {run.status}
                                            </Badge>
                                        </div>
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            Attempt {run.attempt_number ?? '—'}{' '}
                                            · Exit {run.exit_code ?? '—'}
                                        </p>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Task activity</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-2 text-sm">
                                {task.audit_events.map((event) => (
                                    <div
                                        key={event.id}
                                        className="flex justify-between gap-3"
                                    >
                                        <span>{event.event_type}</span>
                                        <time className="text-right text-xs text-muted-foreground">
                                            {new Date(
                                                event.occurred_at,
                                            ).toLocaleString()}
                                        </time>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </>
    );
}
