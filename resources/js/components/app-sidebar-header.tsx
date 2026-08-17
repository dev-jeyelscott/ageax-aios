import { useAppHeaderSlotContent } from '@/components/app-header-slot';
import { SidebarTrigger } from '@/components/ui/sidebar';

export function AppSidebarHeader() {
    const slotContent = useAppHeaderSlotContent();

    return (
        <header className="relative flex h-16 shrink-0 items-center gap-2 overflow-hidden border-b border-primary/10 bg-[linear-gradient(110deg,var(--sidebar),var(--background),color-mix(in_oklch,var(--primary)_18%,transparent))] px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-x-8 bottom-0 h-px bg-gradient-to-r from-transparent via-primary/30 to-transparent"
            />

            <div className="relative flex min-w-0 flex-1 items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                {slotContent}
            </div>
        </header>
    );
}
