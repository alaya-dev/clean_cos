import { computed, onMounted, reactive, ref, type Component } from 'vue';
import { RouterLink } from 'vue-router';
import { adminApi } from './api';
import { showError, showToast } from './feedback';

type Mode = 'disabled' | 'manual' | 'automatic';
type Configuration = {
    public_id: string;
    mode: Mode;
    api_base_url: string;
    creation_credential_configured: boolean;
    tracking_credential_configured: boolean;
    deletion_credential_configured: boolean;
    sender_name: string | null;
    sender_location: string | null;
    sender_governorate: string | null;
    configuration_complete: boolean;
    last_tested_at: string | null;
    last_test_status: string | null;
    last_test_message: string | null;
};
type Shipment = {
    public_id: string;
    status: string;
    tracking_code: string | null;
    raw_status: string | null;
    raw_reason: string | null;
    display_status_label: string;
    last_synchronized_at: string | null;
    created_at: string;
    order: { public_reference: string; customer_name: string; status: string };
};
type Page<T> = { data: T[]; current_page: number; last_page: number; total: number };
type ShipmentSummary = { pending_send: number; in_delivery: number; delivered_today: number; returned: number; action_required: number };

const modeLabels: Record<Mode, string> = { disabled: 'Désactivé', manual: 'Manuel', automatic: 'Automatique' };
const statusLabel = (status: string): string => ({
    non_envoyee: 'Non envoyée', en_attente_envoi: 'En attente d’envoi', envoi_en_cours: 'Envoi en cours',
    resultat_incertain: 'Résultat incertain', acceptee_navex: 'Acceptée par Navex', en_attente_navex: 'En attente chez Navex',
    en_cours_livraison: 'En cours de livraison', livree_payee: 'Livrée et payée', retournee: 'Retournée',
    annulation_en_attente: 'Annulation en cours', annulee_navex: 'Annulée chez Navex',
    erreur_synchronisation: 'Erreur de synchronisation', action_manuelle_requise: 'Action manuelle requise',
})[status] ?? status;

