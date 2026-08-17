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
                <AppContent
                    variant="sidebar"
                    className="min-w-0 overflow-x-hidden"
                >
                    <AppSidebarHeader />
                    <div className="flex min-h-0 min-w-0 w-full flex-1 flex-col [&>*]:min-h-0 [&>*]:min-w-0 [&>*]:w-full [&>*]:max-w-none [&>*]:flex-auto">
                        {children}
                    </div>
                </AppContent>
            </AppShell>
        </AppHeaderProvider>
    );
}
