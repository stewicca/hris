import type { Auth } from '@/types/auth';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            features?: {
                leave?: boolean;
                break?: boolean;
                shift?: boolean;
                payroll?: boolean;
            };
            flash?: {
                success?: string | null;
                error?: string | null;
            };
            [key: string]: unknown;
        };
    }
}
