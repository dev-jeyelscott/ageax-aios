export function AppBackground() {
    return (
        <div className="pointer-events-none fixed inset-0 -z-10">
            <div className="absolute inset-0 bg-[linear-gradient(rgba(56,189,248,0.025)_1px,transparent_1px),linear-gradient(90deg,rgba(56,189,248,0.025)_1px,transparent_1px)] bg-[size:32px_32px]" />
            <div className="absolute -top-32 left-1/4 size-72 rounded-full bg-cyan-500/5 blur-3xl" />
            <div className="absolute right-0 bottom-0 size-72 rounded-full bg-violet-500/5 blur-3xl" />
        </div>
    );
}
