import { AppContent } from '@/components/app-content';
import { AppHeaderProvider } from '@/components/app-header-slot';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({ children }: AppLayoutProps) {
    return (
        <AppHeaderProvider>
            <AppShell variant="sidebar">
                <AppSidebar />
                <AppContent variant="sidebar" className="overflow-x-hidden">
                    <AppSidebarHeader />
                    {children}
                </AppContent>
            </AppShell>
        </AppHeaderProvider>
    );
}
