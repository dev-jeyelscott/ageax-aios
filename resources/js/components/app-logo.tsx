import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-lg bg-gradient-to-br from-primary to-secondary text-foreground shadow-glow-accent">
                <AppLogoIcon className="size-5 fill-current" />
            </div>
            <div className="ml-1 grid flex-1 font-['Goldman'] text-left">
                <span className="truncate text-sm leading-tight font-bold">
                    AGEAX
                </span>
                <span className="truncate text-2xs font-normal tracking-[0.16em] text-muted-foreground">
                    AI Operating System
                </span>
            </div>
        </>
    );
}
