import { AlertTriangle, Gauge, ShieldCheck, Scissors } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

export type ContextBudgetSnapshot = {
    schema_version?: number;
    policy_version?: number;
    capacity_source?: string | null;
    capacity_source_version?: number | null;
    capacity_fallback?: boolean;
    resolved_capacity_tokens?: number | null;
    max_output_tokens?: number | null;
    target_percent?: number;
    warning_percent?: number;
    hard_ceiling_percent?: number;
    reserved_percent?: number;
    budget_tokens?: number;
    warning_tokens?: number;
    hard_ceiling_tokens?: number;
    original_estimated_tokens?: number;
    final_estimated_tokens?: number;
    required_estimated_tokens?: number;
    utilization_before?: number;
    utilization_after?: number;
    included_sources?: string[];
    reduced_sources?: string[];
    excluded_sources?: string[];
    reductions?: Array<{
        source?: string;
        before_estimated_tokens?: number;
        after_estimated_tokens?: number;
        quota_tokens?: number;
        method?: string;
    }>;
    warning_reason?: string | null;
    block_reason?: string | null;
    decision?: 'approved' | 'reduced' | 'blocked' | string;
    final_context_hash?: string;
    recovery_snapshot_reused?: boolean;
    recovery_snapshot_source_run_id?: number | null;
};

function formatNumber(value?: number | null): string {
    return typeof value === 'number' ? value.toLocaleString() : '—';
}

function humanize(value?: string | null): string {
    return value ? value.replaceAll('_', ' ') : '—';
}

function Metric({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-lg border border-border-subtle bg-foreground/[0.025] p-3">
            <p className="font-mono text-2xs tracking-[0.12em] text-muted-foreground uppercase">
                {label}
            </p>
            <p className="mt-1 text-sm font-medium text-foreground">{value}</p>
        </div>
    );
}

export function ContextBudgetEvidence({
    snapshot,
}: {
    snapshot: ContextBudgetSnapshot | null;
}) {
    if (!snapshot) {
        return null;
    }

    const reduced = snapshot.reduced_sources ?? [];
    const excluded = snapshot.excluded_sources ?? [];
    const blocked = snapshot.decision === 'blocked' || Boolean(snapshot.block_reason);

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <CardTitle className="flex items-center gap-2">
                            <Gauge className="size-4 text-primary" />
                            Context Budget
                        </CardTitle>
                        <CardDescription>
                            Immutable pre-provider capacity, utilization, and reduction evidence.
                        </CardDescription>
                    </div>
                    <Badge
                        variant="outline"
                        className={
                            blocked
                                ? 'border-destructive/30 bg-destructive/10 text-destructive-foreground'
                                : reduced.length > 0
                                  ? 'border-warning/30 bg-warning/10 text-warning-foreground'
                                  : 'border-success/30 bg-success/10 text-success-foreground'
                        }
                    >
                        {humanize(snapshot.decision)}
                    </Badge>
                </div>
            </CardHeader>

            <CardContent className="space-y-3">
                <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <Metric
                        label="Capacity"
                        value={`${formatNumber(snapshot.resolved_capacity_tokens)} tokens`}
                    />
                    <Metric
                        label="Target / Warning / Hard"
                        value={`${snapshot.target_percent ?? '—'}% / ${snapshot.warning_percent ?? '—'}% / ${snapshot.hard_ceiling_percent ?? '—'}%`}
                    />
                    <Metric
                        label="Estimated tokens"
                        value={`${formatNumber(snapshot.original_estimated_tokens)} → ${formatNumber(snapshot.final_estimated_tokens)}`}
                    />
                    <Metric
                        label="Utilization"
                        value={`${snapshot.utilization_before ?? '—'}% → ${snapshot.utilization_after ?? '—'}%`}
                    />
                </div>

                <div className="grid gap-2 md:grid-cols-2">
                    <div className="rounded-lg border border-border-subtle bg-foreground/[0.025] p-3">
                        <div className="flex items-center gap-2 text-xs font-medium text-foreground">
                            <ShieldCheck className="size-4 text-primary" />
                            Capacity evidence
                        </div>
                        <dl className="mt-3 grid gap-2 font-mono text-2xs">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Source</dt>
                                <dd className="truncate text-right text-foreground">
                                    {snapshot.capacity_source ?? '—'}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Metadata version</dt>
                                <dd className="text-foreground">
                                    {snapshot.capacity_source_version ?? '—'}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Policy version</dt>
                                <dd className="text-foreground">
                                    {snapshot.policy_version ?? '—'}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Required estimate</dt>
                                <dd className="text-foreground">
                                    {formatNumber(snapshot.required_estimated_tokens)}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Reserved capacity</dt>
                                <dd className="text-foreground">
                                    {snapshot.reserved_percent ?? '—'}%
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <div className="rounded-lg border border-border-subtle bg-foreground/[0.025] p-3">
                        <div className="flex items-center gap-2 text-xs font-medium text-foreground">
                            <Scissors className="size-4 text-secondary-foreground" />
                            Reduction evidence
                        </div>
                        <div className="mt-3 space-y-2 text-xs">
                            <p className="text-muted-foreground">
                                Reduced:{' '}
                                <span className="text-foreground">
                                    {reduced.length > 0
                                        ? reduced.map(humanize).join(', ')
                                        : 'none'}
                                </span>
                            </p>
                            <p className="text-muted-foreground">
                                Excluded:{' '}
                                <span className="text-foreground">
                                    {excluded.length > 0
                                        ? excluded.map(humanize).join(', ')
                                        : 'none'}
                                </span>
                            </p>
                            <p className="text-muted-foreground">
                                Recovery snapshot:{' '}
                                <span className="text-foreground">
                                    {snapshot.recovery_snapshot_reused
                                        ? `reused from run #${snapshot.recovery_snapshot_source_run_id ?? '—'}`
                                        : 'current policy'}
                                </span>
                            </p>
                        </div>
                    </div>
                </div>

                {(snapshot.warning_reason || snapshot.block_reason) && (
                    <div
                        className={`flex items-start gap-2 rounded-lg border p-3 text-xs ${
                            snapshot.block_reason
                                ? 'border-destructive/30 bg-destructive/10 text-destructive-foreground'
                                : 'border-warning/30 bg-warning/10 text-warning-foreground'
                        }`}
                    >
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        <div>
                            {snapshot.block_reason && (
                                <p>
                                    Block: {humanize(snapshot.block_reason)}
                                </p>
                            )}
                            {snapshot.warning_reason && (
                                <p>
                                    Warning: {humanize(snapshot.warning_reason)}
                                </p>
                            )}
                        </div>
                    </div>
                )}

                {snapshot.final_context_hash && (
                    <p className="break-all font-mono text-2xs text-muted-foreground">
                        final context hash: {snapshot.final_context_hash}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

