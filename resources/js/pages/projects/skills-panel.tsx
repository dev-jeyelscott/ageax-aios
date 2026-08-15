import { Form } from '@inertiajs/react';
import { Ban, CheckCircle2, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import {
    destroy as destroySkill,
    store as storeSkill,
    update as updateSkill,
} from '@/actions/App/Http/Controllers/SkillController';
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
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

export type Skill = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    instructions: string;
    constraints: string | null;
    applicable_roles: string[];
    enabled: boolean;
    version: number;
};

const ROLES = [
    { value: 'project_manager', label: 'Project Manager' },
    { value: 'coder', label: 'Coder' },
    { value: 'reviewer', label: 'Reviewer' },
    { value: 'knowledge_architect', label: 'Knowledge Architect' },
];

function SkillFields({
    initial,
    errors,
}: {
    initial: Partial<Skill>;
    errors: Record<string, string | undefined>;
}) {
    const [roles, setRoles] = useState<string[]>(
        initial.applicable_roles ?? [],
    );

    return (
        <div className="grid gap-3">
            <p className="text-xs text-muted-foreground">
                This content is declarative prompt/context guidance for
                Agents — it is not executable code.
            </p>
            <div className="grid gap-1.5">
                <Label htmlFor="name">Name</Label>
                <input
                    id="name"
                    name="name"
                    defaultValue={initial.name ?? ''}
                    required
                    className="border-input flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                />
                <InputError message={errors.name} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="description">Description</Label>
                <input
                    id="description"
                    name="description"
                    defaultValue={initial.description ?? ''}
                    className="border-input flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                />
                <InputError message={errors.description} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="instructions">Instructions</Label>
                <textarea
                    id="instructions"
                    name="instructions"
                    rows={4}
                    defaultValue={initial.instructions ?? ''}
                    required
                    className="w-full rounded-md border bg-background p-3 text-sm"
                    placeholder="Deterministic guidance injected into an assigned agent's context."
                />
                <InputError message={errors.instructions} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="constraints">Constraints</Label>
                <textarea
                    id="constraints"
                    name="constraints"
                    rows={2}
                    defaultValue={initial.constraints ?? ''}
                    className="w-full rounded-md border bg-background p-3 text-sm"
                />
                <InputError message={errors.constraints} />
            </div>
            <div className="grid gap-1.5">
                <Label>Applicable roles</Label>
                <p className="text-xs text-muted-foreground">
                    Leave empty to apply to every role.
                </p>
                <div className="flex flex-wrap gap-3">
                    {ROLES.map((role) => (
                        <label
                            key={role.value}
                            className="flex items-center gap-1.5 text-sm"
                        >
                            <Checkbox
                                checked={roles.includes(role.value)}
                                onCheckedChange={(checked) =>
                                    setRoles((current) =>
                                        checked
                                            ? [...current, role.value]
                                            : current.filter(
                                                  (value) =>
                                                      value !== role.value,
                                              ),
                                    )
                                }
                            />
                            {roles.includes(role.value) && (
                                <input
                                    type="hidden"
                                    name="applicable_roles[]"
                                    value={role.value}
                                />
                            )}
                            {role.label}
                        </label>
                    ))}
                </div>
                <InputError message={errors.applicable_roles} />
            </div>
            <input
                type="hidden"
                name="enabled"
                value={(initial.enabled ?? true) ? '1' : '0'}
            />
        </div>
    );
}

