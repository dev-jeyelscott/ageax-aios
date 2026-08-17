import { Form, Head, Link } from '@inertiajs/react';
import { FolderGit2, Plus, Trash2 } from 'lucide-react';
import { destroy, show, store } from '@/actions/App/Http/Controllers/ProjectController';
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

type Project = {
    id: number;
    name: string;
    path: string;
    status: string;
    git_status: string;
};

export default function ProjectsIndex({ projects }: { projects: Project[] }) {
    return (
        <>
            <Head title="Projects" />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-8">
                <div>
                    <h1 className="text-2xl font-semibold">AIOS projects</h1>
                    <p className="text-muted-foreground">
                        Register a Git-backed project inside the configured
                        workspace.
                    </p>
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>Add project</CardTitle>
                        <CardDescription>
                            Create a new Git project or register an existing Git
                            repository inside the workspace.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...store.form()}
                            className="grid gap-4 md:grid-cols-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-2">
                                        <label htmlFor="mode">
                                            Project type
                                        </label>
                                        <select
                                            id="mode"
                                            name="mode"
                                            defaultValue="create"
                                            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                        >
                                            <option value="create">
                                                Create new
                                            </option>
                                            <option value="existing">
                                                Add existing
                                            </option>
                                        </select>
                                        <InputError message={errors.mode} />
                                    </div>
                                    <div className="grid gap-2">
                                        <label htmlFor="name">Name</label>
                                        <Input id="name" name="name" required />
                                        <InputError message={errors.name} />
                                    </div>
                                    <div className="grid gap-2">
                                        <label htmlFor="path">
                                            Workspace path
                                        </label>
                                        <Input
                                            id="path"
                                            name="path"
                                            placeholder="my-project"
                                            required
                                        />
                                        <InputError message={errors.path} />
                                    </div>
                                    <Button
                                        className="self-end"
                                        disabled={processing}
                                    >
                                        <Plus />
                                        Add project
                                    </Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>
                <div className="grid gap-4 md:grid-cols-2">
                    {projects.map((project) => (
                        <Card
                            key={project.id}
                            className="relative h-full transition-colors hover:bg-accent"
                        >
                            <Link
                                href={show(project).url}
                                className="absolute inset-0 z-0 rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                aria-label={`Open ${project.name}`}
                            />
                            <CardHeader>
                                <div className="flex items-center justify-between gap-4">
                                    <CardTitle className="flex items-center gap-2">
                                        <FolderGit2 className="size-5" />
                                        {project.name}
                                    </CardTitle>
                                    <div className="relative z-10 flex items-center gap-2">
                                        <Badge variant="secondary">
                                            {project.status}
                                        </Badge>
                                        <Dialog>
                                            <DialogTrigger asChild>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8 text-muted-foreground hover:text-destructive"
                                                    aria-label={`Delete ${project.name}`}
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </DialogTrigger>
                                            <DialogContent>
                                                <DialogTitle>
                                                    Delete {project.name}?
                                                </DialogTitle>
                                                <DialogDescription>
                                                    This permanently deletes
                                                    the project and all of its
                                                    roadmaps, tasks, agents,
                                                    and run history. This
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
                                                            <DialogClose
                                                                asChild
                                                            >
                                                                <Button variant="secondary">
                                                                    Cancel
                                                                </Button>
                                                            </DialogClose>
                                                            <Button
                                                                variant="destructive"
                                                                disabled={
                                                                    processing
                                                                }
                                                                asChild
                                                            >
                                                                <button type="submit">
                                                                    Delete
                                                                    project
                                                                </button>
                                                            </Button>
                                                        </DialogFooter>
                                                    )}
                                                </Form>
                                            </DialogContent>
                                        </Dialog>
                                    </div>
                                </div>
                                <CardDescription className="truncate">
                                    {project.path}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="text-sm text-muted-foreground">
                                Git: {project.git_status}
                            </CardContent>
                        </Card>
                    ))}
                    {projects.length === 0 && (
                        <Card>
                            <CardContent className="py-10 text-center text-muted-foreground">
                                No projects are registered yet.
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </>
    );
}
