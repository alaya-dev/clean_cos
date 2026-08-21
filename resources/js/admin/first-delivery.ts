import { computed, onBeforeUnmount, onMounted, reactive, ref, type Component } from 'vue';
import { RouterLink } from 'vue-router';
import { adminApi } from './api';
import { confirmAction, showError, showToast } from './feedback';
import SelectControl from './select-control';

type Mode = 'disabled' | 'manual' | 'automatic';
type Configuration = {
    public_id: string;
    mode: Mode;
    api_base_url: string;
    token_configured: boolean;
    token_masked: string | null;
    configuration_complete: boolean;
    last_tested_at: string | null;
    last_test_status: string | null;
    last_test_message: string | null;
    last_localities_synced_at: string | null;
};
type Shipment = {
    public_id: string;
    status: string;
    status_label: string;
    barcode: string | null;
    remote_state: string | null;
    remote_state_code: number | null;
    print_url: string | null;
    last_synced_at: string | null;
    created_at: string;
    order: { public_reference: string; customer_name: string; status: string };
    pickup_eligible: boolean;
    pickup_eligibility_reason: string | null;
    pickup: { public_id: string; provider_pickup_id: string | null; status: string; status_label: string } | null;
};
type Pickup = {
    public_id: string;
    provider_pickup_id: string | null;
    status: 'pending' | 'creating' | 'created' | 'uncertain_result' | 'failed';
    status_label: string;
    print_url: string | null;
    shipment_count: number;
    retryable: boolean;
    print_refresh_pending: boolean;
    last_error: string | null;
    print_error: string | null;
    safe_message: string | null;
    queued_at: string | null;
    confirmed_at: string | null;
    created_at: string | null;
    items: { barcode: string; order_reference: string }[];
};
type Page<T> = { data: T[]; current_page: number; last_page: number; total: number };
type ShipmentSummary = { pending_send: number; in_delivery: number; delivered_today: number; returned: number; action_required: number };

const modeOptions = [
    { value: 'disabled', label: 'Désactivé' },
    { value: 'manual', label: 'Manuel' },
    { value: 'automatic', label: 'Automatique' },
];
const modeLabel = (mode: Mode): string => modeOptions.find((option) => option.value === mode)?.label ?? mode;
const statusOptions = [
    { value: '', label: 'Tous les statuts' },
    { value: 'en_attente_envoi', label: 'En attente d’envoi' },
    { value: 'en_attente_first_delivery', label: 'En attente chez First Delivery' },
    { value: 'en_cours_first_delivery', label: 'En cours de livraison' },
    { value: 'livree_first_delivery', label: 'Livrée' },
    { value: 'retour_expediteur', label: 'Retour expéditeur' },
    { value: 'retour_definitif', label: 'Retour définitif' },
    { value: 'resultat_incertain', label: 'Résultat incertain' },
    { value: 'erreur_synchronisation', label: 'Erreur de synchronisation' },
    { value: 'action_manuelle_requise', label: 'Action manuelle requise' },
];

