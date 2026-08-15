import { useAppHeaderSlotContent } from '@/components/app-header-slot';
import { SidebarTrigger } from '@/components/ui/sidebar';

export function AppSidebarHeader() {
    const slotContent = useAppHeaderSlotContent();

    return (
        <header className="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex min-w-0 flex-1 items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                {slotContent}
            </div>
        </header>
    );
}
