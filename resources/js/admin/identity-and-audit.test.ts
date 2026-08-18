import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

describe('identity and audit modules', () => {
    it('uses French labels and safe fields', () => {
        const users = readFileSync('resources/js/admin/users.ts', 'utf8');
        const audit = readFileSync('resources/js/admin/audit-logs.ts', 'utf8');
        expect(users).toContain('Utilisateurs');
        expect(audit).toContain('Journal d’audit');
        expect(users).toContain('password_confirmation');
        expect(audit).toContain('changesFor');
        expect(audit).toContain('formatChange');
        expect(audit).toContain('scopeOptions: [{ value: \'\', label: \'Toutes les actions\' }');
        expect(audit).toContain('actorLabel');
        expect(audit).toContain('targetLabel');
        expect(audit).toContain('retentionLabel');
        expect(audit).not.toContain('{{ log.before }}');
        expect(audit).not.toContain('{{ log.after }}');
    });
});
