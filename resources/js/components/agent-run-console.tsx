import {
    Activity,
    Bot,
    Clock3,
    Cpu,
    FileCode2,
    Gauge,
    HeartPulse,
    Radio,
    RotateCcw,
    ShieldCheck,
    Terminal,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useRef } from 'react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

export type ConfigurationSnapshotAgent = {
    id: number;
    name: string;
    role: string;
    harness: string;
    model: string | null;
    reasoning_setting: string | null;
    configuration_version: number;
};

export type ConfigurationSnapshotSkill = {
    id: number;
    slug: string;
    name: string;
    version: number;
    position: number;
};

export type ConfigurationSnapshot = {
    context_schema_version: number;
    context_hash: string;
    agent: ConfigurationSnapshotAgent;
    skills: ConfigurationSnapshotSkill[];
};

export type ContextCostMeasurement = {
    characters: number;
    estimated_tokens: number;
};

export type ContextCostSkill = ContextCostMeasurement & {
    slug: string | null;
    position: number | null;
};

export type ContextCostEstimate = {
    schema_version: number;
    characters_per_token_ratio: number;
    system_rules: ContextCostMeasurement;
    agent_default_context: ContextCostMeasurement;
    skills: ContextCostSkill[];
    skills_total: ContextCostMeasurement;
    task_core: ContextCostMeasurement;
    obsidian_context: ContextCostMeasurement;
    retry_recovery_evidence: ContextCostMeasurement;
    review_evidence: ContextCostMeasurement;
    total: ContextCostMeasurement;
    disproportionate_sections: string[];
};

export type AgentRunCommand = {
    command: string;
    exit_code: number | null;
};

export type AgentRunFileModification = {
    path: string;
    kind: string;
};

export type AgentRun = {
    id: number;
    role: string;
    status: string;
    attempt_number: number | null;
    agent_messages: string[];
    commands: AgentRunCommand[] | null;
    file_modifications: AgentRunFileModification[] | null;
    exit_code: number | null;
    token_usage: number | null;
    started_at: string | null;
    finished_at: string | null;
    harness: string | null;
    external_run_id: string | null;
    context_schema_version: number | null;
    configuration_snapshot: ConfigurationSnapshot | null;
    context_cost_schema_version: number | null;
    context_cost_estimate: ContextCostEstimate | null;
    task: { key: string; title: string } | null;
    worker: {
        role: string;
        status: string;
        last_heartbeat_at: string | null;
    } | null;
};

type ContextCostSectionKey =
    | 'system_rules'
    | 'agent_default_context'
    | 'skills_total'
    | 'task_core'
    | 'obsidian_context'
    | 'retry_recovery_evidence'
    | 'review_evidence';

const CONTEXT_COST_SECTIONS: {
    key: ContextCostSectionKey;
    label: string;
}[] = [
    { key: 'system_rules', label: 'AIOS system rules' },
    { key: 'agent_default_context', label: 'Agent default context' },
    { key: 'skills_total', label: 'Skills (total)' },
    { key: 'task_core', label: 'Task / acceptance context' },
    { key: 'obsidian_context', label: 'Obsidian project context' },
    { key: 'retry_recovery_evidence', label: 'Retry / recovery evidence' },
    { key: 'review_evidence', label: 'Review evidence' },
];

function humanize(value: string): string {
    return value.replaceAll('_', ' ');
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
            return humanize(harness);
    }
}

function formatDateTime(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

function formatDuration(agentRun: AgentRun): string {
    if (!agentRun.started_at) {
        return '—';
    }

    const startedAt = new Date(agentRun.started_at).getTime();
    const finishedAt = agentRun.finished_at
        ? new Date(agentRun.finished_at).getTime()
        : Date.now();

    if (Number.isNaN(startedAt) || Number.isNaN(finishedAt)) {
        return '—';
    }

    const totalSeconds = Math.max(
        0,
        Math.floor((finishedAt - startedAt) / 1000),
    );
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    }

    if (minutes > 0) {
        return `${minutes}m ${seconds}s`;
    }

    return `${seconds}s`;
}