const FirstDeliveryView: Component = {
    components: { RouterLink, SelectControl },
    setup() {
        const loading = ref(true);
        const saving = ref(false);
        const testing = ref(false);
        const configuration = ref<Configuration | null>(null);
        const editing = ref(false);
        const page = ref<Page<Shipment> | null>(null);
        const summary = ref<ShipmentSummary | null>(null);
        const loadingShipments = ref(false);
        const pickupPage = ref<Page<Pickup> | null>(null);
        const loadingPickups = ref(false);
        const creatingPickup = ref(false);
        const selectedShipmentIds = ref<string[]>([]);
        const form = reactive({ mode: 'disabled' as Mode, api_base_url: 'https://www.firstdeliverygroup.com/api/v2', first_delivery_token: '' });
        const filters = reactive({ status: '', action_required: false, page: 1 });
        const canTest = computed(() => Boolean(configuration.value?.token_configured));
        const eligibleOnPage = computed(() => page.value?.data.filter((shipment) => shipment.pickup_eligible) ?? []);
        const allEligibleSelected = computed(() => eligibleOnPage.value.length > 0 && eligibleOnPage.value.every((shipment) => selectedShipmentIds.value.includes(shipment.public_id)));

        const fill = (value: Configuration | null): void => {
            form.mode = value?.mode ?? 'disabled';
            form.api_base_url = value?.api_base_url ?? 'https://www.firstdeliverygroup.com/api/v2';
            form.first_delivery_token = '';
        };
        const loadConfiguration = async (): Promise<void> => {
            const response = await adminApi<{ data: { configuration: Configuration | null } }>('first-delivery/configuration');
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
                const response = await adminApi<{ data: Page<Shipment>; meta: { summary: ShipmentSummary } }>(`first-delivery/deliveries?${query}`);
                page.value = response.data;
                summary.value = response.meta.summary;
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Chargement des expéditions First Delivery impossible.');
            } finally {
                loadingShipments.value = false;
            }
        };
        const loadPickups = async (): Promise<void> => {
            loadingPickups.value = true;
            try {
                const response = await adminApi<{ data: Page<Pickup> }>('first-delivery/pickups?per_page=20');
                pickupPage.value = response.data;
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Chargement des manifestes impossible.');
            } finally {
                loadingPickups.value = false;
            }
        };
        const toggleShipment = (publicId: string): void => {
            selectedShipmentIds.value = selectedShipmentIds.value.includes(publicId)
                ? selectedShipmentIds.value.filter((id) => id !== publicId)
                : [...selectedShipmentIds.value, publicId];
        };
        const toggleEligiblePage = (): void => {
            const ids = eligibleOnPage.value.map((shipment) => shipment.public_id);
            selectedShipmentIds.value = allEligibleSelected.value
                ? selectedShipmentIds.value.filter((id) => !ids.includes(id))
                : [...new Set([...selectedShipmentIds.value, ...ids])];
        };
        const createPickup = async (): Promise<void> => {
            if (!selectedShipmentIds.value.length || creatingPickup.value) return;
            const confirmed = await confirmAction(
                `Créer un manifeste avec ${selectedShipmentIds.value.length} colis ?`,
                'First Delivery créera une demande d’enlèvement. Cette opération ne peut pas être annulée depuis CleanCos.',
                'Créer le manifeste',
            );
            if (!confirmed) return;
            creatingPickup.value = true;
            try {
                const response = await adminApi<{ data: { pickup: Pickup; notice: string } }>('first-delivery/pickups', 'POST', {
                    shipment_public_ids: selectedShipmentIds.value,
                    confirm_creation: true,
                });
                selectedShipmentIds.value = [];
                showToast('success', response.data.notice);
                await Promise.all([loadPickups(), loadShipments()]);
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Création du manifeste impossible.');
            } finally {
                creatingPickup.value = false;
            }
        };
        const retryPickup = async (pickup: Pickup): Promise<void> => {
            if (!await confirmAction('Relancer ce manifeste ?', 'La relance est autorisée car First Delivery a explicitement refusé ou n’a pas reçu la demande.', 'Relancer')) return;
            try {
                const response = await adminApi<{ data: { pickup: Pickup; notice: string } }>(`first-delivery/pickups/${pickup.public_id}/retry`, 'POST', { confirm_retry: true });
                showToast('success', response.data.notice);
                await loadPickups();
            } catch (cause) { showError(cause instanceof Error ? cause.message : 'Relance impossible.'); }
        };
        const refreshPickupPrint = async (pickup: Pickup): Promise<void> => {
            try {
                const response = await adminApi<{ data: { pickup: Pickup; notice: string } }>(`first-delivery/pickups/${pickup.public_id}/refresh-print`, 'POST', { confirm_refresh: true });
                showToast('success', response.data.notice);
                await loadPickups();
            } catch (cause) { showError(cause instanceof Error ? cause.message : 'Préparation du lien impossible.'); }
        };
        const save = async (): Promise<void> => {
            saving.value = true;
            try {
                const response = await adminApi<{ data: { configuration: Configuration; notice: string } }>('first-delivery/configuration', 'POST', form);
                configuration.value = response.data.configuration;
                fill(configuration.value);
                editing.value = false;
                showToast('success', response.data.notice);
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Enregistrement First Delivery impossible.');
            } finally {
                saving.value = false;
            }
        };
        const testConnection = async (): Promise<void> => {
            if (!configuration.value || testing.value) return;
            testing.value = true;
            try {
                const response = await adminApi<{ data: { configuration: Configuration; test_result: { status: string; message: string | null; localities_count: number } } }>(`first-delivery/configuration/${configuration.value.public_id}/test`, 'POST');
                configuration.value = response.data.configuration;
                showToast(response.data.test_result.status === 'connected' ? 'success' : 'info', response.data.test_result.message ?? 'Test First Delivery terminé.');
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Test First Delivery impossible.');
            } finally {
                testing.value = false;
            }
        };
        const removeToken = async (): Promise<void> => {
            if (!configuration.value || !await confirmAction('Supprimer le token First Delivery ?', 'L’intégration sera désactivée. Les expéditions existantes et leur historique seront conservés.', 'Supprimer le token', 'danger')) return;
            try {
                const response = await adminApi<{ data: { configuration: Configuration; notice: string } }>('first-delivery/configuration/token', 'DELETE', { confirm_removal: true });
                configuration.value = response.data.configuration;
                fill(configuration.value);
                showToast('success', response.data.notice);
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Suppression du token impossible.');
            }
        };
        const cancelEdit = (): void => { fill(configuration.value); editing.value = false; };

        onMounted(async () => {
            try { await loadConfiguration(); } catch (cause) { showError(cause instanceof Error ? cause.message : 'Chargement First Delivery impossible.'); } finally { loading.value = false; }
            await Promise.all([loadShipments(), loadPickups()]);
        });

        const pickupPoll = window.setInterval(() => {
            if (pickupPage.value?.data.some((pickup) => ['pending', 'creating'].includes(pickup.status) || pickup.print_refresh_pending)) {
                void Promise.all([loadPickups(), loadShipments()]);
            }
        }, 3000);
        onBeforeUnmount(() => window.clearInterval(pickupPoll));

        return { loading, saving, testing, configuration, editing, page, summary, loadingShipments, pickupPage, loadingPickups, creatingPickup, selectedShipmentIds, eligibleOnPage, allEligibleSelected, form, filters, canTest, modeOptions, modeLabel, statusOptions, save, testConnection, removeToken, cancelEdit, loadShipments, toggleShipment, toggleEligiblePage, createPickup, retryPickup, refreshPickupPrint };
    },
    template: `<section class="admin-page navex-page first-delivery-page">
      <header class="admin-page-header"><div><p class="admin-eyebrow">Opérations / Livraison</p><h1>First Delivery</h1><p class="admin-subtitle">Configurez le token, synchronisez les localités et suivez chaque colis sans exposer les accès API.</p></div></header>
      <nav class="delivery-section-tabs" aria-label="Gestion de la livraison"><RouterLink to="/navex">Navex</RouterLink><RouterLink to="/first-delivery">First Delivery</RouterLink><RouterLink to="/shipping">Règles de livraison</RouterLink></nav>
      <p v-if="loading" class="admin-loading">Chargement de First Delivery…</p>
      <template v-else>
        <section v-if="configuration && !editing" class="navex-summary-card"><div><p class="admin-eyebrow">Configuration First Delivery</p><h2>{{ configuration.configuration_complete ? 'Configuration enregistrée' : 'Configuration incomplète' }}</h2><p>Mode {{ modeLabel(configuration.mode) }} · Token {{ configuration.token_masked || 'non enregistré' }}</p><small v-if="configuration.last_tested_at">Dernier test : {{ new Date(configuration.last_tested_at).toLocaleString('fr-TN') }} · {{ configuration.last_test_message || configuration.last_test_status }}</small><small v-if="configuration.last_localities_synced_at">Localités actualisées : {{ new Date(configuration.last_localities_synced_at).toLocaleString('fr-TN') }}</small></div><span class="admin-badge" :class="configuration.configuration_complete ? 'is-published' : 'is-disabled'">{{ configuration.configuration_complete ? 'Prête' : 'À compléter' }}</span><div class="navex-summary-actions"><button class="admin-outline" type="button" @click="editing = true">Modifier</button><button class="admin-action" type="button" :disabled="testing || !canTest" @click="testConnection">{{ testing ? 'Test…' : 'Tester et synchroniser les localités' }}</button><button v-if="configuration.token_configured" class="text-link danger" type="button" @click="removeToken">Supprimer le token</button></div></section>

        <form v-if="editing" class="category-form navex-form" @submit.prevent="save">
          <header><div><p class="admin-eyebrow">Configuration First Delivery</p><h2>{{ configuration ? 'Modifier la configuration' : 'Configurer First Delivery' }}</h2><p>Le token est chiffré côté serveur. Après l’enregistrement, seule une valeur masquée reste visible.</p></div></header>
          <div class="form-grid"><label>Mode d’envoi<SelectControl v-model="form.mode" :options="modeOptions" required/></label><label>Adresse API officielle<input v-model.trim="form.api_base_url" type="url" required readonly></label><label class="full">Token First Delivery <small>{{ configuration?.token_configured ? 'Déjà enregistré — laissez vide pour le conserver.' : 'Copiez le token depuis le dashboard First Delivery.' }}</small><input v-model="form.first_delivery_token" type="password" autocomplete="new-password" :required="!configuration?.token_configured"></label></div>
          <p class="navex-cancellation-help" role="note"><strong>Modes</strong><span>Désactivé bloque tout nouvel envoi. Manuel affiche le bouton sur une commande confirmée. Automatique met la commande en file lors de sa confirmation.</span></p>
          <footer class="sticky-save-bar"><button v-if="configuration" class="text-link" type="button" @click="cancelEdit">Annuler</button><button class="admin-action" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer la configuration' }}</button></footer>
        </form>

        <section class="navex-shipments first-delivery-pickups"><header><div><p class="admin-eyebrow">Demandes d’enlèvement</p><h2>Manifestes First Delivery</h2><p>Sélectionnez les colis « En attente », créez le manifeste puis imprimez la décharge officielle First Delivery.</p></div><button class="admin-action" type="button" :disabled="!selectedShipmentIds.length || creatingPickup" @click="createPickup">{{ creatingPickup ? 'Création…' : 'Créer le manifeste (' + selectedShipmentIds.length + ')' }}</button></header><p v-if="loadingPickups" class="admin-loading">Chargement des manifestes…</p><div v-else-if="!pickupPage?.data.length" class="admin-empty"><strong>Aucun manifeste créé.</strong><span>Les colis éligibles peuvent être sélectionnés dans le tableau ci-dessous.</span></div><div v-else class="navex-table first-delivery-pickup-table"><div class="navex-table-head"><span>Manifeste</span><span>Colis</span><span>Statut</span><span>Date</span><span>Action</span></div><article v-for="pickup in pickupPage.data" :key="pickup.public_id"><div><strong>{{ pickup.provider_pickup_id || 'En préparation' }}</strong><small>{{ pickup.items.map((item) => item.order_reference).join(', ') }}</small></div><strong>{{ pickup.shipment_count }}</strong><div><span class="admin-badge" :class="['failed', 'uncertain_result'].includes(pickup.status) ? 'is-danger' : ''">{{ pickup.status_label }}</span><small v-if="pickup.safe_message || pickup.last_error">{{ pickup.safe_message || pickup.last_error }}</small></div><time>{{ new Date(pickup.confirmed_at || pickup.queued_at || pickup.created_at || '').toLocaleString('fr-TN') }}</time><div class="navex-summary-actions"><a v-if="pickup.print_url" class="admin-outline" :href="pickup.print_url" target="_blank" rel="noopener noreferrer">Imprimer</a><button v-else-if="pickup.status === 'created'" class="admin-outline" type="button" :disabled="pickup.print_refresh_pending" @click="refreshPickupPrint(pickup)">{{ pickup.print_refresh_pending ? 'Préparation…' : 'Préparer l’impression' }}</button><button v-if="pickup.status === 'failed' && pickup.retryable" class="text-link" type="button" @click="retryPickup(pickup)">Relancer</button></div></article></div><p class="navex-cancellation-help" role="note"><strong>Limite First Delivery</strong><span>CleanCos crée et imprime le manifeste. Les statuts restent synchronisés colis par colis ; aucune édition ou annulation de manifeste n’est exposée par l’API publique.</span></p></section>

        <section class="navex-shipments"><header><div><p class="admin-eyebrow">Suivi des colis</p><h2>Expéditions First Delivery</h2><p>Les statuts locaux sont synchronisés depuis l’API sans modifier le statut commercial de la commande.</p></div></header><div v-if="summary" class="navex-summary-grid"><article><small>En attente d’envoi</small><strong>{{ summary.pending_send }}</strong></article><article><small>En cours de livraison</small><strong>{{ summary.in_delivery }}</strong></article><article><small>Livrées aujourd’hui</small><strong>{{ summary.delivered_today }}</strong></article><article><small>Retournées</small><strong>{{ summary.returned }}</strong></article><article class="is-alert"><small>Actions requises</small><strong>{{ summary.action_required }}</strong></article></div><div class="navex-filters"><label class="toolbar-select"><span>Statut</span><SelectControl v-model="filters.status" :options="statusOptions" @change="loadShipments(1)"/></label><label class="inline-check"><input v-model="filters.action_required" type="checkbox" @change="loadShipments(1)"> Actions requises uniquement</label></div><div v-if="eligibleOnPage.length" class="navex-summary-card"><label class="inline-check"><input type="checkbox" :checked="allEligibleSelected" @change="toggleEligiblePage"> Sélectionner les colis éligibles de cette page</label><strong>{{ selectedShipmentIds.length }} colis sélectionné{{ selectedShipmentIds.length > 1 ? 's' : '' }}</strong></div><p v-if="loadingShipments" class="admin-loading">Chargement des expéditions…</p><div v-else-if="!page?.data.length" class="admin-empty"><strong>Aucune expédition First Delivery ne correspond aux filtres.</strong><span>Les commandes confirmées apparaîtront ici après leur mise en file.</span></div><div v-else class="navex-table"><div class="navex-table-head"><span>Sélection</span><span>Commande</span><span>Barcode</span><span>Statut</span><span>Action</span></div><article v-for="shipment in page.data" :key="shipment.public_id"><div><input v-if="shipment.pickup_eligible" type="checkbox" :checked="selectedShipmentIds.includes(shipment.public_id)" :aria-label="'Sélectionner ' + shipment.order.public_reference" @change="toggleShipment(shipment.public_id)"><small v-else :title="shipment.pickup_eligibility_reason || ''">Non éligible</small></div><div><strong>{{ shipment.order.public_reference }}</strong><small>{{ shipment.order.customer_name }}</small></div><code>{{ shipment.barcode || '—' }}</code><div><span class="admin-badge" :class="['action_manuelle_requise', 'resultat_incertain', 'erreur_synchronisation'].includes(shipment.status) ? 'is-danger' : ''">{{ shipment.status_label }}</span><small v-if="shipment.pickup">{{ shipment.pickup.status_label }} · {{ shipment.pickup.provider_pickup_id || 'en préparation' }}</small><small v-else>{{ shipment.last_synced_at ? new Date(shipment.last_synced_at).toLocaleString('fr-TN') : 'Pas encore synchronisée' }}</small></div><RouterLink class="admin-outline" :to="'/orders/' + shipment.order.public_reference">Ouvrir</RouterLink></article><footer class="orders-pagination"><span>{{ page.total }} expédition{{ page.total > 1 ? 's' : '' }}</span><span>Page {{ page.current_page }} sur {{ page.last_page }}</span><div><button type="button" :disabled="page.current_page <= 1" @click="loadShipments(page.current_page - 1)">‹</button><button type="button" :disabled="page.current_page >= page.last_page" @click="loadShipments(page.current_page + 1)">›</button></div></footer></div></section>
      </template>
    </section>`,
};

export default FirstDeliveryView;
