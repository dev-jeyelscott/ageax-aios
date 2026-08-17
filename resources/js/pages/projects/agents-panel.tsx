import { Form } from '@inertiajs/react';
import {
    Activity,
    Ban,
    Bot,
    CheckCircle2,
    ChevronDown,
    ChevronUp,
    CircleDot,
    Cpu,
    Gauge,
    Link2,
    Pencil,
    Plus,
    ShieldCheck,
    Sparkles,
    X,
    Zap,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import {
    assignSkill,
    bindWorker,
    reorderSkills,
    store as storeAgent,
    unassignSkill,
    update as updateAgent,
} from '@/actions/App/Http/Controllers/AgentController';
import { AgentFields, selectClassName } from '@/components/agent-fields';
import type { HarnessCapabilities } from '@/components/agent-fields';
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
import { cn } from '@/lib/utils';

export type {
    HarnessCapability,
    HarnessCapabilities,
} from '@/components/agent-fields';

export type AgentSkillSummary = {
    id: number;
    name: string;
    slug: string;
    version: number;
    enabled: boolean;
};

export type Agent = {
    id: number;
    name: string;
    role: string;
    harness: string;
    model: string | null;
    reasoning_setting: string | null;
    default_context: string | null;
    enabled: boolean;
    configuration_version: number;
    is_default: boolean;
    skills: AgentSkillSummary[];
};

export type Skill = {
    id: number;
    name: string;
    slug: string;
    enabled: boolean;
    version: number;
};

export type Worker = {
    id: number;
    role: string;
    agent_id: number | null;
    status: string;
    last_heartbeat_at: string | null;
};

type RoleVisual = {
    label: string;
    icon: LucideIcon;
    ringClassName: string;
    surfaceClassName: string;
    textClassName: string;
    lineClassName: string;
};

type WorkerVisual = {
    label: string;
    className: string;
    dotClassName: string;
};

const ROLES = [
    { value: 'project_manager', label: 'Project Manager' },
    { value: 'coder', label: 'Coder' },
    { value: 'reviewer', label: 'Reviewer' },
];

function roleLabel(role: string): string {
    return ROLES.find((candidate) => candidate.value === role)?.label ?? role;
}

function roleVisual(role: string): RoleVisual {
    switch (role) {
        case 'project_manager':
            return {
                label: 'Project Manager',
                icon: Sparkles,
                ringClassName:
                    'border-secondary-foreground/30 shadow-glow-secondary',
                surfaceClassName: 'bg-secondary/20',
                textClassName: 'text-secondary-foreground',
                lineClassName:
                    'via-secondary-foreground/65 from-transparent to-transparent',
            };
        case 'reviewer':
            return {
                label: 'Reviewer',
                icon: ShieldCheck,
                ringClassName: 'border-success/30',
                surfaceClassName: 'bg-success/10',
                textClassName: 'text-success-foreground',
                lineClassName:
                    'via-success-foreground/55 from-transparent to-transparent',
            };
        default:
            return {
                label: 'Coder',
                icon: Cpu,
                ringClassName: 'border-primary/30 shadow-glow-sm',
                surfaceClassName: 'bg-primary/10',
                textClassName: 'text-primary',
                lineClassName: 'via-primary/65 from-transparent to-transparent',
            };
    }
}

function workerVisual(status: string): WorkerVisual {
    switch (status) {
        case 'working':
            return {
                label: 'Working',
                className: 'border-primary/25 bg-primary/10 text-primary',
                dotClassName: 'bg-primary text-primary status-glow-pulse',
            };
        case 'recovering':
            return {
                label: 'Recovering',
                className:
                    'border-warning/25 bg-warning/10 text-warning-foreground',
                dotClassName:
                    'bg-warning text-warning-foreground status-glow-pulse',
            };
        case 'idle':
            return {
                label: 'Idle',
                className:
                    'border-success/25 bg-success/10 text-success-foreground',
                dotClassName: 'bg-success text-success-foreground',
            };
        case 'interrupted':
            return {
                label: 'Interrupted',
                className:
                    'border-warning/25 bg-warning/10 text-warning-foreground',
                dotClassName: 'bg-warning text-warning-foreground',
            };
        case 'failed':
            return {
                label: 'Failed',
                className:
                    'border-destructive/30 bg-destructive/10 text-destructive-foreground',
                dotClassName: 'bg-destructive text-destructive-foreground',
            };
        default:
            return {
                label: status.replaceAll('_', ' '),
                className:
                    'border-border bg-foreground/[0.035] text-muted-foreground',
                dotClassName: 'bg-muted-foreground text-muted-foreground',
            };
    }
}

function harnessLabel(harness: string): string {
    switch (harness) {
        case 'claude_code':
            return 'Claude Code';
        case 'codex':
            return 'Codex';
        default:
            return harness.replaceAll('_', ' ');
    }
}

function heartbeatLabel(value: string | null): string {
    if (!value) {
        return 'No heartbeat recorded';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Heartbeat unavailable';
    }

    return new Intl.DateTimeFormat(undefined, {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }).format(date);
}

function CreateAgentForm({
    projectId,
    harnessCapabilities,
    onDone,
}: {
    projectId: number;
    harnessCapabilities: HarnessCapabilities;
    onDone: () => void;
}) {
    return (
        <Form
            {...storeAgent.form(projectId)}
            resetOnSuccess
            onSuccess={onDone}
            className="grid gap-4"
        >
            {({ errors, processing }) => (
                <>
                    <AgentFields
                        harnessCapabilities={harnessCapabilities}
                        initial={{ enabled: true }}
                        errors={errors}
                        roleField={{ editable: true, options: ROLES }}
                    />

                    <div className="flex flex-wrap gap-2 border-t border-border/60 pt-4">
                        <Button type="submit" disabled={processing}>
                            <Plus />
                            Create agent
                        </Button>

                        <Button
                            type="button"
                            variant="outline"
                            onClick={onDone}
                        >
                            Cancel
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function EditAgentForm({
    projectId,
    agent,
    harnessCapabilities,
    onDone,
}: {
    projectId: number;
    agent: Agent;
    harnessCapabilities: HarnessCapabilities;
    onDone: () => void;
}) {
    return (
        <Form
            {...updateAgent.form([projectId, agent.id])}
            onSuccess={onDone}
            className="grid gap-4"
        >
            {({ errors, processing }) => (
                <>
                    <AgentFields
                        harnessCapabilities={harnessCapabilities}
                        initial={agent}
                        errors={errors}
                        roleField={{ editable: true, options: ROLES }}
                    />

                    <div className="flex flex-wrap gap-2 border-t border-border/60 pt-4">
                        <Button type="submit" disabled={processing}>
                            Save changes
                        </Button>

                        <Button
                            type="button"
                            variant="outline"
                            onClick={onDone}
                        >
                            Cancel
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function ToggleEnabledForm({
    projectId,
    agent,
}: {
    projectId: number;
    agent: Agent;
}) {
    return (
        <Form {...updateAgent.form([projectId, agent.id])} className="inline">
            {({ errors, processing }) => (
                <>
                    <input type="hidden" name="name" value={agent.name} />
                    <input type="hidden" name="role" value={agent.role} />
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
                        title={
                            errors.enabled ??
                            (agent.enabled ? 'Disable agent' : 'Enable agent')
                        }
                        className="border-border/80 bg-background/40"
                    >
                        {agent.enabled ? <Ban /> : <CheckCircle2 />}
                        {agent.enabled ? 'Disable' : 'Enable'}
                    </Button>

                    {errors.enabled && (
                        <p className="mt-1 text-xs text-destructive">
                            {errors.enabled}
                        </p>
                    )}
                </>
            )}
        </Form>
    );
}

function ReorderButton({
    projectId,
    agent,
    index,
    swapWith,
    direction,
}: {
    projectId: number;
    agent: Agent;
    index: number;
    swapWith: number;
    direction: 'up' | 'down';
}) {
    const reordered = agent.skills.map((skill) => skill.id);

    [reordered[index], reordered[swapWith]] = [
        reordered[swapWith],
        reordered[index],
    ];

    return (
        <Form {...reorderSkills.form([projectId, agent.id])} className="inline">
            {({ processing }) => (
                <>
                    {reordered.map((skillId) => (
                        <input
                            key={skillId}
                            type="hidden"
                            name="skill_ids[]"
                            value={skillId}
                        />
                    ))}

                    <Button
                        type="submit"
                        size="icon"
                        variant="ghost"
                        disabled={processing}
                        className="size-6 text-muted-foreground hover:text-primary"
                        aria-label={`Move skill ${direction}`}
                        title={`Move skill ${direction}`}
                    >
                        {direction === 'up' ? (
                            <ChevronUp className="size-3.5" />
                        ) : (
                            <ChevronDown className="size-3.5" />
                        )}
                    </Button>
                </>
            )}
        </Form>
    );
}

function SkillAssignment({
    projectId,
    agent,
    skills,
}: {
    projectId: number;
    agent: Agent;
    skills: Skill[];
}) {
    const assignedIds = new Set(agent.skills.map((skill) => skill.id));
    const available = skills.filter((skill) => !assignedIds.has(skill.id));

    return (
        <section className="grid gap-3">
            <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <Zap className="size-3.5 text-primary" />
                    <p className="font-mono text-2xs tracking-[0.13em] text-muted-foreground uppercase">
                        Skills
                    </p>
                </div>

                <Badge
                    variant="outline"
                    className="border-border/70 bg-background/40 font-mono text-2xs"
                >
                    {agent.skills.length}
                </Badge>
            </div>

            {agent.skills.length === 0 && (
                <div className="rounded-lg border border-dashed border-border/70 bg-background/25 px-3 py-4 text-center">
                    <p className="text-xs text-muted-foreground">
                        No skills assigned.
                    </p>
                </div>
            )}

            {agent.skills.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {agent.skills.map((skill, index) => (
                        <div
                            key={skill.id}
                            className={cn(
                                'group flex min-h-8 items-center gap-1 rounded-lg border border-primary/15 bg-primary/[0.055] px-2 py-1 text-xs',
                                !skill.enabled &&
                                    'border-border bg-foreground/[0.025] opacity-60',
                            )}
                        >
                            <span className="font-mono text-2xs text-muted-foreground">
                                {index + 1}
                            </span>

                            <span className="font-medium text-foreground">
                                {skill.name}
                            </span>

                            <span className="font-mono text-2xs text-primary/70">
                                v{skill.version}
                            </span>

                            {!skill.enabled && (
                                <Badge
                                    variant="outline"
                                    className="ml-1 h-5 border-border px-1.5 text-2xs"
                                >
                                    disabled
                                </Badge>
                            )}

                            <div className="ml-1 flex items-center">
                                {index > 0 && (
                                    <ReorderButton
                                        projectId={projectId}
                                        agent={agent}
                                        index={index}
                                        swapWith={index - 1}
                                        direction="up"
                                    />
                                )}

                                {index < agent.skills.length - 1 && (
                                    <ReorderButton
                                        projectId={projectId}
                                        agent={agent}
                                        index={index}
                                        swapWith={index + 1}
                                        direction="down"
                                    />
                                )}

                                <Form
                                    {...unassignSkill.form([
                                        projectId,
                                        agent.id,
                                        skill.id,
                                    ])}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            size="icon"
                                            variant="ghost"
                                            disabled={processing}
                                            title="Remove skill"
                                            aria-label={`Remove ${skill.name}`}
                                            className="size-6 text-muted-foreground hover:text-destructive"
                                        >
                                            <X className="size-3.5" />
                                        </Button>
                                    )}
                                </Form>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {available.length > 0 && (
                <Form
                    {...assignSkill.form([projectId, agent.id])}
                    className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto]"
                >
                    {({ errors, processing }) => (
                        <>
                            <select
                                name="skill_id"
                                defaultValue=""
                                required
                                aria-label={`Assign skill to ${agent.name}`}
                                className={cn(
                                    selectClassName(),
                                    'border-border/80 bg-background/45 text-xs',
                                )}
                            >
                                <option value="" disabled>
                                    Assign a skill…
                                </option>

                                {available.map((skill) => (
                                    <option key={skill.id} value={skill.id}>
                                        {skill.name}
                                        {!skill.enabled ? ' (disabled)' : ''}
                                    </option>
                                ))}
                            </select>

                            <Button
                                type="submit"
                                size="sm"
                                variant="outline"
                                disabled={processing}
                                className="border-primary/20 bg-primary/[0.035] text-primary"
                            >
                                <Plus className="size-3.5" />
                                Add skill
                            </Button>

                            <div className="sm:col-span-2">
                                <InputError message={errors.skill_id} />
                            </div>
                        </>
                    )}
                </Form>
            )}
        </section>
    );
}

function WorkerBinding({
    projectId,
    agent,
    worker,
}: {
    projectId: number;
    agent: Agent;
    worker: Worker | undefined;
}) {
    if (!worker) {
        return (
            <section className="grid gap-3">
                <div className="flex items-center gap-2">
                    <Activity className="size-3.5 text-muted-foreground" />
                    <p className="font-mono text-2xs tracking-[0.13em] text-muted-foreground uppercase">
                        Worker status
                    </p>
                </div>

                <div className="rounded-lg border border-dashed border-border/70 bg-background/25 p-3">
                    <p className="text-xs text-muted-foreground">
                        No workflow worker provisioned for this role.
                    </p>
                </div>
            </section>
        );
    }

    const isBound = worker.agent_id === agent.id;
    const visual = workerVisual(worker.status);

    return (
        <section className="grid gap-3">
            <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <Activity className="size-3.5 text-primary" />
                    <p className="font-mono text-2xs tracking-[0.13em] text-muted-foreground uppercase">
                        Worker status
                    </p>
                </div>

                {isBound && (
                    <Badge
                        variant="outline"
                        className="border-primary/25 bg-primary/10 font-mono text-2xs text-primary"
                    >
                        <Link2 className="size-3" />
                        bound
                    </Badge>
                )}
            </div>

            <div className="panel-recessed relative overflow-hidden p-3">
                <div
                    className={cn(
                        'absolute inset-x-0 top-0 h-px bg-gradient-to-r',
                        worker.status === 'working'
                            ? 'from-transparent via-primary/70 to-transparent'
                            : 'from-transparent via-border to-transparent',
                    )}
                />

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div
                        className={cn(
                            'inline-flex items-center gap-2 rounded-full border px-2.5 py-1 text-xs font-medium capitalize',
                            visual.className,
                        )}
                    >
                        <span
                            className={cn(
                                'size-1.5 rounded-full',
                                visual.dotClassName,
                            )}
                        />
                        {visual.label}
                    </div>

                    <span className="font-mono text-2xs text-muted-foreground">
                        {roleLabel(worker.role)} worker
                    </span>
                </div>

                <div className="mt-3 grid gap-1.5 text-xs">
                    <div className="flex items-center justify-between gap-3">
                        <span className="text-muted-foreground">
                            Last heartbeat
                        </span>
                        <span className="font-mono text-foreground/80">
                            {heartbeatLabel(worker.last_heartbeat_at)}
                        </span>
                    </div>

                    <div className="flex items-center justify-between gap-3">
                        <span className="text-muted-foreground">Binding</span>
                        <span
                            className={cn(
                                'font-mono',
                                isBound
                                    ? 'text-primary'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {isBound ? agent.name : 'another agent / unbound'}
                        </span>
                    </div>
                </div>
            </div>

            {!isBound && (
                <Form
                    {...bindWorker.form([projectId, agent.id])}
                    className="grid gap-2"
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="agent_worker_id"
                                value={worker.id}
                            />

                            <Button
                                type="submit"
                                size="sm"
                                variant="outline"
                                disabled={processing || !agent.enabled}
                                className="justify-self-start border-primary/20 bg-primary/[0.035] text-primary"
                            >
                                <Link2 className="size-3.5" />
                                Bind to this agent
                            </Button>

                            <InputError message={errors.agent_worker_id} />
                        </>
                    )}
                </Form>
            )}
        </section>
    );
}

function MetricTile({
    icon: Icon,
    value,
    label,
    detail,
}: {
    icon: LucideIcon;
    value: string | number;
    label: string;
    detail?: string;
}) {
    return (
        <div className="panel-recessed relative min-w-0 overflow-hidden px-3 py-3">
            <div className="absolute inset-x-4 top-0 h-px bg-gradient-to-r from-transparent via-primary/35 to-transparent" />

            <div className="flex min-w-0 items-center gap-2.5">
                <div className="grid size-8 shrink-0 place-items-center rounded-lg border border-primary/15 bg-primary/[0.055] text-primary">
                    <Icon className="size-4" />
                </div>

                <div className="min-w-0">
                    <p className="truncate text-lg leading-none font-semibold text-foreground">
                        {value}
                    </p>
                    <p className="mt-1 truncate text-xs text-muted-foreground">
                        {label}
                    </p>
                </div>
            </div>

            {detail && (
                <p className="mt-2 truncate font-mono text-2xs text-muted-foreground/70">
                    {detail}
                </p>
            )}
        </div>
    );
}

function AgentCard({
    projectId,
    agent,
    worker,
    skills,
    harnessCapabilities,
    isEditing,
    onEdit,
    onEditingDone,
}: {
    projectId: number;
    agent: Agent;
    worker: Worker | undefined;
    skills: Skill[];
    harnessCapabilities: HarnessCapabilities;
    isEditing: boolean;
    onEdit: () => void;
    onEditingDone: () => void;
}) {
    const visual = roleVisual(agent.role);
    const RoleIcon = visual.icon;
    const isBound = worker?.agent_id === agent.id;
    const isActivelyWorking =
        isBound &&
        worker !== undefined &&
        ['working', 'recovering'].includes(worker.status);

    return (
        <article
            className={cn(
                'panel-elevated relative overflow-hidden p-4 transition duration-200',
                'hover:border-primary/20',
                isActivelyWorking && 'agent-card-active',
                !agent.enabled && 'opacity-70',
            )}
        >
            <div
                className={cn(
                    'pointer-events-none absolute inset-x-10 top-0 h-px bg-gradient-to-r',
                    visual.lineClassName,
                )}
            />

            {isEditing ? (
                <div className="relative z-10">
                    <div className="mb-4 flex items-center gap-3 border-b border-border/60 pb-3">
                        <div
                            className={cn(
                                'grid size-9 place-items-center rounded-xl border',
                                visual.ringClassName,
                                visual.surfaceClassName,
                                visual.textClassName,
                            )}
                        >
                            <RoleIcon className="size-4" />
                        </div>

                        <div>
                            <p className="text-sm font-semibold">
                                Edit {agent.name}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Configuration v{agent.configuration_version}
                            </p>
                        </div>
                    </div>

                    <EditAgentForm
                        projectId={projectId}
                        agent={agent}
                        harnessCapabilities={harnessCapabilities}
                        onDone={onEditingDone}
                    />
                </div>
            ) : (
                <div className="relative z-10">
                    <header className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex min-w-0 items-start gap-3">
                            <div
                                className={cn(
                                    'relative grid size-16 shrink-0 place-items-center rounded-2xl border',
                                    visual.ringClassName,
                                    visual.surfaceClassName,
                                )}
                            >
                                <div
                                    className={cn(
                                        'absolute inset-2 rounded-full border border-current/20',
                                        visual.textClassName,
                                    )}
                                />
                                <RoleIcon
                                    className={cn(
                                        'relative size-7',
                                        visual.textClassName,
                                    )}
                                />

                                <span
                                    className={cn(
                                        'absolute right-1.5 bottom-1.5 size-2.5 rounded-full border-2 border-card',
                                        agent.enabled
                                            ? 'bg-success'
                                            : 'bg-muted-foreground',
                                    )}
                                />
                            </div>

                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h3 className="truncate text-lg font-semibold tracking-tight text-foreground">
                                        {agent.name}
                                    </h3>

                                    <Badge
                                        variant="outline"
                                        className={cn(
                                            'font-mono text-2xs uppercase',
                                            agent.enabled
                                                ? 'border-success/25 bg-success/10 text-success-foreground'
                                                : 'border-border bg-foreground/[0.035] text-muted-foreground',
                                        )}
                                    >
                                        {agent.enabled ? 'enabled' : 'disabled'}
                                    </Badge>

                                    {agent.is_default && (
                                        <Badge
                                            variant="outline"
                                            className="border-primary/20 bg-primary/[0.045] font-mono text-2xs text-primary"
                                        >
                                            default
                                        </Badge>
                                    )}
                                </div>

                                <p
                                    className={cn(
                                        'mt-1 text-sm font-medium',
                                        visual.textClassName,
                                    )}
                                >
                                    {visual.label}
                                </p>

                                <div className="mt-2 flex flex-wrap gap-1.5">
                                    <Badge
                                        variant="outline"
                                        className="border-border/80 bg-background/35 font-mono text-2xs"
                                    >
                                        {harnessLabel(agent.harness)}
                                    </Badge>

                                    <Badge
                                        variant="outline"
                                        className="border-border/80 bg-background/35 font-mono text-2xs"
                                    >
                                        {agent.model ?? 'default model'}
                                    </Badge>

                                    {agent.reasoning_setting && (
                                        <Badge
                                            variant="outline"
                                            className="border-border/80 bg-background/35 font-mono text-2xs capitalize"
                                        >
                                            {agent.reasoning_setting}
                                        </Badge>
                                    )}

                                    <Badge
                                        variant="outline"
                                        className="border-primary/15 bg-primary/[0.035] font-mono text-2xs text-primary"
                                    >
                                        config v{agent.configuration_version}
                                    </Badge>
                                </div>
                            </div>
                        </div>

                        <div className="flex shrink-0 items-center gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={onEdit}
                                className="border-primary/20 bg-primary/[0.035] text-primary"
                            >
                                <Pencil />
                                Edit
                            </Button>

                            <ToggleEnabledForm
                                projectId={projectId}
                                agent={agent}
                            />
                        </div>
                    </header>

                    <div className="mt-4 grid gap-4 border-t border-border/60 pt-4 lg:grid-cols-[1.2fr_0.8fr]">
                        <SkillAssignment
                            projectId={projectId}
                            agent={agent}
                            skills={skills}
                        />

                        <WorkerBinding
                            projectId={projectId}
                            agent={agent}
                            worker={worker}
                        />
                    </div>
                </div>
            )}
        </article>
    );
}

export function AgentsPanel({
    projectId,
    agents,
    skills,
    workers,
    harnessCapabilities,
}: {
    projectId: number;
    agents: Agent[];
    skills: Skill[];
    workers: Worker[];
    harnessCapabilities: HarnessCapabilities;
}) {
    const [creating, setCreating] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);

    const enabledAgents = agents.filter((agent) => agent.enabled).length;
    const disabledAgents = agents.length - enabledAgents;
    const skillAssignments = agents.reduce(
        (total, agent) => total + agent.skills.length,
        0,
    );
    const boundWorkers = workers.filter(
        (worker) => worker.agent_id !== null,
    ).length;
    const activeWorkers = workers.filter((worker) =>
        ['working', 'recovering'].includes(worker.status),
    ).length;

    const latestHeartbeatTimestamp = workers.reduce((latest, worker) => {
        if (!worker.last_heartbeat_at) {
            return latest;
        }

        const timestamp = new Date(worker.last_heartbeat_at).getTime();

        if (Number.isNaN(timestamp)) {
            return latest;
        }

        return Math.max(latest, timestamp);
    }, 0);

    const latestHeartbeat =
        latestHeartbeatTimestamp > 0
            ? heartbeatLabel(new Date(latestHeartbeatTimestamp).toISOString())
            : '—';

    const skillDistribution = skills.map((skill) => ({
        ...skill,
        assignedAgents: agents.filter((agent) =>
            agent.skills.some((assigned) => assigned.id === skill.id),
        ).length,
    }));

    return (
        <Card className="relative overflow-hidden border-border/70 bg-card/55 shadow-panel">
            <div className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-primary/55 to-transparent" />
            <div className="pointer-events-none absolute -top-24 right-20 size-48 rounded-full bg-primary/[0.055] blur-3xl" />

            <CardHeader className="relative flex flex-row flex-wrap items-start justify-between gap-4 border-b border-border/55 pb-4">
                <div>
                    <div className="flex items-center gap-2">
                        <Bot className="size-4 text-primary" />
                        <CardTitle className="text-lg">Agents</CardTitle>
                    </div>

                    <CardDescription className="mt-1 max-w-2xl">
                        Project-scoped execution configuration and live worker
                        bindings for the Project Manager, Coder, and Reviewer
                        roles.
                    </CardDescription>
                </div>

                {!creating && (
                    <Button
                        size="sm"
                        onClick={() => {
                            setEditingId(null);
                            setCreating(true);
                        }}
                        className="shadow-glow-sm"
                    >
                        <Plus />
                        New agent
                    </Button>
                )}
            </CardHeader>

            <CardContent className="relative grid gap-4 pt-4">
                <section
                    aria-label="Agent operational summary"
                    className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6"
                >
                    <MetricTile
                        icon={Bot}
                        value={agents.length}
                        label="Total agents"
                    />
                    <MetricTile
                        icon={CheckCircle2}
                        value={enabledAgents}
                        label="Enabled"
                    />
                    <MetricTile
                        icon={Ban}
                        value={disabledAgents}
                        label="Disabled"
                    />
                    <MetricTile
                        icon={Zap}
                        value={skillAssignments}
                        label="Skill links"
                        detail={`${skills.length} project skills`}
                    />
                    <MetricTile
                        icon={Link2}
                        value={`${boundWorkers}/${workers.length}`}
                        label="Bound workers"
                    />
                    <MetricTile
                        icon={Activity}
                        value={activeWorkers}
                        label="Active workers"
                        detail={`Heartbeat ${latestHeartbeat}`}
                    />
                </section>

                {creating && (
                    <section className="panel-elevated relative overflow-hidden p-4">
                        <div className="glow-line-accent" />

                        <div className="mb-4 flex items-center gap-2 border-b border-border/60 pb-3">
                            <Plus className="size-4 text-primary" />
                            <div>
                                <p className="text-sm font-semibold">
                                    Create project agent
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Configure a supported workflow role and
                                    execution harness.
                                </p>
                            </div>
                        </div>

                        <CreateAgentForm
                            projectId={projectId}
                            harnessCapabilities={harnessCapabilities}
                            onDone={() => setCreating(false)}
                        />
                    </section>
                )}

                <section
                    aria-label="Configured project agents"
                    className="grid gap-3"
                >
                    {agents.map((agent) => {
                        const worker = workers.find(
                            (candidate) => candidate.role === agent.role,
                        );

                        return (
                            <AgentCard
                                key={agent.id}
                                projectId={projectId}
                                agent={agent}
                                worker={worker}
                                skills={skills}
                                harnessCapabilities={harnessCapabilities}
                                isEditing={editingId === agent.id}
                                onEdit={() => {
                                    setCreating(false);
                                    setEditingId(agent.id);
                                }}
                                onEditingDone={() => setEditingId(null)}
                            />
                        );
                    })}

                    {agents.length === 0 && !creating && (
                        <div className="panel-recessed grid min-h-36 place-items-center border-dashed px-6 text-center">
                            <div>
                                <Bot className="mx-auto size-7 text-muted-foreground" />
                                <p className="mt-2 text-sm font-medium">
                                    No agents configured
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Create an agent to configure a project
                                    workflow role.
                                </p>
                            </div>
                        </div>
                    )}
                </section>

                <section className="panel-elevated relative overflow-hidden p-3">
                    <div className="glow-line-secondary" />

                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-2.5">
                            <div className="grid size-8 place-items-center rounded-lg border border-primary/15 bg-primary/[0.055] text-primary">
                                <Gauge className="size-4" />
                            </div>

                            <div>
                                <p className="text-sm font-semibold">
                                    Skill distribution
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Project skills and current agent assignment
                                    counts.
                                </p>
                            </div>
                        </div>

                        <div className="flex items-center gap-1.5 font-mono text-2xs text-muted-foreground">
                            <CircleDot className="size-3 text-primary" />
                            deterministic assignment order
                        </div>
                    </div>

                    <div className="mt-3 flex flex-wrap gap-2">
                        {skillDistribution.map((skill) => (
                            <div
                                key={skill.id}
                                className={cn(
                                    'flex items-center gap-2 rounded-lg border border-border/70 bg-background/35 px-2.5 py-2',
                                    !skill.enabled && 'opacity-55',
                                )}
                            >
                                <Zap className="size-3.5 text-primary" />

                                <div>
                                    <p className="text-xs font-medium text-foreground">
                                        {skill.name}
                                    </p>
                                    <p className="font-mono text-2xs text-muted-foreground">
                                        {skill.assignedAgents}{' '}
                                        {skill.assignedAgents === 1
                                            ? 'agent'
                                            : 'agents'}{' '}
                                        · v{skill.version}
                                    </p>
                                </div>
                            </div>
                        ))}

                        {skillDistribution.length === 0 && (
                            <p className="py-2 text-xs text-muted-foreground">
                                No project skills configured.
                            </p>
                        )}
                    </div>
                </section>
            </CardContent>
        </Card>
    );
}
