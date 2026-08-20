import { Link, usePage } from '@inertiajs/react';
import { index as knowledgeImprovementsIndex } from '@/actions/App/Http/Controllers/KnowledgeImprovementController';
import { show as showProject } from '@/actions/App/Http/Controllers/ProjectController';
import { index as ticketsIndex } from '@/actions/App/Http/Controllers/TicketController';
import { AppContent } from '@/components/app-content';
import { AppHeaderProvider } from '@/components/app-header-slot';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import type { AppLayoutProps } from '@/types';

type ProjectSection =
    | 'overview'
    | 'agents'
    | 'skills'
    | 'tasks'
    | 'tickets'
    | 'knowledge'
    | 'activity';

type ProjectReference = {
    id?: number;
};

type ProjectSectionItem = {
    value: ProjectSection;
    label: string;
    href: string;
};

const projectShowSections = ['agents', 'skills', 'tasks', 'activity'] as const;

function splitInertiaUrl(url: string): {
    path: string;
    query: string;
} {
    const withoutHash = url.split('#')[0] ?? '';
    const [path = '', query = ''] = withoutHash.split('?');

    return {
        path,
        query,
    };
}

function projectSectionItems(projectId: number): ProjectSectionItem[] {
    return [
        {
            value: 'overview',
            label: 'Overview',
            href: showProject(projectId).url,
        },
        {
            value: 'agents',
            label: 'Agents',
            href: showProject(projectId, {
                query: { tab: 'agents' },
            }).url,
        },
        {
            value: 'skills',
            label: 'Skills',
            href: showProject(projectId, {
                query: { tab: 'skills' },
            }).url,
        },
        {
            value: 'tasks',
            label: 'Tasks',
            href: showProject(projectId, {
                query: { tab: 'tasks' },
            }).url,
        },
        {
            value: 'tickets',
            label: 'Tickets',
            href: ticketsIndex(projectId).url,
        },
        {
            value: 'knowledge',
            label: 'Knowledge',
            href: knowledgeImprovementsIndex(projectId).url,
        },
        {
            value: 'activity',
            label: 'Recent Activity',
            href: showProject(projectId, {
                query: { tab: 'activity' },
            }).url,
        },
    ];
}

function resolveProjectSection(url: string, projectId: number): ProjectSection {
    const { path: currentPath, query } = splitInertiaUrl(url);
    const projectPath = splitInertiaUrl(showProject(projectId).url).path;
    const ticketsPath = splitInertiaUrl(ticketsIndex(projectId).url).path;
    const knowledgePath = splitInertiaUrl(
        knowledgeImprovementsIndex(projectId).url,
    ).path;

    if (
        currentPath === ticketsPath ||
        currentPath.startsWith(`${ticketsPath}/`)
    ) {
        return 'tickets';
    }

    if (
        currentPath === knowledgePath ||
        currentPath.startsWith(`${knowledgePath}/`)
    ) {
        return 'knowledge';
    }

    if (currentPath.startsWith(`${projectPath}/tasks/`)) {
        return 'tasks';
    }

    if (currentPath.startsWith(`${projectPath}/agent-runs/`)) {
        return 'activity';
    }

    if (currentPath !== projectPath) {
        return 'overview';
    }

    const requestedSection = new URLSearchParams(query).get('tab');

    return projectShowSections.some((section) => section === requestedSection)
        ? (requestedSection as ProjectSection)
        : 'overview';
}

export default function AppSidebarLayout({ children }: AppLayoutProps) {
    const page = usePage();
    const project = page.props.project as ProjectReference | undefined;
    const projectId = typeof project?.id === 'number' ? project.id : null;
    const activeSection =
        projectId === null ? null : resolveProjectSection(page.url, projectId);
    const projectSections =
        projectId === null ? [] : projectSectionItems(projectId);

    return (
        <AppHeaderProvider>
            <AppShell variant="sidebar">
                <AppSidebar />

                <AppContent
                    variant="sidebar"
                    className="min-w-0 overflow-x-hidden"
                >
                    <AppSidebarHeader />

                    {projectId !== null && (
                        <div className="shrink-0 px-3 pt-3 md:px-4">
                            <nav
                                aria-label="Project sections"
                                className="flex gap-1 overflow-x-auto rounded-xl border border-border/80 bg-background/60 p-1"
                            >
                                {projectSections.map(
                                    ({ value, label, href }) => (
                                        <Link
                                            key={value}
                                            href={href}
                                            aria-current={
                                                activeSection === value
                                                    ? 'page'
                                                    : undefined
                                            }
                                            className={`shrink-0 rounded-lg border px-3 py-1.5 text-xs font-medium transition focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none ${
                                                activeSection === value
                                                    ? 'glow-border border-primary/20 bg-primary/10 text-primary/80'
                                                    : 'border-transparent text-muted-foreground hover:bg-foreground/3 hover:text-foreground'
                                            }`}
                                        >
                                            {label}
                                        </Link>
                                    ),
                                )}
                            </nav>
                        </div>
                    )}

                    <div className="flex min-h-0 w-full min-w-0 flex-1 flex-col [&>*]:min-h-0 [&>*]:w-full [&>*]:max-w-none [&>*]:min-w-0 [&>*]:flex-auto">
                        {children}
                    </div>
                </AppContent>
            </AppShell>
        </AppHeaderProvider>
    );
}
