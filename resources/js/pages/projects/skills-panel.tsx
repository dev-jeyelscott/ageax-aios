import { Form } from '@inertiajs/react';
import {
    Ban,
    CheckCircle2,
    Code2,
    FileText,
    Pencil,
    Plus,
    Search,
    ShieldCheck,
    Sparkles,
    Trash2,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

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
    created_at: string;
    updated_at: string;
};

type RoleValue =
    'project_manager' | 'coder' | 'reviewer' | 'knowledge_architect';

type RoleOption = {
    value: RoleValue;
    label: string;
    shortLabel: string;
    icon: LucideIcon;
    className: string;
};

type StatusFilter = 'all' | 'enabled' | 'disabled';
type RoleFilter = 'all' | RoleValue;
type SortOrder = 'updated_desc' | 'name_asc' | 'version_desc';

const ROLE_OPTIONS: RoleOption[] = [
    {
        value: 'project_manager',
        label: 'Project Manager',
        shortLabel: 'PM',
        icon: Sparkles,
        className:
            'border-secondary/30 bg-secondary/15 text-secondary-foreground',
    },
    {
        value: 'coder',
        label: 'Coder',
        shortLabel: 'Coder',
        icon: Code2,
        className: 'border-primary/30 bg-primary/10 text-primary',
    },
    {
        value: 'reviewer',
        label: 'Reviewer',
        shortLabel: 'Reviewer',
        icon: ShieldCheck,
        className: 'border-success/30 bg-success/10 text-success-foreground',
    },
    {
        value: 'knowledge_architect',
        label: 'Knowledge Architect',
        shortLabel: 'Knowledge',
        icon: FileText,
        className: 'border-warning/30 bg-warning/10 text-warning-foreground',
    },
];

const TEXTAREA_CLASS_NAME =
    'w-full rounded-lg border border-input bg-background/60 px-3 py-2 text-sm text-foreground shadow-none outline-none transition-[color,background-color,border-color,box-shadow] placeholder:text-muted-foreground hover:border-primary/20 hover:bg-background/75 focus-visible:border-primary/50 focus-visible:ring-[3px] focus-visible:ring-primary/20';

function roleOption(role: string): RoleOption | null {
    return ROLE_OPTIONS.find((candidate) => candidate.value === role) ?? null;
}

function roleLabel(role: string): string {
    return roleOption(role)?.label ?? role.replaceAll('_', ' ');
}

function formatDate(value: string): string {
    const timestamp = Date.parse(value);

    if (Number.isNaN(timestamp)) {
        return 'Unknown';
    }

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(timestamp);
}

function sortableTimestamp(value: string): number {
    const timestamp = Date.parse(value);

    return Number.isNaN(timestamp) ? 0 : timestamp;
}

