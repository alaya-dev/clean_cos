import { computed, onMounted, reactive, ref, type Component } from 'vue';
import '../../css/admin-meta.css';
import { adminApi } from './api';
import { showError, showToast } from './feedback';

type Mode = 'disabled' | 'test' | 'live';
type TestResult = {
    request_sent: boolean;
    http_status: number | null;
    events_received: number | null;
    error_code: string | null;
    error_subcode: string | null;
    message: string | null;
    fbtrace_id: string | null;
    classification: string | null;
    graph_api_version: string | null;
    source_url: string | null;
};
type MetaConfiguration = {
    public_id: string;
    configuration_version: number;
    state: 'proposed' | 'active' | 'superseded';
    mode: Mode;
    pixel_id: string | null;
    domain_verification_configured: boolean;
    token_configured: boolean;
    test_event_code_configured: boolean;
    tested_at: string | null;
    test_outcome: 'succeeded' | 'failed' | null;
    activated_at: string | null;
    last_test: TestResult;
};
type DeliveryDiagnostics = {
    queue_worker_state: string;
    browser_pixel_last_attempted_at: string | null;
    server_capi_last_accepted_at: string | null;
    deduplication_status: 'pending_confirmation' | 'not_observed';
};
type ConfigurationPayload = {
    data: {
        active: MetaConfiguration | null;
        proposed: MetaConfiguration | null;
        graph_api_version: string;
        delivery_diagnostics: DeliveryDiagnostics;
    };
};

const modeLabel = (mode: Mode): string => ({ disabled: 'Désactivé', test: 'Test', live: 'Production' })[mode];

