import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowUpRight,
    Bot,
    Database,
    Gauge,
    ShieldCheck,
    SlidersHorizontal,
    TriangleAlert,
} from 'lucide-react';
import { AppBackground } from '@/components/app-background';
import { useAppHeaderSlot } from '@/components/app-header-slot';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Option = {
    value: string;
    label: string;
};

type ProjectOption = {
    id: number;
    name: string;
    path: string;
};

type Filters = {
    project_id: number | null;
    role: string;
    work_type: string;
    complexity: string;
    harness: string;
    model: string;
    reasoning_setting: string;
    confidence: string;
    cohort: string;
};

type FilterOptions = {
    roles: Option[];
    work_types: Option[];
    complexities: Option[];
    harnesses: Option[];
    models: Option[];
    reasoning_settings: Option[];
    confidences: Option[];
    cohorts: Option[];
};

type Configuration = {
    harness: string;
    model: string | null;
    reasoning_setting: string | null;
};

type Cohort = {
    human_label?: string;
    fallback_level?: number;
    fallback_key?: string;
    broadened_dimensions?: string[];
    filters?: {
        workflow_role?: string;
        work_type?: string | null;
        complexity?: string | null;
    };
};

type CoderConfigurationScore = {
    configuration_key: string;
    configuration: Configuration | null;
    sample_count: number;
    successful_task_count: number;
    failed_task_count: number;
    blocked_task_count: number;
    rates: {
        first_pass_reviewer_approval?: number | null;
        first_pass_deterministic_validation?: number | null;
        no_operational_retry_or_block?: number | null;
        no_no_progress_retry_condition?: number | null;
        cost_efficiency?: number | null;
        speed?: number | null;
    };
    medians: {
        token_usage?: number | null;
        execution_duration_seconds?: number | null;
    };
    component_points: {
        quality?: {
            first_pass_reviewer_approval?: number;
            first_pass_deterministic_validation?: number;
            total?: number;
        };
        reliability?: {
            no_operational_retry_or_block?: number;
            no_no_progress_retry_condition?: number;
            total?: number;
        };
        cost_efficiency?: number;
        speed?: number;
    };
    composite_score: number;
};

type CoderRecommendation = {
    eligible: boolean;
    leading_configuration: {
        configuration_key?: string;
        harness?: string;
        model?: string | null;
        reasoning_setting?: string | null;
        composite_score?: number;
    } | null;
    reason?: string;
};

type CoderScorecard = {
    schema_version: number;
    score_version: number;
    selected_cohort: Cohort;
    sample: {
        terminal_task_count?: number;
        attributed_task_count?: number;
        unattributed_task_count?: number;
        comparable_completed_task_count?: number;
        configuration_count?: number;
    };
    confidence: {
        level?: string;
        comparable_completed_task_count?: number;
        preliminary_minimum?: number;
        recommendation_eligible_minimum?: number;
    };
    reference: {
        token_median?: number | null;
        duration_median_seconds?: number | null;
    };
    configuration_scores: CoderConfigurationScore[];
    configuration_count_total: number;
    configuration_count_visible: number;
    recommendation: CoderRecommendation | null;
    matches_filters: boolean;
    fallback_evaluations: {
        fallback_level?: number;
        fallback_key?: string;
        cohort?: Cohort;
        confidence?: string;
        sample?: {
            comparable_completed_task_count?: number;
        };
    }[];
};

type ReviewerConfigurationDiagnostic = {
    configuration_key?: string;
    configuration: Configuration | null;
    sample?: {
        review_cycle_count?: number;
        valid_review_count?: number;
        review_started_invocation_count?: number;
    };
    rates?: {
        operational_success?: number | null;
        structured_output_validity?: number | null;
        review_retry?: number | null;
        first_attempt_review_completion?: number | null;
        approval_rate?: number | null;
        changes_required_rate?: number | null;
        changes_required?: {
            value?: number | null;
            diagnostic_only?: boolean;
        };
    };
    review_retry_count?: number;
    medians?: {
        token_consumption?: number | null;
        duration_seconds?: number | null;
    };
};

