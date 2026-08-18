import { showError } from './feedback';

const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

export async function adminApi<T>(path: string, method = 'GET', body?: unknown, signal?: AbortSignal): Promise<T> {
    const multipart = body instanceof FormData;
    const endpoint = path.startsWith('/') ? path : `/api/v1/admin/${path}`;
    const response = await fetch(endpoint, {
        method,
        credentials: 'same-origin',
        signal,
        headers: {
            Accept: 'application/json',
            ...(method === 'GET' ? {} : { 'X-CSRF-TOKEN': csrf() }),
            ...(multipart || method === 'GET' ? {} : { 'Content-Type': 'application/json' }),
        },
        ...(body === undefined ? {} : { body: multipart ? body : JSON.stringify(body) }),
    });

    if (!response.ok) {
        const failure = await response.json().catch(() => null) as { message?: string; errors?: Record<string, string[]> } | null;
        if (response.status === 419) {
            window.location.reload();
            throw new Error('Votre session a expiré. La page est en cours d’actualisation.');
        }
        if (response.status === 401) {
            showError('Authentification requise.');
            throw new Error('Authentification requise.');
        }
        throw new Error(failure?.errors ? Object.values(failure.errors).flat().join(' ') : failure?.message || 'Opération impossible.');
    }

    return response.json() as Promise<T>;
}

export function dinarsToMillimes(dinars: string): number | null {
    const normalized = dinars.trim().replace(',', '.');
    if (!/^\d+(?:\.\d{0,3})?$/.test(normalized)) return null;
    const [whole, fraction = ''] = normalized.split('.');

    return Number(whole) * 1000 + Number(fraction.padEnd(3, '0'));
}

export function millimesToDinars(millimes: number | null): string {
    if (millimes === null) return '';

    return `${Math.floor(millimes / 1000)},${String(millimes % 1000).padStart(3, '0')}`;
}