const MetaConfigurationView: Component = {
    setup() {
        const loading = ref(true);
        const saving = ref(false);
        const testing = ref(false);
        const activating = ref(false);
        const editing = ref(false);
        const active = ref<MetaConfiguration | null>(null);
        const proposed = ref<MetaConfiguration | null>(null);
        const graphVersion = ref('v25.0');
        const delivery = reactive<DeliveryDiagnostics>({
            queue_worker_state: 'inconnu',
            browser_pixel_last_attempted_at: null,
            server_capi_last_accepted_at: null,
            deduplication_status: 'not_observed',
        });
        const form = reactive({ mode: 'test' as Mode, pixel_id: '', facebook_domain_verification: '', capi_access_token: '', test_event_code: '' });
        const selected = computed(() => proposed.value ?? active.value);
        const configured = computed(() => Boolean(selected.value?.pixel_id && selected.value?.token_configured));
        const testTarget = computed(() => proposed.value ?? active.value);
        const statusLabel = computed(() => selected.value?.test_outcome === 'succeeded'
            ? 'Fonctionnelle'
            : selected.value?.test_outcome === 'failed' ? 'Erreur' : 'À tester');

        const fillForm = (configuration: MetaConfiguration | null): void => {
            form.mode = configuration?.mode ?? 'test';
            form.pixel_id = configuration?.pixel_id ?? '';
            form.facebook_domain_verification = '';
            form.capi_access_token = '';
            form.test_event_code = '';
        };
        const load = async (): Promise<void> => {
            loading.value = true;
            try {
                const response = await adminApi<ConfigurationPayload>('meta/configuration');
                active.value = response.data.active;
                proposed.value = response.data.proposed;
                graphVersion.value = response.data.graph_api_version;
                Object.assign(delivery, response.data.delivery_diagnostics);
                fillForm(proposed.value ?? active.value);
                editing.value = !configured.value;
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Chargement de la configuration impossible.');
            } finally {
                loading.value = false;
            }
        };
        const save = async (): Promise<void> => {
            saving.value = true;
            try {
                const response = await adminApi<{ data: { active?: MetaConfiguration; proposed: MetaConfiguration | null; notice: string } }>('meta/configuration', 'POST', {
                    mode: form.mode,
                    pixel_id: form.pixel_id.trim() || null,
                    facebook_domain_verification: form.facebook_domain_verification.trim() || null,
                    capi_access_token: form.capi_access_token.trim() || null,
                    test_event_code: form.mode === 'test' ? (form.test_event_code.trim() || null) : null,
                    base_configuration_public_id: selected.value?.public_id ?? null,
                });
                if (response.data.active) active.value = response.data.active;
                proposed.value = response.data.proposed;
                fillForm(proposed.value ?? active.value);
                editing.value = false;
                showToast('success', response.data.notice);
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Enregistrement impossible.');
            } finally {
                saving.value = false;
            }
        };
        const test = async (): Promise<void> => {
            if (!testTarget.value || testing.value) return;
            testing.value = true;
            try {
                const response = await adminApi<{ data: { active: MetaConfiguration | null; notice: string } }>(`meta/configuration/${testTarget.value.public_id}/test`, 'POST');
                if (response.data.active) active.value = response.data.active;
                await load();
                showToast('success', response.data.notice);
            } catch (cause) {
                await load();
                showError(cause instanceof Error ? cause.message : 'Le test Meta a échoué.');
            } finally {
                testing.value = false;
            }
        };
        const activate = async (): Promise<void> => {
            if (!proposed.value || !window.confirm('Activer cette configuration en Production ? Les événements réels consentis pourront être envoyés à Meta.')) return;
            activating.value = true;
            try {
                const response = await adminApi<{ data: { active: MetaConfiguration; notice: string } }>(`meta/configuration/${proposed.value.public_id}/activate`, 'POST', {
                    configuration_version: proposed.value.configuration_version,
                    confirm_production: true,
                });
                active.value = response.data.active;
                proposed.value = null;
                fillForm(active.value);
                showToast('success', response.data.notice);
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Activation impossible.');
            } finally {
                activating.value = false;
            }
        };
        const cancel = (): void => {
            fillForm(selected.value);
            editing.value = false;
        };
        const removeToken = async (): Promise<void> => {
            if (!window.confirm('Supprimer le jeton CAPI ? Le suivi Meta sera désactivé.')) return;
            try {
                const response = await adminApi<{ data: { active: MetaConfiguration; notice: string } }>('meta/configuration/token', 'DELETE', { confirm_removal: true });
                active.value = response.data.active;
                proposed.value = null;
                fillForm(active.value);
                editing.value = true;
                showToast('success', response.data.notice);
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Suppression du jeton impossible.');
            }
        };

        onMounted(load);
        return { loading, saving, testing, activating, editing, active, proposed, selected, configured, testTarget, statusLabel, graphVersion, delivery, form, modeLabel, save, test, activate, cancel, removeToken };
    },
    template: `<section class="admin-page meta-configuration-page">
      <header class="admin-page-header"><div><p class="admin-eyebrow">Suivi Meta</p><h1>Configuration Meta</h1><p class="admin-subtitle">Pixel navigateur, API Conversions et contrôle de livraison.</p></div><RouterLink class="admin-outline" to="/meta/diagnostics">Historique des événements</RouterLink></header>
      <p v-if="loading" class="admin-loading">Chargement de la configuration…</p>
      <template v-else>
        <section v-if="configured && !editing" class="meta-saved-card"><div><p class="admin-eyebrow">Configuration Meta enregistrée</p><h2>Pixel et API Conversions configurés</h2><p>Mode {{ modeLabel(selected.mode) }} · API Graph {{ graphVersion }}<template v-if="selected.tested_at"> · Dernier test {{ new Date(selected.tested_at).toLocaleString('fr-TN') }}</template></p><small v-if="selected.domain_verification_configured">Domaine Meta : balise de vérification enregistrée.</small></div><span class="admin-badge" :class="{ 'is-published': selected.test_outcome === 'succeeded', 'is-danger': selected.test_outcome === 'failed' }">{{ statusLabel }}</span><button class="admin-outline" type="button" @click="editing = true">Modifier les informations Meta</button></section>

        <form v-if="editing" class="category-form meta-form" @submit.prevent="save"><header><div><p class="admin-eyebrow">Configuration Meta</p><h2>{{ configured ? 'Modifier les informations' : 'Configurer le suivi' }}</h2></div></header>
          <fieldset class="meta-mode-picker"><legend>Mode</legend><label><input v-model="form.mode" type="radio" value="disabled"><strong>Désactivé</strong><small>Aucun envoi Pixel ou CAPI.</small></label><label><input v-model="form.mode" type="radio" value="test"><strong>Test</strong><small>Validation dans Meta Test Events.</small></label><label><input v-model="form.mode" type="radio" value="live"><strong>Production</strong><small>Événements réels avec consentement.</small></label></fieldset>
          <label class="meta-domain-verification">Vérification du domaine Meta <small>{{ selected?.domain_verification_configured ? 'Balise déjà enregistrée — laissez vide pour la conserver.' : 'Facultatif : collez la balise Meta complète ou uniquement sa valeur content.' }}</small><input v-model.trim="form.facebook_domain_verification" maxlength="400" placeholder="&lt;meta name=&quot;facebook-domain-verification&quot; content=&quot;…&quot;&gt;"></label>
          <div v-if="form.mode !== 'disabled'" class="form-grid"><label>Identifiant Pixel<input v-model.trim="form.pixel_id" inputmode="numeric" pattern="[0-9]{5,30}" required></label><label>Jeton CAPI <small>{{ selected?.token_configured ? 'Jeton CAPI déjà enregistré — laissez vide pour le conserver.' : 'Saisissez le jeton CAPI.' }}</small><input v-model="form.capi_access_token" type="password" autocomplete="new-password" :placeholder="selected?.token_configured ? 'Jeton déjà enregistré — laissez vide pour le conserver' : 'Jeton CAPI'"></label><label v-if="form.mode === 'test'">Code d’événement de test<input v-model.trim="form.test_event_code" maxlength="120" :required="!selected?.test_event_code_configured" :placeholder="selected?.test_event_code_configured ? 'Code déjà enregistré — saisissez-le uniquement pour le remplacer' : 'TEST12345'"></label></div>
          <footer class="sticky-save-bar"><button v-if="configured" class="text-link" type="button" @click="cancel">Annuler</button><button v-if="selected?.token_configured" class="text-link danger" type="button" @click="removeToken">Supprimer le jeton</button><button class="admin-action" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button></footer>
        </form>

        <section class="meta-section"><header><div><p class="admin-eyebrow">État du suivi</p><h2>Canaux et disponibilité</h2></div></header><div class="meta-status-grid">
          <article><span aria-hidden="true">◉</span><div><strong>Pixel navigateur</strong><p>{{ delivery.browser_pixel_last_attempted_at ? 'Tentative envoyée' : 'Non observé' }}</p><small>{{ delivery.browser_pixel_last_attempted_at ? new Date(delivery.browser_pixel_last_attempted_at).toLocaleString('fr-TN') : 'Un bloqueur ou le consentement peut empêcher le Pixel.' }}</small></div></article>
          <article><span aria-hidden="true">↗</span><div><strong>API Conversions serveur</strong><p>{{ delivery.server_capi_last_accepted_at ? 'Opérationnel' : 'Non observé' }}</p><small>{{ delivery.server_capi_last_accepted_at ? new Date(delivery.server_capi_last_accepted_at).toLocaleString('fr-TN') : 'Aucune acceptation récente enregistrée.' }}</small></div></article>
          <article><span aria-hidden="true">≡</span><div><strong>File d’attente</strong><p>{{ delivery.queue_worker_state === 'operationnel' ? 'Opérationnelle' : delivery.queue_worker_state }}</p><small>Les événements commerce restent indépendants du checkout.</small></div></article>
          <article><span aria-hidden="true">⇄</span><div><strong>Déduplication</strong><p>{{ delivery.deduplication_status === 'pending_confirmation' ? 'En attente de confirmation Meta' : 'Non observée' }}</p><small>Le navigateur et le serveur partagent event_name et event_id.</small></div></article>
          <article><span aria-hidden="true">●</span><div><strong>Mode actuel</strong><p>{{ selected ? modeLabel(selected.mode) : 'Non configuré' }}</p><small>Version {{ selected?.configuration_version || '—' }}</small></div></article>
        </div></section>

        <section v-if="testTarget && testTarget.mode !== 'disabled'" class="meta-section meta-test-section"><header><div><p class="admin-eyebrow">Tester l’intégration</p><h2>Connexion serveur</h2><p>Ce test fonctionne même si Brave ou une extension bloque le Pixel navigateur.</p></div><button class="admin-action" type="button" :disabled="testing" @click="test">{{ testing ? 'Test en cours…' : 'Tester la connexion serveur' }}</button></header>
          <p v-if="testTarget.test_outcome === 'succeeded'" class="meta-inline-result is-success" role="status">Test réussi<template v-if="testTarget.last_test.events_received"> — {{ testTarget.last_test.events_received }} événement accepté par Meta</template>.</p>
          <p v-else-if="testTarget.test_outcome === 'failed'" class="meta-inline-result is-error" role="alert">{{ testTarget.last_test.request_sent ? 'Meta a reçu la requête mais l’a refusée.' : 'Le test n’a pas été envoyé à Meta, car la configuration enregistrée est incomplète ou indisponible.' }}</p>
          <dl v-if="testTarget.tested_at" class="meta-test-results"><dt>Requête envoyée</dt><dd>{{ testTarget.last_test.request_sent ? 'Oui' : 'Non' }}</dd><dt>Statut HTTP</dt><dd>{{ testTarget.last_test.http_status || '—' }}</dd><dt>Source</dt><dd>{{ testTarget.last_test.source_url || '—' }}</dd><dt>API</dt><dd>{{ testTarget.last_test.graph_api_version || graphVersion }}</dd><dt v-if="testTarget.last_test.message">Message</dt><dd v-if="testTarget.last_test.message">{{ testTarget.last_test.message }}</dd><dt v-if="testTarget.last_test.fbtrace_id">fbtrace_id</dt><dd v-if="testTarget.last_test.fbtrace_id">{{ testTarget.last_test.fbtrace_id }}</dd></dl>
          <button v-if="proposed?.mode === 'live' && proposed.test_outcome === 'succeeded'" class="admin-action" type="button" :disabled="activating" @click="activate">{{ activating ? 'Activation…' : 'Activer en Production' }}</button>
          <details class="meta-browser-help"><summary>Instructions pour tester le Pixel navigateur</summary><p>Utilisez Chrome sans extension, puis Brave avec Shields désactivés. Avec Shields activés, le Pixel peut être bloqué, mais l’événement serveur CAPI doit rester livrable.</p></details>
        </section>
      </template>
    </section>`,
};

export default MetaConfigurationView;
