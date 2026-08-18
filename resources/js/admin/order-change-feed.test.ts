import { beforeEach, describe, expect, it, vi } from 'vitest';
import { loadAdminOrderPollingConfig, normalizeAdminOrderPollingConfig, PollingOrderChangeFeed } from './order-change-feed';

const response = (data: unknown, status = 200): Response => new Response(JSON.stringify({ data }), { status, headers: { 'Content-Type': 'application/json' } });

describe('admin order change polling', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.restoreAllMocks();
        Object.defineProperty(navigator, 'onLine', { configurable: true, value: true });
        Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
    });

    it('clamps runtime values and keeps the hidden interval at least as long as visible', () => {
        expect(normalizeAdminOrderPollingConfig({ visible_seconds: 10, hidden_seconds: 40 })).toEqual({ enabled: true, visible_seconds: 30, hidden_seconds: 60 });
        expect(normalizeAdminOrderPollingConfig({ visible_seconds: 600, hidden_seconds: 30 })).toEqual({ enabled: true, visible_seconds: 600, hidden_seconds: 600 });
        expect(normalizeAdminOrderPollingConfig({ enabled: false })).toEqual({ enabled: false, visible_seconds: 60, hidden_seconds: 120 });
    });

    it('loads the runtime configuration once and reuses it', async () => {
        const fetch = vi.fn().mockResolvedValue(response({ order_polling: { enabled: false, visible_seconds: 5, hidden_seconds: 10 } }));
        vi.stubGlobal('fetch', fetch);

        await expect(loadAdminOrderPollingConfig()).resolves.toEqual({ enabled: false, visible_seconds: 30, hidden_seconds: 60 });
        await expect(loadAdminOrderPollingConfig()).resolves.toEqual({ enabled: false, visible_seconds: 30, hidden_seconds: 60 });
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('polls once at the visible interval and resumes immediately when visible again', async () => {
        const fetch = vi.fn().mockResolvedValue(response({ changed: false, cursor: 'c2' }));
        vi.stubGlobal('fetch', fetch);
        const onChanges = vi.fn();
        const feed = new PollingOrderChangeFeed(normalizeAdminOrderPollingConfig({ visible_seconds: 60, hidden_seconds: 120 }), { onChanges });

        feed.start('c1');
        await vi.advanceTimersByTimeAsync(59_999);
        expect(fetch).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1);
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(fetch.mock.calls[0][0]).toContain('cursor=c1');
        await vi.advanceTimersByTimeAsync(0);

        Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' });
        document.dispatchEvent(new Event('visibilitychange'));
        await vi.advanceTimersByTimeAsync(119_999);
        expect(fetch).toHaveBeenCalledTimes(1);
        Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
        document.dispatchEvent(new Event('visibilitychange'));
        await vi.advanceTimersByTimeAsync(0);
        expect(fetch).toHaveBeenCalledTimes(2);
        feed.stop();
    });

    it('does not overlap requests and cancels them when stopped', async () => {
        let resolveRequest: ((value: Response) => void) | undefined;
        const pending = new Promise<Response>((resolve) => { resolveRequest = resolve; });
        const fetch = vi.fn().mockReturnValue(pending);
        vi.stubGlobal('fetch', fetch);
        const feed = new PollingOrderChangeFeed(normalizeAdminOrderPollingConfig({ visible_seconds: 30 }), { onChanges: vi.fn() });

        feed.start('c1');
        await vi.advanceTimersByTimeAsync(30_000);
        await vi.advanceTimersByTimeAsync(30_000);
        expect(fetch).toHaveBeenCalledTimes(1);
        feed.stop();
        expect(fetch.mock.calls[0][1].signal.aborted).toBe(true);
        resolveRequest?.(response({ changed: false, cursor: 'c2' }));
        await vi.runAllTimersAsync();
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('does not initialize polling when disabled', async () => {
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);
        const feed = new PollingOrderChangeFeed(normalizeAdminOrderPollingConfig({ enabled: false }), { onChanges: vi.fn() });

        feed.start('c1');
        await vi.advanceTimersByTimeAsync(180_000);
        expect(fetch).not.toHaveBeenCalled();
    });

    it('handles authentication, malformed and rate-limited responses without notifying the UI', async () => {
        const onChanges = vi.fn();
        const fetch = vi.fn()
            .mockResolvedValueOnce(new Response('', { status: 401 }))
            .mockResolvedValueOnce(response({ changed: true }))
            .mockResolvedValueOnce(new Response('', { status: 429, headers: { 'Retry-After': '2' } }));
        vi.stubGlobal('fetch', fetch);
        const feed = new PollingOrderChangeFeed(normalizeAdminOrderPollingConfig({ visible_seconds: 30 }), { onChanges });

        feed.start('c1');
        await feed.checkNow();
        expect(onChanges).not.toHaveBeenCalled();

        const second = new PollingOrderChangeFeed(normalizeAdminOrderPollingConfig({ visible_seconds: 30 }), { onChanges });
        second.start('c1');
        await second.checkNow();
        expect(onChanges).not.toHaveBeenCalled();
        second.stop();

        const third = new PollingOrderChangeFeed(normalizeAdminOrderPollingConfig({ visible_seconds: 30 }), { onChanges });
        third.start('c1');
        await third.checkNow();
        await vi.advanceTimersByTimeAsync(1_999);
        expect(fetch).toHaveBeenCalledTimes(3);
        third.stop();
    });

    it('pauses while offline and resumes on the online event', async () => {
        const fetch = vi.fn().mockResolvedValue(response({ changed: false, cursor: 'c2' }));
        vi.stubGlobal('fetch', fetch);
        const feed = new PollingOrderChangeFeed(normalizeAdminOrderPollingConfig({ visible_seconds: 30 }), { onChanges: vi.fn() });

        Object.defineProperty(navigator, 'onLine', { configurable: true, value: false });
        feed.start('c1');
        await vi.advanceTimersByTimeAsync(60_000);
        expect(fetch).not.toHaveBeenCalled();
        Object.defineProperty(navigator, 'onLine', { configurable: true, value: true });
        window.dispatchEvent(new Event('online'));
        await vi.advanceTimersByTimeAsync(0);
        expect(fetch).toHaveBeenCalledTimes(1);
        feed.stop();
    });
});
