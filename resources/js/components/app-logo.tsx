import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-lg bg-gradient-to-br from-primary to-secondary text-foreground shadow-glow-accent">
                <AppLogoIcon className="size-5 fill-current" />
            </div>

            <div className="ml-1 flex min-w-0 flex-1 flex-col items-center justify-center text-center font-['Goldman']">
                <span className="w-full truncate text-lg leading-none font-bold tracking-[0.08em]">
                    AGEAX
                </span>

                <span className="mt-0.5 w-full truncate text-[0.75rem] leading-tight font-normal tracking-[0.08em] text-muted-foreground">
                    AI Operating System
                </span>
            </div>
        </>
    );
}
