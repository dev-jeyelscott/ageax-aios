import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { index as projectsIndex } from '@/routes/projects';

export default function Dashboard() {
    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <h1 className="text-2xl font-semibold">AGEAX AIOS 2.0</h1>
                <p className="text-muted-foreground">
                    Create or select a workspace project to start the
                    deterministic development workflow.
                </p>
                <div>
                    <Button asChild>
                        <Link href={projectsIndex().url}>Open projects</Link>
                    </Button>
                </div>
            </div>
        </>
    );
}

Dashboard.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }] };