function statusBadgeClasses(status: string): string {
    if (status === 'running') {
        return 'border-primary/30 bg-primary/10 text-primary';
    }

    if (status === 'completed') {
        return 'border-success/30 bg-success/10 text-success-foreground';
    }

    if (status === 'failed') {
        return 'border-destructive/30 bg-destructive/10 text-destructive-foreground';
    }

    return 'border-border bg-card text-muted-foreground';
}

export function isAgentRunLive(agentRun: AgentRun): boolean {
    return agentRun.status === 'running';
}

/** Auto-scrolls a console container to the bottom while a run is still live. */
export function useAutoScrollConsole(
    live: boolean,
    dependency: unknown,
): React.RefObject<HTMLDivElement | null> {
    const consoleRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (live && consoleRef.current) {
            consoleRef.current.scrollTop = consoleRef.current.scrollHeight;
        }
    }, [dependency, live]);

    return consoleRef;
}

export function RunSummaryMetrics({ agentRun }: { agentRun: AgentRun }) {
    const metrics = [
        {
            label: 'Attempt',
            value: agentRun.attempt_number?.toString() ?? '—',
            icon: <RotateCcw className="size-4" />,
        },
        {
            label: 'Duration',
            value: formatDuration(agentRun),
            icon: <Clock3 className="size-4" />,
        },
        {
            label: 'Token usage',
            value: agentRun.token_usage?.toLocaleString() ?? 'Unavailable',
            icon: <Gauge className="size-4" />,
        },
        {
            label: 'Files changed',
            value: (agentRun.file_modifications?.length ?? 0).toString(),
            icon: <FileCode2 className="size-4" />,
        },
        {
            label: 'Harness',
            value: harnessLabel(agentRun.harness),
            icon: <Cpu className="size-4" />,
        },
        {
            label: 'Worker state',
            value: agentRun.worker?.status
                ? humanize(agentRun.worker.status)
                : 'Not recorded',
            icon: <Radio className="size-4" />,
        },
    ];

    return (
        <section className="panel-elevated relative overflow-hidden">
            <div className="glow-line-accent" />
            <div className="grid grid-cols-2 divide-x divide-y divide-border-subtle sm:grid-cols-3 xl:grid-cols-6 xl:divide-y-0">
                {metrics.map((metric) => (
                    <div
                        key={metric.label}
                        className="flex min-w-0 items-center gap-3 px-3 py-3.5"
                    >
                        <div className="grid size-8 shrink-0 place-items-center rounded-lg border border-primary/15 bg-primary/5 text-primary">
                            {metric.icon}
                        </div>
                        <div className="min-w-0">
                            <p className="font-mono text-2xs tracking-[0.08em] text-muted-foreground uppercase">
                                {metric.label}
                            </p>
                            <p
                                className="mt-0.5 truncate text-sm font-semibold text-foreground capitalize"
                                title={metric.value}
                            >
                                {metric.value}
                            </p>
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}

export function AgentMessagesCard({
    agentRun,
    live,
    consoleRef,
}: {
    agentRun: AgentRun;
    live: boolean;
    consoleRef: React.RefObject<HTMLDivElement | null>;
}) {
    return (
        <Card className="overflow-hidden">
            <CardHeader className="flex-row items-start justify-between gap-3">
                <div>
                    <CardTitle className="flex items-center gap-2">
                        <Bot className="size-4 text-primary" />
                        Agent messages
                    </CardTitle>
                    <CardDescription className="mt-1">
                        {live
                            ? 'Live execution output, refreshed every two seconds.'
                            : 'Persisted agent execution messages.'}
                    </CardDescription>
                </div>

                <Badge
                    variant="outline"
                    className={
                        live
                            ? 'status-glow-pulse border-primary/30 bg-primary/10 font-mono text-2xs text-primary'
                            : 'border-border bg-card font-mono text-2xs text-muted-foreground'
                    }
                >
                    {live ? 'Live stream' : 'Captured'}
                </Badge>
            </CardHeader>

            <CardContent
                ref={consoleRef}
                aria-live={live ? 'polite' : undefined}
                className="max-h-[34rem] overflow-auto"
            >
                <div className="grid gap-2">
                    {agentRun.agent_messages.map((message, index) => (
                        <article
                            key={`${index}-${message}`}
                            className="group grid grid-cols-[2.25rem_minmax(0,1fr)] gap-2 rounded-lg border border-border-subtle bg-foreground/[0.025] p-2 transition hover:border-primary/15 hover:bg-primary/[0.035]"
                        >
                            <div className="grid size-8 place-items-center rounded-md border border-primary/15 bg-primary/5 font-mono text-2xs text-primary">
                                {String(index + 1).padStart(2, '0')}
                            </div>
                            <p className="min-w-0 self-center text-sm leading-6 whitespace-pre-wrap text-foreground/90">
                                {message}
                            </p>
                        </article>
                    ))}

                    {agentRun.agent_messages.length === 0 && (
                        <div className="rounded-lg border border-dashed border-border p-6 text-center">
                            <Activity className="mx-auto size-5 text-muted-foreground" />
                            <p className="mt-2 text-sm text-muted-foreground">
                                {live
                                    ? 'Waiting for the agent’s first update.'
                                    : 'This run did not produce an agent message.'}
                            </p>
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

export function ExecutionDetailsCard({ agentRun }: { agentRun: AgentRun }) {
    const details = [
        ['Attempt', agentRun.attempt_number ?? '—'],
        [
            'Exit code',
            agentRun.exit_code === null
                ? agentRun.status === 'running'
                    ? 'Running'
                    : 'Not recorded'
                : agentRun.exit_code,
        ],
        ['Status', humanize(agentRun.status)],
        [
            'Token usage',
            agentRun.token_usage?.toLocaleString() ?? 'Unavailable',
        ],
        ['Worker', agentRun.worker?.status ?? 'Not recorded'],
        ['Commands', agentRun.commands?.length ?? 0],
        ['File changes', agentRun.file_modifications?.length ?? 0],
    ];

    return (
        <Card className="h-full">
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Terminal className="size-4 text-primary" />
                    Execution details
                </CardTitle>
                <CardDescription>
                    Persisted runtime evidence for this execution.
                </CardDescription>
            </CardHeader>

            <CardContent className="grid gap-3">
                <dl className="grid gap-2 sm:grid-cols-2">
                    {details.map(([label, value]) => (
                        <div
                            key={label}
                            className="tile-inset min-w-0 px-2.5 py-2"
                        >
                            <dt className="font-mono text-2xs tracking-[0.08em] text-muted-foreground uppercase">
                                {label}
                            </dt>
                            <dd className="mt-1 truncate text-sm font-medium text-foreground capitalize">
                                {value}
                            </dd>
                        </div>
                    ))}
                </dl>

                <div className="grid gap-2 border-t border-border-subtle pt-3 text-xs">
                    <div className="flex items-start justify-between gap-4">
                        <span className="text-muted-foreground">Started</span>
                        <span className="text-right font-mono text-2xs text-foreground">
                            {formatDateTime(agentRun.started_at)}
                        </span>
                    </div>
                    <div className="flex items-start justify-between gap-4">
                        <span className="text-muted-foreground">Finished</span>
                        <span className="text-right font-mono text-2xs text-foreground">
                            {formatDateTime(agentRun.finished_at)}
                        </span>
                    </div>
                    <div className="flex items-start justify-between gap-4">
                        <span className="text-muted-foreground">
                            Last heartbeat
                        </span>
                        <span className="text-right font-mono text-2xs text-foreground">
                            {formatDateTime(
                                agentRun.worker?.last_heartbeat_at ?? null,
                            )}
                        </span>
                    </div>
                </div>

                {(agentRun.file_modifications?.length ?? 0) > 0 && (
                    <div className="border-t border-border-subtle pt-3">
                        <p className="mb-2 font-mono text-2xs tracking-[0.08em] text-muted-foreground uppercase">
                            Recorded file changes
                        </p>
                        <div className="grid gap-1.5">
                            {agentRun.file_modifications
                                ?.slice(0, 4)
                                .map((file) => (
                                    <div
                                        key={`${file.kind}-${file.path}`}
                                        className="flex min-w-0 items-center justify-between gap-3 rounded-md bg-foreground/[0.025] px-2 py-1.5"
                                    >
                                        <span
                                            className="truncate font-mono text-2xs text-foreground/80"
                                            title={file.path}
                                        >
                                            {file.path}
                                        </span>
                                        <Badge
                                            variant="outline"
                                            className="shrink-0 border-border bg-card font-mono text-2xs text-muted-foreground"
                                        >
                                            {file.kind}
                                        </Badge>
                                    </div>
                                ))}

                            {(agentRun.file_modifications?.length ?? 0) > 4 && (
                                <p className="text-2xs text-muted-foreground">
                                    +
                                    {(agentRun.file_modifications?.length ??
                                        0) - 4}{' '}
                                    additional file changes
                                </p>
                            )}
                        </div>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

export function ConfigurationEvidenceCard({
    agentRun,
}: {
    agentRun: AgentRun;
}) {
    const snapshot = agentRun.configuration_snapshot;

    return (
        <Card className="h-full">
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle className="flex items-center gap-2">
                        <ShieldCheck className="size-4 text-secondary-foreground" />
                        Configuration evidence
                    </CardTitle>
                    <Badge
                        variant="outline"
                        className={
                            snapshot
                                ? 'border-secondary-foreground/20 bg-secondary/10 font-mono text-2xs text-secondary-foreground'
                                : 'border-warning/30 bg-warning/10 font-mono text-2xs text-warning-foreground'
                        }
                    >
                        {snapshot ? 'Immutable snapshot' : 'Legacy run'}
                    </Badge>
                </div>
                <CardDescription>
                    {snapshot
                        ? 'Captured at run start. Mutable Agent and Skill records are not used for historical evidence.'
                        : 'This execution predates immutable configuration snapshots.'}
                </CardDescription>
            </CardHeader>

            <CardContent className="grid gap-3 text-sm">
                {snapshot ? (
                    <>
                        <dl className="grid gap-2 sm:grid-cols-2">
                            <div className="tile-inset px-2.5 py-2">
                                <dt className="font-mono text-2xs text-muted-foreground uppercase">
                                    Agent
                                </dt>
                                <dd className="mt-1 font-medium text-foreground">
                                    {snapshot.agent.name}{' '}
                                    <span className="font-mono text-2xs text-primary">
                                        v{snapshot.agent.configuration_version}
                                    </span>
                                </dd>
                            </div>

                            <div className="tile-inset px-2.5 py-2">
                                <dt className="font-mono text-2xs text-muted-foreground uppercase">
                                    Role
                                </dt>
                                <dd className="mt-1 text-foreground capitalize">
                                    {humanize(snapshot.agent.role)}
                                </dd>
                            </div>

                            <div className="tile-inset px-2.5 py-2">
                                <dt className="font-mono text-2xs text-muted-foreground uppercase">
                                    Harness
                                </dt>
                                <dd className="mt-1 text-foreground">
                                    {harnessLabel(
                                        agentRun.harness ??
                                            snapshot.agent.harness,
                                    )}
                                </dd>
                            </div>

                            <div className="tile-inset px-2.5 py-2">
                                <dt className="font-mono text-2xs text-muted-foreground uppercase">
                                    Model
                                </dt>
                                <dd
                                    className="mt-1 truncate font-mono text-xs text-foreground"
                                    title={
                                        snapshot.agent.model ??
                                        'Harness default'
                                    }
                                >
                                    {snapshot.agent.model ?? 'Harness default'}
                                </dd>
                            </div>

                            <div className="tile-inset px-2.5 py-2">
                                <dt className="font-mono text-2xs text-muted-foreground uppercase">
                                    Reasoning / effort
                                </dt>
                                <dd className="mt-1 text-foreground">
                                    {snapshot.agent.reasoning_setting ??
                                        'Model default'}
                                </dd>
                            </div>

                            <div className="tile-inset px-2.5 py-2">
                                <dt className="font-mono text-2xs text-muted-foreground uppercase">
                                    Context schema
                                </dt>
                                <dd className="mt-1 font-mono text-xs text-foreground">
                                    v{snapshot.context_schema_version}
                                </dd>
                            </div>
                        </dl>

                        <div className="panel-recessed p-2.5">
                            <p className="font-mono text-2xs tracking-[0.08em] text-muted-foreground uppercase">
                                Context hash
                            </p>
                            <p className="mt-1 font-mono text-2xs leading-5 break-all text-primary">
                                {snapshot.context_hash}
                            </p>
                        </div>

                        {agentRun.external_run_id && (
                            <div className="panel-recessed p-2.5">
                                <p className="font-mono text-2xs tracking-[0.08em] text-muted-foreground uppercase">
                                    External provider run ID
                                </p>
                                <p className="mt-1 font-mono text-2xs leading-5 break-all text-foreground">
                                    {agentRun.external_run_id}
                                </p>
                            </div>
                        )}

                        <div>
                            <div className="mb-2 flex items-center justify-between gap-3">
                                <p className="font-mono text-2xs tracking-[0.08em] text-muted-foreground uppercase">
                                    Skills applied
                                </p>
                                <span className="font-mono text-2xs text-muted-foreground">
                                    {snapshot.skills.length}
                                </span>
                            </div>

                            {snapshot.skills.length === 0 ? (
                                <div className="rounded-lg border border-dashed border-border px-3 py-4 text-center text-xs text-muted-foreground">
                                    No Skills were applied to this run.
                                </div>
                            ) : (
                                <div className="grid gap-1.5">
                                    {snapshot.skills.map((skill) => (
                                        <div
                                            key={skill.id}
                                            className="flex items-center justify-between gap-3 rounded-lg border border-border-subtle bg-foreground/[0.025] px-2.5 py-2"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate text-xs font-medium text-foreground">
                                                    {skill.position + 1}.{' '}
                                                    {skill.name}
                                                </p>
                                                <p className="truncate font-mono text-2xs text-muted-foreground">
                                                    {skill.slug}
                                                </p>
                                            </div>
                                            <Badge
                                                variant="outline"
                                                className="shrink-0 border-secondary-foreground/20 bg-secondary/10 font-mono text-2xs text-secondary-foreground"
                                            >
                                                v{skill.version}
                                            </Badge>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </>
                ) : (
                    <div className="rounded-lg border border-dashed border-warning/30 bg-warning/5 p-4">
                        <p className="text-sm font-medium text-warning-foreground">
                            Immutable configuration evidence unavailable
                        </p>
                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                            The run remains readable, but Agent, Skill, model,
                            and context evidence cannot be reconstructed from
                            mutable current configuration.
                        </p>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

export function ContextCostCard({ agentRun }: { agentRun: AgentRun }) {
    const estimate = agentRun.context_cost_estimate;
    const totalCharacters = estimate?.total.characters ?? 0;

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Gauge className="size-4 text-secondary-foreground" />
                    Context cost estimate
                </CardTitle>
                <CardDescription>
                    {estimate
                        ? 'Deterministic preflight character/token attribution captured at run start.'
                        : 'This run predates preflight context-cost attribution.'}
                </CardDescription>
            </CardHeader>

            <CardContent>
                {estimate ? (
                    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_16rem]">
                        <div className="min-w-0">
                            <div className="grid gap-2">
                                {CONTEXT_COST_SECTIONS.map(({ key, label }) => {
                                    const measurement = estimate[key];
                                    const percentage =
                                        totalCharacters === 0
                                            ? 0
                                            : (measurement.characters /
                                                  totalCharacters) *
                                              100;

                                    return (
                                        <div
                                            key={key}
                                            className="rounded-lg border border-border-subtle bg-foreground/[0.025] px-2.5 py-2"
                                        >
                                            <div className="flex items-center justify-between gap-3">
                                                <span className="min-w-0 truncate text-xs text-foreground/80">
                                                    {label}
                                                </span>
                                                <div className="flex shrink-0 items-center gap-2">
                                                    {estimate.disproportionate_sections.includes(
                                                        key,
                                                    ) && (
                                                        <Badge
                                                            variant="outline"
                                                            className="border-warning/30 bg-warning/10 font-mono text-2xs text-warning-foreground"
                                                        >
                                                            High
                                                        </Badge>
                                                    )}
                                                    <span className="font-mono text-2xs text-muted-foreground">
                                                        {measurement.estimated_tokens.toLocaleString()}{' '}
                                                        tok
                                                    </span>
                                                </div>
                                            </div>

                                            <div className="mt-2 grid grid-cols-[minmax(0,1fr)_3.5rem] items-center gap-2">
                                                <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                                    <div
                                                        className="progress-flow h-full rounded-full"
                                                        style={{
                                                            width: `${Math.min(
                                                                100,
                                                                percentage,
                                                            )}%`,
                                                        }}
                                                    />
                                                </div>
                                                <span className="text-right font-mono text-2xs text-muted-foreground">
                                                    {percentage.toFixed(1)}%
                                                </span>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>

                            {estimate.skills.length > 0 && (
                                <div className="mt-4 border-t border-border-subtle pt-3">
                                    <p className="mb-2 font-mono text-2xs tracking-[0.08em] text-muted-foreground uppercase">
                                        Per-skill breakdown
                                    </p>
                                    <div className="grid gap-1.5 sm:grid-cols-2">
                                        {estimate.skills.map((skill) => (
                                            <div
                                                key={`${skill.slug}-${skill.position}`}
                                                className="flex min-w-0 items-center justify-between gap-3 rounded-md bg-foreground/[0.025] px-2.5 py-2"
                                            >
                                                <span className="truncate font-mono text-2xs text-foreground/80">
                                                    {skill.slug ??
                                                        'Unlabelled skill'}
                                                </span>
                                                <span className="shrink-0 font-mono text-2xs text-muted-foreground">
                                                    ~
                                                    {skill.estimated_tokens.toLocaleString()}{' '}
                                                    tok
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>

                        <aside className="panel-recessed self-start p-3">
                            <div className="flex items-center gap-2">
                                <div className="grid size-8 place-items-center rounded-lg border border-secondary-foreground/15 bg-secondary/10 text-secondary-foreground">
                                    <Gauge className="size-4" />
                                </div>
                                <div>
                                    <p className="text-xs font-semibold text-foreground">
                                        Cost summary
                                    </p>
                                    <p className="font-mono text-2xs text-muted-foreground">
                                        Pre-execution estimate
                                    </p>
                                </div>
                            </div>

                            <dl className="mt-4 grid gap-2.5">
                                <div className="flex items-center justify-between gap-3">
                                    <dt className="text-xs text-muted-foreground">
                                        Characters
                                    </dt>
                                    <dd className="font-mono text-xs text-foreground">
                                        {estimate.total.characters.toLocaleString()}
                                    </dd>
                                </div>
                                <div className="flex items-center justify-between gap-3">
                                    <dt className="text-xs text-muted-foreground">
                                        Est. tokens
                                    </dt>
                                    <dd className="font-mono text-xs text-primary">
                                        {estimate.total.estimated_tokens.toLocaleString()}
                                    </dd>
                                </div>
                                <div className="flex items-center justify-between gap-3">
                                    <dt className="text-xs text-muted-foreground">
                                        Chars / token
                                    </dt>
                                    <dd className="font-mono text-xs text-foreground">
                                        {estimate.characters_per_token_ratio}
                                    </dd>
                                </div>
                                <div className="flex items-center justify-between gap-3">
                                    <dt className="text-xs text-muted-foreground">
                                        Schema
                                    </dt>
                                    <dd className="font-mono text-xs text-foreground">
                                        v{estimate.schema_version}
                                    </dd>
                                </div>
                            </dl>

                            <p className="mt-4 border-t border-border-subtle pt-3 text-2xs leading-5 text-muted-foreground">
                                Estimates are captured before execution and do
                                not represent provider billing.
                            </p>
                        </aside>
                    </div>
                ) : (
                    <Badge variant="outline">Legacy run</Badge>
                )}
            </CardContent>
        </Card>
    );
}

export function RunHealthCard({ agentRun }: { agentRun: AgentRun }) {
    const failed =
        agentRun.status === 'failed' ||
        (agentRun.exit_code !== null && agentRun.exit_code !== 0);
    const activeWorker =
        agentRun.status === 'running' &&
        ['working', 'recovering'].includes(agentRun.worker?.status ?? '');
    const completedSuccessfully =
        agentRun.status === 'completed' && agentRun.exit_code === 0;

    const health = failed
        ? {
              label: 'Attention',
              description: 'Execution failure evidence was recorded.',
              icon: TriangleAlert,
              className:
                  'border-destructive/30 bg-destructive/10 text-destructive-foreground',
          }
        : activeWorker || completedSuccessfully
          ? {
                label: 'Healthy',
                description: activeWorker
                    ? 'Run and worker evidence are active.'
                    : 'Execution completed successfully.',
                icon: ShieldCheck,
                className:
                    'border-success/30 bg-success/10 text-success-foreground',
            }
          : {
                label: 'Observed',
                description: 'Persisted execution evidence is available.',
                icon: HeartPulse,
                className: 'border-primary/30 bg-primary/10 text-primary',
            };

    const HealthIcon = health.icon;

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <HeartPulse className="size-4 text-primary" />
                    Run health
                </CardTitle>
            </CardHeader>

            <CardContent>
                <div className="flex items-start gap-3">
                    <div
                        className={`grid size-10 shrink-0 place-items-center rounded-lg border ${health.className}`}
                    >
                        <HealthIcon className="size-5" />
                    </div>
                    <div>
                        <p className="text-sm font-semibold text-foreground">
                            {health.label}
                        </p>
                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                            {health.description}
                        </p>
                    </div>
                </div>

                <div className="mt-4 grid gap-2 border-t border-border-subtle pt-3">
                    <div className="flex items-center justify-between gap-3 text-xs">
                        <span className="text-muted-foreground">Run</span>
                        <Badge
                            variant="outline"
                            className={`font-mono text-2xs ${statusBadgeClasses(
                                agentRun.status,
                            )}`}
                        >
                            {humanize(agentRun.status)}
                        </Badge>
                    </div>

                    <div className="flex items-center justify-between gap-3 text-xs">
                        <span className="text-muted-foreground">Worker</span>
                        <span className="text-foreground capitalize">
                            {agentRun.worker?.status
                                ? humanize(agentRun.worker.status)
                                : 'Not recorded'}
                        </span>
                    </div>

                    <div className="flex items-center justify-between gap-3 text-xs">
                        <span className="text-muted-foreground">Harness</span>
                        <span className="text-foreground">
                            {harnessLabel(agentRun.harness)}
                        </span>
                    </div>

                    <div className="flex items-start justify-between gap-3 text-xs">
                        <span className="text-muted-foreground">Heartbeat</span>
                        <span className="text-right font-mono text-2xs text-foreground">
                            {formatDateTime(
                                agentRun.worker?.last_heartbeat_at ?? null,
                            )}
                        </span>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

export function RecentRunActivityCard({ agentRun }: { agentRun: AgentRun }) {
    const recentMessages = agentRun.agent_messages.slice(-5).reverse();

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Activity className="size-4 text-secondary-foreground" />
                    Recent activity
                </CardTitle>
                <CardDescription>
                    Latest ordered agent messages from persisted output.
                </CardDescription>
            </CardHeader>

            <CardContent className="grid gap-2">
                {recentMessages.map((message, index) => (
                    <div
                        key={`${index}-${message}`}
                        className="flex min-w-0 gap-2 rounded-lg border border-border-subtle bg-foreground/[0.025] px-2.5 py-2"
                    >
                        <span className="mt-1 size-1.5 shrink-0 rounded-full bg-primary shadow-glow-sm" />
                        <p className="max-h-10 overflow-hidden text-xs leading-5 text-muted-foreground">
                            {message}
                        </p>
                    </div>
                ))}

                {recentMessages.length === 0 && (
                    <div className="rounded-lg border border-dashed border-border px-3 py-5 text-center text-xs text-muted-foreground">
                        No agent activity messages recorded yet.
                    </div>
                )}

                <div className="mt-1 grid grid-cols-2 gap-2 border-t border-border-subtle pt-3">
                    <div className="tile-inset p-2">
                        <Terminal className="size-3.5 text-primary" />
                        <p className="mt-1 font-mono text-lg font-semibold text-foreground">
                            {agentRun.commands?.length ?? 0}
                        </p>
                        <p className="text-2xs text-muted-foreground">
                            commands
                        </p>
                    </div>
                    <div className="tile-inset p-2">
                        <FileCode2 className="size-3.5 text-secondary-foreground" />
                        <p className="mt-1 font-mono text-lg font-semibold text-foreground">
                            {agentRun.file_modifications?.length ?? 0}
                        </p>
                        <p className="text-2xs text-muted-foreground">
                            file changes
                        </p>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
