import { createContext, useContext, useEffect, useState } from 'react';
import type { ReactNode } from 'react';

type AppHeaderContextValue = {
    content: ReactNode;
    setContent: (content: ReactNode) => void;
};

const AppHeaderContext = createContext<AppHeaderContextValue | null>(null);

export function AppHeaderProvider({ children }: { children: ReactNode }) {
    const [content, setContent] = useState<ReactNode>(null);

    return (
        <AppHeaderContext.Provider value={{ content, setContent }}>
            {children}
        </AppHeaderContext.Provider>
    );
}

export function useAppHeaderSlotContent(): ReactNode {
    return useContext(AppHeaderContext)?.content ?? null;
}

/**
 * Renders `content` into the shared app header bar for as long as the
 * calling component is mounted, clearing it on unmount.
 */
export function useAppHeaderSlot(content: ReactNode) {
    const ctx = useContext(AppHeaderContext);

    useEffect(() => {
        ctx?.setContent(content);

        return () => ctx?.setContent(null);
    });
}
