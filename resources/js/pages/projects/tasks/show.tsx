import { Form, Head, Link, usePoll } from '@inertiajs/react';
import {
    Activity,
    ArrowLeft,
    Ban,
    Bot,
    Braces,
    CheckCircle2,
    Clock3,
    FileCode2,
    GitBranch,
    GitCommit,
    ListChecks,
    MessageSquare,
    Radio,
    Send,
    ShieldCheck,
    Sparkles,
    Terminal,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import {
    show as showProject,
    showAgentRun,
    requeueTask,
    skipTask,
    storeOperatorMessage,
} from '@/actions/App/Http/Controllers/ProjectController';
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
    dependents: { id: number; key: string; title: string; status: string }[];
    attempts: Attempt[];
    reviews: Review[];
    runs: Run[];
    handoffs: Handoff[];
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
    changed_files: string[] | null;
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
    agent_messages: string[];
    live_output: string | null;
    transcript: string | null;
    exit_code: number | null;
    started_at: string | null;
    finished_at: string | null;
};
type HandoffSourceRun = {
    id: number;
    role: string;
    status: string;
    attempt_number: number | null;
    started_at: string | null;
    finished_at: string | null;
};
type Handoff = {
    id: number;
    from_role: string;
    to_role: string;
    handoff_type: string;
    schema_version: number;
    payload: unknown;
    content_hash: string;
    status: string;
    created_at: string;
    consumed_at: string | null;
    source_run: HandoffSourceRun | null;
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
type AgentOutputEntry = {
    isAgentMessage: boolean;
    label?: string;
    labelClassName?: string;
    message: string;
    className: string;
};
type ValidationCheck = {
    key: string;
    label: string;
    passed: boolean | null;
};

function humanize(value: string): string {
    return value.replaceAll('_', ' ');
}

function titleize(value: string): string {
    return humanize(value).replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function formatDateTime(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

function formatDuration(run: Run | undefined): string {
    if (!run?.started_at) {
        return '—';
    }

    const startedAt = new Date(run.started_at).getTime();
    const finishedAt = run.finished_at
        ? new Date(run.finished_at).getTime()
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

function shortSha(value: string | null): string {
    return value ? value.slice(0, 7) : '—';
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/**
 * Return one non-empty string from structured handoff evidence.
 */
function evidenceText(
    payload: Record<string, unknown>,
    key: string,
): string | null {
    const value = payload[key];

    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

/**
 * Return only string members from one structured handoff evidence list.
 */
function evidenceList(payload: Record<string, unknown>, key: string): string[] {
    const value = payload[key];

    if (!Array.isArray(value)) {
        return [];
    }

    return value.filter(
        (item): item is string =>
            typeof item === 'string' && item.trim() !== '',
    );
}

/**
 * Return only object members from one structured handoff evidence list.
 */
function evidenceRecords(
    payload: Record<string, unknown>,
    key: string,
): Record<string, unknown>[] {
    const value = payload[key];

    return Array.isArray(value) ? value.filter(isRecord) : [];
}

function validationChecks(value: unknown): ValidationCheck[] {
    if (!isRecord(value) || !isRecord(value.checks)) {
        return [];
    }

    return Object.entries(value.checks).map(([key, check]) => ({
        key,
        label: titleize(key),
        passed: typeof check === 'boolean' ? check : null,
    }));
}

function validationPassed(value: unknown): boolean | null {
    if (!isRecord(value) || typeof value.passed !== 'boolean') {
        return null;
    }

    return value.passed;
}

function statusBadgeClasses(status: string): string {
    if (
        [
            'done',
            'completed',
            'approved',
            'ready_for_review',
            'consumed',
        ].includes(status)
    ) {
        return 'border-success/30 bg-success/10 text-success-foreground';
    }

    if (['coding', 'validating', 'reviewing', 'running'].includes(status)) {
        return 'status-glow-pulse border-primary/30 bg-primary/10 text-primary';
    }

    if (
        ['changes_required', 'blocked', 'interrupted', 'pending'].includes(
            status,
        )
    ) {
        return 'border-warning/30 bg-warning/10 text-warning-foreground';
    }

    if (['failed', 'cancelled'].includes(status)) {
        return 'border-destructive/30 bg-destructive/10 text-destructive-foreground';
    }

    return 'border-border bg-card text-muted-foreground';
}

function StatusBadge({ status }: { status: string }) {
    return (
        <Badge
            variant="outline"
            className={`font-mono text-2xs tracking-[0.08em] uppercase ${statusBadgeClasses(
                status,
            )}`}
        >
            {humanize(status)}
        </Badge>
    );
}

function DisplayList({ items }: { items: string[] | null }) {
    if (!items || items.length === 0) {
        return <p className="text-xs text-muted-foreground">None recorded.</p>;
    }

    return (
        <ul className="grid gap-1.5 text-sm text-foreground/90">
            {items.map((item) => (
                <li key={item} className="flex items-start gap-2">
                    <CheckCircle2 className="mt-0.5 size-3.5 shrink-0 text-primary" />
                    <span className="leading-5">{item}</span>
                </li>
            ))}
        </ul>
    );
}

function JsonDetail({ value }: { value: unknown }) {
    if (
        !value ||
        (Array.isArray(value) && value.length === 0) ||
        (isRecord(value) && Object.keys(value).length === 0)
    ) {
        return <p className="text-xs text-muted-foreground">None recorded.</p>;
    }

    return (
        <pre className="panel-recessed max-h-72 overflow-auto p-3 font-mono text-2xs leading-5 whitespace-pre-wrap text-foreground/80">
            {JSON.stringify(value, null, 2)}
        </pre>
    );
}

function DetailBlock({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-2 border-t border-border-subtle pt-4 first:border-t-0 first:pt-0">
            <h3 className="font-mono text-2xs tracking-[0.1em] text-muted-foreground uppercase">
                {label}
            </h3>
            {children}
        </div>
    );
}

function MetricTile({
    label,
    value,
    icon,
    accent = 'primary',
}: {
    label: string;
    value: string;
    icon: ReactNode;
    accent?: 'primary' | 'secondary' | 'success';
}) {
    const accentClass =
        accent === 'success'
            ? 'border-success/15 bg-success/5 text-success-foreground'
            : accent === 'secondary'
              ? 'border-secondary-foreground/15 bg-secondary/10 text-secondary-foreground'
              : 'border-primary/15 bg-primary/5 text-primary';

    return (
        <div className="flex min-w-0 items-center gap-3 px-3 py-3.5">
            <div
                className={`grid size-8 shrink-0 place-items-center rounded-lg border ${accentClass}`}
            >
                {icon}
            </div>
            <div className="min-w-0">
                <p className="font-mono text-2xs tracking-[0.08em] text-muted-foreground uppercase">
                    {label}
                </p>
                <p
                    className="mt-0.5 truncate text-sm font-semibold text-foreground"
                    title={value}
                >
                    {value}
                </p>
            </div>
        </div>
    );
}

function ValidationSummary({
    value,
    changedFiles,
}: {
    value: unknown;
    changedFiles: string[] | null;
}) {
    const checks = validationChecks(value);
    const passed = validationPassed(value);

    return (
        <div className="grid gap-3">
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <div className="tile-inset px-2.5 py-2">
                    <p className="font-mono text-2xs text-muted-foreground uppercase">
                        Result
                    </p>
                    <p
                        className={`mt-1 text-sm font-semibold ${
                            passed === true
                                ? 'text-success-foreground'
                                : passed === false
                                  ? 'text-destructive-foreground'
                                  : 'text-muted-foreground'
                        }`}
                    >
                        {passed === null
                            ? 'Recorded'
                            : passed
                              ? 'Passed'
                              : 'Failed'}
                    </p>
                </div>
                <div className="tile-inset px-2.5 py-2">
                    <p className="font-mono text-2xs text-muted-foreground uppercase">
                        Checks
                    </p>
                    <p className="mt-1 text-sm font-semibold text-foreground">
                        {checks.length || '—'}
                    </p>
                </div>
                <div className="tile-inset px-2.5 py-2">
                    <p className="font-mono text-2xs text-muted-foreground uppercase">
                        Passed
                    </p>
                    <p className="mt-1 text-sm font-semibold text-success-foreground">
                        {checks.filter((check) => check.passed === true).length}
                    </p>
                </div>
                <div className="tile-inset px-2.5 py-2">
                    <p className="font-mono text-2xs text-muted-foreground uppercase">
                        Changed files
                    </p>
                    <p className="mt-1 text-sm font-semibold text-foreground">
                        {changedFiles?.length ?? 0}
                    </p>
                </div>
            </div>

            {checks.length > 0 && (
                <div className="grid gap-1.5 sm:grid-cols-2">
                    {checks.map((check) => (
                        <div
                            key={check.key}
                            className="flex items-center justify-between gap-3 rounded-md border border-border-subtle bg-foreground/[0.025] px-2.5 py-2"
                        >
                            <span className="min-w-0 truncate text-xs text-foreground/80">
                                {check.label}
                            </span>
                            <span
                                className={`font-mono text-2xs uppercase ${
                                    check.passed === true
                                        ? 'text-success-foreground'
                                        : check.passed === false
                                          ? 'text-destructive-foreground'
                                          : 'text-muted-foreground'
                                }`}
                            >
                                {check.passed === null
                                    ? 'recorded'
                                    : check.passed
                                      ? 'passed'
                                      : 'failed'}
                            </span>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

function RunEvidenceGateway({
    project,
    run,
}: {
    project: Project;
    run: Run | undefined;
}) {
    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle className="flex items-center gap-2">
                        <ShieldCheck className="size-4 text-secondary-foreground" />
                        Run evidence
                    </CardTitle>
                    {run && <StatusBadge status={run.status} />}
                </div>
                <CardDescription>
                    Runtime summary here; immutable Agent, harness, model,
                    Skill, and context evidence stays on the dedicated Agent Run
                    view.
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-3">
                {!run ? (
                    <p className="text-sm text-muted-foreground">
                        No agent run has started for this task.
                    </p>
                ) : (
                    <>
                        <dl className="grid grid-cols-2 gap-2 xl:grid-cols-1 2xl:grid-cols-2">
                            <div className="tile-inset px-2.5 py-2">
                                <dt className="font-mono text-2xs text-muted-foreground uppercase">
                                    Role
                                </dt>
                                <dd className="mt-1 text-sm font-medium text-foreground capitalize">
                                    {humanize(run.role)}
                                </dd>
                            </div>
                            <div className="tile-inset px-2.5 py-2">
                                <dt className="font-mono text-2xs text-muted-foreground uppercase">
                                    Attempt
                                </dt>
                                <dd className="mt-1 text-sm font-medium text-foreground">
                                    {run.attempt_number ?? '—'}
                                </dd>
                            </div>
                            <div className="tile-inset px-2.5 py-2">
                                <dt className="font-mono text-2xs text-muted-foreground uppercase">
                                    Exit code
                                </dt>
                                <dd className="mt-1 text-sm font-medium text-foreground">
                                    {run.exit_code ?? '—'}
                                </dd>
                            </div>
                            <div className="tile-inset px-2.5 py-2">
                                <dt className="font-mono text-2xs text-muted-foreground uppercase">
                                    Duration
                                </dt>
                                <dd className="mt-1 text-sm font-medium text-foreground">
                                    {formatDuration(run)}
                                </dd>
                            </div>
                        </dl>

                        <Link
                            href={showAgentRun({
                                project: project.id,
                                run: run.id,
                            })}
                            className="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-secondary-foreground/20 bg-secondary/10 px-3 text-xs font-medium text-secondary-foreground transition hover:border-secondary-foreground/35 hover:bg-secondary/15"
                        >
                            <ShieldCheck className="size-3.5" />
                            Inspect immutable run evidence
                        </Link>
                    </>
                )}
            </CardContent>
        </Card>
    );
}
/**
 * Render one labeled text field from durable handoff evidence.
 */
function HandoffEvidenceText({
    label,
    value,
    mono = false,
}: {
    label: string;
    value: string | null;
    mono?: boolean;
}) {
    if (!value) {
        return null;
    }

    return (
        <div className="grid gap-1">
            <p className="font-mono text-2xs tracking-[0.08em] text-muted-foreground uppercase">
                {label}
            </p>
            <p
                className={
                    mono
                        ? 'font-mono text-2xs leading-5 break-all text-foreground/85'
                        : 'text-xs leading-5 break-words whitespace-pre-wrap text-foreground/90'
                }
            >
                {value}
            </p>
        </div>
    );
}

/**
 * Render one bounded string list from durable handoff evidence.
 */
function HandoffEvidenceList({
    label,
    items,
    mono = false,
}: {
    label: string;
    items: string[];
    mono?: boolean;
}) {
    if (items.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-1.5">
            <p className="font-mono text-2xs tracking-[0.08em] text-muted-foreground uppercase">
                {label}
            </p>
            <ul className="grid gap-1.5">
                {items.map((item, index) => (
                    <li
                        key={`${label}-${index}`}
                        className="flex min-w-0 items-start gap-2"
                    >
                        <span
                            aria-hidden="true"
                            className="mt-1.5 size-1.5 shrink-0 rounded-full bg-primary"
                        />
                        <span
                            className={
                                mono
                                    ? 'min-w-0 font-mono text-2xs leading-5 break-all text-foreground/85'
                                    : 'min-w-0 text-xs leading-5 break-words text-foreground/90'
                            }
                        >
                            {item}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * Render only the approved schema fields for one typed durable handoff.
 */
function HandoffPayloadEvidence({ handoff }: { handoff: Handoff }) {
    const payload = isRecord(handoff.payload) ? handoff.payload : {};

    if (handoff.handoff_type === 'implementation_handoff') {
        return (
            <div className="grid gap-3">
                <HandoffEvidenceText
                    label="Summary"
                    value={evidenceText(payload, 'summary')}
                />
                <HandoffEvidenceList
                    label="Changed files"
                    items={evidenceList(payload, 'changed_files')}
                    mono
                />
                <HandoffEvidenceList
                    label="Tests added or updated"
                    items={evidenceList(payload, 'tests_added_or_updated')}
                    mono
                />
                <HandoffEvidenceList
                    label="Verification attempts"
                    items={evidenceList(payload, 'verification_attempts')}
                />
                <HandoffEvidenceList
                    label="Blockers"
                    items={evidenceList(payload, 'blockers')}
                />
            </div>
        );
    }

    if (handoff.handoff_type === 'review_request') {
        return (
            <div className="grid gap-3">
                <HandoffEvidenceText
                    label="Summary"
                    value={evidenceText(payload, 'summary')}
                />
                <HandoffEvidenceList
                    label="Focus areas"
                    items={evidenceList(payload, 'focus_areas')}
                />
            </div>
        );
    }

    if (handoff.handoff_type === 'review_finding') {
        const findings = evidenceRecords(payload, 'findings');

        return (
            <div className="grid gap-3">
                <HandoffEvidenceText
                    label="Summary"
                    value={evidenceText(payload, 'summary')}
                />

                {findings.length === 0 ? (
                    <p className="text-xs text-muted-foreground">
                        No structured findings were recorded.
                    </p>
                ) : (
                    <div className="grid gap-2">
                        {findings.map((finding, index) => (
                            <div
                                key={`handoff-finding-${index}`}
                                className="rounded-lg border border-warning/20 bg-warning/[0.035] p-3"
                            >
                                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                    <p className="font-mono text-2xs text-muted-foreground uppercase">
                                        Finding {index + 1}
                                    </p>
                                    {evidenceText(finding, 'severity') && (
                                        <Badge
                                            variant="outline"
                                            className="border-warning/30 bg-warning/10 font-mono text-2xs text-warning-foreground"
                                        >
                                            {evidenceText(finding, 'severity')}
                                        </Badge>
                                    )}
                                </div>

                                <div className="grid gap-3">
                                    <HandoffEvidenceText
                                        label="Location"
                                        value={evidenceText(
                                            finding,
                                            'location',
                                        )}
                                        mono
                                    />
                                    <HandoffEvidenceText
                                        label="Current implementation"
                                        value={evidenceText(
                                            finding,
                                            'current_implementation',
                                        )}
                                    />
                                    <HandoffEvidenceText
                                        label="Expected implementation"
                                        value={evidenceText(
                                            finding,
                                            'expected_implementation',
                                        )}
                                    />
                                    <HandoffEvidenceText
                                        label="Why incorrect"
                                        value={evidenceText(
                                            finding,
                                            'why_incorrect',
                                        )}
                                    />
                                    <HandoffEvidenceText
                                        label="Required fix"
                                        value={evidenceText(
                                            finding,
                                            'required_fix',
                                        )}
                                    />
                                    <HandoffEvidenceText
                                        label="Verification requirement"
                                        value={evidenceText(
                                            finding,
                                            'verification_requirement',
                                        )}
                                    />
                                    <HandoffEvidenceText
                                        label="Implementation fix context"
                                        value={evidenceText(
                                            finding,
                                            'implementation_fix_context',
                                        )}
                                    />
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        );
    }

    if (handoff.handoff_type === 'context_request') {
        return (
            <div className="grid gap-3">
                <HandoffEvidenceText
                    label="Request"
                    value={evidenceText(payload, 'request')}
                />
                <HandoffEvidenceList
                    label="Requested evidence"
                    items={evidenceList(payload, 'requested_evidence')}
                />
                <HandoffEvidenceText
                    label="Reason"
                    value={evidenceText(payload, 'reason')}
                />
            </div>
        );
    }

    if (handoff.handoff_type === 'recovery_advice') {
        return (
            <div className="grid gap-3">
                <HandoffEvidenceText
                    label="Summary"
                    value={evidenceText(payload, 'summary')}
                />
                <HandoffEvidenceText
                    label="Root cause category"
                    value={evidenceText(payload, 'root_cause_category')}
                    mono
                />
                <HandoffEvidenceText
                    label="Recommended focus"
                    value={evidenceText(payload, 'recommended_focus')}
                />
                <HandoffEvidenceList
                    label="Changed files"
                    items={evidenceList(payload, 'changed_files')}
                    mono
                />
                <HandoffEvidenceText
                    label="Escalation reason"
                    value={evidenceText(payload, 'escalation_reason')}
                />
            </div>
        );
    }

    if (handoff.handoff_type === 'knowledge_reference') {
        return (
            <div className="grid gap-3">
                <HandoffEvidenceText
                    label="Evidence summary"
                    value={evidenceText(payload, 'evidence_summary')}
                />
                <HandoffEvidenceText
                    label="Proposed change"
                    value={evidenceText(payload, 'proposed_change')}
                />
                <HandoffEvidenceText
                    label="Confidence"
                    value={evidenceText(payload, 'confidence')}
                    mono
                />
                <HandoffEvidenceList
                    label="References"
                    items={evidenceList(payload, 'references')}
                    mono
                />
            </div>
        );
    }

    return <JsonDetail value={handoff.payload} />;
}

/**
 * Render typed Agent collaboration as read-only workflow evidence.
 */
function HandoffEvidenceCard({
    project,
    handoffs,
}: {
    project: Project;
    handoffs: Handoff[];
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <GitBranch className="size-4 text-primary" />
                    Handoff evidence
                </CardTitle>
                <CardDescription>
                    Typed AIOS-mediated workflow artifacts. These records are
                    read-only evidence, not an Agent conversation.
                </CardDescription>
            </CardHeader>

            <CardContent>
                {handoffs.length === 0 ? (
                    <p className="text-xs text-muted-foreground">
                        No Agent handoffs recorded for this task.
                    </p>
                ) : (
                    <ol className="grid gap-2">
                        {handoffs.map((handoff) => (
                            <li key={handoff.id}>
                                <article className="panel-recessed overflow-hidden">
                                    <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border-subtle p-3">
                                        <div className="min-w-0">
                                            <p className="text-xs font-semibold text-foreground">
                                                {titleize(handoff.from_role)}
                                                <span
                                                    aria-hidden="true"
                                                    className="px-1.5 text-primary"
                                                >
                                                    →
                                                </span>
                                                {titleize(handoff.to_role)}
                                            </p>
                                            <p className="mt-1 font-mono text-2xs text-muted-foreground">
                                                {titleize(handoff.handoff_type)}{' '}
                                                · schema v
                                                {handoff.schema_version}
                                            </p>
                                        </div>

                                        <StatusBadge status={handoff.status} />
                                    </div>

                                    <div className="grid gap-3 p-3">
                                        <HandoffPayloadEvidence
                                            handoff={handoff}
                                        />

                                        <div className="grid gap-2 border-t border-border-subtle pt-3">
                                            <div className="flex flex-wrap gap-x-3 gap-y-1 font-mono text-2xs text-muted-foreground">
                                                <span>
                                                    Created{' '}
                                                    {formatDateTime(
                                                        handoff.created_at,
                                                    )}
                                                </span>
                                                {handoff.consumed_at && (
                                                    <span className="text-success-foreground">
                                                        Consumed{' '}
                                                        {formatDateTime(
                                                            handoff.consumed_at,
                                                        )}
                                                    </span>
                                                )}
                                            </div>

                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <span
                                                    title={handoff.content_hash}
                                                    className="font-mono text-2xs text-muted-foreground"
                                                >
                                                    Hash{' '}
                                                    {handoff.content_hash.slice(
                                                        0,
                                                        12,
                                                    )}
                                                </span>

                                                {handoff.source_run ? (
                                                    <Link
                                                        href={showAgentRun({
                                                            project: project.id,
                                                            run: handoff
                                                                .source_run.id,
                                                        })}
                                                        aria-label={`Inspect source run #${handoff.source_run.id}`}
                                                        className="font-mono text-2xs text-primary transition hover:text-primary/80"
                                                    >
                                                        Run #
                                                        {handoff.source_run.id}{' '}
                                                        ·{' '}
                                                        {titleize(
                                                            handoff.source_run
                                                                .role,
                                                        )}{' '}
                                                        · attempt{' '}
                                                        {handoff.source_run
                                                            .attempt_number ??
                                                            '—'}{' '}
                                                        →
                                                    </Link>
                                                ) : (
                                                    <span className="font-mono text-2xs text-muted-foreground">
                                                        Source run unavailable
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                </article>
                            </li>
                        ))}
                    </ol>
                )}
            </CardContent>
        </Card>
    );
}

function ReviewSummary({ review }: { review: Review | undefined }) {
    if (!review) {
        return (
            <Card className="overflow-hidden border-secondary-foreground/15 bg-secondary/[0.055]">
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <ShieldCheck className="size-4 text-secondary-foreground" />
                        Review summary
                    </CardTitle>
                    <CardDescription>
                        No reviewer verdict has been recorded for this task yet.
                    </CardDescription>
                </CardHeader>
            </Card>
        );
    }

    return (
        <Card className="overflow-hidden border-secondary-foreground/20 bg-secondary/[0.07] shadow-glow-secondary">
            <div className="glow-line-secondary" />
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <ShieldCheck className="size-4 text-secondary-foreground" />
                            Review summary
                        </CardTitle>
                        <CardDescription className="mt-1">
                            Independent reviewer outcome for the latest recorded
                            review.
                        </CardDescription>
                    </div>
                    <StatusBadge status={review.status} />
                </div>
            </CardHeader>
            <CardContent className="grid gap-4 lg:grid-cols-[14rem_minmax(0,1fr)]">
                <dl className="grid content-start gap-2">
                    <div className="tile-inset px-3 py-2.5">
                        <dt className="font-mono text-2xs text-muted-foreground uppercase">
                            Attempt reviewed
                        </dt>
                        <dd className="mt-1 text-sm font-semibold text-foreground">
                            {review.attempt
                                ? `Attempt ${review.attempt.number}`
                                : 'Not recorded'}
                        </dd>
                    </div>
                    <div className="tile-inset px-3 py-2.5">
                        <dt className="font-mono text-2xs text-muted-foreground uppercase">
                            Findings
                        </dt>
                        <dd className="mt-1 text-sm font-semibold text-foreground">
                            {review.findings.length}
                        </dd>
                    </div>
                    <div className="tile-inset px-3 py-2.5">
                        <dt className="font-mono text-2xs text-muted-foreground uppercase">
                            Reviewed at
                        </dt>
                        <dd className="mt-1 font-mono text-2xs leading-5 text-foreground">
                            {formatDateTime(review.completed_at)}
                        </dd>
                    </div>
                    <div className="tile-inset px-3 py-2.5">
                        <dt className="font-mono text-2xs text-muted-foreground uppercase">
                            Risk / confidence
                        </dt>
                        <dd className="mt-1 text-xs text-muted-foreground">
                            Not recorded by the current review contract.
                        </dd>
                    </div>
                </dl>

                <div className="grid gap-3">
                    <div className="panel-recessed p-4">
                        <p className="font-mono text-2xs tracking-[0.1em] text-secondary-foreground uppercase">
                            Review conclusion
                        </p>
                        <p className="mt-2 text-sm leading-6 whitespace-pre-wrap text-foreground/90">
                            {review.summary ??
                                'No review summary was recorded.'}
                        </p>
                    </div>

                    {review.findings.length > 0 && (
                        <div className="grid gap-2">
                            {review.findings.map((finding) => (
                                <article
                                    key={finding.id}
                                    className="rounded-lg border border-warning/20 bg-warning/[0.035] p-3"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge
                                            variant="outline"
                                            className="border-warning/30 bg-warning/10 font-mono text-2xs text-warning-foreground"
                                        >
                                            {finding.severity}
                                        </Badge>
                                        {finding.location && (
                                            <span className="font-mono text-2xs break-all text-muted-foreground">
                                                {finding.location}
                                            </span>
                                        )}
                                    </div>
                                    <p className="mt-2 text-sm leading-6 text-foreground/85">
                                        {finding.required_fix}
                                    </p>
                                    <p className="mt-2 font-mono text-2xs leading-5 text-muted-foreground">
                                        Verify:{' '}
                                        {finding.verification_requirement}
                                    </p>
                                </article>
                            ))}
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

function formatAgentOutput(
    output: string | null,
    agentRole: string,
): AgentOutputEntry[] {
    if (!output) {
        return [
            {
                isAgentMessage: false,
                message: 'No output has reached AIOS yet.',
                className: 'text-muted-foreground',
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
                    labelClassName: 'text-destructive',
                    message: line.slice(9),
                    className: 'text-destructive-foreground',
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
                        labelClassName: 'text-success',
                        message: item.text,
                        className: 'text-success-foreground',
                    };
                }

                if (item?.type === 'reasoning' && item.text) {
                    return {
                        isAgentMessage: false,
                        label: 'thinking>',
                        labelClassName: 'text-primary',
                        message: item.text,
                        className: 'text-primary/80',
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
                        className: 'text-warning-foreground',
                    };
                }

                if (event.type === 'error' && event.message) {
                    return {
                        isAgentMessage: false,
                        label: 'error>',
                        labelClassName: 'text-destructive',
                        message: event.message,
                        className: 'text-destructive-foreground',
                    };
                }
            } catch {
                return {
                    isAgentMessage: false,
                    message: line,
                    className: 'text-foreground',
                };
            }

            return {
                isAgentMessage: false,
                message: line,
                className: 'text-foreground',
            };
        })
        .filter((entry) => entry.message !== '');
}

function formatNormalizedAgentMessages(
    messages: string[],
    agentRole: string,
): AgentOutputEntry[] {
    return messages.map((message) => ({
        isAgentMessage: true,
        label: `${agentRole.replace('_', ' ')}>`,
        labelClassName: 'text-success',
        message,
        className: 'text-success-foreground',
    }));
}

function AgentConsoleOutput({
    output,
    agentMessages,
    agentRole,
    showTechnicalOutput,
}: {
    output: string | null;
    agentMessages: string[];
    agentRole: string;
    showTechnicalOutput: boolean;
}) {
    const entries = showTechnicalOutput
        ? formatAgentOutput(output, agentRole)
        : formatNormalizedAgentMessages(agentMessages, agentRole);

    if (entries.length === 0) {
        return (
            <span className="text-muted-foreground">
                Agent messages are not present in the captured output. Use
                technical output to inspect the execution transcript.
            </span>
        );
    }

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
    recovery_escalation_reason: recoveryEscalationReason,
}: {
    project: Project;
    task: Task;
    recovery_escalation_reason: string | null;
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
    const latestRun = task.runs[0];
    const latestAttempt = task.attempts[0];
    const latestReview = task.reviews[0];
    const latestActivity = task.audit_events[0];
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
                        <h1 className="truncate text-base font-semibold text-foreground">
                            {task.title}
                        </h1>
                        <span className="hidden font-mono text-2xs tracking-[0.14em] text-primary uppercase sm:inline">
                            {task.key}
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
            <Head title={`${task.key}: ${task.title}`} />
            <div className="dark relative min-h-full w-full overflow-hidden bg-background text-foreground">
                <div className="pointer-events-none absolute inset-0 bg-[linear-gradient(color-mix(in_oklch,var(--primary)_4%,transparent)_1px,transparent_1px),linear-gradient(90deg,color-mix(in_oklch,var(--primary)_4%,transparent)_1px,transparent_1px)] bg-size-[32px_32px]" />
                <div className="pointer-events-none absolute -top-40 left-1/4 size-96 rounded-full bg-primary/8 blur-3xl" />
                <div className="pointer-events-none absolute right-0 bottom-0 size-96 rounded-full bg-secondary/20 blur-3xl" />

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
                                    <span>tasks</span>
                                    <span>/</span>
                                    <span className="text-primary">
                                        {task.key}
                                    </span>
                                </div>
                                <div className="mt-3 flex min-w-0 items-center gap-3">
                                    <div className="grid size-10 shrink-0 place-items-center rounded-xl border border-primary/20 bg-primary/10 text-primary shadow-glow-sm">
                                        <Sparkles className="size-5" />
                                    </div>
                                    <div className="min-w-0">
                                        <p className="font-mono text-2xs tracking-[0.12em] text-primary uppercase">
                                            {task.key}
                                        </p>
                                        <h2 className="mt-0.5 truncate text-xl font-semibold tracking-tight text-foreground md:text-2xl">
                                            {task.title}
                                        </h2>
                                        <p className="mt-1 truncate text-xs text-muted-foreground">
                                            {task.phase
                                                ? `Phase: ${task.phase.title}`
                                                : 'No phase assigned'}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div className="flex flex-col items-end gap-1.5">
                                <StatusBadge status={task.status} />
                                <p className="font-mono text-2xs text-muted-foreground">
                                    {latestActivity
                                        ? `Last activity ${formatDateTime(
                                              latestActivity.occurred_at,
                                          )}`
                                        : 'No activity recorded'}
                                </p>
                            </div>
                        </div>
                    </header>

                    <section className="panel-elevated relative overflow-hidden">
                        <div className="glow-line-accent" />
                        <div className="grid grid-cols-2 divide-x divide-y divide-border-subtle sm:grid-cols-4 xl:grid-cols-8 xl:divide-y-0">
                            <MetricTile
                                label="Task status"
                                value={titleize(task.status)}
                                icon={<Activity className="size-4" />}
                                accent={
                                    task.status === 'done'
                                        ? 'success'
                                        : 'primary'
                                }
                            />
                            <MetricTile
                                label="Duration"
                                value={formatDuration(latestRun)}
                                icon={<Clock3 className="size-4" />}
                            />
                            <MetricTile
                                label="Latest run"
                                value={
                                    latestRun
                                        ? titleize(latestRun.status)
                                        : 'Not started'
                                }
                                icon={<Radio className="size-4" />}
                            />
                            <MetricTile
                                label="Base SHA"
                                value={shortSha(
                                    latestAttempt?.base_sha ?? null,
                                )}
                                icon={<GitBranch className="size-4" />}
                            />
                            <MetricTile
                                label="Head SHA"
                                value={shortSha(
                                    latestAttempt?.head_sha ?? null,
                                )}
                                icon={<GitCommit className="size-4" />}
                            />
                            <MetricTile
                                label="Reviewer"
                                value={
                                    latestReview
                                        ? titleize(latestReview.status)
                                        : 'Pending'
                                }
                                icon={<ShieldCheck className="size-4" />}
                                accent={
                                    latestReview?.status === 'approved'
                                        ? 'success'
                                        : 'secondary'
                                }
                            />
                            <MetricTile
                                label="Changed files"
                                value={(
                                    latestAttempt?.changed_files?.length ?? 0
                                ).toString()}
                                icon={<FileCode2 className="size-4" />}
                                accent="secondary"
                            />
                            <MetricTile
                                label="Runs"
                                value={task.runs.length.toString()}
                                icon={<Radio className="size-4" />}
                            />
                        </div>
                    </section>

                    <div className="grid min-w-0 gap-3 xl:grid-cols-[minmax(0,1fr)_20rem]">
                        <main className="min-w-0 space-y-3">
                            <Card className="overflow-hidden">
                                <CardHeader className="flex-row items-start justify-between gap-3">
                                    <div>
                                        <CardTitle className="flex items-center gap-2">
                                            <Bot className="size-4 text-primary" />
                                            Agent console
                                        </CardTitle>
                                        <CardDescription className="mt-1">
                                            {isLiveOutput && liveRun
                                                ? `Live ${humanize(
                                                      liveRun.role,
                                                  )} output. Refreshes every two seconds.`
                                                : 'Latest captured agent output.'}
                                        </CardDescription>
                                    </div>
                                    {liveRun && (
                                        <StatusBadge status={liveRun.status} />
                                    )}
                                </CardHeader>
                                <CardContent className="grid gap-3">
                                    {liveRun ? (
                                        <>
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <div className="flex items-center gap-2 font-mono text-2xs text-muted-foreground">
                                                    <span className="capitalize">
                                                        {humanize(liveRun.role)}
                                                    </span>
                                                    <span>·</span>
                                                    <span>
                                                        Attempt{' '}
                                                        {liveRun.attempt_number ??
                                                            '—'}
                                                    </span>
                                                    <span>·</span>
                                                </div>
                                                {hasTechnicalOutput && (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            setShowTechnicalOutput(
                                                                (showing) =>
                                                                    !showing,
                                                            )
                                                        }
                                                    >
                                                        <Terminal className="size-3.5" />
                                                        {showTechnicalOutput
                                                            ? 'Hide technical output'
                                                            : 'Show technical output'}
                                                    </Button>
                                                )}
                                            </div>
                                            <pre
                                                ref={consoleRef}
                                                aria-live={
                                                    isLiveOutput
                                                        ? 'polite'
                                                        : undefined
                                                }
                                                className="panel-recessed max-h-[32rem] min-h-24 overflow-auto p-4 font-mono text-xs leading-5 whitespace-pre-wrap text-foreground"
                                            >
                                                <AgentConsoleOutput
                                                    output={
                                                        liveRun.live_output ??
                                                        liveRun.transcript
                                                    }
                                                    agentMessages={
                                                        liveRun.agent_messages
                                                    }
                                                    agentRole={liveRun.role}
                                                    showTechnicalOutput={
                                                        showTechnicalOutput
                                                    }
                                                />
                                            </pre>
                                        </>
                                    ) : (
                                        <div className="rounded-lg border border-dashed border-border p-6 text-center">
                                            <Bot className="mx-auto size-5 text-muted-foreground" />
                                            <p className="mt-2 text-sm text-muted-foreground">
                                                No agent run has started for
                                                this task.
                                            </p>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            <Card className="overflow-hidden">
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <ListChecks className="size-4 text-primary" />
                                        Task details
                                    </CardTitle>
                                    <CardDescription>
                                        Durable task objective, constraints, and
                                        execution contract.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid gap-4">
                                    <DetailBlock label="Objective">
                                        <p className="text-sm leading-6 text-foreground/90">
                                            {task.objective}
                                        </p>
                                    </DetailBlock>

                                    <DetailBlock label="Acceptance criteria">
                                        <DisplayList
                                            items={task.acceptance_criteria}
                                        />
                                    </DetailBlock>

                                    {task.status === 'done' &&
                                        task.context_capsule
                                            .completion_evidence && (
                                            <DetailBlock label="Existing implementation evidence">
                                                <p className="text-sm leading-6 text-foreground/80">
                                                    {
                                                        task.context_capsule
                                                            .completion_evidence
                                                    }
                                                </p>
                                            </DetailBlock>
                                        )}

                                    <div className="grid gap-4 md:grid-cols-2">
                                        <DetailBlock label="Scope">
                                            <JsonDetail value={task.scope} />
                                        </DetailBlock>
                                        <DetailBlock label="Constraints">
                                            <JsonDetail
                                                value={task.constraints}
                                            />
                                        </DetailBlock>
                                    </div>

                                    <div className="grid gap-4 md:grid-cols-2">
                                        <DetailBlock label="Relevant paths">
                                            <DisplayList
                                                items={task.relevant_paths}
                                            />
                                        </DetailBlock>
                                        <DetailBlock label="Verification commands">
                                            <DisplayList
                                                items={
                                                    task.verification_commands
                                                }
                                            />
                                        </DetailBlock>
                                    </div>

                                    <DetailBlock label="Dependencies">
                                        {task.dependencies.length === 0 ? (
                                            <p className="text-xs text-muted-foreground">
                                                No dependencies.
                                            </p>
                                        ) : (
                                            <div className="flex flex-wrap gap-2">
                                                {task.dependencies.map(
                                                    (dependency) => (
                                                        <Badge
                                                            key={dependency.id}
                                                            variant="outline"
                                                            className="border-primary/15 bg-primary/5 font-mono text-2xs text-primary"
                                                            title={
                                                                dependency.title
                                                            }
                                                        >
                                                            {dependency.key} ·{' '}
                                                            {humanize(
                                                                dependency.status,
                                                            )}
                                                        </Badge>
                                                    ),
                                                )}
                                            </div>
                                        )}
                                    </DetailBlock>

                                    <DetailBlock label="Implementation prompt">
                                        <pre className="panel-recessed overflow-x-auto p-3 font-mono text-2xs leading-5 whitespace-pre-wrap text-foreground/85">
                                            {task.implementation_prompt}
                                        </pre>
                                    </DetailBlock>
                                </CardContent>
                            </Card>

                            <Card className="overflow-hidden">
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <GitCommit className="size-4 text-primary" />
                                        Attempts & reviews
                                    </CardTitle>
                                    <CardDescription>
                                        Git, validation, and reviewer evidence
                                        by implementation attempt.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid gap-3">
                                    {task.attempts.length === 0 && (
                                        <div className="rounded-lg border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
                                            No implementation attempts yet.
                                        </div>
                                    )}

                                    {task.attempts.map((attempt) => {
                                        const attemptReviews =
                                            task.reviews.filter(
                                                (review) =>
                                                    review.attempt?.id ===
                                                    attempt.id,
                                            );

                                        return (
                                            <article
                                                key={attempt.id}
                                                className="panel-recessed overflow-hidden"
                                            >
                                                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border-subtle px-3 py-3">
                                                    <div>
                                                        <p className="font-mono text-xs font-semibold text-foreground uppercase">
                                                            Attempt{' '}
                                                            {attempt.number}
                                                        </p>
                                                        <p className="mt-1 font-mono text-2xs text-muted-foreground">
                                                            {formatDateTime(
                                                                attempt.started_at,
                                                            )}{' '}
                                                            →{' '}
                                                            {formatDateTime(
                                                                attempt.finished_at,
                                                            )}
                                                        </p>
                                                    </div>
                                                    <StatusBadge
                                                        status={attempt.status}
                                                    />
                                                </div>

                                                <div className="grid gap-3 p-3 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                                                    <div className="grid content-start gap-3">
                                                        <dl className="grid grid-cols-2 gap-2">
                                                            <div className="tile-inset px-2.5 py-2">
                                                                <dt className="font-mono text-2xs text-muted-foreground uppercase">
                                                                    Base SHA
                                                                </dt>
                                                                <dd
                                                                    className="mt-1 truncate font-mono text-xs text-primary"
                                                                    title={
                                                                        attempt.base_sha ??
                                                                        'Not recorded'
                                                                    }
                                                                >
                                                                    {shortSha(
                                                                        attempt.base_sha,
                                                                    )}
                                                                </dd>
                                                            </div>
                                                            <div className="tile-inset px-2.5 py-2">
                                                                <dt className="font-mono text-2xs text-muted-foreground uppercase">
                                                                    Head SHA
                                                                </dt>
                                                                <dd
                                                                    className="mt-1 truncate font-mono text-xs text-primary"
                                                                    title={
                                                                        attempt.head_sha ??
                                                                        'Not recorded'
                                                                    }
                                                                >
                                                                    {shortSha(
                                                                        attempt.head_sha,
                                                                    )}
                                                                </dd>
                                                            </div>
                                                        </dl>

                                                        <div className="panel-recessed p-2.5">
                                                            <p className="font-mono text-2xs tracking-[0.08em] text-muted-foreground uppercase">
                                                                Commit
                                                            </p>
                                                            <p className="mt-1 font-mono text-2xs break-all text-foreground/80">
                                                                {attempt.commit_sha ??
                                                                    'Not recorded'}
                                                            </p>
                                                        </div>

                                                        <ValidationSummary
                                                            value={
                                                                attempt.validation_results
                                                            }
                                                            changedFiles={
                                                                attempt.changed_files
                                                            }
                                                        />
                                                    </div>

                                                    <div className="grid min-w-0 gap-3">
                                                        <div>
                                                            <div className="mb-2 flex items-center gap-2">
                                                                <Braces className="size-3.5 text-primary" />
                                                                <p className="font-mono text-2xs tracking-[0.08em] text-muted-foreground uppercase">
                                                                    Evidence
                                                                    JSON
                                                                </p>
                                                            </div>
                                                            <JsonDetail
                                                                value={
                                                                    attempt.validation_results
                                                                }
                                                            />
                                                        </div>

                                                        <div>
                                                            <div className="mb-2 flex items-center justify-between gap-3">
                                                                <div className="flex items-center gap-2">
                                                                    <FileCode2 className="size-3.5 text-primary" />
                                                                    <p className="font-mono text-2xs tracking-[0.08em] text-muted-foreground uppercase">
                                                                        Changed
                                                                        files
                                                                    </p>
                                                                </div>
                                                                <span className="font-mono text-2xs text-muted-foreground">
                                                                    {attempt
                                                                        .changed_files
                                                                        ?.length ??
                                                                        0}
                                                                </span>
                                                            </div>
                                                            {attempt.changed_files &&
                                                            attempt
                                                                .changed_files
                                                                .length > 0 ? (
                                                                <div className="grid gap-1.5">
                                                                    {attempt.changed_files.map(
                                                                        (
                                                                            file,
                                                                        ) => (
                                                                            <div
                                                                                key={
                                                                                    file
                                                                                }
                                                                                className="flex min-w-0 items-center gap-2 rounded-md border border-border-subtle bg-foreground/[0.025] px-2.5 py-2"
                                                                            >
                                                                                <FileCode2 className="size-3.5 shrink-0 text-primary" />
                                                                                <span
                                                                                    className="truncate font-mono text-2xs text-foreground/80"
                                                                                    title={
                                                                                        file
                                                                                    }
                                                                                >
                                                                                    {
                                                                                        file
                                                                                    }
                                                                                </span>
                                                                            </div>
                                                                        ),
                                                                    )}
                                                                </div>
                                                            ) : (
                                                                <p className="text-xs text-muted-foreground">
                                                                    No
                                                                    changed-file
                                                                    evidence was
                                                                    recorded.
                                                                </p>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>

                                                {attemptReviews.length > 0 && (
                                                    <div className="grid gap-2 border-t border-border-subtle p-3">
                                                        {attemptReviews.map(
                                                            (review) => (
                                                                <div
                                                                    key={
                                                                        review.id
                                                                    }
                                                                    className="rounded-lg border border-secondary-foreground/15 bg-secondary/[0.055] p-3"
                                                                >
                                                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                                                        <p className="text-xs font-medium text-foreground">
                                                                            Review
                                                                            for
                                                                            attempt{' '}
                                                                            {
                                                                                attempt.number
                                                                            }
                                                                        </p>
                                                                        <StatusBadge
                                                                            status={
                                                                                review.status
                                                                            }
                                                                        />
                                                                    </div>
                                                                    {review.summary && (
                                                                        <p className="mt-2 text-xs leading-5 text-muted-foreground">
                                                                            {
                                                                                review.summary
                                                                            }
                                                                        </p>
                                                                    )}
                                                                </div>
                                                            ),
                                                        )}
                                                    </div>
                                                )}
                                            </article>
                                        );
                                    })}
                                </CardContent>
                            </Card>

                            <ReviewSummary review={latestReview} />
                        </main>

                        <aside className="min-w-0 space-y-3 xl:sticky xl:top-3 xl:self-start">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <Send className="size-4 text-primary" />
                                        Message an agent
                                    </CardTitle>
                                    <CardDescription>
                                        Saved for the selected role’s next fresh
                                        execution. It cannot interrupt a
                                        currently running session.
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
                                                        className="h-9 rounded-md border border-input bg-surface-sunken px-3 text-sm text-foreground transition outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
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
                                                        className="rounded-md border border-input bg-surface-sunken px-3 py-2 text-sm text-foreground transition outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                                                    />
                                                    <InputError
                                                        message={errors.body}
                                                    />
                                                </div>
                                                <p className="text-2xs text-muted-foreground">
                                                    Do not paste credentials or
                                                    secrets.
                                                </p>
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                    className="shadow-glow-sm"
                                                >
                                                    <Send className="size-4" />
                                                    Send instruction
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                </CardContent>
                            </Card>

                            {task.status === 'blocked' && (
                                <Card className="border-destructive/25">
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2 text-destructive-foreground">
                                            <Ban className="size-4" />
                                            Skip task
                                        </CardTitle>
                                        <CardDescription>
                                            Cancels this task instead of
                                            retrying it. Use this when the
                                            acceptance criteria cannot be met by
                                            an automated Coder run (e.g. they
                                            require physical hardware or access
                                            this environment doesn’t have).
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="grid gap-3">
                                        {task.audit_events.some(
                                            (event) =>
                                                event.event_type ===
                                                'task.review_no_progress_blocked',
                                        ) && (
                                            <div className="flex items-start gap-2 rounded-lg border border-warning/25 bg-warning/5 p-3 text-xs text-warning-foreground">
                                                <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
                                                <span>
                                                    AIOS stopped repeated
                                                    Coder/Reviewer retries after
                                                    three rejected attempts had
                                                    no repository progress.
                                                    Provide the missing external
                                                    prerequisite before
                                                    requeueing.
                                                </span>
                                            </div>
                                        )}
                                        {recoveryEscalationReason && (
                                            <div className="flex items-start gap-2 rounded-lg border border-warning/25 bg-warning/5 p-3 text-xs text-warning-foreground">
                                                <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
                                                <span>
                                                    Recovery Engineer:{' '}
                                                    {recoveryEscalationReason}
                                                </span>
                                            </div>
                                        )}
                                        {task.dependents.length > 0 && (
                                            <div className="flex items-start gap-2 rounded-lg border border-warning/25 bg-warning/5 p-3 text-xs text-warning-foreground">
                                                <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
                                                <span>
                                                    {task.dependents.length}{' '}
                                                    task
                                                    {task.dependents.length ===
                                                    1
                                                        ? ''
                                                        : 's'}{' '}
                                                    depend on this one and will
                                                    stay blocked once it is
                                                    skipped:{' '}
                                                    {task.dependents
                                                        .map(
                                                            (dependent) =>
                                                                dependent.key,
                                                        )
                                                        .join(', ')}
                                                </span>
                                            </div>
                                        )}
                                        <Form
                                            {...requeueTask.form({
                                                project: project.id,
                                                task: task.id,
                                            })}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    disabled={processing}
                                                >
                                                    Requeue after resolving
                                                    prerequisite
                                                </Button>
                                            )}
                                        </Form>
                                        <Form
                                            {...skipTask.form({
                                                project: project.id,
                                                task: task.id,
                                            })}
                                            className="grid gap-3"
                                        >
                                            {({ errors, processing }) => (
                                                <>
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="reason">
                                                            Reason
                                                        </Label>
                                                        <textarea
                                                            id="reason"
                                                            name="reason"
                                                            required
                                                            maxLength={2000}
                                                            rows={4}
                                                            placeholder="Why can't this task be completed as specified?"
                                                            className="rounded-md border border-input bg-surface-sunken px-3 py-2 text-sm text-foreground transition outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
                                                        />
                                                        <InputError
                                                            message={
                                                                errors.reason
                                                            }
                                                        />
                                                    </div>
                                                    <Button
                                                        type="submit"
                                                        variant="outline"
                                                        disabled={processing}
                                                        className="border-destructive/25 bg-destructive/10 text-destructive-foreground hover:bg-destructive/15"
                                                    >
                                                        <Ban className="size-4" />
                                                        Skip task
                                                    </Button>
                                                </>
                                            )}
                                        </Form>
                                    </CardContent>
                                </Card>
                            )}

                            <RunEvidenceGateway
                                project={project}
                                run={latestRun}
                            />

                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <MessageSquare className="size-4 text-primary" />
                                        Agent instructions
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="grid gap-2">
                                    {task.operator_messages.length === 0 && (
                                        <div className="rounded-lg border border-dashed border-border p-4 text-center text-xs text-muted-foreground">
                                            No instructions sent.
                                        </div>
                                    )}
                                    {task.operator_messages.map((message) => (
                                        <div
                                            key={message.id}
                                            className="rounded-lg border border-border-subtle bg-foreground/[0.025] p-3 text-sm"
                                        >
                                            <div className="mb-2 flex items-center justify-between gap-2">
                                                <Badge
                                                    variant="outline"
                                                    className="border-primary/15 bg-primary/5 font-mono text-2xs text-primary capitalize"
                                                >
                                                    {humanize(
                                                        message.recipient_role,
                                                    )}
                                                </Badge>
                                                <span
                                                    className={`font-mono text-2xs ${
                                                        message.delivered_at
                                                            ? 'text-success-foreground'
                                                            : 'text-warning-foreground'
                                                    }`}
                                                >
                                                    {message.delivered_at
                                                        ? 'delivered'
                                                        : 'pending'}
                                                </span>
                                            </div>
                                            <p className="text-xs leading-5 whitespace-pre-wrap text-foreground/90">
                                                {message.body}
                                            </p>
                                            <p className="mt-2 font-mono text-2xs text-muted-foreground">
                                                {message.user.name} ·{' '}
                                                {formatDateTime(
                                                    message.created_at,
                                                )}
                                            </p>
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <Radio className="size-4 text-primary" />
                                        Execution history
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="grid gap-2">
                                    {task.runs.length === 0 && (
                                        <p className="text-xs text-muted-foreground">
                                            No agent runs recorded.
                                        </p>
                                    )}
                                    {task.runs.map((run) => (
                                        <Link
                                            key={run.id}
                                            href={showAgentRun({
                                                project: project.id,
                                                run: run.id,
                                            })}
                                            className="block rounded-lg border border-border-subtle bg-foreground/[0.025] p-3 transition hover:border-primary/20 hover:bg-primary/[0.035]"
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <div className="min-w-0">
                                                    <p className="truncate text-xs font-medium text-foreground capitalize">
                                                        {humanize(run.role)}
                                                    </p>
                                                    <p className="mt-0.5 font-mono text-2xs text-muted-foreground">
                                                        Run #{run.id} · Attempt{' '}
                                                        {run.attempt_number ??
                                                            '—'}
                                                    </p>
                                                </div>
                                                <StatusBadge
                                                    status={run.status}
                                                />
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1 font-mono text-2xs text-muted-foreground">
                                                <span>
                                                    Exit {run.exit_code ?? '—'}
                                                </span>
                                                <span>
                                                    {formatDuration(run)}
                                                </span>
                                                <span className="text-primary">
                                                    View run evidence →
                                                </span>
                                            </div>
                                        </Link>
                                    ))}
                                </CardContent>
                            </Card>

                            <HandoffEvidenceCard
                                project={project}
                                handoffs={task.handoffs}
                            />

                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <Activity className="size-4 text-primary" />
                                        Task activity
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="grid gap-1.5">
                                    {task.audit_events.length === 0 && (
                                        <p className="text-xs text-muted-foreground">
                                            No task activity recorded.
                                        </p>
                                    )}
                                    {task.audit_events.map((event) => (
                                        <div
                                            key={event.id}
                                            className="grid grid-cols-[1.5rem_minmax(0,1fr)] gap-2 py-1"
                                        >
                                            <div className="mt-0.5 grid size-5 place-items-center rounded-full border border-primary/15 bg-primary/5 text-primary">
                                                <span className="size-1.5 rounded-full bg-current" />
                                            </div>
                                            <div className="min-w-0">
                                                <p className="truncate font-mono text-2xs text-foreground/85">
                                                    {event.event_type}
                                                </p>
                                                <time className="font-mono text-2xs text-muted-foreground">
                                                    {formatDateTime(
                                                        event.occurred_at,
                                                    )}
                                                </time>
                                            </div>
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>
                        </aside>
                    </div>
                </div>
            </div>
        </>
    );
}
