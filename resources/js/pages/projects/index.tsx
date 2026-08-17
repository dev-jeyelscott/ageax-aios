import { Form, Head, Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ChevronRight,
    Clock3,
    FolderGit2,
    FolderKanban,
    FolderPlus,
    GitCommitHorizontal,
    Pause,
    Play,
    Plus,
    Trash2,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import {
    destroy,
    show,
    store,
} from '@/actions/App/Http/Controllers/ProjectController';
import { AppBackground } from '@/components/app-background';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

type Project = {
    id: number;
    name: string;
    path: string;
    status: string;
    git_status: string;
    updated_at: string;
};

function titleCase(value: string): string {
    return value
        .replace(/[._-]+/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

function formatUpdatedAt(value: string): string {
    const timestamp = new Date(value);

    if (Number.isNaN(timestamp.getTime())) {
        return 'Unknown';
    }

    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone: 'UTC',
        timeZoneName: 'short',
    }).format(timestamp);
}

function projectStatusTone(status: string): string {
    if (status === 'running') {
        return 'border-success/25 bg-success/10 text-success';
    }

    if (status === 'paused') {
        return 'border-warning/25 bg-warning/10 text-warning';
    }

    if (status === 'stopping') {
        return 'border-primary/25 bg-primary/10 text-primary';
    }

    return 'border-border bg-muted/30 text-muted-foreground';
}

function gitStatusTone(status: string): string {
    if (status === 'clean') {
        return 'text-success';
    }

    return 'text-warning';
}

function MetricCard({
    label,
    value,
    detail,
    icon: Icon,
    iconClassName,
}: {
    label: string;
    value: number;
    detail: string;
    icon: LucideIcon;
    iconClassName?: string;
}) {
    return (
        <div className="panel-elevated relative overflow-hidden p-4">
            <div className="glow-line-accent" />

            <div className="flex items-center gap-3">
                <div
                    className={cn(
                        'flex size-10 shrink-0 items-center justify-center rounded-lg border border-primary/20 bg-primary/8 text-primary',
                        iconClassName,
                    )}
                >
                    <Icon className="size-5" aria-hidden="true" />
                </div>

                <div className="min-w-0">
                    <p className="font-mono text-2xs tracking-[0.14em] text-muted-foreground uppercase">
                        {label}
                    </p>

                    <div className="mt-1 flex items-end gap-2">
                        <span className="text-2xl font-semibold tracking-tight text-foreground">
                            {value}
                        </span>

                        <span className="pb-0.5 text-xs text-muted-foreground">
                            {detail}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    );
}

function ProjectSignal({
    label,
    value,
    icon: Icon,
    valueClassName,
}: {
    label: string;
    value: string;
    icon: LucideIcon;
    valueClassName?: string;
}) {
    return (
        <div className="tile-inset min-w-0 p-3">
            <div className="flex items-center gap-1.5 font-mono text-2xs tracking-[0.1em] text-muted-foreground uppercase">
                <Icon className="size-3.5" aria-hidden="true" />
                {label}
            </div>

            <p
                className={cn(
                    'mt-2 truncate text-sm font-medium text-foreground',
                    valueClassName,
                )}
                title={value}
            >
                {value}
            </p>
        </div>
    );
}

function AddProjectDialog() {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    className="h-10 gap-2 px-4 shadow-glow-sm"
                >
                    <Plus className="size-4" aria-hidden="true" />
                    Add project
                </Button>
            </DialogTrigger>

            <DialogContent className="overflow-hidden border-primary/20 bg-card/95 p-0 shadow-panel-lifted sm:max-w-2xl">
                <div className="glow-line-accent" />

                <div className="border-b border-border-subtle px-6 py-5">
                    <div className="flex items-center gap-3">
                        <div className="flex size-10 shrink-0 items-center justify-center rounded-lg border border-primary/20 bg-primary/10 text-primary shadow-glow-sm">
                            <FolderPlus className="size-5" aria-hidden="true" />
                        </div>

                        <div>
                            <DialogTitle className="text-lg font-semibold tracking-tight">
                                Add project
                            </DialogTitle>

                            <DialogDescription className="mt-1 text-sm">
                                Create a new Git project or register an existing
                                repository inside the configured workspace.
                            </DialogDescription>
                        </div>
                    </div>
                </div>

                <Form
                    {...store.form()}
                    onError={() => setOpen(true)}
                    onSuccess={() => setOpen(false)}
                    className="p-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-5">
                                <div className="grid gap-2">
                                    <label
                                        htmlFor="mode"
                                        className="text-xs font-medium text-foreground"
                                    >
                                        Project type
                                    </label>

                                    <Select
                                        name="mode"
                                        defaultValue="create"
                                        required
                                        disabled={processing}
                                    >
                                        <SelectTrigger
                                            id="mode"
                                            className="h-11 w-full border-primary/15 bg-surface-sunken/70"
                                        >
                                            <SelectValue placeholder="Select project type" />
                                        </SelectTrigger>

                                        <SelectContent align="start">
                                            <SelectItem value="create">
                                                <FolderPlus
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                                Create new
                                            </SelectItem>

                                            <SelectItem value="existing">
                                                <FolderGit2
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                                Add existing
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>

                                    <InputError message={errors.mode} />
                                </div>

                                <div className="grid gap-2">
                                    <label
                                        htmlFor="name"
                                        className="text-xs font-medium text-foreground"
                                    >
                                        Name
                                    </label>

                                    <Input
                                        id="name"
                                        name="name"
                                        placeholder="e.g. my-awesome-project"
                                        required
                                        disabled={processing}
                                        className="h-11 border-primary/15 bg-surface-sunken/70"
                                    />

                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <label
                                        htmlFor="path"
                                        className="text-xs font-medium text-foreground"
                                    >
                                        Workspace path
                                    </label>

                                    <Input
                                        id="path"
                                        name="path"
                                        placeholder="e.g. my-project"
                                        required
                                        disabled={processing}
                                        className="h-11 border-primary/15 bg-surface-sunken/70 font-mono"
                                    />

                                    <InputError message={errors.path} />
                                </div>
                            </div>

                            <DialogFooter className="mt-6 border-t border-border-subtle pt-5">
                                <DialogClose asChild>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        disabled={processing}
                                    >
                                        Cancel
                                    </Button>
                                </DialogClose>

                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="shadow-glow-sm"
                                >
                                    <Plus
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {processing
                                        ? 'Adding project…'
                                        : 'Add project'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function ProjectCard({ project }: { project: Project }) {
    const isRunning = project.status === 'running';
    const isPaused = project.status === 'paused';

    return (
        <article className="relative">
            <Link
                href={show(project).url}
                className="group block rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                aria-label={`Open ${project.name}`}
            >
                <div className="panel-elevated relative h-full overflow-hidden transition-colors group-hover:border-primary/40 group-hover:bg-card/85">
                    <div className="glow-line-accent opacity-70 transition-opacity group-hover:opacity-100" />

                    <div className="border-b border-border-subtle p-5 pr-14">
                        <div className="flex min-w-0 items-start gap-3">
                            <div className="flex size-10 shrink-0 items-center justify-center rounded-lg border border-primary/20 bg-primary/8 text-primary shadow-glow-sm">
                                <FolderGit2
                                    className="size-5"
                                    aria-hidden="true"
                                />
                            </div>

                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h2 className="truncate text-lg font-semibold tracking-tight text-foreground">
                                        {project.name}
                                    </h2>

                                    <Badge
                                        variant="outline"
                                        className={projectStatusTone(
                                            project.status,
                                        )}
                                    >
                                        {isRunning && (
                                            <Play
                                                className="mr-1 size-3"
                                                aria-hidden="true"
                                            />
                                        )}

                                        {isPaused && (
                                            <Pause
                                                className="mr-1 size-3"
                                                aria-hidden="true"
                                            />
                                        )}

                                        {!isRunning && !isPaused && (
                                            <Activity
                                                className="mr-1 size-3"
                                                aria-hidden="true"
                                            />
                                        )}

                                        {titleCase(project.status)}
                                    </Badge>
                                </div>

                                <p
                                    className="mt-1.5 truncate font-mono text-xs text-muted-foreground"
                                    title={project.path}
                                >
                                    {project.path}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="grid gap-3 p-5 sm:grid-cols-3">
                        <ProjectSignal
                            label="Git state"
                            value={titleCase(project.git_status)}
                            icon={GitCommitHorizontal}
                            valueClassName={gitStatusTone(project.git_status)}
                        />

                        <ProjectSignal
                            label="Project state"
                            value={titleCase(project.status)}
                            icon={Activity}
                            valueClassName={
                                project.status === 'running'
                                    ? 'text-success'
                                    : project.status === 'paused'
                                      ? 'text-warning'
                                      : 'text-primary'
                            }
                        />

                        <ProjectSignal
                            label="Updated"
                            value={formatUpdatedAt(project.updated_at)}
                            icon={Clock3}
                        />
                    </div>

                    <div className="flex items-center border-t border-border-subtle bg-surface-recessed/40 px-5 py-3 font-mono text-2xs text-muted-foreground">
                        <span
                            className={cn(
                                'mr-2 size-2 rounded-full',
                                project.status === 'running' &&
                                    'status-glow-pulse bg-success text-success',
                                project.status === 'paused' && 'bg-warning',
                                project.status === 'stopping' &&
                                    'status-glow-pulse bg-primary text-primary',
                            )}
                        />

                        <span>
                            Git-backed project · {titleCase(project.git_status)}
                        </span>

                        <span className="ml-auto inline-flex items-center gap-1 text-primary">
                            Open project
                            <ChevronRight
                                className="size-3"
                                aria-hidden="true"
                            />
                        </span>
                    </div>
                </div>
            </Link>

            <div className="absolute top-4 right-4 z-20">
                <Dialog>
                    <DialogTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8 border border-transparent text-muted-foreground hover:border-destructive/20 hover:bg-destructive/10 hover:text-destructive"
                            aria-label={`Delete ${project.name}`}
                        >
                            <Trash2 className="size-4" aria-hidden="true" />
                        </Button>
                    </DialogTrigger>

                    <DialogContent>
                        <DialogTitle>Delete {project.name}?</DialogTitle>

                        <DialogDescription>
                            This permanently deletes the project and all of its
                            roadmaps, tasks, agents, and run history. This
                            cannot be undone.
                        </DialogDescription>

                        <Form
                            {...destroy.form(project)}
                            options={{
                                preserveScroll: true,
                            }}
                        >
                            {({ processing }) => (
                                <DialogFooter className="gap-2">
                                    <DialogClose asChild>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                        >
                                            Cancel
                                        </Button>
                                    </DialogClose>

                                    <Button
                                        variant="destructive"
                                        disabled={processing}
                                        asChild
                                    >
                                        <button type="submit">
                                            {processing
                                                ? 'Deleting…'
                                                : 'Delete project'}
                                        </button>
                                    </Button>
                                </DialogFooter>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </article>
    );
}

export default function ProjectsIndex({ projects }: { projects: Project[] }) {
    const runningProjects = projects.filter(
        (project) => project.status === 'running',
    ).length;

    const pausedProjects = projects.filter(
        (project) => project.status === 'paused',
    ).length;

    const gitAttentionProjects = projects.filter(
        (project) => project.git_status !== 'clean',
    ).length;

    return (
        <>
            <Head title="Projects" />

            <div className="relative min-h-full overflow-hidden">
                <AppBackground contained />

                <div className="relative z-10 flex w-full flex-col gap-5 p-4 sm:p-6 lg:p-8">
                    <header className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div className="max-w-3xl">
                            <div className="mb-3 inline-flex items-center gap-2 rounded-md border border-primary/15 bg-primary/5 px-2.5 py-1 font-mono text-2xs tracking-[0.1em] text-primary uppercase">
                                <FolderKanban
                                    className="size-3"
                                    aria-hidden="true"
                                />
                                Git-backed workspace orchestration
                            </div>

                            <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">
                                AIOS projects
                            </h1>

                            <p className="mt-2 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                                Register and operate AIOS-managed repositories
                                inside the configured workspace while durable
                                workflow and Git state remain controlled by
                                AIOS.
                            </p>
                        </div>

                        <div className="flex flex-wrap items-center gap-2 self-start">
                            <div className="inline-flex h-10 items-center gap-2 rounded-md border border-primary/15 bg-primary/5 px-3 font-mono text-2xs text-muted-foreground">
                                <span className="size-2 rounded-full bg-primary shadow-glow-sm" />
                                Durable project registry
                            </div>

                            <AddProjectDialog />
                        </div>
                    </header>

                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <MetricCard
                            label="Total projects"
                            value={projects.length}
                            detail="registered"
                            icon={FolderKanban}
                        />

                        <MetricCard
                            label="Running"
                            value={runningProjects}
                            detail="active"
                            icon={Play}
                            iconClassName="border-success/20 bg-success/8 text-success"
                        />

                        <MetricCard
                            label="Paused"
                            value={pausedProjects}
                            detail="suspended"
                            icon={Pause}
                            iconClassName="border-warning/20 bg-warning/8 text-warning"
                        />

                        <MetricCard
                            label="Git attention"
                            value={gitAttentionProjects}
                            detail="non-clean"
                            icon={AlertTriangle}
                            iconClassName="border-secondary-foreground/20 bg-secondary/20 text-secondary-foreground"
                        />
                    </section>

                    <section
                        className="grid min-w-0 gap-4 xl:grid-cols-2"
                        aria-label="Registered projects"
                    >
                        {projects.map((project) => (
                            <ProjectCard key={project.id} project={project} />
                        ))}

                        {projects.length === 0 && (
                            <div className="panel-elevated relative col-span-full overflow-hidden p-10 text-center">
                                <div className="glow-line-accent" />

                                <FolderGit2
                                    className="mx-auto size-9 text-muted-foreground"
                                    aria-hidden="true"
                                />

                                <h2 className="mt-3 text-sm font-medium text-foreground">
                                    No projects registered
                                </h2>

                                <p className="mx-auto mt-1 max-w-md text-xs leading-relaxed text-muted-foreground">
                                    Use the Add project button above to create a
                                    new Git-backed project or register an
                                    existing repository.
                                </p>
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}
