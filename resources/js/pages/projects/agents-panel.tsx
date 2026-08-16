import { Form } from '@inertiajs/react';
import { Ban, CheckCircle2, Pencil, Plus, X } from 'lucide-react';
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

const ROLES = [
    { value: 'project_manager', label: 'Project Manager' },
    { value: 'coder', label: 'Coder' },
    { value: 'reviewer', label: 'Reviewer' },
];

function roleLabel(role: string): string {
    return ROLES.find((r) => r.value === role)?.label ?? role;
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
            className="grid gap-3"
        >
            {({ errors, processing }) => (
                <>
                    <AgentFields
                        harnessCapabilities={harnessCapabilities}
                        initial={{ enabled: true }}
                        errors={errors}
                        roleField={{ editable: true, options: ROLES }}
                    />
                    <div className="flex gap-2">
                        <Button type="submit" disabled={processing}>
                            <Plus /> Create agent
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
            className="grid gap-3"
        >
            {({ errors, processing }) => (
                <>
                    <AgentFields
                        harnessCapabilities={harnessCapabilities}
                        initial={agent}
                        errors={errors}
                        roleField={{ editable: true, options: ROLES }}
                    />
                    <div className="flex gap-2">
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
        <div className="grid gap-2">
            <p className="text-xs font-medium text-muted-foreground">
                Assigned skills
            </p>
            {agent.skills.length === 0 && (
                <p className="text-xs text-muted-foreground">
                    No skills assigned.
                </p>
            )}
            <div className="grid gap-1.5">
                {agent.skills.map((skill, index) => (
                    <div
                        key={skill.id}
                        className="flex items-center justify-between gap-2 rounded-md border px-2 py-1.5 text-sm"
                    >
                        <span>
                            {index + 1}. {skill.name}{' '}
                            <span className="text-muted-foreground">
                                v{skill.version}
                            </span>
                            {!skill.enabled && (
                                <Badge variant="outline" className="ml-2">
                                    disabled
                                </Badge>
                            )}
                        </span>
                        <div className="flex items-center gap-1">
                            {index > 0 && (
                                <ReorderButton
                                    projectId={projectId}
                                    agent={agent}
                                    index={index}
                                    swapWith={index - 1}
                                    label="↑"
                                />
                            )}
                            {index < agent.skills.length - 1 && (
                                <ReorderButton
                                    projectId={projectId}
                                    agent={agent}
                                    index={index}
                                    swapWith={index + 1}
                                    label="↓"
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
                                        size="sm"
                                        variant="ghost"
                                        disabled={processing}
                                        title="Remove skill"
                                    >
                                        <X className="size-3.5" />
                                    </Button>
                                )}
                            </Form>
                        </div>
                    </div>
                ))}
            </div>
            {available.length > 0 && (
                <Form
                    {...assignSkill.form([projectId, agent.id])}
                    className="flex items-center gap-2"
                >
                    {({ errors, processing }) => (
                        <>
                            <select
                                name="skill_id"
                                defaultValue=""
                                required
                                className={selectClassName()}
                            >
                                <option value="" disabled>
                                    Assign a skill…
                                </option>
                                {available.map((skill) => (
                                    <option key={skill.id} value={skill.id}>
                                        {skill.name}
                                    </option>
                                ))}
                            </select>
                            <Button
                                type="submit"
                                size="sm"
                                variant="outline"
                                disabled={processing}
                            >
                                Assign
                            </Button>
                            <InputError message={errors.skill_id} />
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}

function ReorderButton({
    projectId,
    agent,
    index,
    swapWith,
    label,
}: {
    projectId: number;
    agent: Agent;
    index: number;
    swapWith: number;
    label: string;
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
                        size="sm"
                        variant="ghost"
                        disabled={processing}
                    >
                        {label}
                    </Button>
                </>
            )}
        </Form>
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
            <p className="text-xs text-muted-foreground">
                No workflow worker provisioned for this role.
            </p>
        );
    }

    const isBound = worker.agent_id === agent.id;

    return (
        <div className="flex items-center gap-2 text-xs">
            <span className="text-muted-foreground">
                {roleLabel(worker.role)} worker: {worker.status}
            </span>
            {isBound ? (
                <Badge>bound</Badge>
            ) : (
                <Form
                    {...bindWorker.form([projectId, agent.id])}
                    className="inline"
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
                            >
                                Bind to this agent
                            </Button>
                            <InputError message={errors.agent_worker_id} />
                        </>
                    )}
                </Form>
            )}
        </div>
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

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between gap-3">
                <div>
                    <CardTitle>Agents</CardTitle>
                    <CardDescription>
                        Project-scoped execution configuration for the Project
                        Manager, Coder, and Reviewer roles.
                    </CardDescription>
                </div>
                {!creating && (
                    <Button size="sm" onClick={() => setCreating(true)}>
                        <Plus /> New agent
                    </Button>
                )}
            </CardHeader>
            <CardContent className="grid gap-4">
                {creating && (
                    <div className="rounded-lg border p-4">
                        <CreateAgentForm
                            projectId={projectId}
                            harnessCapabilities={harnessCapabilities}
                            onDone={() => setCreating(false)}
                        />
                    </div>
                )}
                {agents.map((agent) => {
                    const worker = workers.find(
                        (candidate) => candidate.role === agent.role,
                    );

                    return (
                        <div key={agent.id} className="rounded-lg border p-4">
                            {editingId === agent.id ? (
                                <EditAgentForm
                                    projectId={projectId}
                                    agent={agent}
                                    harnessCapabilities={harnessCapabilities}
                                    onDone={() => setEditingId(null)}
                                />
                            ) : (
                                <>
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium">
                                                    {agent.name}
                                                </span>
                                                {agent.is_default && (
                                                    <Badge variant="outline">
                                                        default
                                                    </Badge>
                                                )}
                                                <Badge
                                                    variant={
                                                        agent.enabled
                                                            ? 'default'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {agent.enabled
                                                        ? 'enabled'
                                                        : 'disabled'}
                                                </Badge>
                                            </div>
                                            <p className="text-sm text-muted-foreground">
                                                {roleLabel(agent.role)} ·{' '}
                                                {agent.harness}
                                                {agent.model
                                                    ? ` · ${agent.model}`
                                                    : ''}
                                                {agent.reasoning_setting
                                                    ? ` · ${agent.reasoning_setting}`
                                                    : ''}{' '}
                                                · v{agent.configuration_version}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setEditingId(agent.id)
                                                }
                                            >
                                                <Pencil /> Edit
                                            </Button>
                                            <ToggleEnabledForm
                                                projectId={projectId}
                                                agent={agent}
                                            />
                                        </div>
                                    </div>
                                    <div className="mt-3 grid gap-3 border-t pt-3 sm:grid-cols-2">
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
                                </>
                            )}
                        </div>
                    );
                })}
                {agents.length === 0 && !creating && (
                    <p className="py-6 text-center text-muted-foreground">
                        No agents configured yet.
                    </p>
                )}
            </CardContent>
        </Card>
    );
}
