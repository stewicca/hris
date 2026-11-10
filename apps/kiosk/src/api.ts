/**
 * Thin client for the kiosk endpoints.
 *
 * Deliberately not the shared ApiClient: that one exists to carry a session
 * cookie and an XSRF token for the logged-in apps. A terminal has no session
 * and must never acquire one — it authenticates as a device, over a header, and
 * sends `credentials: 'omit'` so a cookie left behind by someone who browsed to
 * the portal on this machine can never be attached to an attendance request.
 */

const TOKEN_KEY = 'kiosk_device_token';

export class KioskApiError extends Error {
    constructor(
        public status: number,
        message: string,
        public reason: string | null = null,
    ) {
        super(message);
        this.name = 'KioskApiError';
    }
}

export interface KioskSettings {
    device: { name: string; location: string | null };
    office_hours: Record<string, unknown>;
    break_enabled: boolean;
    shift_enabled: boolean;
    face_service_operational: boolean;
}

export type NextAction = 'check_in' | 'break_start' | 'break_end' | 'check_out';

export interface IdentifyResult {
    scan_id: string;
    expires_in: number;
    employee: { name: string; employee_number: string; department: string | null };
    next_action: NextAction;
    prompt: string;
}

export interface RecordResult {
    message: string;
    employee: { name: string };
    next_action: NextAction | null;
}

export function storedToken(): string | null {
    try {
        return localStorage.getItem(TOKEN_KEY);
    } catch {
        return null;
    }
}

export function storeToken(token: string): void {
    localStorage.setItem(TOKEN_KEY, token.trim());
}

export function clearToken(): void {
    localStorage.removeItem(TOKEN_KEY);
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
    const token = storedToken();

    const response = await fetch(`/api/kiosk${path}`, {
        ...init,
        credentials: 'omit',
        headers: {
            Accept: 'application/json',
            ...(token ? { 'X-Kiosk-Token': token } : {}),
            ...(init.headers ?? {}),
        },
    });

    const body = await response.json().catch(() => null);

    if (!response.ok) {
        throw new KioskApiError(
            response.status,
            (body?.message as string) ?? 'Terjadi kesalahan pada terminal.',
            (body?.reason as string) ?? null,
        );
    }

    return body as T;
}

export function fetchSettings(): Promise<KioskSettings> {
    return request<KioskSettings>('/settings');
}

export function identify(photo: Blob): Promise<IdentifyResult> {
    const form = new FormData();
    form.append('image', photo, 'scan.jpg');

    // No Content-Type header: the browser has to set it itself so the
    // multipart boundary travels with it.
    return request<IdentifyResult>('/identify', { method: 'POST', body: form });
}

export function recordScan(scanId: string): Promise<RecordResult> {
    return request<RecordResult>('/event', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ scan_id: scanId }),
    });
}
