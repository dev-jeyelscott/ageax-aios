import { Link, usePage } from '@inertiajs/react';
import { Bot, FolderKanban, LayoutGrid, Ticket } from 'lucide-react';
import { index as agentsIndex } from '@/actions/App/Http/Controllers/GlobalAgentController';
import { index as projectsIndex } from '@/actions/App/Http/Controllers/ProjectController';
import { index as ticketsIndex } from '@/actions/App/Http/Controllers/TicketController';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const baseNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Projects',
        href: projectsIndex(),
        icon: FolderKanban,
    },
    {
        title: 'Agents',
        href: agentsIndex(),
        icon: Bot,
    },
];

export function AppSidebar() {
    const page = usePage();
    const project = page.props.project as { id?: number } | undefined;
    const mainNavItems = [...baseNavItems];

    if (typeof project?.id === 'number') {
        mainNavItems.splice(2, 0, {
            title: 'Tickets',
            href: ticketsIndex(project.id),
            icon: Ticket,
        });
    }

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