const NavexView: Component = {
    components: { RouterLink },
    setup() {
        const loading = ref(true);
        const saving = ref(false);
        const testing = ref(false);
        const configuration = ref<Configuration | null>(null);
        const editing = ref(false);
        const page = ref<Page<Shipment> | null>(null);
        const summary = ref<ShipmentSummary | null>(null);
        const loadingShipments = ref(false);
        const form = reactive({
            mode: 'disabled' as Mode,
            api_base_url: 'https://app.navex.tn',
            creation_credential: '',
            tracking_credential: '',
            deletion_credential: '',
            sender_name: '',
            sender_location: '',
            sender_governorate: '',
        });
        const filters = reactive({ status: '', action_required: false, page: 1 });
        const isConfigured = computed(() => Boolean(configuration.value?.configuration_complete));
        const fill = (value: Configuration | null): void => {
            form.mode = value?.mode ?? 'disabled';
            form.api_base_url = value?.api_base_url ?? 'https://app.navex.tn';
            form.creation_credential = '';
            form.tracking_credential = '';
            form.deletion_credential = '';
            form.sender_name = value?.sender_name ?? '';
            form.sender_location = value?.sender_location ?? '';
            form.sender_governorate = value?.sender_governorate ?? '';
        };
        const loadConfiguration = async (): Promise<void> => {
            const response = await adminApi<{ data: { configuration: Configuration | null } }>('navex/configuration');
            configuration.value = response.data.configuration;
            fill(configuration.value);
            editing.value = !configuration.value;
        };
        const loadShipments = async (requestedPage = filters.page): Promise<void> => {
            loadingShipments.value = true;
            try {
                filters.page = requestedPage;
                const query = new URLSearchParams({ page: String(filters.page), per_page: '20' });
                if (filters.status) query.set('status', filters.status);
                if (filters.action_required) query.set('action_required', '1');
                const response = await adminApi<{ data: Page<Shipment>; meta: { summary: ShipmentSummary } }>(`navex/deliveries?${query}`);
                page.value = response.data;
                summary.value = response.meta.summary;
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Chargement des expéditions Navex impossible.');
            } finally {
                loadingShipments.value = false;
            }
        };
        const save = async (): Promise<void> => {
            saving.value = true;
            try {
                const response = await adminApi<{ data: { configuration: Configuration; notice: string } }>('navex/configuration', 'POST', form);
                configuration.value = response.data.configuration;
                fill(configuration.value);
                editing.value = false;
                showToast('success', response.data.notice);
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Enregistrement Navex impossible.');
            } finally {
                saving.value = false;
            }
        };
        const test = async (): Promise<void> => {
            if (!configuration.value || testing.value) return;
            testing.value = true;
            try {
                const response = await adminApi<{ data: { configuration: Configuration; test_result: { request_sent: boolean; http_status: number | null; message: string | null } } }>(`navex/configuration/${configuration.value.public_id}/test`, 'POST');
                configuration.value = response.data.configuration;
                showToast(response.data.test_result.request_sent && response.data.test_result.http_status ? 'success' : 'info', response.data.test_result.message ?? 'Test Navex terminé.');
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Test Navex impossible.');
            } finally {
                testing.value = false;
            }
        };
        const cancel = (): void => { fill(configuration.value); editing.value = false; };

        onMounted(async () => {
            try { await loadConfiguration(); } catch (cause) { showError(cause instanceof Error ? cause.message : 'Chargement Navex impossible.'); } finally { loading.value = false; }
            await loadShipments();
        });
        return { loading, saving, testing, configuration, editing, form, filters, page, summary, loadingShipments, isConfigured, modeLabels, statusLabel, save, test, cancel, loadShipments };
    },
    template: `<section class="admin-page navex-page">
      <header class="admin-page-header"><div><p class="admin-eyebrow">Opérations / Livraison</p><h1>Livraison Navex</h1><p class="admin-subtitle">Configurez l’envoi, suivez les colis et gardez le contrôle des exceptions.</p></div></header>
      <nav class="delivery-section-tabs" aria-label="Gestion de la livraison"><RouterLink to="/navex">Livraison Navex</RouterLink><RouterLink to="/shipping">Règles de livraison</RouterLink></nav>
      <p v-if="loading" class="admin-loading">Chargement de Navex…</p>
      <template v-else>
        <section v-if="configuration && !editing" class="navex-summary-card"><div><p class="admin-eyebrow">Configuration Navex</p><h2>{{ configuration.configuration_complete ? 'Configuration enregistrée' : 'Configuration incomplète' }}</h2><p>Mode {{ modeLabels[configuration.mode] }} · {{ configuration.sender_name || 'Expéditeur à compléter' }}</p><small v-if="configuration.last_tested_at">Dernier test : {{ new Date(configuration.last_tested_at).toLocaleString('fr-TN') }} · {{ configuration.last_test_message || configuration.last_test_status }}</small></div><span class="admin-badge" :class="configuration.configuration_complete ? 'is-published' : 'is-disabled'">{{ configuration.configuration_complete ? 'Prête' : 'À compléter' }}</span><div class="navex-summary-actions"><button class="admin-outline" type="button" @click="editing = true">Modifier</button><button class="admin-action" type="button" :disabled="testing || !configuration.tracking_credential_configured" @click="test">{{ testing ? 'Test…' : 'Tester Navex' }}</button></div></section>

        <form v-if="editing" class="category-form navex-form" @submit.prevent="save">
          <header><div><p class="admin-eyebrow">Configuration Navex</p><h2>{{ configuration ? 'Modifier la configuration' : 'Configurer Navex' }}</h2><p>Les identifiants restent chiffrés et ne sont jamais affichés après enregistrement.</p></div></header>
          <fieldset class="navex-mode-picker"><legend>Mode d’envoi</legend><label v-for="mode in ['disabled', 'manual', 'automatic']" :key="mode"><input v-model="form.mode" type="radio" :value="mode"><strong>{{ modeLabels[mode] }}</strong><small>{{ mode === 'disabled' ? 'Aucun colis n’est envoyé.' : mode === 'manual' ? 'Un opérateur envoie chaque commande confirmée.' : 'Les commandes confirmées sont mises en file automatiquement.' }}</small></label></fieldset>
          <div class="form-grid"><label>Adresse API Navex<input v-model.trim="form.api_base_url" type="url" required></label><label>Nom de l’expéditeur<input v-model.trim="form.sender_name" required></label><label>Localisation de l’expéditeur<input v-model.trim="form.sender_location" required></label><label>Gouvernorat de l’expéditeur<input v-model.trim="form.sender_governorate" required></label></div>
          <div class="form-grid navex-credentials"><label>Identifiant de création <small>{{ configuration?.creation_credential_configured ? 'Déjà enregistré — laissez vide pour le conserver.' : 'Requis pour créer un colis.' }}</small><input v-model="form.creation_credential" type="password" autocomplete="new-password"></label><label>Identifiant de suivi <small>{{ configuration?.tracking_credential_configured ? 'Déjà enregistré — laissez vide pour le conserver.' : 'Requis pour suivre un colis.' }}</small><input v-model="form.tracking_credential" type="password" autocomplete="new-password"></label><label>Identifiant de suppression <small>{{ configuration?.deletion_credential_configured ? 'Déjà enregistré — laissez vide pour le conserver.' : 'Requis pour annuler un colis.' }}</small><input v-model="form.deletion_credential" type="password" autocomplete="new-password"></label></div>
          <footer class="sticky-save-bar"><button v-if="configuration" class="text-link" type="button" @click="cancel">Annuler</button><button class="admin-action" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer la configuration' }}</button></footer>
        </form>

        <section class="navex-shipments"><header><div><p class="admin-eyebrow">Suivi des colis</p><h2>Expéditions Navex</h2><p>Les mises à jour automatiques n’effacent jamais le statut local sans correspondance confirmée.</p></div></header><div v-if="summary" class="navex-summary-grid" aria-label="Résumé des livraisons Navex"><article><small>En attente d’envoi</small><strong>{{ summary.pending_send }}</strong></article><article><small>En cours de livraison</small><strong>{{ summary.in_delivery }}</strong></article><article><small>Livrées aujourd’hui</small><strong>{{ summary.delivered_today }}</strong></article><article><small>Retournées</small><strong>{{ summary.returned }}</strong></article><article class="is-alert"><small>Actions requises</small><strong>{{ summary.action_required }}</strong></article></div><div class="navex-filters"><label class="toolbar-select"><span>Statut</span><select v-model="filters.status" @change="loadShipments(1)"><option value="">Tous les statuts</option><option value="action_manuelle_requise">Action manuelle requise</option><option value="resultat_incertain">Résultat incertain</option><option value="acceptee_navex">Acceptée par Navex</option><option value="en_cours_livraison">En cours de livraison</option><option value="livree_payee">Livrée et payée</option><option value="retournee">Retournée</option></select></label><label class="inline-check"><input v-model="filters.action_required" type="checkbox" @change="loadShipments(1)"> Actions requises uniquement</label></div><p v-if="loadingShipments" class="admin-loading">Chargement des expéditions…</p><div v-else-if="!page?.data.length" class="admin-empty"><strong>Aucune expédition Navex ne correspond aux filtres.</strong><span>Les commandes confirmées apparaîtront ici lorsqu’un colis sera mis en file.</span></div><div v-else class="navex-table"><div class="navex-table-head"><span>Commande</span><span>Suivi</span><span>Statut</span><span>Dernière synchronisation</span><span>Action</span></div><article v-for="shipment in page.data" :key="shipment.public_id"><div><strong>{{ shipment.order.public_reference }}</strong><small>{{ shipment.order.customer_name }}</small></div><code>{{ shipment.tracking_code || '—' }}</code><span class="admin-badge" :class="shipment.status === 'action_manuelle_requise' || shipment.status === 'resultat_incertain' ? 'is-danger' : ''">{{ shipment.display_status_label }}</span><time>{{ shipment.last_synchronized_at ? new Date(shipment.last_synchronized_at).toLocaleString('fr-TN') : 'Pas encore synchronisée' }}</time><RouterLink class="admin-outline" :to="'/orders/' + shipment.order.public_reference">Ouvrir</RouterLink></article><footer class="orders-pagination"><span>{{ page.total }} expédition{{ page.total > 1 ? 's' : '' }}</span><span>Page {{ page.current_page }} sur {{ page.last_page }}</span><div><button type="button" :disabled="page.current_page <= 1" aria-label="Page précédente" @click="loadShipments(page.current_page - 1)">‹</button><button type="button" :disabled="page.current_page >= page.last_page" aria-label="Page suivante" @click="loadShipments(page.current_page + 1)">›</button></div></footer></div></section>
      </template>
    </section>`,
};

export default NavexView;
