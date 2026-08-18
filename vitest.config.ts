import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.ts'],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'html', 'json-summary'],
            thresholds: { lines: 80, functions: 80, statements: 80, branches: 70 },
        },
    },
});
