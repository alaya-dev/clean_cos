export type AdminOrderPollingConfig = {
    enabled: boolean;
    visible_seconds: number;
    hidden_seconds: number;
};

export type AdminOrderChangePayload = {
    changed: boolean;
    cursor: string;
    created_ids?: string[];
    updated_ids?: string[];
    deleted_ids?: string[];
    counts?: Record<string, number>;
};

type AdminRuntimeResponse = {
    data?: {
        order_polling?: Partial<AdminOrderPollingConfig>;
    };
};

type FeedOptions = {
    onChanges: (payload: AdminOrderChangePayload) => void | Promise<void>;
};

const DEFAULT_CONFIG: AdminOrderPollingConfig = { enabled: true, visible_seconds: 60, hidden_seconds: 120 };
let runtimeConfig: AdminOrderPollingConfig | null = null;
let runtimeRequest: Promise<AdminOrderPollingConfig> | null = null;

function bounded(value: unknown, fallback: number, minimum: number, maximum: number): number {
    const parsed = typeof value === 'number' && Number.isFinite(value) ? Math.trunc(value) : fallback;

    return Math.min(maximum, Math.max(minimum, parsed));
}

export function normalizeAdminOrderPollingConfig(value?: Partial<AdminOrderPollingConfig>): AdminOrderPollingConfig {
    const visible = bounded(value?.visible_seconds, DEFAULT_CONFIG.visible_seconds, 30, 600);
    const hidden = bounded(value?.hidden_seconds, DEFAULT_CONFIG.hidden_seconds, 60, 1800);

    return { enabled: value?.enabled !== false, visible_seconds: visible, hidden_seconds: Math.max(hidden, visible) };
}

export async function loadAdminOrderPollingConfig(): Promise<AdminOrderPollingConfig> {
    if (runtimeConfig) return runtimeConfig;
    if (runtimeRequest) return runtimeRequest;

    runtimeRequest = fetch('/api/v1/admin/me', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(async (response) => {
            if (!response.ok) throw new Error('Configuration de suivi indisponible.');
            const payload = await response.json() as AdminRuntimeResponse;
            runtimeConfig = normalizeAdminOrderPollingConfig(payload.data?.order_polling);

            return runtimeConfig;
        })
        .finally(() => { runtimeRequest = null; });

    return runtimeRequest;
}

export class PollingOrderChangeFeed {
    private timer: number | undefined;
    private activeRequest: Promise<void> | null = null;
    private requestController: AbortController | null = null;
    private started = false;
    private failureCount = 0;
    private cursor: string;
    private retryAfterMilliseconds: number | null = null;

    private readonly onVisibilityChange = (): void => {
        if (document.visibilityState === 'visible') {
            this.clearTimer();
            void this.check();
            return;
        }
        this.schedule(this.intervalMilliseconds());
    };

    private readonly onOnline = (): void => {
        this.clearTimer();
        void this.check();
    };

    constructor(
        private readonly config: AdminOrderPollingConfig,
        private readonly options: FeedOptions,
    ) {
        this.cursor = '';
    }

    start(cursor: string): void {
        if (this.started || !this.config.enabled) return;
        this.started = true;
        this.cursor = cursor;
        document.addEventListener('visibilitychange', this.onVisibilityChange);
        window.addEventListener('online', this.onOnline);
        this.schedule(this.intervalMilliseconds());
    }

    stop(): void {
        this.started = false;
        this.clearTimer();
        this.requestController?.abort();
        this.requestController = null;
        document.removeEventListener('visibilitychange', this.onVisibilityChange);
        window.removeEventListener('online', this.onOnline);
    }

    async checkNow(): Promise<void> {
        if (!this.started || !navigator.onLine) return;
        this.clearTimer();
        await this.check();
    }

    setCursor(cursor: string): void {
        if (cursor) this.cursor = cursor;
    }

    resetTimer(): void {
        if (!this.started) return;
        this.schedule(this.intervalMilliseconds());
    }

    private intervalMilliseconds(): number {
        const seconds = document.visibilityState === 'hidden' ? this.config.hidden_seconds : this.config.visible_seconds;

        return seconds * 1000;
    }

    private schedule(delay: number): void {
        if (!this.started || !navigator.onLine) return;
        this.clearTimer();
        this.timer = window.setTimeout(() => { void this.check(); }, delay);
    }

    private clearTimer(): void {
        if (this.timer !== undefined) window.clearTimeout(this.timer);
        this.timer = undefined;
    }

    private nextFailureDelay(): number {
        if (this.retryAfterMilliseconds !== null) {
            const delay = this.retryAfterMilliseconds;
            this.retryAfterMilliseconds = null;

            return delay;
        }
        if (this.failureCount <= 1) return this.intervalMilliseconds();

        return Math.min(300_000, Math.max(this.intervalMilliseconds(), 120_000) * 2 ** Math.min(this.failureCount - 2, 2));
    }

    private check(): Promise<void> {
        if (!this.started || !navigator.onLine) return Promise.resolve();
        if (this.activeRequest) return this.activeRequest;

        const controller = new AbortController();
        this.requestController = controller;
        this.activeRequest = (async () => {
            const response = await fetch(`/api/v1/admin/orders/changes${this.cursor ? `?cursor=${encodeURIComponent(this.cursor)}` : ''}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            if (response.status === 401) {
                this.stop();
                return;
            }
            if (response.status === 419) {
                this.stop();
                window.location.reload();
                return;
            }
            if (response.status === 429) {
                const retryAfterHeader = response.headers.get('Retry-After');
                const retryAfter = retryAfterHeader === null || retryAfterHeader.trim() === '' ? Number.NaN : Number(retryAfterHeader);
                if (Number.isFinite(retryAfter) && retryAfter >= 0) this.retryAfterMilliseconds = Math.min(300_000, retryAfter * 1000);
                else if (retryAfterHeader) {
                    const retryAt = Date.parse(retryAfterHeader);
                    if (Number.isFinite(retryAt)) this.retryAfterMilliseconds = Math.min(300_000, Math.max(0, retryAt - Date.now()));
                }
                throw new Error('RATE_LIMITED');
            }
            if (!response.ok) throw new Error(`POLLING_${response.status}`);

            const payload = await response.json() as { data?: AdminOrderChangePayload };
            if (!payload.data?.cursor) throw new Error('INVALID_POLLING_RESPONSE');
            this.cursor = payload.data.cursor;
            this.failureCount = 0;
            await this.options.onChanges(payload.data);
        })()
            .catch(() => { this.failureCount += 1; })
            .finally(() => {
                this.activeRequest = null;
                if (this.requestController === controller) this.requestController = null;
                if (this.started) this.schedule(this.failureCount ? this.nextFailureDelay() : this.intervalMilliseconds());
            });

        return this.activeRequest;
    }
}
