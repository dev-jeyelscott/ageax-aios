import { Form, Head, Link, usePoll } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowLeft,
    Ban,
    CheckCircle2,
    GitBranch,
    Loader2,
    Play,
    ShieldAlert,
    TriangleAlert,
} from 'lucide-react';
import type { ReactNode } from 'react';
import {
    index as agentsIndex,
    invoke as invokeAgent,
    showRun,
    update as updateAgent,
} from '@/actions/App/Http/Controllers/GlobalAgentController';
import { AgentFields } from '@/components/agent-fields';
import type { HarnessCapabilities } from '@/components/agent-fields';
import { AppBackground } from '@/components/app-background';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

type Agent = {
    id: number;
    name: string;
    role: string;
    harness: string;
    model: string | null;
    reasoning_setting: string | null;
    default_context: string | null;
    enabled: boolean;
    configuration_version: number;
    invoke_in_progress: boolean;
};

type RuntimeStackFrame = {
    file: string;
    line?: number;
    class?: string;
    function?: string;
};

type RecoveryRun = {
    id: number;
    status: string;
    attempt_number: number | null;
    started_at: string;
    finished_at: string | null;
    exit_code: number | null;
    viewable_in_agent_console: boolean;
};

type Incident = {
    id: number;
    is_runtime: boolean;
    failure_type: string;
    status: string;
    operator_outcome: 'automatic' | 'blocked' | 'escalated' | 'resolved';
    root_cause_category: string | null;
    root_cause: string | null;
    recoverable: boolean | null;
    fingerprint: string | null;
    source: string | null;
    exception_class: string | null;
    occurrence_count: number;
    first_seen_at: string | null;
    last_seen_at: string | null;
    detected_at: string;
    resolved_at: string | null;
    attempt_count: number;
    fix_summary: string | null;
    escalation_reason: string | null;
    resulting_task_transition: string | null;
    project: { id: number; name: string } | null;
    task: { key: string; title: string; project_id: number } | null;
    evidence: { message: string | null; stack: RuntimeStackFrame[] } | null;
    recovery_runs: RecoveryRun[];
    git: {
        base_sha: string | null;
        head_sha: string | null;
        commit_sha: string | null;
        changed_files: string[];
    } | null;
    validation: {
        passed: boolean | null;
        checks: { name: string; passed: boolean }[];
    } | null;
    circuit_breaker: {
        state: 'opened' | 'closed' | 'not_applicable';
        failure_fingerprint: string | null;
        consecutive_repeat_count: number | null;
        threshold: number | null;
        attempt_count: number | null;
        occurred_at: string | null;
    };
    blocking: {
        state: 'blocked' | 'clear';
        event_type: string | null;
        reason: string | null;
        occurred_at: string | null;
    };
};