type ReviewerDiagnostics = {
    schema_version: number;
    methodology_version: string | null;
    selected_cohort: Cohort;
    sample: {
        review_cycle_count?: number;
        review_started_invocation_count?: number;
        valid_review_count?: number;
        attributed_cycle_count?: number;
        unattributed_cycle_count?: number;
        operational_failure_count?: number;
    };
    rates: {
        operational_success?: number | null;
        structured_output_validity?: number | null;
        review_retry?: number | null;
        changes_required?: {
            value?: number | null;
            diagnostic_only?: boolean;
            interpretation?: string;
        };
        first_attempt_review_completion?: number | null;
    };
    configuration_diagnostics: ReviewerConfigurationDiagnostic[];
    configuration_count_total: number;
    configuration_count_visible: number;
    approval_consistency: {
        available?: boolean;
        configuration_count?: number;
        approval_rate_range?: number | null;
        changes_required_rate_range?: number | null;
        ground_truth?: string;
        interpretation?: string;
    };
    codex_claude_divergence: {
        available?: boolean;
        absolute_rate_delta?: {
            approval?: number | null;
            changes_required?: number | null;
        };
        ground_truth?: string;
        interpretation?: string;
    };
    actionable_finding_follow_through: {
        changes_required_review_count?: number;
        with_actionable_findings_count?: number;
        with_corrected_coder_attempt_count?: number;
        eventual_approval_count?: number;
        eventual_approval_rate?: number | null;
        interpretation?: string;
    };
    matches_filters: boolean;
};

type PageProps = {
    projects: ProjectOption[];
    selected_project: ProjectOption | null;
    filters: Filters;
    filter_options: FilterOptions;
    coder_scorecard: CoderScorecard | null;
    reviewer_diagnostics: ReviewerDiagnostics | null;
    links: {
        self: string;
        agent_configuration: string | null;
    };
};

