import * as React from 'react';
import { AppBackground } from '@/components/app-background';
import { SidebarInset } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import type { AppVariant } from '@/types';

type Props = React.ComponentProps<'main'> & {
    variant?: AppVariant;
};

export function AppContent({
    variant = 'sidebar',
    children,
    className,
    ...props
}: Props) {
    if (variant === 'sidebar') {
        return (
            <SidebarInset
                className={cn('isolate min-h-0 min-w-0', className)}
                {...props}
            >
                <AppBackground contained />

                <div className="relative z-10 flex min-h-0 w-full min-w-0 flex-1 flex-col">
                    {children}
                </div>
            </SidebarInset>
        );
    }

    return (
        <main
            className={cn(
                'flex min-h-0 w-full min-w-0 flex-1 flex-col gap-4 rounded-xl',
                className,
            )}
            {...props}
        >
            {children}
        </main>
    );
}
