import type { Auth } from '@/types/auth';

type VoiceCapabilities = {
    enabled: boolean;
    transcription_url: string;
    max_audio_bytes: number;
    max_duration_seconds: number;
};

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            voice: VoiceCapabilities;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