function humanize(value: string | null | undefined): string {
    if (!value) {
        return 'Provider default';
    }

    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function percent(value: number | null | undefined): string {
    if (typeof value !== 'number') {
        return '—';
    }

    return `${(value * 100).toFixed(1)}%`;
}

function number(value: number | null | undefined, digits = 1): string {
    if (typeof value !== 'number') {
        return '—';
    }

    return value.toFixed(digits);
}

function tokens(value: number | null | undefined): string {
    if (typeof value !== 'number') {
        return '—';
    }

    return new Intl.NumberFormat().format(value);
}

function duration(value: number | null | undefined): string {
    if (typeof value !== 'number') {
        return '—';
    }

    if (value < 60) {
        return `${number(value)}s`;
    }

    const minutes = Math.floor(value / 60);
    const seconds = Math.round(value % 60);

    return `${minutes}m ${seconds.toString().padStart(2, '0')}s`;
}

function configurationLabel(configuration: Configuration | null): string {
    if (!configuration) {
        return 'Unattributed configuration';
    }

    return [
        humanize(configuration.harness),
        configuration.model ?? 'Provider default',
        configuration.reasoning_setting
            ? humanize(configuration.reasoning_setting)
            : 'Provider default reasoning',
    ].join(' / ');
}

function cohortIsBroadened(cohort: Cohort): boolean {
    return (
        typeof cohort.fallback_level === 'number' && cohort.fallback_level > 0
    );
}

function confidenceClass(value: string | undefined): string {
    switch (value) {
        case 'recommendation_eligible':
            return 'border-success/30 bg-success/10 text-success-foreground';
        case 'preliminary':
            return 'border-warning/30 bg-warning/10 text-warning-foreground';
        default:
            return 'border-border bg-card text-muted-foreground';
    }
}

function FilterSelect({
    label,
    value,
    options,
    allLabel,
    onChange,
}: {
    label: string;
    value: string;
    options: Option[];
    allLabel?: string;
    onChange: (value: string) => void;
}) {
    return (
        <label className="grid gap-1.5">
            <span className="font-mono text-2xs tracking-[0.1em] text-muted-foreground uppercase">
                {label}
            </span>
            <select
                value={value}
                onChange={(event) => onChange(event.currentTarget.value)}
                className="h-9 min-w-0 rounded-lg border border-border bg-background px-3 text-xs text-foreground transition outline-none focus:border-primary/50 focus:ring-2 focus:ring-primary/10"
            >
                {allLabel && <option value="">{allLabel}</option>}
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </label>
    );
}

function Metric({
    label,
    value,
    detail,
}: {
    label: string;
    value: string;
    detail?: string;
}) {
    return (
        <div className="rounded-lg border border-border-subtle bg-foreground/2 p-3">
            <p className="font-mono text-2xs tracking-[0.08em] text-muted-foreground uppercase">
                {label}
            </p>
            <p className="mt-1 text-lg font-semibold text-foreground">
                {value}
            </p>
            {detail && (
                <p className="mt-1 text-2xs leading-4 text-muted-foreground">
                    {detail}
                </p>
            )}
        </div>
    );
}

function CohortHeader({
    cohort,
    sample,
}: {
    cohort: Cohort;
    sample: number | undefined;
}) {
    const broadened = cohortIsBroadened(cohort);

    return (
        <div className="rounded-xl border border-border-subtle bg-foreground/2 p-4">
            <div className="flex flex-wrap items-center gap-2">
                <Badge
                    variant="outline"
                    className={
                        broadened
                            ? 'border-warning/30 bg-warning/10 text-warning-foreground'
                            : 'border-success/30 bg-success/10 text-success-foreground'
                    }
                >
                    {broadened ? 'Broadened cohort' : 'Exact cohort'}
                </Badge>

                <Badge variant="outline" className="font-mono">
                    Sample {sample ?? 0}
                </Badge>

                {typeof cohort.fallback_level === 'number' && (
                    <Badge variant="secondary" className="font-mono">
                        Fallback {cohort.fallback_level}
                    </Badge>
                )}
            </div>

            <p className="mt-3 font-mono text-xs leading-5 text-foreground">
                {cohort.human_label ?? 'No cohort evidence available.'}
            </p>

            {broadened && (
                <p className="mt-2 text-xs text-muted-foreground">
                    Broadened dimensions:{' '}
                    {(cohort.broadened_dimensions ?? [])
                        .map(humanize)
                        .join(', ') || '—'}
                </p>
            )}
        </div>
    );
}

function CoderConfigurationCard({
    score,
}: {
    score: CoderConfigurationScore;
}) {
    return (
        <article className="rounded-xl border border-border-subtle bg-card/70 p-4">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0">
                    <p className="font-mono text-xs font-semibold text-primary">
                        {configurationLabel(score.configuration)}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {score.sample_count} attributable Tasks ·{' '}
                        {score.successful_task_count} completed ·{' '}
                        {score.failed_task_count} failed ·{' '}
                        {score.blocked_task_count} blocked
                    </p>
                </div>

                <div className="shrink-0 text-left lg:text-right">
                    <p className="font-mono text-2xs text-muted-foreground uppercase">
                        Composite
                    </p>
                    <p className="text-2xl font-semibold text-primary">
                        {number(score.composite_score, 2)}
                    </p>
                </div>
            </div>

            <div className="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                <Metric
                    label="Quality"
                    value={number(score.component_points.quality?.total, 2)}
                    detail="55 points maximum"
                />
                <Metric
                    label="Reliability"
                    value={number(score.component_points.reliability?.total, 2)}
                    detail="25 points maximum"
                />
                <Metric
                    label="Token efficiency"
                    value={number(score.component_points.cost_efficiency, 2)}
                    detail="15 points maximum"
                />
                <Metric
                    label="Speed"
                    value={number(score.component_points.speed, 2)}
                    detail="5 points maximum"
                />
            </div>

            <dl className="mt-4 grid gap-2 text-xs sm:grid-cols-2 xl:grid-cols-4">
                <Metric
                    label="First-pass approval"
                    value={percent(score.rates.first_pass_reviewer_approval)}
                />
                <Metric
                    label="First-pass validation"
                    value={percent(
                        score.rates.first_pass_deterministic_validation,
                    )}
                />
                <Metric
                    label="No retry / block"
                    value={percent(score.rates.no_operational_retry_or_block)}
                />
                <Metric
                    label="No no-progress"
                    value={percent(score.rates.no_no_progress_retry_condition)}
                />
                <Metric
                    label="Median tokens"
                    value={tokens(score.medians.token_usage)}
                />
                <Metric
                    label="Median duration"
                    value={duration(score.medians.execution_duration_seconds)}
                />
            </dl>
        </article>
    );
}

function ReviewerConfigurationCard({
    diagnostic,
}: {
    diagnostic: ReviewerConfigurationDiagnostic;
}) {
    const changesRequired = diagnostic.rates?.changes_required;

    return (
        <article className="rounded-xl border border-border-subtle bg-card/70 p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="font-mono text-xs font-semibold text-secondary-foreground">
                        {configurationLabel(diagnostic.configuration)}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {diagnostic.sample?.valid_review_count ?? 0} valid
                        reviews · {diagnostic.review_retry_count ?? 0} review
                        retries
                    </p>
                </div>

                <Badge
                    variant="outline"
                    className="border-secondary/40 bg-secondary/10 text-secondary-foreground"
                >
                    Diagnostic only
                </Badge>
            </div>

            <div className="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                <Metric
                    label="Operational success"
                    value={percent(diagnostic.rates?.operational_success)}
                />
                <Metric
                    label="Structured output"
                    value={percent(
                        diagnostic.rates?.structured_output_validity,
                    )}
                />
                <Metric
                    label="Review retry rate"
                    value={percent(diagnostic.rates?.review_retry)}
                />
                <Metric
                    label="First-attempt review"
                    value={percent(
                        diagnostic.rates?.first_attempt_review_completion,
                    )}
                />
                <Metric
                    label="Changes required"
                    value={percent(
                        typeof changesRequired === 'object'
                            ? changesRequired.value
                            : diagnostic.rates?.changes_required_rate,
                    )}
                    detail="Diagnostic only — not a Reviewer quality score"
                />
                <Metric
                    label="Median tokens"
                    value={tokens(diagnostic.medians?.token_consumption)}
                />
                <Metric
                    label="Median duration"
                    value={duration(diagnostic.medians?.duration_seconds)}
                />
            </div>
        </article>
    );
}

export default function HarnessScorecardsIndex({
    projects,
    selected_project,
    filters,
    filter_options,
    coder_scorecard,
    reviewer_diagnostics,
    links,
}: PageProps) {
    useAppHeaderSlot(
        <div className="flex min-w-0 flex-1 items-center justify-between gap-3">
            <div className="min-w-0">
                <div className="flex items-center gap-2">
                    <Gauge className="size-4 text-primary" />
                    <h1 className="truncate text-base font-semibold text-foreground">
                        Harness Scorecards
                    </h1>
                </div>
                <p className="mt-0.5 truncate font-mono text-2xs text-muted-foreground">
                    Observe → score → recommend · routing stays manual
                </p>
            </div>
        </div>,
    );

    function visit(nextFilters: Filters) {
        const query: Record<string, string | number> = {};

        if (nextFilters.project_id !== null) {
            query.project_id = nextFilters.project_id;
        }

        query.work_type = nextFilters.work_type;
        query.complexity = nextFilters.complexity;

        if (nextFilters.role !== 'all') {
            query.role = nextFilters.role;
        }

        if (nextFilters.harness) {
            query.harness = nextFilters.harness;
        }

        if (nextFilters.model) {
            query.model = nextFilters.model;
        }

        if (nextFilters.reasoning_setting) {
            query.reasoning_setting = nextFilters.reasoning_setting;
        }

        if (nextFilters.confidence !== 'all') {
            query.confidence = nextFilters.confidence;
        }

        if (nextFilters.cohort !== 'all') {
            query.cohort = nextFilters.cohort;
        }

        router.get(links.self, query, {
            preserveScroll: true,
            preserveState: true,
        });
    }

    function updateFilter<K extends keyof Filters>(
        key: K,
        value: Filters[K],
    ) {
        const nextFilters: Filters = {
            ...filters,
            [key]: value,
        };

        if (key === 'project_id') {
            nextFilters.harness = '';
            nextFilters.model = '';
            nextFilters.reasoning_setting = '';
        }

        visit(nextFilters);
    }

    const configurationFiltersActive =
        filters.harness !== '' ||
        filters.model !== '' ||
        filters.reasoning_setting !== '';

    const recommendation = coder_scorecard?.recommendation;
    const recommendationEligible =
        recommendation?.eligible === true &&
        recommendation.leading_configuration !== null;
    const insufficientData =
        coder_scorecard?.confidence.level === 'insufficient_data';

    return (
        <>
            <Head title="Harness Scorecards" />

            <div className="relative min-h-full overflow-hidden">
                <AppBackground contained />

                <div className="relative z-10 w-full space-y-5 p-4 sm:p-6 lg:p-8">
                    <section className="panel-elevated relative overflow-hidden p-5">
                        <div className="glow-line-accent" />

                        <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                            <div>
                                <div className="flex items-center gap-2 font-mono text-2xs tracking-[0.14em] text-primary uppercase">
                                    <Database className="size-3.5" />
                                    Durable delivery evidence
                                </div>

                                <h2 className="mt-2 text-xl font-semibold tracking-tight text-foreground">
                                    Compare harness configurations fairly
                                </h2>

                                <p className="mt-1 max-w-3xl text-sm leading-6 text-muted-foreground">
                                    Scores are derived from persisted Task,
                                    validation, Review, AgentRun, token, and
                                    timing evidence. Recommendations are
                                    advisory only and never change Agent
                                    bindings or workflow routing.
                                </p>
                            </div>

                            <div className="flex flex-wrap gap-2">
                                <Badge
                                    variant="outline"
                                    className="border-success/30 bg-success/10 text-success-foreground"
                                >
                                    <ShieldCheck className="mr-1 size-3" />
                                    Read-only
                                </Badge>
                                <Badge
                                    variant="outline"
                                    className="border-primary/30 bg-primary/10 text-primary"
                                >
                                    Advisory only
                                </Badge>
                            </div>
                        </div>
                    </section>

                    {projects.length === 0 || selected_project === null ? (
                        <section className="panel-elevated p-10 text-center">
                            <Database className="mx-auto size-8 text-muted-foreground" />
                            <h2 className="mt-3 text-sm font-semibold text-foreground">
                                No project evidence available
                            </h2>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Harness scorecards require durable project
                                delivery evidence.
                            </p>
                        </section>
                    ) : (
                        <>
                            <section className="panel-elevated p-5">
                                <div className="flex items-center gap-2">
                                    <SlidersHorizontal className="size-4 text-primary" />
                                    <h2 className="text-sm font-semibold text-foreground">
                                        Cohort filters
                                    </h2>
                                </div>

                                <p className="mt-1 text-xs text-muted-foreground">
                                    Project, work type, and complexity determine
                                    the authoritative comparable cohort.
                                    Harness/model/reasoning selectors filter the
                                    displayed configuration cards; they do not
                                    recalculate or override the recommendation
                                    engine.
                                </p>

                                <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                                    <label className="grid gap-1.5 sm:col-span-2">
                                        <span className="font-mono text-2xs tracking-[0.1em] text-muted-foreground uppercase">
                                            Project / repository
                                        </span>
                                        <select
                                            value={String(
                                                filters.project_id ?? '',
                                            )}
                                            onChange={(event) =>
                                                updateFilter(
                                                    'project_id',
                                                    Number(
                                                        event.currentTarget
                                                            .value,
                                                    ),
                                                )
                                            }
                                            className="h-9 min-w-0 rounded-lg border border-border bg-background px-3 text-xs text-foreground transition outline-none focus:border-primary/50 focus:ring-2 focus:ring-primary/10"
                                        >
                                            {projects.map((project) => (
                                                <option
                                                    key={project.id}
                                                    value={project.id}
                                                >
                                                    {project.name} ·{' '}
                                                    {project.path}
                                                </option>
                                            ))}
                                        </select>
                                    </label>

                                    <FilterSelect
                                        label="Workflow role"
                                        value={filters.role}
                                        options={filter_options.roles}
                                        onChange={(value) =>
                                            updateFilter('role', value)
                                        }
                                    />

                                    <FilterSelect
                                        label="Work type"
                                        value={filters.work_type}
                                        options={filter_options.work_types}
                                        onChange={(value) =>
                                            updateFilter('work_type', value)
                                        }
                                    />

                                    <FilterSelect
                                        label="Complexity"
                                        value={filters.complexity}
                                        options={filter_options.complexities}
                                        onChange={(value) =>
                                            updateFilter('complexity', value)
                                        }
                                    />

                                    <FilterSelect
                                        label="Cohort"
                                        value={filters.cohort}
                                        options={filter_options.cohorts}
                                        onChange={(value) =>
                                            updateFilter('cohort', value)
                                        }
                                    />

                                    <FilterSelect
                                        label="Coder confidence"
                                        value={filters.confidence}
                                        options={filter_options.confidences}
                                        onChange={(value) =>
                                            updateFilter('confidence', value)
                                        }
                                    />

                                    <FilterSelect
                                        label="Harness"
                                        value={filters.harness}
                                        options={filter_options.harnesses}
                                        allLabel="All harnesses"
                                        onChange={(value) =>
                                            updateFilter('harness', value)
                                        }
                                    />

                                    <FilterSelect
                                        label="Model"
                                        value={filters.model}
                                        options={filter_options.models}
                                        allLabel="All models"
                                        onChange={(value) =>
                                            updateFilter('model', value)
                                        }
                                    />

                                    <FilterSelect
                                        label="Reasoning"
                                        value={filters.reasoning_setting}
                                        options={
                                            filter_options.reasoning_settings
                                        }
                                        allLabel="All reasoning settings"
                                        onChange={(value) =>
                                            updateFilter(
                                                'reasoning_setting',
                                                value,
                                            )
                                        }
                                    />
                                </div>
                            </section>

                            {coder_scorecard?.matches_filters && (
                                <section className="panel-elevated relative overflow-hidden p-5">
                                    <div className="glow-line-accent" />

                                    <div className="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                                        <div className="max-w-3xl">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Bot className="size-4 text-primary" />
                                                <span className="font-mono text-2xs tracking-[0.12em] text-primary uppercase">
                                                    Recommendation workflow
                                                </span>
                                                <Badge
                                                    variant="outline"
                                                    className={confidenceClass(
                                                        coder_scorecard
                                                            .confidence.level,
                                                    )}
                                                >
                                                    {humanize(
                                                        coder_scorecard
                                                            .confidence.level,
                                                    )}
                                                </Badge>
                                                <Badge
                                                    variant="outline"
                                                    className="font-mono"
                                                >
                                                    {
                                                        coder_scorecard.sample
                                                            .comparable_completed_task_count
                                                    }{' '}
                                                    comparable Tasks
                                                </Badge>
                                            </div>

                                            {recommendationEligible ? (
                                                <>
                                                    <h2 className="mt-3 text-xl font-semibold text-foreground">
                                                        Recommended
                                                        configuration
                                                    </h2>
                                                    <p className="mt-1 font-mono text-sm text-primary">
                                                        {humanize(
                                                            recommendation
                                                                ?.leading_configuration
                                                                ?.harness,
                                                        )}{' '}
                                                        /{' '}
                                                        {recommendation
                                                            ?.leading_configuration
                                                            ?.model ??
                                                            'Provider default'}{' '}
                                                        /{' '}
                                                        {humanize(
                                                            recommendation
                                                                ?.leading_configuration
                                                                ?.reasoning_setting,
                                                        )}
                                                    </p>
                                                </>
                                            ) : (
                                                <>
                                                    <h2 className="mt-3 text-xl font-semibold text-foreground">
                                                        No recommendation yet
                                                    </h2>
                                                    {insufficientData && (
                                                        <p className="mt-1 font-mono text-sm text-warning-foreground">
                                                            insufficient_data
                                                        </p>
                                                    )}
                                                </>
                                            )}

                                            <p className="mt-3 text-sm leading-6 text-muted-foreground">
                                                {recommendation?.reason ??
                                                    'The current comparable cohort does not contain enough evidence for an advisory recommendation.'}
                                            </p>

                                            <p className="mt-2 font-mono text-2xs text-muted-foreground">
                                                Score methodology version{' '}
                                                {coder_scorecard.score_version}.
                                                Eligible recommendation requires
                                                at least{' '}
                                                {
                                                    coder_scorecard.confidence
                                                        .recommendation_eligible_minimum
                                                }{' '}
                                                comparable completed Tasks.
                                            </p>

                                            {configurationFiltersActive && (
                                                <p className="mt-2 text-xs text-warning-foreground">
                                                    Configuration display
                                                    filters are active. They
                                                    change only the cards shown
                                                    below; the authoritative
                                                    recommendation remains
                                                    calculated from the complete
                                                    selected cohort.
                                                </p>
                                            )}
                                        </div>

                                        {links.agent_configuration && (
                                            <Button asChild variant="outline">
                                                <Link
                                                    href={
                                                        links.agent_configuration
                                                    }
                                                >
                                                    Configure manually
                                                    <ArrowUpRight className="size-3.5" />
                                                </Link>
                                            </Button>
                                        )}
                                    </div>
                                </section>
                            )}

                            {coder_scorecard && (
                                <section className="panel-elevated p-5">
                                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p className="font-mono text-2xs tracking-[0.14em] text-primary uppercase">
                                                Coder scorecards
                                            </p>
                                            <h2 className="mt-1 text-base font-semibold text-foreground">
                                                Quality → reliability → tokens →
                                                speed
                                            </h2>
                                        </div>

                                        <Badge
                                            variant="outline"
                                            className="font-mono"
                                        >
                                            {
                                                coder_scorecard.configuration_count_visible
                                            }{' '}
                                            /{' '}
                                            {
                                                coder_scorecard.configuration_count_total
                                            }{' '}
                                            configurations shown
                                        </Badge>
                                    </div>

                                    {!coder_scorecard.matches_filters ? (
                                        <div className="mt-4 rounded-xl border border-dashed border-border p-8 text-center">
                                            <TriangleAlert className="mx-auto size-6 text-warning-foreground" />
                                            <p className="mt-2 text-sm font-medium text-foreground">
                                                This cohort does not match the
                                                active confidence/cohort filter
                                            </p>
                                        </div>
                                    ) : (
                                        <>
                                            <div className="mt-4">
                                                <CohortHeader
                                                    cohort={
                                                        coder_scorecard.selected_cohort
                                                    }
                                                    sample={
                                                        coder_scorecard.sample
                                                            .comparable_completed_task_count
                                                    }
                                                />
                                            </div>

                                            <div className="mt-4 grid gap-3">
                                                {coder_scorecard
                                                    .configuration_scores
                                                    .length === 0 ? (
                                                    <div className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
                                                        No Coder configuration
                                                        matches the active
                                                        display filters.
                                                    </div>
                                                ) : (
                                                    coder_scorecard.configuration_scores.map(
                                                        (score) => (
                                                            <CoderConfigurationCard
                                                                key={
                                                                    score.configuration_key
                                                                }
                                                                score={score}
                                                            />
                                                        ),
                                                    )
                                                )}
                                            </div>

                                            <div className="mt-4 rounded-xl border border-primary/15 bg-primary/5 p-4">
                                                <p className="font-mono text-2xs tracking-[0.1em] text-primary uppercase">
                                                    Phase 3 cost semantics
                                                </p>
                                                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                                    Token consumption is the
                                                    Phase 3 cost measure.
                                                    Monetary provider pricing is
                                                    intentionally not presented
                                                    as historical truth.
                                                </p>
                                            </div>

                                            <div className="mt-4 grid gap-2 md:grid-cols-3">
                                                {coder_scorecard.fallback_evaluations.map(
                                                    (evaluation, index) => (
                                                        <div
                                                            key={
                                                                evaluation.fallback_key ??
                                                                index
                                                            }
                                                            className="rounded-lg border border-border-subtle bg-foreground/2 p-3"
                                                        >
                                                            <p className="font-mono text-2xs text-muted-foreground uppercase">
                                                                Fallback{' '}
                                                                {
                                                                    evaluation.fallback_level
                                                                }
                                                            </p>
                                                            <p className="mt-1 text-xs font-medium text-foreground">
                                                                {evaluation
                                                                    .cohort
                                                                    ?.human_label ??
                                                                    humanize(
                                                                        evaluation.fallback_key,
                                                                    )}
                                                            </p>
                                                            <p className="mt-1 font-mono text-2xs text-muted-foreground">
                                                                {
                                                                    evaluation
                                                                        .sample
                                                                        ?.comparable_completed_task_count
                                                                }{' '}
                                                                Tasks ·{' '}
                                                                {humanize(
                                                                    evaluation.confidence,
                                                                )}
                                                            </p>
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        </>
                                    )}
                                </section>
                            )}

                            {reviewer_diagnostics && (
                                <section className="panel-elevated p-5">
                                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p className="font-mono text-2xs tracking-[0.14em] text-secondary-foreground uppercase">
                                                Reviewer diagnostics
                                            </p>
                                            <h2 className="mt-1 text-base font-semibold text-foreground">
                                                Operational and consistency
                                                evidence
                                            </h2>
                                        </div>

                                        <div className="flex flex-wrap gap-2">
                                            <Badge
                                                variant="outline"
                                                className="border-secondary/40 bg-secondary/10 text-secondary-foreground"
                                            >
                                                Diagnostic only
                                            </Badge>
                                            <Badge
                                                variant="outline"
                                                className="font-mono"
                                            >
                                                {
                                                    reviewer_diagnostics.methodology_version
                                                }
                                            </Badge>
                                        </div>
                                    </div>

                                    {!reviewer_diagnostics.matches_filters ? (
                                        <div className="mt-4 rounded-xl border border-dashed border-border p-8 text-center">
                                            <TriangleAlert className="mx-auto size-6 text-warning-foreground" />
                                            <p className="mt-2 text-sm font-medium text-foreground">
                                                Reviewer evidence does not match
                                                the active cohort filter
                                            </p>
                                        </div>
                                    ) : (
                                        <>
                                            <div className="mt-4">
                                                <CohortHeader
                                                    cohort={
                                                        reviewer_diagnostics.selected_cohort
                                                    }
                                                    sample={
                                                        reviewer_diagnostics
                                                            .sample
                                                            .valid_review_count
                                                    }
                                                />
                                            </div>

                                            <div className="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
                                                <Metric
                                                    label="Operational success"
                                                    value={percent(
                                                        reviewer_diagnostics
                                                            .rates
                                                            .operational_success,
                                                    )}
                                                />
                                                <Metric
                                                    label="Structured validity"
                                                    value={percent(
                                                        reviewer_diagnostics
                                                            .rates
                                                            .structured_output_validity,
                                                    )}
                                                />
                                                <Metric
                                                    label="Review retry"
                                                    value={percent(
                                                        reviewer_diagnostics
                                                            .rates.review_retry,
                                                    )}
                                                />
                                                <Metric
                                                    label="First attempt"
                                                    value={percent(
                                                        reviewer_diagnostics
                                                            .rates
                                                            .first_attempt_review_completion,
                                                    )}
                                                />
                                                <Metric
                                                    label="Changes required"
                                                    value={percent(
                                                        reviewer_diagnostics
                                                            .rates
                                                            .changes_required
                                                            ?.value,
                                                    )}
                                                    detail="Diagnostic only — neither high nor low is inherently good"
                                                />
                                            </div>

                                            <div className="mt-4 grid gap-3">
                                                {reviewer_diagnostics
                                                    .configuration_diagnostics
                                                    .length === 0 ? (
                                                    <div className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
                                                        No attributed Reviewer
                                                        configuration matches
                                                        the active display
                                                        filters.
                                                    </div>
                                                ) : (
                                                    reviewer_diagnostics.configuration_diagnostics.map(
                                                        (diagnostic, index) => (
                                                            <ReviewerConfigurationCard
                                                                key={
                                                                    diagnostic.configuration_key ??
                                                                    index
                                                                }
                                                                diagnostic={
                                                                    diagnostic
                                                                }
                                                            />
                                                        ),
                                                    )
                                                )}
                                            </div>

                                            <div className="mt-4 grid gap-3 lg:grid-cols-3">
                                                <div className="rounded-xl border border-border-subtle bg-foreground/2 p-4">
                                                    <p className="font-mono text-2xs tracking-[0.1em] text-muted-foreground uppercase">
                                                        Approval consistency
                                                    </p>
                                                    <p className="mt-2 text-sm font-semibold text-foreground">
                                                        {reviewer_diagnostics
                                                            .approval_consistency
                                                            .available
                                                            ? 'Comparable evidence available'
                                                            : 'Insufficient comparable evidence'}
                                                    </p>
                                                    <p className="mt-2 text-xs leading-5 text-muted-foreground">
                                                        {reviewer_diagnostics
                                                            .approval_consistency
                                                            .interpretation ??
                                                            'No independent ground truth is inferred from approval distribution.'}
                                                    </p>
                                                    <p className="mt-2 font-mono text-2xs text-muted-foreground">
                                                        Ground truth:{' '}
                                                        {humanize(
                                                            reviewer_diagnostics
                                                                .approval_consistency
                                                                .ground_truth,
                                                        )}
                                                    </p>
                                                </div>

                                                <div className="rounded-xl border border-border-subtle bg-foreground/2 p-4">
                                                    <p className="font-mono text-2xs tracking-[0.1em] text-muted-foreground uppercase">
                                                        Codex ↔ Claude
                                                        divergence
                                                    </p>
                                                    <p className="mt-2 text-sm font-semibold text-foreground">
                                                        {reviewer_diagnostics
                                                            .codex_claude_divergence
                                                            .available
                                                            ? 'Comparable divergence observed'
                                                            : 'No comparable divergence yet'}
                                                    </p>
                                                    <div className="mt-2 grid grid-cols-2 gap-2">
                                                        <Metric
                                                            label="Approval delta"
                                                            value={percent(
                                                                reviewer_diagnostics
                                                                    .codex_claude_divergence
                                                                    .absolute_rate_delta
                                                                    ?.approval,
                                                            )}
                                                        />
                                                        <Metric
                                                            label="Change delta"
                                                            value={percent(
                                                                reviewer_diagnostics
                                                                    .codex_claude_divergence
                                                                    .absolute_rate_delta
                                                                    ?.changes_required,
                                                            )}
                                                        />
                                                    </div>
                                                    <p className="mt-2 text-xs leading-5 text-muted-foreground">
                                                        {reviewer_diagnostics
                                                            .codex_claude_divergence
                                                            .interpretation ??
                                                            'Divergence is observational and does not establish which Reviewer is correct.'}
                                                    </p>
                                                </div>

                                                <div className="rounded-xl border border-border-subtle bg-foreground/2 p-4">
                                                    <p className="font-mono text-2xs tracking-[0.1em] text-muted-foreground uppercase">
                                                        Finding follow-through
                                                    </p>
                                                    <div className="mt-2 grid grid-cols-2 gap-2">
                                                        <Metric
                                                            label="Changes required"
                                                            value={String(
                                                                reviewer_diagnostics
                                                                    .actionable_finding_follow_through
                                                                    .changes_required_review_count ??
                                                                    0,
                                                            )}
                                                        />
                                                        <Metric
                                                            label="Eventual approval"
                                                            value={percent(
                                                                reviewer_diagnostics
                                                                    .actionable_finding_follow_through
                                                                    .eventual_approval_rate,
                                                            )}
                                                        />
                                                    </div>
                                                    <p className="mt-2 text-xs leading-5 text-muted-foreground">
                                                        {reviewer_diagnostics
                                                            .actionable_finding_follow_through
                                                            .interpretation ??
                                                            'Follow-through records whether actionable changes_required evidence is followed by a corrected Coder attempt and eventual approval.'}
                                                    </p>
                                                </div>
                                            </div>

                                            <div className="mt-4 rounded-xl border border-secondary/30 bg-secondary/10 p-4">
                                                <p className="text-xs leading-5 text-muted-foreground">
                                                    Reviewer approval and
                                                    changes-required rates are
                                                    diagnostic evidence only.
                                                    They are not standalone
                                                    Reviewer quality scores, and
                                                    disagreement does not invent
                                                    independent ground truth.
                                                </p>
                                            </div>
                                        </>
                                    )}
                                </section>
                            )}
                        </>
                    )}
                </div>
            </div>
        </>
    );
}
