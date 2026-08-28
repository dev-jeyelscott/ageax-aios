import { Link } from '@inertiajs/react';
import {
    Bot,
    BrainCircuit,
    FolderKanban,
    Gauge,
    LayoutGrid,
    ShieldAlert,
} from 'lucide-react';
import { index as agentsIndex } from '@/actions/App/Http/Controllers/GlobalAgentController';
import { index as harnessScorecardsIndex } from '@/actions/App/Http/Controllers/HarnessScorecardController';
import { index as orchestratorRecommendationsIndex } from '@/actions/App/Http/Controllers/OrchestratorRecommendationController';
import { index as projectsIndex } from '@/actions/App/Http/Controllers/ProjectController';
import { index as ticketOperationsIndex } from '@/actions/App/Http/Controllers/TicketOperationsController';
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

const mainNavItems: NavItem[] = [
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
        title: 'Ticket Operations',
        href: ticketOperationsIndex(),
        icon: ShieldAlert,
    },
    {
        title: 'Harness Scorecards',
        href: harnessScorecardsIndex(),
        icon: Gauge,
    },
    {
        title: 'Orchestrator',
        href: orchestratorRecommendationsIndex(),
        icon: BrainCircuit,
    },
    {
        title: 'Agents',
        href: agentsIndex(),
        icon: Bot,
    },
];

/**
 * Render the authenticated AGEAX application navigation using the existing shared sidebar shell.
 */
export function AppSidebar() {
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