function CreateSkillForm({
    projectId,
    onDone,
}: {
    projectId: number;
    onDone: () => void;
}) {
    return (
        <Form
            {...storeSkill.form(projectId)}
            resetOnSuccess
            onSuccess={onDone}
            className="grid gap-3"
        >
            {({ errors, processing }) => (
                <>
                    <SkillFields initial={{ enabled: true }} errors={errors} />
                    <div className="flex gap-2">
                        <Button type="submit" disabled={processing}>
                            <Plus /> Create skill
                        </Button>
                        <Button type="button" variant="outline" onClick={onDone}>
                            Cancel
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function EditSkillForm({
    projectId,
    skill,
    onDone,
}: {
    projectId: number;
    skill: Skill;
    onDone: () => void;
}) {
    return (
        <Form
            {...updateSkill.form([projectId, skill.id])}
            onSuccess={onDone}
            className="grid gap-3"
        >
            {({ errors, processing }) => (
                <>
                    <SkillFields initial={skill} errors={errors} />
                    <div className="flex gap-2">
                        <Button type="submit" disabled={processing}>
                            Save changes
                        </Button>
                        <Button type="button" variant="outline" onClick={onDone}>
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
    skill,
}: {
    projectId: number;
    skill: Skill;
}) {
    return (
        <Form {...updateSkill.form([projectId, skill.id])} className="inline">
            {({ processing }) => (
                <>
                    <input type="hidden" name="name" value={skill.name} />
                    <input
                        type="hidden"
                        name="description"
                        value={skill.description ?? ''}
                    />
                    <input
                        type="hidden"
                        name="instructions"
                        value={skill.instructions}
                    />
                    <input
                        type="hidden"
                        name="constraints"
                        value={skill.constraints ?? ''}
                    />
                    {skill.applicable_roles.map((role) => (
                        <input
                            key={role}
                            type="hidden"
                            name="applicable_roles[]"
                            value={role}
                        />
                    ))}
                    <input
                        type="hidden"
                        name="enabled"
                        value={skill.enabled ? '0' : '1'}
                    />
                    <Button
                        type="submit"
                        size="sm"
                        variant="outline"
                        disabled={processing}
                    >
                        {skill.enabled ? <Ban /> : <CheckCircle2 />}
                        {skill.enabled ? 'Disable' : 'Enable'}
                    </Button>
                </>
            )}
        </Form>
    );
}

export function SkillsPanel({
    projectId,
    skills,
}: {
    projectId: number;
    skills: Skill[];
}) {
    const [creating, setCreating] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between gap-3">
                <div>
                    <CardTitle>Skills</CardTitle>
                    <CardDescription>
                        Declarative, non-executable prompt/context guidance
                        assignable to Agents.
                    </CardDescription>
                </div>
                {!creating && (
                    <Button size="sm" onClick={() => setCreating(true)}>
                        <Plus /> New skill
                    </Button>
                )}
            </CardHeader>
            <CardContent className="grid gap-4">
                {creating && (
                    <div className="rounded-lg border p-4">
                        <CreateSkillForm
                            projectId={projectId}
                            onDone={() => setCreating(false)}
                        />
                    </div>
                )}
                {skills.map((skill) => (
                    <div key={skill.id} className="rounded-lg border p-4">
                        {editingId === skill.id ? (
                            <EditSkillForm
                                projectId={projectId}
                                skill={skill}
                                onDone={() => setEditingId(null)}
                            />
                        ) : (
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <span className="font-medium">
                                            {skill.name}
                                        </span>
                                        <Badge variant="outline">
                                            v{skill.version}
                                        </Badge>
                                        <Badge
                                            variant={
                                                skill.enabled
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {skill.enabled
                                                ? 'enabled'
                                                : 'disabled'}
                                        </Badge>
                                    </div>
                                    {skill.description && (
                                        <p className="text-sm text-muted-foreground">
                                            {skill.description}
                                        </p>
                                    )}
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {skill.applicable_roles.length === 0
                                            ? 'Applies to every role'
                                            : skill.applicable_roles.join(
                                                  ', ',
                                              )}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            setEditingId(skill.id)
                                        }
                                    >
                                        <Pencil /> Edit
                                    </Button>
                                    <ToggleEnabledForm
                                        projectId={projectId}
                                        skill={skill}
                                    />
                                    <Form
                                        {...destroySkill.form([
                                            projectId,
                                            skill.id,
                                        ])}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="ghost"
                                                disabled={processing}
                                                title="Delete skill"
                                            >
                                                <Trash2 className="size-3.5" />
                                            </Button>
                                        )}
                                    </Form>
                                </div>
                            </div>
                        )}
                    </div>
                ))}
                {skills.length === 0 && !creating && (
                    <p className="py-6 text-center text-muted-foreground">
                        No skills configured yet.
                    </p>
                )}
            </CardContent>
        </Card>
    );
}
