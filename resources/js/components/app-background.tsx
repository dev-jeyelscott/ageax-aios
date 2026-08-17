import { cn } from '@/lib/utils';

type Props = {
    contained?: boolean;
    className?: string;
};

export function AppBackground({
    contained = false,
    className,
}: Props) {
    return (
        <div
            aria-hidden="true"
            className={cn(
                'pointer-events-none',
                contained
                    ? 'absolute inset-0 z-0 overflow-hidden'
                    : 'fixed inset-0 -z-10',
                className,
            )}
        >
            <div className="absolute inset-0 bg-[linear-gradient(color-mix(in_oklch,var(--primary)_4%,transparent)_1px,transparent_1px),linear-gradient(90deg,color-mix(in_oklch,var(--primary)_4%,transparent)_1px,transparent_1px)] bg-size-[32px_32px]" />
            <div className="absolute -top-32 left-1/4 size-72 rounded-full bg-primary/8 blur-3xl" />
            <div className="absolute right-0 bottom-0 size-72 rounded-full bg-secondary/20 blur-3xl" />
        </div>
    );
}
