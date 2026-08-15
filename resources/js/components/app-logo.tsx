import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-lg bg-gradient-to-br from-primary to-secondary text-foreground shadow-glow-accent">
                <AppLogoIcon className="size-5 fill-current" />
            </div>
            <div className="ml-1 grid flex-1 text-left">
                <span className="truncate text-sm leading-tight font-semibold">
                    AI Operating System
                </span>
                <span className="truncate text-2xs tracking-[0.16em] text-muted-foreground uppercase">
                    AGEAX AIOS
                </span>
            </div>
        </>
    );
}