type IncidentPage = {
    data: Incident[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

/** Convert a persisted identifier into operator-readable text. */
function humanize(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replaceAll('.', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

/** Format one persisted timestamp using the operator's browser locale. */
function formatDateTime(value: string | null): string {
    if (!value) {
        return 'Not recorded';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

/** Render a compact evidence value with an explicit label. */
function EvidenceValue({
    label,
    value,
    mono = false,
}: {
    label: string;
    value: ReactNode;
    mono?: boolean;
}) {
    return (
        <div className="tile-inset min-w-0 p-2.5">
            <dt className="font-mono text-2xs tracking-[0.1em] text-muted-foreground uppercase">
                {label}
            </dt>
            <dd
                className={`mt-1 min-w-0 text-xs text-foreground ${
                    mono ? 'font-mono break-all' : ''
                }`}
            >
                {value}
            </dd>
        </div>
    );
}

/** Render one workflow or runtime incident from sanitized durable AIOS evidence. */
function RecoveryIncidentRow({
    incident,
    agentId,
}: {
    incident: Incident;
    agentId: number;
}) {
    const outcomeClassName =
        incident.operator_outcome === 'resolved'
            ? 'border-success/25 bg-success/10 text-success'
            : incident.operator_outcome === 'blocked'
              ? 'border-destructive/25 bg-destructive/10 text-destructive'
              : incident.operator_outcome === 'escalated'
                ? 'border-warning/25 bg-warning/10 text-warning'
                : 'border-primary/25 bg-primary/10 text-primary';

    return (
        <details className="group tile-inset overflow-hidden">
            <summary className="flex cursor-pointer list-none flex-col gap-3 p-4 marker:hidden lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-mono text-2xs tracking-[0.12em] text-primary uppercase">
                            {incident.is_runtime ? 'Runtime' : 'Workflow'}
                        </span>
                        <Badge variant="outline">
                            {humanize(incident.failure_type)}
                        </Badge>
                        {incident.root_cause_category && (
                            <Badge variant="outline">
                                {humanize(incident.root_cause_category)}
                            </Badge>
                        )}
                    </div>

                    <p className="mt-2 truncate text-sm font-semibold text-foreground">
                        {incident.project?.name ?? 'AIOS system scope'}
                        {incident.task
                            ? ` · ${incident.task.key}: ${incident.task.title}`
                            : ''}
                    </p>

                    <p className="mt-1 font-mono text-2xs text-muted-foreground">
                        #{incident.id} · {formatDateTime(incident.detected_at)}
                        {incident.source ? ` · ${incident.source}` : ''}
                    </p>
                </div>

                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    <Badge variant="outline" className={outcomeClassName}>
                        {incident.operator_outcome === 'automatic'
                            ? 'Automatic / in progress'
                            : humanize(incident.operator_outcome)}
                    </Badge>

                    <Badge variant="outline">{humanize(incident.status)}</Badge>

                    <span className="font-mono text-2xs text-muted-foreground group-open:hidden">
                        Expand evidence
                    </span>

                    <span className="hidden font-mono text-2xs text-muted-foreground group-open:inline">
                        Collapse evidence
                    </span>
                </div>
            </summary>

            <div className="border-t border-border-subtle p-4">
                <dl className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <EvidenceValue
                        label="First occurrence"
                        value={formatDateTime(incident.first_seen_at)}
                    />
                    <EvidenceValue
                        label="Last occurrence"
                        value={formatDateTime(incident.last_seen_at)}
                    />
                    <EvidenceValue
                        label="Occurrences"
                        value={
                            incident.is_runtime
                                ? incident.occurrence_count
                                : 'N/A'
                        }
                    />
                    <EvidenceValue
                        label="Attempts"
                        value={incident.attempt_count}
                    />
                    <EvidenceValue
                        label="Exception class"
                        value={incident.exception_class ?? 'Not recorded'}
                        mono
                    />
                    <EvidenceValue
                        label="Recoverable"
                        value={
                            incident.recoverable === null
                                ? 'Not classified'
                                : incident.recoverable
                                  ? 'Yes'
                                  : 'No'
                        }
                    />
                    <EvidenceValue
                        label="Resolved"
                        value={formatDateTime(incident.resolved_at)}
                    />
                    <EvidenceValue
                        label="Circuit breaker"
                        value={humanize(incident.circuit_breaker.state)}
                    />
                </dl>

                {incident.fingerprint && (
                    <div className="panel-recessed mt-3 p-3">
                        <p className="font-mono text-2xs tracking-[0.1em] text-muted-foreground uppercase">
                            Fingerprint
                        </p>
                        <code className="mt-1 block text-2xs break-all text-foreground">
                            {incident.fingerprint}
                        </code>
                    </div>
                )}

                <div className="mt-3 grid gap-3 xl:grid-cols-2">
                    <section className="panel-recessed p-3">
                        <h3 className="text-xs font-semibold text-foreground">
                            Diagnosis
                        </h3>

                        <p className="mt-2 text-xs leading-relaxed text-muted-foreground">
                            {incident.root_cause ??
                                'No diagnosis recorded yet.'}
                        </p>

                        {incident.evidence?.message && (
                            <div className="mt-3">
                                <p className="font-mono text-2xs text-muted-foreground uppercase">
                                    Sanitized message
                                </p>
                                <p className="mt-1 text-xs break-words text-foreground">
                                    {incident.evidence.message}
                                </p>
                            </div>
                        )}

                        {incident.evidence &&
                            incident.evidence.stack.length > 0 && (
                                <div className="mt-3">
                                    <p className="font-mono text-2xs text-muted-foreground uppercase">
                                        Bounded application stack
                                    </p>

                                    <div className="mt-2 grid gap-1">
                                        {incident.evidence.stack.map(
                                            (frame, index) => (
                                                <code
                                                    key={`${frame.file}:${frame.line ?? 0}:${index}`}
                                                    className="block rounded-md border border-border-subtle bg-background/30 px-2 py-1.5 text-2xs break-all text-muted-foreground"
                                                >
                                                    {frame.file}
                                                    {frame.line
                                                        ? `:${frame.line}`
                                                        : ''}
                                                    {frame.class ||
                                                    frame.function
                                                        ? ` · ${[
                                                              frame.class,
                                                              frame.function,
                                                          ]
                                                              .filter(Boolean)
                                                              .join('::')}`
                                                        : ''}
                                                </code>
                                            ),
                                        )}
                                    </div>
                                </div>
                            )}

                        {incident.fix_summary && (
                            <p className="mt-3 text-xs leading-relaxed text-success-foreground">
                                <span className="font-semibold">Fix:</span>{' '}
                                {incident.fix_summary}
                            </p>
                        )}

                        {incident.resulting_task_transition && (
                            <p className="mt-2 font-mono text-2xs text-muted-foreground">
                                Transition: {incident.resulting_task_transition}
                            </p>
                        )}
                    </section>

                    <section className="panel-recessed p-3">
                        <h3 className="text-xs font-semibold text-foreground">
                            Recovery attempts
                        </h3>

                        {incident.recovery_runs.length === 0 ? (
                            <p className="mt-2 text-xs leading-relaxed text-muted-foreground">
                                No Recovery Engineer AgentRun is attached. The
                                incident may have been handled deterministically
                                or escalated before LLM execution.
                            </p>
                        ) : (
                            <div className="mt-2 grid gap-2">
                                {incident.recovery_runs.map((run) => (
                                    <div
                                        key={run.id}
                                        className="rounded-md border border-border-subtle bg-background/30 p-2.5"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <span className="font-mono text-2xs text-foreground">
                                                AgentRun #{run.id}
                                                {run.attempt_number !== null
                                                    ? ` · attempt ${run.attempt_number}`
                                                    : ''}
                                            </span>

                                            <Badge variant="outline">
                                                {humanize(run.status)}
                                            </Badge>
                                        </div>

                                        <p className="mt-1 text-2xs text-muted-foreground">
                                            {formatDateTime(run.started_at)}
                                            {run.exit_code !== null
                                                ? ` · exit ${run.exit_code}`
                                                : ''}
                                        </p>

                                        {run.viewable_in_agent_console ? (
                                            <Link
                                                href={
                                                    showRun([agentId, run.id])
                                                        .url
                                                }
                                                className="mt-1 inline-block text-2xs text-primary hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                            >
                                                View AgentRun evidence
                                            </Link>
                                        ) : (
                                            <p className="mt-1 text-2xs text-muted-foreground">
                                                Legacy run, not attributable to
                                                this current global Agent
                                                identity.
                                            </p>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}
                    </section>
                </div>

                <div className="mt-3 grid gap-3 xl:grid-cols-2">
                    <section className="panel-recessed p-3">
                        <div className="flex items-center gap-2">
                            <GitBranch
                                className="size-3.5 text-primary"
                                aria-hidden="true"
                            />
                            <h3 className="text-xs font-semibold text-foreground">
                                Git and validation
                            </h3>
                        </div>

                        {incident.git === null &&
                        incident.validation === null ? (
                            <p className="mt-2 text-xs text-muted-foreground">
                                No Git repair or validation evidence was
                                persisted.
                            </p>
                        ) : (
                            <div className="mt-2 grid gap-2">
                                {incident.git?.base_sha && (
                                    <EvidenceValue
                                        label="Base SHA"
                                        value={incident.git.base_sha}
                                        mono
                                    />
                                )}
                                {incident.git?.head_sha && (
                                    <EvidenceValue
                                        label="Head SHA"
                                        value={incident.git.head_sha}
                                        mono
                                    />
                                )}
                                {incident.git?.commit_sha && (
                                    <EvidenceValue
                                        label="Fix commit"
                                        value={incident.git.commit_sha}
                                        mono
                                    />
                                )}

                                {incident.git &&
                                    incident.git.changed_files.length > 0 && (
                                        <div>
                                            <p className="font-mono text-2xs text-muted-foreground uppercase">
                                                Changed files
                                            </p>
                                            <div className="mt-1 grid gap-1">
                                                {incident.git.changed_files.map(
                                                    (file) => (
                                                        <code
                                                            key={file}
                                                            className="block text-2xs break-all text-foreground"
                                                        >
                                                            {file}
                                                        </code>
                                                    ),
                                                )}
                                            </div>
                                        </div>
                                    )}

                                {incident.validation && (
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-mono text-2xs text-muted-foreground uppercase">
                                                Validation
                                            </span>

                                            <Badge
                                                variant="outline"
                                                className={
                                                    incident.validation
                                                        .passed === true
                                                        ? 'border-success/25 bg-success/10 text-success'
                                                        : incident.validation
                                                                .passed ===
                                                            false
                                                          ? 'border-destructive/25 bg-destructive/10 text-destructive'
                                                          : undefined
                                                }
                                            >
                                                {incident.validation.passed ===
                                                null
                                                    ? 'Partial evidence'
                                                    : incident.validation.passed
                                                      ? 'Passed'
                                                      : 'Failed'}
                                            </Badge>
                                        </div>

                                        <div className="mt-2 flex flex-wrap gap-1.5">
                                            {incident.validation.checks.map(
                                                (check) => (
                                                    <span
                                                        key={check.name}
                                                        className={`inline-flex items-center gap-1 rounded-md border px-2 py-1 font-mono text-2xs ${
                                                            check.passed
                                                                ? 'border-success/20 bg-success/5 text-success-foreground'
                                                                : 'border-destructive/20 bg-destructive/5 text-destructive-foreground'
                                                        }`}
                                                    >
                                                        {check.passed ? (
                                                            <CheckCircle2
                                                                className="size-3"
                                                                aria-hidden="true"
                                                            />
                                                        ) : (
                                                            <TriangleAlert
                                                                className="size-3"
                                                                aria-hidden="true"
                                                            />
                                                        )}
                                                        {humanize(check.name)}
                                                    </span>
                                                ),
                                            )}
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}
                    </section>

                    <section className="panel-recessed p-3">
                        <h3 className="text-xs font-semibold text-foreground">
                            Circuit breaker and operator escalation
                        </h3>

                        <div className="mt-2 flex flex-wrap gap-2">
                            <Badge
                                variant="outline"
                                className={
                                    incident.circuit_breaker.state === 'opened'
                                        ? 'border-destructive/25 bg-destructive/10 text-destructive'
                                        : incident.circuit_breaker.state ===
                                            'closed'
                                          ? 'border-success/25 bg-success/10 text-success'
                                          : undefined
                                }
                            >
                                Circuit:{' '}
                                {humanize(incident.circuit_breaker.state)}
                            </Badge>

                            {incident.circuit_breaker.state === 'opened' && (
                                <Badge variant="outline">
                                    {incident.circuit_breaker
                                        .consecutive_repeat_count ?? '?'}{' '}
                                    /{' '}
                                    {incident.circuit_breaker.threshold ?? '?'}{' '}
                                    repeats
                                </Badge>
                            )}
                        </div>

                        {incident.circuit_breaker.failure_fingerprint && (
                            <code className="mt-2 block text-2xs break-all text-muted-foreground">
                                No-progress:{' '}
                                {incident.circuit_breaker.failure_fingerprint}
                            </code>
                        )}

                        {incident.blocking.reason && (
                            <div className="mt-2 rounded-md border border-destructive/20 bg-destructive/5 p-2.5">
                                <div className="flex items-start gap-2">
                                    <AlertTriangle
                                        className="mt-0.5 size-3.5 shrink-0 text-destructive"
                                        aria-hidden="true"
                                    />
                                    <div>
                                        <p className="text-2xs font-medium text-destructive">
                                            Automatic recovery blocked
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {incident.blocking.reason}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}

                        {incident.escalation_reason && (
                            <div className="mt-2 rounded-md border border-warning/20 bg-warning/5 p-2.5">
                                <p className="font-mono text-2xs text-warning uppercase">
                                    Operator escalation
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {incident.escalation_reason}
                                </p>
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </details>
    );
}

/** Render the shared global Agent configuration and recovery command center. */
export default function AgentShow({
    agent,
    incidents,
    harness_capabilities: harnessCapabilities,
}: {
    agent: Agent;
    incidents: IncidentPage;
    harness_capabilities: HarnessCapabilities;
}) {
    const isRecoveryEngineer = agent.role === 'recovery_engineer';

    usePoll(
        3_000,
        {
            only: isRecoveryEngineer ? ['agent', 'incidents'] : ['agent'],
            preserveErrors: true,
        },
        { mode: 'rest' },
    );

    return (
        <>
            <Head title={agent.name} />

            <div className="relative min-h-full overflow-hidden">
                <AppBackground contained />

                <div className="relative z-10 mx-auto flex w-full max-w-7xl flex-col gap-5 p-4 sm:p-6 lg:p-8">
                    <header className="panel-elevated relative overflow-hidden p-5">
                        <div className="glow-line-accent" />

                        <Link
                            href={agentsIndex().url}
                            className="mb-3 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <ArrowLeft className="size-4" aria-hidden="true" />
                            Agents
                        </Link>

                        <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <h1 className="text-2xl font-semibold tracking-tight">
                                        {agent.name}
                                    </h1>
                                    <Badge variant="outline">
                                        {agent.enabled ? 'Enabled' : 'Disabled'}
                                    </Badge>
                                    <Badge variant="outline">
                                        {humanize(agent.role)} · v
                                        {agent.configuration_version}
                                    </Badge>
                                </div>

                                <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                                    Laravel/AIOS remains authoritative over
                                    recovery state, validation, Git, audit
                                    evidence, and operator escalation.
                                </p>
                            </div>

                            {isRecoveryEngineer && (
                                <div className="flex items-center gap-2">
                                    <Badge
                                        variant="outline"
                                        className="border-primary/25 bg-primary/10 text-primary"
                                    >
                                        <Activity
                                            className="mr-1 size-3"
                                            aria-hidden="true"
                                        />
                                        {incidents.total} incidents
                                    </Badge>
                                    <InvokeNowForm agent={agent} />
                                </div>
                            )}
                        </div>
                    </header>

                    <Card className="border-border/70 bg-card/70 shadow-panel">
                        <CardHeader className="flex flex-row items-center justify-between gap-3">
                            <div>
                                <CardTitle>Configuration</CardTitle>
                                <CardDescription>
                                    Changes apply only to future executions.
                                </CardDescription>
                            </div>
                            <ToggleEnabledForm agent={agent} />
                        </CardHeader>

                        <CardContent>
                            <Form
                                {...updateAgent.form(agent)}
                                className="grid gap-3"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <AgentFields
                                            harnessCapabilities={
                                                harnessCapabilities
                                            }
                                            initial={agent}
                                            errors={errors}
                                            roleField={{
                                                editable: false,
                                                label: humanize(agent.role),
                                            }}
                                        />
                                        <div>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                Save changes
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>

                    {isRecoveryEngineer && (
                        <section className="panel-elevated relative overflow-hidden">
                            <div className="glow-line-accent" />

                            <div className="border-b border-border-subtle p-4 sm:p-5">
                                <div className="flex items-center gap-2">
                                    <ShieldAlert
                                        className="size-4 text-primary"
                                        aria-hidden="true"
                                    />
                                    <h2 className="text-base font-semibold">
                                        Recovery command center
                                    </h2>
                                </div>
                                <p className="mt-1 max-w-3xl text-xs text-muted-foreground">
                                    Workflow and runtime incidents with
                                    sanitized diagnosis, AgentRun, Git,
                                    validation, circuit-breaker, and escalation
                                    evidence.
                                </p>
                            </div>

                            <div className="grid gap-2 p-4 sm:p-5">
                                {incidents.data.length === 0 ? (
                                    <div className="tile-inset flex items-center gap-3 p-5">
                                        <CheckCircle2
                                            className="size-5 text-success"
                                            aria-hidden="true"
                                        />
                                        <p className="text-sm text-muted-foreground">
                                            No recovery incidents recorded yet.
                                        </p>
                                    </div>
                                ) : (
                                    incidents.data.map((incident) => (
                                        <RecoveryIncidentRow
                                            key={incident.id}
                                            incident={incident}
                                            agentId={agent.id}
                                        />
                                    ))
                                )}
                            </div>

                            {(incidents.prev_page_url ||
                                incidents.next_page_url) && (
                                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border-subtle px-4 py-3 sm:px-5">
                                    <p className="font-mono text-2xs text-muted-foreground">
                                        Page {incidents.current_page} of{' '}
                                        {incidents.last_page}.{' '}
                                        {incidents.from ?? 0}-
                                        {incidents.to ?? 0} of {incidents.total}
                                        .
                                    </p>

                                    <div className="flex gap-2">
                                        {incidents.prev_page_url ? (
                                            <Button
                                                asChild
                                                size="sm"
                                                variant="outline"
                                            >
                                                <Link
                                                    href={
                                                        incidents.prev_page_url
                                                    }
                                                >
                                                    Newer
                                                </Link>
                                            </Button>
                                        ) : (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                disabled
                                            >
                                                Newer
                                            </Button>
                                        )}

                                        {incidents.next_page_url ? (
                                            <Button
                                                asChild
                                                size="sm"
                                                variant="outline"
                                            >
                                                <Link
                                                    href={
                                                        incidents.next_page_url
                                                    }
                                                >
                                                    Older
                                                </Link>
                                            </Button>
                                        ) : (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                disabled
                                            >
                                                Older
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            )}
                        </section>
                    )}
                </div>
            </div>
        </>
    );
}

/** Submit the existing recovery-only manual invocation action. */
function InvokeNowForm({ agent }: { agent: Agent }) {
    return (
        <Form {...invokeAgent.form(agent)} className="inline">
            {({ processing }) => {
                const busy = processing || agent.invoke_in_progress;

                return (
                    <Button
                        type="submit"
                        size="sm"
                        variant="outline"
                        disabled={busy || !agent.enabled}
                    >
                        {busy ? <Loader2 className="animate-spin" /> : <Play />}
                        {processing
                            ? 'Invoking…'
                            : agent.invoke_in_progress
                              ? 'Working…'
                              : 'Invoke now'}
                    </Button>
                );
            }}
        </Form>
    );
}

/** Enable or disable an approved global Agent through the existing update route. */
function ToggleEnabledForm({ agent }: { agent: Agent }) {
    return (
        <Form {...updateAgent.form(agent)} className="inline">
            {({ errors, processing }) => (
                <>
                    <input type="hidden" name="name" value={agent.name} />
                    <input type="hidden" name="harness" value={agent.harness} />
                    <input
                        type="hidden"
                        name="model"
                        value={agent.model ?? ''}
                    />
                    <input
                        type="hidden"
                        name="reasoning_setting"
                        value={agent.reasoning_setting ?? ''}
                    />
                    <input
                        type="hidden"
                        name="default_context"
                        value={agent.default_context ?? ''}
                    />
                    <input
                        type="hidden"
                        name="enabled"
                        value={agent.enabled ? '0' : '1'}
                    />

                    <Button
                        type="submit"
                        size="sm"
                        variant="outline"
                        disabled={processing}
                        title={errors.enabled ?? undefined}
                    >
                        {agent.enabled ? <Ban /> : <CheckCircle2 />}
                        {agent.enabled ? 'Disable' : 'Enable'}
                    </Button>
                </>
            )}
        </Form>
    );
}
