import { ref } from 'vue';

export const adminNewOrderCount = ref(0);
export const adminOrderAttentionPulse = ref(false);

let pulseTimer: ReturnType<typeof setTimeout> | undefined;
let countRequest: Promise<void> | undefined;

export function setAdminNewOrderCount(count: number): void {
    adminNewOrderCount.value = Math.max(0, Math.trunc(count));
}

export async function refreshAdminNewOrderCount(): Promise<void> {
    if (countRequest) return countRequest;
    countRequest = (async () => {
        try {
            const response = await fetch('/api/v1/admin/me', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const payload = await response.json() as { data?: { new_orders_count?: number } };
            if (payload.data?.new_orders_count !== undefined) setAdminNewOrderCount(payload.data.new_orders_count);
        } finally {
            countRequest = undefined;
        }
    })();
    return countRequest;
}

export function pulseAdminOrderAttention(): void {
    if (pulseTimer !== undefined) clearTimeout(pulseTimer);
    adminOrderAttentionPulse.value = false;
    if (typeof requestAnimationFrame === 'function') {
        requestAnimationFrame(() => { adminOrderAttentionPulse.value = true; });
    } else {
        setTimeout(() => { adminOrderAttentionPulse.value = true; }, 0);
    }
    pulseTimer = setTimeout(() => { adminOrderAttentionPulse.value = false; }, 700);
}
