import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    Archive,
    BrainCircuit,
    ChevronLeft,
    ChevronRight,
    ExternalLink,
    FileSearch,
    History,
    Settings2,
    ShieldCheck,
} from 'lucide-react';
import { updateStatus as updateRecommendationStatus } from '@/actions/App/Http/Controllers/OrchestratorRecommendationController';
import { AppBackground } from '@/components/app-background';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type CurrentConfiguration = {
    id: number;
    name: string;
    scope: 'global' | 'project';
    role: string;
    harness: string;
    model: string | null;
    reasoning_setting: string | null;
    default_context: string | null;
    configuration_version: number;
    enabled: boolean;
};

type AgentRun = {
    id: number;
    role: string;
    harness: string | null;
    status: string;
    token_usage: number | null;
    context_schema_version: number | null;
    configuration_snapshot: Record<string, unknown> | null;
    started_at: string | null;
    finished_at: string | null;
    agent: {
        id: number;
        name: string;
        role: string;
    } | null;
    url: string | null;
};

type EvaluatedEvidence = {
    evidence_hash: string;
    prompt_hash: string;
    retrieval_manifest: Record<string, unknown> | null;
    context_budget_schema_version: number | null;
    context_budget_snapshot: Record<string, unknown> | null;
};

type Recommendation = {
    id: number;
    advisory: boolean;
    recommendation_type: string;
    schema_version: number;
    confidence: string;
    status: 'active' | 'dismissed' | 'superseded';
    created_at: string | null;
    status_changed_at: string | null;
    status_changed_by: {
        id: number;
        name: string;
    } | null;
    project: {
        id: number;
        name: string;
        path: string;
    } | null;
    task: {
        id: number;
        key: string;
        title: string;
        status: string;
    } | null;
    recovery_incident: {
        id: number;
        failure_type: string;
        status: string;
    } | null;
    recommendation: Record<string, unknown>;
    reason: string | null;
    current_configuration: CurrentConfiguration | null;
    suggested_configuration: Record<string, unknown> | null;
    evaluated_evidence: EvaluatedEvidence;
    agent_run: AgentRun;
    manual_action: {
        label: string;
        url: string;
    } | null;
};

type RecommendationPaginator = {
    current_page: number;
    data: Recommendation[];
    from: number | null;
    last_page: number;
    next_page_url: string | null;
    per_page: number;
    prev_page_url: string | null;
    to: number | null;
    total: number;
};

type Summary = {
    total: number;
    active: number;
    dismissed: number;
    superseded: number;
};

/**
 * Convert persisted identifiers into readable operator labels without changing durable values.
 */