function roleCoverage(skill: Skill): string {
    if (skill.applicable_roles.length === 0) {
        return 'All roles';
    }

    return `${skill.applicable_roles.length} ${
        skill.applicable_roles.length === 1 ? 'role' : 'roles'
    }`;
}

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
        <div className="grid gap-4">
            <div className="panel-recessed relative overflow-hidden p-3">
                <div className="glow-line-accent" />
                <div className="flex items-start gap-2.5">
                    <Sparkles className="mt-0.5 size-4 shrink-0 text-primary" />
                    <div>
                        <p className="text-xs font-medium text-foreground">
                            Declarative context capability
                        </p>
                        <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                            Skill content is injected as prompt/context
                            guidance. It is not executable code and cannot
                            control AIOS workflow state.
                        </p>
                    </div>
                </div>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <div className="grid gap-1.5">
                    <Label htmlFor="name">Name</Label>
                    <Input
                        id="name"
                        name="name"
                        defaultValue={initial.name ?? ''}
                        required
                        aria-invalid={Boolean(errors.name)}
                        placeholder="Acceptance Criteria Engineering"
                    />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor="description">Description</Label>
                    <Input
                        id="description"
                        name="description"
                        defaultValue={initial.description ?? ''}
                        aria-invalid={Boolean(errors.description)}
                        placeholder="What this capability contributes to an Agent."
                    />
                    <InputError message={errors.description} />
                </div>
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="instructions">Instructions</Label>
                <textarea
                    id="instructions"
                    name="instructions"
                    rows={5}
                    defaultValue={initial.instructions ?? ''}
                    required
                    aria-invalid={Boolean(errors.instructions)}
                    className={TEXTAREA_CLASS_NAME}
                    placeholder="Deterministic guidance injected into an assigned Agent's context."
                />
                <InputError message={errors.instructions} />
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="constraints">Constraints</Label>
                <textarea
                    id="constraints"
                    name="constraints"
                    rows={3}
                    defaultValue={initial.constraints ?? ''}
                    aria-invalid={Boolean(errors.constraints)}
                    className={TEXTAREA_CLASS_NAME}
                    placeholder="Optional boundaries that this Skill must respect."
                />
                <InputError message={errors.constraints} />
            </div>

            <div className="grid gap-2">
                <div>
                    <Label>Applicable roles</Label>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Leave every role unselected to make the Skill universal.
                    </p>
                </div>

                <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    {ROLE_OPTIONS.map((role) => {
                        const selected = roles.includes(role.value);
                        const Icon = role.icon;

                        return (
                            <label
                                key={role.value}
                                className={cn(
                                    'flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2.5 text-xs font-medium transition-[color,background-color,border-color,box-shadow]',
                                    selected
                                        ? role.className
                                        : 'border-border-subtle bg-foreground/[0.025] text-muted-foreground hover:border-primary/20 hover:bg-primary/5 hover:text-foreground',
                                )}
                            >
                                <Checkbox
                                    checked={selected}
                                    onCheckedChange={(checked) => {
                                        const shouldSelect = checked === true;

                                        setRoles((current) => {
                                            if (shouldSelect) {
                                                return current.includes(
                                                    role.value,
                                                )
                                                    ? current
                                                    : [...current, role.value];
                                            }

                                            return current.filter(
                                                (value) => value !== role.value,
                                            );
                                        });
                                    }}
                                />

                                {selected && (
                                    <input
                                        type="hidden"
                                        name="applicable_roles[]"
                                        value={role.value}
                                    />
                                )}

                                <Icon className="size-3.5 shrink-0" />
                                <span>{role.label}</span>
                            </label>
                        );
                    })}
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
            className="grid gap-4"
        >
            {({ errors, processing }) => (
                <>
                    <SkillFields initial={{ enabled: true }} errors={errors} />

                    <div className="flex flex-wrap justify-end gap-2 border-t border-border-subtle pt-4">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onDone}
                        >
                            Cancel
                        </Button>

                        <Button type="submit" disabled={processing}>
                            <Plus />
                            Create skill
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
            className="grid gap-4"
        >
            {({ errors, processing }) => (
                <>
                    <SkillFields initial={skill} errors={errors} />

                    <div className="flex flex-wrap justify-end gap-2 border-t border-border-subtle pt-4">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onDone}
                        >
                            Cancel
                        </Button>

                        <Button type="submit" disabled={processing}>
                            Save changes
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

function SummaryTile({
    icon: Icon,
    label,
    value,
    detail,
}: {
    icon: LucideIcon;
    label: string;
    value: number;
    detail: string;
}) {
    return (
        <div className="tile-inset flex items-center gap-3 px-3 py-2.5">
            <div className="grid size-8 shrink-0 place-items-center rounded-lg border border-primary/20 bg-primary/8 text-primary">
                <Icon className="size-4" />
            </div>

            <div className="min-w-0">
                <p className="font-mono text-2xs tracking-[0.14em] text-muted-foreground uppercase">
                    {label}
                </p>
                <div className="mt-0.5 flex items-baseline gap-1.5">
                    <span className="text-lg font-semibold text-foreground">
                        {value}
                    </span>
                    <span className="truncate text-2xs text-muted-foreground">
                        {detail}
                    </span>
                </div>
            </div>
        </div>
    );
}

function RoleBadges({ skill }: { skill: Skill }) {
    if (skill.applicable_roles.length === 0) {
        return (
            <Badge
                variant="outline"
                className="border-primary/20 bg-primary/5 text-primary"
            >
                <Users className="size-3" />
                All roles
            </Badge>
        );
    }

    return skill.applicable_roles.map((role) => {
        const option = roleOption(role);

        if (!option) {
            return (
                <Badge
                    key={role}
                    variant="outline"
                    className="border-border bg-foreground/[0.035] text-muted-foreground"
                >
                    <Users className="size-3" />
                    {roleLabel(role)}
                </Badge>
            );
        }

        const Icon = option.icon;

        return (
            <Badge key={role} variant="outline" className={option.className}>
                <Icon className="size-3" />
                {option.shortLabel}
            </Badge>
        );
    });
}

function SkillCard({
    projectId,
    skill,
    onEdit,
}: {
    projectId: number;
    skill: Skill;
    onEdit: () => void;
}) {
    return (
        <section
            className={cn(
                'group relative overflow-hidden rounded-xl border border-border/70 bg-card/45 p-3.5 transition-[background-color,border-color,box-shadow,opacity] hover:border-primary/25 hover:bg-card/60 hover:shadow-panel',
                !skill.enabled && 'opacity-70',
            )}
        >
            <div className="pointer-events-none absolute inset-x-5 top-0 h-px bg-gradient-to-r from-transparent via-primary/0 to-transparent transition group-hover:via-primary/45" />

            <div className="grid gap-3 xl:grid-cols-[minmax(0,1fr)_13rem_auto] xl:items-center">
                <div className="flex min-w-0 items-start gap-3">
                    <div
                        className={cn(
                            'relative grid size-11 shrink-0 place-items-center rounded-xl border',
                            skill.enabled
                                ? 'border-primary/25 bg-primary/8 text-primary shadow-glow-sm'
                                : 'border-border bg-foreground/[0.025] text-muted-foreground',
                        )}
                    >
                        <Sparkles className="size-5" />

                        <span
                            className={cn(
                                'absolute -right-0.5 -bottom-0.5 size-2.5 rounded-full border-2 border-card',
                                skill.enabled
                                    ? 'bg-success'
                                    : 'bg-muted-foreground',
                            )}
                        />
                    </div>

                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h3 className="truncate text-sm font-semibold text-foreground">
                                {skill.name}
                            </h3>

                            <Badge
                                variant="outline"
                                className="border-border bg-background/40 font-mono text-2xs text-muted-foreground"
                            >
                                v{skill.version}
                            </Badge>

                            <Badge
                                variant="outline"
                                className={cn(
                                    'font-mono text-2xs',
                                    skill.enabled
                                        ? 'border-success/25 bg-success/10 text-success-foreground'
                                        : 'border-border bg-foreground/[0.035] text-muted-foreground',
                                )}
                            >
                                <span
                                    className={cn(
                                        'size-1.5 rounded-full',
                                        skill.enabled
                                            ? 'bg-success'
                                            : 'bg-muted-foreground',
                                    )}
                                />
                                {skill.enabled ? 'enabled' : 'disabled'}
                            </Badge>
                        </div>

                        {skill.description ? (
                            <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                                {skill.description}
                            </p>
                        ) : (
                            <p className="mt-1 text-xs text-muted-foreground italic">
                                No description configured.
                            </p>
                        )}

                        <div className="mt-2 flex flex-wrap gap-1.5">
                            <RoleBadges skill={skill} />
                        </div>

                        <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 font-mono text-2xs text-muted-foreground">
                            <span>{skill.slug}</span>
                            <span aria-hidden="true">•</span>
                            <span>
                                {skill.constraints
                                    ? 'constraints defined'
                                    : 'no explicit constraints'}
                            </span>
                        </div>
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-2 xl:grid-cols-1">
                    <div className="tile-inset px-2.5 py-2">
                        <p className="font-mono text-2xs tracking-[0.12em] text-muted-foreground uppercase">
                            Role coverage
                        </p>
                        <p className="mt-0.5 text-xs font-medium text-foreground">
                            {roleCoverage(skill)}
                        </p>
                    </div>

                    <div className="tile-inset px-2.5 py-2">
                        <p className="font-mono text-2xs tracking-[0.12em] text-muted-foreground uppercase">
                            Updated
                        </p>
                        <p className="mt-0.5 text-xs font-medium text-foreground">
                            {formatDate(skill.updated_at)}
                        </p>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-1.5 xl:justify-end">
                    <Button size="sm" variant="outline" onClick={onEdit}>
                        <Pencil />
                        Edit
                    </Button>

                    <ToggleEnabledForm projectId={projectId} skill={skill} />

                    <Form {...destroySkill.form([projectId, skill.id])}>
                        {({ processing }) => (
                            <Button
                                type="submit"
                                size="sm"
                                variant="ghost"
                                disabled={processing}
                                aria-label={`Delete ${skill.name}`}
                                title="Delete skill"
                                className="text-muted-foreground hover:text-destructive-foreground"
                            >
                                <Trash2 className="size-3.5" />
                            </Button>
                        )}
                    </Form>
                </div>
            </div>
        </section>
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
    const [query, setQuery] = useState('');
    const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');
    const [roleFilter, setRoleFilter] = useState<RoleFilter>('all');
    const [sortOrder, setSortOrder] = useState<SortOrder>('updated_desc');

    const enabledCount = skills.filter((skill) => skill.enabled).length;
    const universalCount = skills.filter(
        (skill) => skill.applicable_roles.length === 0,
    ).length;

    const filteredSkills = useMemo(() => {
        const normalizedQuery = query.trim().toLowerCase();

        return [...skills]
            .filter((skill) => {
                const searchableText = [
                    skill.name,
                    skill.slug,
                    skill.description ?? '',
                    skill.instructions,
                    skill.constraints ?? '',
                    skill.applicable_roles.length === 0
                        ? 'all roles universal'
                        : skill.applicable_roles
                              .map((role) => roleLabel(role))
                              .join(' '),
                ]
                    .join(' ')
                    .toLowerCase();

                const matchesQuery =
                    normalizedQuery === '' ||
                    searchableText.includes(normalizedQuery);

                const matchesStatus =
                    statusFilter === 'all' ||
                    (statusFilter === 'enabled' && skill.enabled) ||
                    (statusFilter === 'disabled' && !skill.enabled);

                const matchesRole =
                    roleFilter === 'all' ||
                    skill.applicable_roles.length === 0 ||
                    skill.applicable_roles.includes(roleFilter);

                return matchesQuery && matchesStatus && matchesRole;
            })
            .sort((left, right) => {
                if (sortOrder === 'name_asc') {
                    return left.name.localeCompare(right.name);
                }

                if (sortOrder === 'version_desc') {
                    return (
                        right.version - left.version ||
                        left.name.localeCompare(right.name)
                    );
                }

                return (
                    sortableTimestamp(right.updated_at) -
                        sortableTimestamp(left.updated_at) ||
                    left.name.localeCompare(right.name)
                );
            });
    }, [query, roleFilter, skills, sortOrder, statusFilter]);

    const hasFilters =
        query !== '' ||
        statusFilter !== 'all' ||
        roleFilter !== 'all' ||
        sortOrder !== 'updated_desc';

    const resetFilters = () => {
        setQuery('');
        setStatusFilter('all');
        setRoleFilter('all');
        setSortOrder('updated_desc');
    };

    return (
        <Card className="panel-elevated relative overflow-hidden border-border/70 bg-background/70 text-foreground">
            <div className="glow-line-accent" />

            <CardHeader className="border-b border-border-subtle pb-4">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div className="flex min-w-0 items-start gap-3">
                        <div className="grid size-10 shrink-0 place-items-center rounded-xl border border-primary/25 bg-primary/10 text-primary shadow-glow-sm">
                            <Sparkles className="size-5" />
                        </div>

                        <div className="min-w-0">
                            <p className="font-mono text-2xs tracking-[0.16em] text-primary uppercase">
                                Context library
                            </p>
                            <CardTitle className="mt-0.5">Skills</CardTitle>
                            <CardDescription className="mt-1 max-w-3xl">
                                Declarative, non-executable prompt and context
                                capabilities assigned deterministically to
                                Agents.
                            </CardDescription>
                        </div>
                    </div>

                    {!creating && (
                        <Button
                            size="sm"
                            onClick={() => {
                                setEditingId(null);
                                setCreating(true);
                            }}
                            className="shrink-0"
                        >
                            <Plus />
                            New skill
                        </Button>
                    )}
                </div>
            </CardHeader>

            <CardContent className="grid gap-4 pt-4">
                <div className="grid gap-2 sm:grid-cols-3">
                    <SummaryTile
                        icon={Sparkles}
                        label="Definitions"
                        value={skills.length}
                        detail="skills"
                    />
                    <SummaryTile
                        icon={CheckCircle2}
                        label="Operational"
                        value={enabledCount}
                        detail="enabled"
                    />
                    <SummaryTile
                        icon={Users}
                        label="Universal"
                        value={universalCount}
                        detail="all roles"
                    />
                </div>

                <div className="panel-recessed grid gap-2 p-2.5 lg:grid-cols-[minmax(14rem,1fr)_auto_auto_auto]">
                    <div className="relative">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            aria-label="Search skills"
                            placeholder="Search skills by name or keyword…"
                            className="pl-9"
                        />
                    </div>

                    <Select
                        value={roleFilter}
                        onValueChange={(value) =>
                            setRoleFilter(value as RoleFilter)
                        }
                    >
                        <SelectTrigger
                            aria-label="Filter skills by role"
                            className="w-full lg:w-44"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent align="end">
                            <SelectItem value="all">All roles</SelectItem>
                            {ROLE_OPTIONS.map((role) => (
                                <SelectItem key={role.value} value={role.value}>
                                    {role.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={statusFilter}
                        onValueChange={(value) =>
                            setStatusFilter(value as StatusFilter)
                        }
                    >
                        <SelectTrigger
                            aria-label="Filter skills by status"
                            className="w-full lg:w-36"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent align="end">
                            <SelectItem value="all">All status</SelectItem>
                            <SelectItem value="enabled">Enabled</SelectItem>
                            <SelectItem value="disabled">Disabled</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        value={sortOrder}
                        onValueChange={(value) =>
                            setSortOrder(value as SortOrder)
                        }
                    >
                        <SelectTrigger
                            aria-label="Sort skills"
                            className="w-full lg:w-40"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent align="end">
                            <SelectItem value="updated_desc">
                                Recently updated
                            </SelectItem>
                            <SelectItem value="name_asc">Name A-Z</SelectItem>
                            <SelectItem value="version_desc">
                                Highest version
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {creating && (
                    <section className="panel-recessed relative overflow-hidden p-4">
                        <div className="glow-line-accent" />

                        <div className="mb-4">
                            <p className="font-mono text-2xs tracking-[0.14em] text-primary uppercase">
                                New definition
                            </p>
                            <h3 className="mt-0.5 text-sm font-semibold text-foreground">
                                Create project Skill
                            </h3>
                        </div>

                        <CreateSkillForm
                            projectId={projectId}
                            onDone={() => setCreating(false)}
                        />
                    </section>
                )}

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p className="font-mono text-2xs tracking-[0.14em] text-muted-foreground uppercase">
                            Context inventory
                        </p>
                        <p
                            aria-live="polite"
                            className="mt-0.5 text-xs text-muted-foreground"
                        >
                            Showing {filteredSkills.length} of {skills.length}{' '}
                            {skills.length === 1 ? 'skill' : 'skills'}
                        </p>
                    </div>

                    {hasFilters && (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={resetFilters}
                            className="text-xs text-muted-foreground"
                        >
                            Reset filters
                        </Button>
                    )}
                </div>

                <div className="grid gap-2.5">
                    {filteredSkills.map((skill) =>
                        editingId === skill.id ? (
                            <section
                                key={skill.id}
                                className="panel-recessed relative overflow-hidden p-4"
                            >
                                <div className="glow-line-secondary" />

                                <div className="mb-4">
                                    <p className="font-mono text-2xs tracking-[0.14em] text-secondary-foreground uppercase">
                                        Editing v{skill.version}
                                    </p>
                                    <h3 className="mt-0.5 text-sm font-semibold text-foreground">
                                        {skill.name}
                                    </h3>
                                </div>

                                <EditSkillForm
                                    projectId={projectId}
                                    skill={skill}
                                    onDone={() => setEditingId(null)}
                                />
                            </section>
                        ) : (
                            <SkillCard
                                key={skill.id}
                                projectId={projectId}
                                skill={skill}
                                onEdit={() => {
                                    setCreating(false);
                                    setEditingId(skill.id);
                                }}
                            />
                        ),
                    )}
                </div>

                {skills.length === 0 && !creating && (
                    <div className="panel-recessed grid place-items-center px-6 py-12 text-center">
                        <div className="grid size-11 place-items-center rounded-xl border border-primary/20 bg-primary/5 text-primary">
                            <Sparkles className="size-5" />
                        </div>
                        <p className="mt-3 text-sm font-medium text-foreground">
                            No Skills configured yet
                        </p>
                        <p className="mt-1 max-w-md text-xs leading-relaxed text-muted-foreground">
                            Create a declarative capability and assign it to
                            compatible Agents from the Agent management console.
                        </p>
                    </div>
                )}

                {skills.length > 0 && filteredSkills.length === 0 && (
                    <div className="panel-recessed grid place-items-center px-6 py-10 text-center">
                        <Search className="size-5 text-muted-foreground" />
                        <p className="mt-2 text-sm font-medium text-foreground">
                            No matching Skills
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Adjust the search query or filters to restore
                            results.
                        </p>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={resetFilters}
                            className="mt-3"
                        >
                            Reset filters
                        </Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
