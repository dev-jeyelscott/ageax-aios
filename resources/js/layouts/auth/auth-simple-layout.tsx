import { Link } from '@inertiajs/react';
import { AppBackground } from '@/components/app-background';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="relative flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            <AppBackground />

            <div className="relative w-full max-w-sm">
                <div className="flex flex-col gap-8 rounded-2xl border border-transparent p-0 dark:border-cyan-300/15 dark:bg-slate-950/70 dark:p-8 dark:shadow-[0_24px_90px_rgba(2,6,23,0.6)] dark:backdrop-blur">
                    <div className="flex flex-col items-center gap-4">
                        <Link
                            href={home()}
                            className="flex flex-col items-center gap-2 font-medium"
                        >
                            <div className="mb-1 flex h-9 w-9 items-center justify-center rounded-md dark:shadow-[0_0_20px_rgba(34,211,238,0.35)]">
                                <AppLogoIcon className="size-9 fill-current text-foreground" />
                            </div>
                            <span className="sr-only">{title}</span>
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-medium">{title}</h1>
                            <p className="text-center text-sm text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
