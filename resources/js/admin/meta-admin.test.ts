import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';

const configuration = readFileSync('resources/js/admin/meta-configuration.ts', 'utf8');
const diagnostics = readFileSync('resources/js/admin/meta-diagnostics.ts', 'utf8');

describe('Meta administration UX', () => {
    it('uses a compact saved state and reveals credentials only for editing', () => {
        expect(configuration).toContain('Configuration Meta enregistrée');
        expect(configuration).toContain('Modifier les informations Meta');
        expect(configuration).toContain('v-if="configured && !editing"');
        expect(configuration).toContain('v-if="editing"');
        expect(configuration).toContain('Annuler');
    });

    it('never renders the saved token and preserves it when the field is blank', () => {
        expect(configuration).toContain('Jeton déjà enregistré — laissez vide pour le conserver');
        expect(configuration).toContain('capi_access_token: form.capi_access_token.trim() || null');
        expect(configuration).not.toContain('capi_access_token_encrypted');
        expect(configuration).not.toContain('localStorage');
    });

    it('accepts a Meta domain verification value without rendering raw HTML', () => {
        expect(configuration).toContain('Vérification du domaine Meta');
        expect(configuration).toContain('facebook_domain_verification: form.facebook_domain_verification.trim() || null');
        expect(configuration).toContain('Balise déjà enregistrée — laissez vide pour la conserver');
    });

    it('separates browser, server, queue, deduplication and mode status', () => {
        for (const label of ['Pixel navigateur', 'API Conversions serveur', 'File d’attente', 'Déduplication', 'Mode actuel']) {
            expect(configuration).toContain(label);
        }
        expect(configuration).toContain('Tester la connexion serveur');
        expect(configuration).toContain('Le test n’a pas été envoyé à Meta');
    });

    it('provides bounded diagnostic filters, pagination and readable details', () => {
        for (const label of ['Diagnostic de livraison', 'Navigateur', 'Serveur', 'Déduplication', 'Statut global']) {
            expect(diagnostics).toContain(label);
        }
        expect(diagnostics).toContain('filters.page');
        expect(diagnostics).toContain('meta.last_page');
        expect(diagnostics).toContain('Détail de l’événement');
        expect(diagnostics).toContain('request_sent');
        expect(diagnostics).not.toContain('capi_access_token');
        expect(diagnostics).not.toContain('test_event_code');
    });
});