function humanize(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replaceAll('.', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

/**
 * Format one persisted ISO timestamp using the operator browser locale.
 */
function formatDateTime(value: string | null): string {
    if (!value) {
        return 'Not recorded';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

/**
 * Convert normalized zero-to-one confidence into an operator-readable percentage.
 */
function formatConfidence(value: string): string {
    const confidence = Number(value);

    return Number.isFinite(confidence)
        ? `${Math.round(confidence * 100)}%`
        : value;
}

/**
 * Convert a configuration value into compact readable text.
 */
function formatConfigurationValue(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return 'Not set';
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    if (typeof value === 'string' || typeof value === 'number') {
        return String(value);
    }

    return JSON.stringify(value);
}

/**
 * Remove identity metadata from current configuration before rendering the comparable settings.
 */
function currentConfigurationValues(
    configuration: CurrentConfiguration | null,
): Record<string, unknown> | null {
    if (!configuration) {
        return null;
    }

    return {
        role: configuration.role,
        harness: configuration.harness,
        model: configuration.model,
        reasoning_setting: configuration.reasoning_setting,
        default_context: configuration.default_context,
        configuration_version: configuration.configuration_version,
        enabled: configuration.enabled,
    };
}

/**
 * Render a current or suggested configuration using the existing recessed surface pattern.
 */
function ConfigurationPanel({
    title,
    configuration,
    emptyMessage,
}: {
    title: string;
    configuration: Record<string, unknown> | null;
    emptyMessage: string;
}) {
    return (
        <section className="panel-recessed p-4">
            <div className="flex items-center gap-2">
                <Settings2 className="size-4 text-primary" aria-hidden="true" />
                <h3 className="text-sm font-semibold text-foreground">
                    {title}
                </h3>
            </div>

            {!configuration || Object.keys(configuration).length === 0 ? (
                <p className="mt-3 text-xs leading-relaxed text-muted-foreground">
                    {emptyMessage}
                </p>
            ) : (
                <dl className="mt-3 grid gap-2">
                    {Object.entries(configuration).map(([key, value]) => (
                        <div key={key} className="tile-inset p-2.5">
                            <dt className="font-mono text-2xs tracking-[0.1em] text-muted-foreground uppercase">
                                {humanize(key)}
                            </dt>
                            <dd className="mt-1 text-xs break-words whitespace-pre-wrap text-foreground">
                                {formatConfigurationValue(value)}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}
        </section>
    );
}

/**
 * Render exact structured evidence inside an operator-expandable diagnostic section.
 */
function JsonDetails({
    title,
    value,
}: {
    title: string;
    value: Record<string, unknown> | null;
}) {
    return (
        <details className="tile-inset overflow-hidden">
            <summary className="cursor-pointer list-none p-3 text-xs font-medium text-foreground marker:hidden">
                {title}
            </summary>

            <div className="border-t border-border-subtle p-3">
                {value ? (
                    <pre className="max-h-80 overflow-auto font-mono text-2xs leading-relaxed break-words whitespace-pre-wrap text-muted-foreground">
                        {JSON.stringify(value, null, 2)}
                    </pre>
                ) : (
                    <p className="text-xs text-muted-foreground">
                        No durable evidence was persisted for this section.
                    </p>
                )}
            </div>
        </details>
    );
}

/**
 * Render one advisory recommendation with source evidence and explicit operator-only lifecycle controls.
 */
function RecommendationCard({
    recommendation,
}: {
    recommendation: Recommendation;
}) {
    const statusClassName =
        recommendation.status === 'active'
            ? 'border-primary/25 bg-primary/10 text-primary'
            : recommendation.status === 'superseded'
              ? 'border-warning/25 bg-warning/10 text-warning'
              : 'border-border bg-card text-muted-foreground';

    return (
        <article className="panel-elevated relative overflow-hidden p-5">
            <div className="glow-line-accent opacity-60" />

            <div className="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge
                            variant="outline"
                            className="border-primary/25 bg-primary/10 text-primary"
                        >
                            <ShieldCheck className="mr-1 size-3" />
                            Advisory only
                        </Badge>

                        <Badge variant="secondary">
                            {humanize(recommendation.recommendation_type)}
                        </Badge>

                        <Badge variant="outline" className={statusClassName}>
                            {humanize(recommendation.status)}
                        </Badge>

                        <Badge variant="outline">
                            {formatConfidence(recommendation.confidence)}{' '}
                            confidence
                        </Badge>

                        <span className="font-mono text-2xs text-muted-foreground">
                            Recommendation #{recommendation.id}
                        </span>
                    </div>

                    <h2 className="mt-4 text-base font-semibold text-foreground">
                        {recommendation.project?.name ??
                            'AIOS system recommendation'}
                        {recommendation.task
                            ? ` · ${recommendation.task.key}`
                            : ''}
                    </h2>

                    <p className="mt-2 max-w-4xl text-sm leading-relaxed text-foreground">
                        {recommendation.reason ??
                            'No explicit reason was persisted.'}
                    </p>

                    <div className="mt-4 grid gap-3 md:grid-cols-3">
                        <div className="tile-inset p-3">
                            <div className="font-mono text-2xs text-muted-foreground uppercase">
                                Created
                            </div>
                            <div className="mt-1 text-xs text-foreground">
                                {formatDateTime(recommendation.created_at)}
                            </div>
                        </div>

                        <div className="tile-inset p-3">
                            <div className="font-mono text-2xs text-muted-foreground uppercase">
                                Evidence hash
                            </div>
                            <code className="mt-1 block text-2xs break-all text-foreground">
                                {
                                    recommendation.evaluated_evidence
                                        .evidence_hash
                                }
                            </code>
                        </div>

                        <div className="tile-inset p-3">
                            <div className="font-mono text-2xs text-muted-foreground uppercase">
                                Schema
                            </div>
                            <div className="mt-1 text-xs text-foreground">
                                v{recommendation.schema_version}
                            </div>
                        </div>
                    </div>

                    <div className="mt-4 grid gap-4 xl:grid-cols-2">
                        <ConfigurationPanel
                            title={
                                recommendation.current_configuration
                                    ? `Current configuration · ${recommendation.current_configuration.name}`
                                    : 'Current configuration'
                            }
                            configuration={currentConfigurationValues(
                                recommendation.current_configuration,
                            )}
                            emptyMessage="This recommendation type does not target a currently bound Agent configuration."
                        />

                        <ConfigurationPanel
                            title="Suggested configuration"
                            configuration={
                                recommendation.suggested_configuration
                            }
                            emptyMessage="This recommendation advises workflow, recovery, context, retry, or decomposition behavior rather than an Agent configuration change."
                        />
                    </div>

                    <section className="panel-recessed mt-4 p-4">
                        <div className="flex items-center gap-2">
                            <Activity
                                className="size-4 text-primary"
                                aria-hidden="true"
                            />
                            <h3 className="text-sm font-semibold text-foreground">
                                Source AgentRun
                            </h3>
                        </div>

                        <div className="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                            <div className="tile-inset p-2.5">
                                <div className="font-mono text-2xs text-muted-foreground uppercase">
                                    AgentRun
                                </div>
                                <div className="mt-1 text-xs text-foreground">
                                    #{recommendation.agent_run.id}
                                </div>
                            </div>

                            <div className="tile-inset p-2.5">
                                <div className="font-mono text-2xs text-muted-foreground uppercase">
                                    Harness
                                </div>
                                <div className="mt-1 text-xs text-foreground">
                                    {recommendation.agent_run.harness
                                        ? humanize(
                                              recommendation.agent_run.harness,
                                          )
                                        : 'Not recorded'}
                                </div>
                            </div>

                            <div className="tile-inset p-2.5">
                                <div className="font-mono text-2xs text-muted-foreground uppercase">
                                    Status
                                </div>
                                <div className="mt-1 text-xs text-foreground">
                                    {humanize(recommendation.agent_run.status)}
                                </div>
                            </div>

                            <div className="tile-inset p-2.5">
                                <div className="font-mono text-2xs text-muted-foreground uppercase">
                                    Tokens
                                </div>
                                <div className="mt-1 text-xs text-foreground">
                                    {recommendation.agent_run.token_usage ??
                                        'Not recorded'}
                                </div>
                            </div>
                        </div>

                        <div className="mt-3 flex flex-wrap items-center gap-2">
                            {recommendation.agent_run.url && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={recommendation.agent_run.url}>
                                        <FileSearch className="size-4" />
                                        Inspect AgentRun
                                    </Link>
                                </Button>
                            )}

                            <span className="font-mono text-2xs text-muted-foreground">
                                {formatDateTime(
                                    recommendation.agent_run.started_at,
                                )}
                            </span>
                        </div>
                    </section>

                    <section className="mt-4">
                        <div className="mb-2 flex items-center gap-2">
                            <History
                                className="size-4 text-primary"
                                aria-hidden="true"
                            />
                            <h3 className="text-sm font-semibold text-foreground">
                                Evaluated evidence
                            </h3>
                        </div>

                        <div className="grid gap-2">
                            <JsonDetails
                                title="Exact recommendation payload"
                                value={recommendation.recommendation}
                            />

                            <JsonDetails
                                title="Retrieval manifest"
                                value={
                                    recommendation.evaluated_evidence
                                        .retrieval_manifest
                                }
                            />

                            <JsonDetails
                                title="AgentRun configuration snapshot"
                                value={
                                    recommendation.agent_run
                                        .configuration_snapshot
                                }
                            />

                            <JsonDetails
                                title="Context Budget evidence"
                                value={
                                    recommendation.evaluated_evidence
                                        .context_budget_snapshot
                                }
                            />
                        </div>
                    </section>

                    {recommendation.status !== 'active' && (
                        <div className="mt-4 rounded-lg border border-border-subtle bg-surface-recessed p-3 text-xs text-muted-foreground">
                            Marked {humanize(recommendation.status)}
                            {recommendation.status_changed_by
                                ? ` by ${recommendation.status_changed_by.name}`
                                : ''}
                            {recommendation.status_changed_at
                                ? ` on ${formatDateTime(
                                      recommendation.status_changed_at,
                                  )}`
                                : ''}
                            . Historical recommendation evidence remains
                            unchanged.
                        </div>
                    )}
                </div>

                <aside className="w-full shrink-0 rounded-xl border border-border bg-card/50 p-4 xl:w-72">
                    <div className="flex items-center gap-2 font-mono text-2xs tracking-[0.12em] text-primary uppercase">
                        <BrainCircuit className="size-4" />
                        Operator controls
                    </div>

                    <p className="mt-3 text-xs leading-relaxed text-muted-foreground">
                        These controls do not apply the recommendation, change
                        Agent configuration, switch a harness/model, or
                        transition workflow state.
                    </p>

                    <div className="mt-4 grid gap-2">
                        {recommendation.manual_action && (
                            <Button asChild variant="outline">
                                <Link href={recommendation.manual_action.url}>
                                    <ExternalLink className="size-4" />
                                    {recommendation.manual_action.label}
                                </Link>
                            </Button>
                        )}

                        {recommendation.status === 'active' && (
                            <>
                                <Form
                                    action={
                                        updateRecommendationStatus(
                                            recommendation.id,
                                        ).url
                                    }
                                    method="patch"
                                >
                                    <input
                                        type="hidden"
                                        name="status"
                                        value="dismissed"
                                    />

                                    <Button
                                        type="submit"
                                        variant="ghost"
                                        className="w-full"
                                    >
                                        <Archive className="size-4" />
                                        Dismiss
                                    </Button>
                                </Form>

                                <Form
                                    action={
                                        updateRecommendationStatus(
                                            recommendation.id,
                                        ).url
                                    }
                                    method="patch"
                                >
                                    <input
                                        type="hidden"
                                        name="status"
                                        value="superseded"
                                    />

                                    <Button
                                        type="submit"
                                        variant="secondary"
                                        className="w-full"
                                    >
                                        <History className="size-4" />
                                        Mark superseded
                                    </Button>
                                </Form>
                            </>
                        )}
                    </div>
                </aside>
            </div>
        </article>
    );
}

/**
 * Render the global advisory Orchestrator recommendation command center.
 */
export default function OrchestratorRecommendationsIndex({
    recommendations,
    summary,
}: {
    recommendations: RecommendationPaginator;
    summary: Summary;
}) {
    const page = usePage();
    const errors = page.props.errors as Record<string, string> | undefined;

    return (
        <>
            <Head title="Orchestrator Recommendations" />

            <div className="relative min-h-full overflow-hidden">
                <AppBackground contained />

                <div className="relative z-10 w-full space-y-4 p-4 sm:p-6 lg:p-8">
                    <section className="panel-elevated relative overflow-hidden p-5">
                        <div className="glow-line-accent" />

                        <div className="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                            <div className="max-w-3xl">
                                <div className="flex items-center gap-2 font-mono text-2xs tracking-[0.14em] text-primary uppercase">
                                    <BrainCircuit className="size-4" />
                                    Global AI architect
                                </div>

                                <h1 className="mt-2 text-xl font-semibold tracking-tight text-foreground">
                                    Orchestrator Recommendation Command Center
                                </h1>

                                <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                    Recommendations are advisory evidence only.
                                    Laravel/AIOS remains authoritative over
                                    Agent configuration, workflow state,
                                    routing, Git, validation, authorization,
                                    persistence, and every durable operational
                                    decision.
                                </p>
                            </div>

                            <Badge
                                variant="outline"
                                className="border-primary/25 bg-primary/10 text-primary"
                            >
                                <ShieldCheck className="mr-1 size-3.5" />
                                No automatic apply
                            </Badge>
                        </div>
                    </section>

                    {errors?.status && (
                        <div
                            role="alert"
                            className="rounded-lg border border-destructive/25 bg-destructive/10 px-4 py-3 text-sm text-destructive-foreground"
                        >
                            {errors.status}
                        </div>
                    )}

                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {[
                            ['Total', summary.total],
                            ['Active', summary.active],
                            ['Dismissed', summary.dismissed],
                            ['Superseded', summary.superseded],
                        ].map(([label, value]) => (
                            <div
                                key={String(label)}
                                className="panel-elevated p-4"
                            >
                                <div className="font-mono text-2xs tracking-[0.12em] text-muted-foreground uppercase">
                                    {label}
                                </div>

                                <div className="mt-1 text-2xl font-semibold tracking-tight text-foreground">
                                    {value}
                                </div>
                            </div>
                        ))}
                    </section>

                    {recommendations.total === 0 ? (
                        <section className="panel-elevated p-8 text-center">
                            <BrainCircuit className="mx-auto size-8 text-muted-foreground" />

                            <h2 className="mt-3 font-semibold text-foreground">
                                No Orchestrator recommendations yet
                            </h2>

                            <p className="mx-auto mt-1 max-w-xl text-sm text-muted-foreground">
                                Recommendations appear here only after the
                                existing bounded Orchestrator execution path
                                produces validated durable advisory evidence.
                            </p>
                        </section>
                    ) : (
                        <>
                            <div className="grid gap-4">
                                {recommendations.data.map((recommendation) => (
                                    <RecommendationCard
                                        key={recommendation.id}
                                        recommendation={recommendation}
                                    />
                                ))}
                            </div>

                            <section className="panel-elevated flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                                <div className="text-sm text-muted-foreground">
                                    Showing{' '}
                                    <span className="font-medium text-foreground">
                                        {recommendations.from ?? 0}
                                    </span>{' '}
                                    to{' '}
                                    <span className="font-medium text-foreground">
                                        {recommendations.to ?? 0}
                                    </span>{' '}
                                    of{' '}
                                    <span className="font-medium text-foreground">
                                        {recommendations.total}
                                    </span>{' '}
                                    recommendations
                                </div>

                                <div className="flex items-center gap-2">
                                    <Button
                                        asChild
                                        variant="outline"
                                        size="sm"
                                        disabled={
                                            recommendations.prev_page_url ===
                                            null
                                        }
                                    >
                                        <Link
                                            href={
                                                recommendations.prev_page_url ??
                                                '#'
                                            }
                                        >
                                            <ChevronLeft className="size-4" />
                                            Previous
                                        </Link>
                                    </Button>

                                    <span className="font-mono text-2xs text-muted-foreground">
                                        Page {recommendations.current_page} of{' '}
                                        {recommendations.last_page}
                                    </span>

                                    <Button
                                        asChild
                                        variant="outline"
                                        size="sm"
                                        disabled={
                                            recommendations.next_page_url ===
                                            null
                                        }
                                    >
                                        <Link
                                            href={
                                                recommendations.next_page_url ??
                                                '#'
                                            }
                                        >
                                            Next
                                            <ChevronRight className="size-4" />
                                        </Link>
                                    </Button>
                                </div>
                            </section>
                        </>
                    )}
                </div>
            </div>
        </>
    );
}
