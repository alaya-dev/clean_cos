import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';

describe('coverage configuration', () => {
    it('defines executable frontend thresholds', () => {
        const source = readFileSync('vitest.config.ts', 'utf8');
        expect(source).toContain('lines: 80');
        expect(source).toContain('branches: 70');
    });
});
