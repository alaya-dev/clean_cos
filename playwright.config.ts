import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    // The local Laravel development server is single-process; serial browser
    // execution avoids request starvation while preserving deterministic data.
    fullyParallel: false,
    workers: 1,
    reporter: 'line',
    use: { baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8000', trace: 'retain-on-failure' },
    webServer: { command: "php -r \"file_exists('public/hot') && unlink('public/hot');\" && php artisan serve --host=127.0.0.1 --port=8000", url: 'http://127.0.0.1:8000', reuseExistingServer: true },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
