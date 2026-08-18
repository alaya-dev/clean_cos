import { computed, onBeforeUnmount, onMounted, ref, type Component } from 'vue';
import '../../css/admin-dashboard.css';
import { adminApi, millimesToDinars } from './api';
import { showError, showToast } from './feedback';
import { loadAdminOrderPollingConfig, PollingOrderChangeFeed, type AdminOrderChangePayload } from './order-change-feed';
import { pulseAdminOrderAttention, setAdminNewOrderCount } from './order-attention';

type Dashboard = {
    data: {
        orders: { submitted: number; by_status: Record<string, number>; delivered_revenue_millimes: number; average_delivered_order_millimes: number; best_sellers: Array<{ name: string; quantity: number }> };
        inventory: { low_stock_products: Array<{ public_id: string; name: string; stock_quantity: number; low_stock_threshold: number }>; low_stock_variants: Array<{ public_id: string; product_name: string | null; combination_key: string; stock_quantity: number; low_stock_threshold: number }> };
        complaints: Array<{ public_reference: string; status: string; created_at: string }>;
        meta: { tracking_available: boolean; pixel_attempts: number; capi?: Record<string, number>; purchases: Record<string, number> };
        order_changes_cursor?: string;
    };
};

const statusLabels: Record<string, string> = { nouvelle: 'Nouvelle', confirmee: 'Confirmée', tentative_1: 'Tentative 1', tentative_2: 'Tentative 2', tentative_3: 'Tentative 3', annulee: 'Annulée', resolue: 'Résolue', en_cours: 'En cours' };

// Inline markup keeps this route self-contained while retaining the admin SPA's shared controls.
const dashboardTemplate = `<section class="admin-page dashboard-page">
  <header class="admin-page-header">
    <div><p class="admin-eyebrow">Pilotage</p><h1>Tableau de bord</h1><p class="admin-subtitle">Commandes, opérations et suivi Meta.</p></div>
    <div class="admin-filter-bar"><label>Période<select v-model="period" @change="period === 'custom' ? null : refresh()"><option value="today">Aujourd’hui</option><option value="7d">7 derniers jours</option><option value="30d">30 derniers jours</option><option value="month">Mois en cours</option><option value="custom">Personnalisée</option></select></label><template v-if="period === 'custom'"><label>Du<input v-model="dateFrom" type="date"></label><label>Au<input v-model="dateTo" type="date"></label><button class="admin-outline" type="button" :disabled="!dateFrom || !dateTo" @click="refresh">Appliquer</button></template><button class="admin-outline" type="button" :disabled="loading" @click="manualRefresh">Actualiser</button></div>
  </header>
  <p v-if="loading" class="admin-loading">Chargement des indicateurs…</p>
  <template v-else-if="dashboard">
    <section class="dashboard-kpis" aria-label="Indicateurs principaux">
      <article class="dashboard-kpi"><span class="dashboard-kpi-icon" aria-hidden="true">⌁</span><div><span>Commandes reçues</span><strong>{{ dashboard.orders.submitted }}</strong><small>Période sélectionnée</small></div></article>
      <article class="dashboard-kpi"><span class="dashboard-kpi-icon" aria-hidden="true">DT</span><div><span>Chiffre d’affaires livré</span><strong>{{ money(dashboard.orders.delivered_revenue_millimes) }}</strong><small>Commandes livrées</small></div></article>
      <article class="dashboard-kpi"><span class="dashboard-kpi-icon" aria-hidden="true">≈</span><div><span>Panier moyen livré</span><strong>{{ money(dashboard.orders.average_delivered_order_millimes) }}</strong><small>Commandes livrées</small></div></article>
      <article class="dashboard-kpi"><span class="dashboard-kpi-icon" aria-hidden="true">!</span><div><span>Réclamations récentes</span><strong>{{ complaintCount }}</strong><small>Sur la période</small></div></article>
    </section>
    <div class="dashboard-layout">
      <section class="dashboard-card is-commercial"><header><div><p class="admin-eyebrow">Commandes</p><h2>Commandes par statut</h2></div></header><div v-if="orderRows.length"><div v-for="[status,total] in orderRows" :key="status" class="dashboard-status" :class="'status-' + status"><span>{{ statusLabels[status] || status }}</span><strong>{{ total }}</strong></div></div><div v-else class="dashboard-empty">Aucune commande sur cette période.</div></section>
      <section class="dashboard-card"><header><div><p class="admin-eyebrow">Catalogue</p><h2>Meilleures ventes</h2></div></header><ol v-if="dashboard.orders.best_sellers.length" class="dashboard-list"><li v-for="product in dashboard.orders.best_sellers" :key="product.name"><span>{{ product.name }}</span><strong>{{ product.quantity }} unités</strong></li></ol><div v-else class="dashboard-empty">Aucune vente livrée sur cette période.</div></section>
      <section class="dashboard-card is-commercial"><header><div><p class="admin-eyebrow">Opérations</p><h2>Stock faible</h2></div></header><template v-if="dashboard.inventory.low_stock_products.length || dashboard.inventory.low_stock_variants.length"><ul class="dashboard-list"><li v-for="product in dashboard.inventory.low_stock_products" :key="product.public_id"><span>{{ product.name }}</span><strong>{{ product.stock_quantity }} / seuil {{ product.low_stock_threshold }}</strong></li><li v-for="variant in dashboard.inventory.low_stock_variants" :key="variant.public_id"><span>{{ variant.product_name }} · {{ variant.combination_key }}</span><strong>{{ variant.stock_quantity }} / seuil {{ variant.low_stock_threshold }}</strong></li></ul></template><div v-else class="dashboard-empty">Aucun produit ou variante en alerte.</div></section>
      <section class="dashboard-card"><header><div><p class="admin-eyebrow">Service client</p><h2>Réclamations récentes</h2></div></header><ul v-if="dashboard.complaints.length" class="dashboard-list"><li v-for="complaint in dashboard.complaints" :key="complaint.public_reference"><span><RouterLink class="text-link" :to="'/complaints/' + complaint.public_reference">{{ complaint.public_reference }}</RouterLink><small>{{ new Date(complaint.created_at).toLocaleDateString('fr-TN') }}</small></span><strong>{{ statusLabels[complaint.status] || complaint.status }}</strong></li></ul><div v-else class="dashboard-empty">Aucune réclamation récente.</div></section>
<section class="dashboard-card dashboard-meta is-wide"><header><div><p class="admin-eyebrow">Suivi Meta</p><h2>{{ dashboard.meta.tracking_available ? 'Configuré' : 'Non configuré' }}</h2><p>Le détail de livraison reste dans les diagnostics.</p></div><RouterLink class="admin-outline dashboard-link" to="/meta/diagnostics">Voir les diagnostics Meta</RouterLink></header><dl class="dashboard-list"><dt>Pixel</dt><dd>{{ dashboard.meta.tracking_available ? 'Configuré' : 'Non configuré' }}</dd><dt>CAPI</dt><dd>{{ dashboard.meta.capi ? 'Configuré' : 'Indisponible' }}</dd><dt>Événements en attente</dt><dd>{{ dashboard.meta.purchases.pending || 0 }}</dd><dt>Livrés par le serveur</dt><dd>{{ dashboard.meta.purchases.capi_delivered || 0 }}</dd><dt>En échec</dt><dd>{{ dashboard.meta.purchases.failed || 0 }}</dd><dt>Tentatives Pixel</dt><dd>{{ dashboard.meta.pixel_attempts }}</dd><dt>Achats éligibles au consentement</dt><dd>{{ dashboard.meta.purchases.consent_eligible || 0 }}</dd></dl></section>
    </div>
  </template>
</section>`;

const DashboardView: Component = {
    setup() {
        const period = ref('7d');
        const dateFrom = ref('');
        const dateTo = ref('');
        const dashboard = ref<Dashboard['data'] | null>(null);
        const loading = ref(true);
        let refreshController: AbortController | null = null;
        let feed: PollingOrderChangeFeed | null = null;
        const notifiedOrders = new Set<string>();
        const refresh = async (background = false): Promise<string | null> => {
            refreshController?.abort();
            const controller = new AbortController();
            refreshController = controller;
            if (!background) loading.value = true;
            try {
                const query = new URLSearchParams({ period: period.value });
                if (period.value === 'custom') { query.set('date_from', dateFrom.value); query.set('date_to', dateTo.value); }
                const dashboardResponse = await adminApi<Dashboard>(`dashboard?${query}`, 'GET', undefined, controller.signal);
                dashboard.value = dashboardResponse.data;
                return dashboardResponse.data.order_changes_cursor || null;
            } catch (cause) {
                if (controller.signal.aborted) return null;
                showError(cause instanceof Error ? cause.message : 'Le tableau de bord est indisponible.');
                return null;
            } finally {
                if (refreshController === controller) loading.value = false;
            }
        };
        const onOrderChanges = async (payload: AdminOrderChangePayload) => {
            if (!payload.changed || loading.value) return;
            const newOrders = (payload.created_ids || []).filter((reference) => !notifiedOrders.has(reference));
            newOrders.forEach((reference) => notifiedOrders.add(reference));
            if (newOrders.length) {
                pulseAdminOrderAttention();
                showToast('info', newOrders.length === 1 ? 'Nouvelle commande reçue' : `${newOrders.length} nouvelles commandes reçues`);
            }
            if (dashboard.value && payload.counts) {
                setAdminNewOrderCount(payload.counts.new || 0);
                const byStatus = {
                    ...dashboard.value.orders.by_status,
                    nouvelle: payload.counts.new || 0,
                    confirmee: payload.counts.confirmed || 0,
                    annulee: payload.counts.cancelled || 0,
                    tentative_1: payload.counts.attempt_1 || 0,
                    tentative_2: payload.counts.attempt_2 || 0,
                    tentative_3: payload.counts.attempt_3 || 0,
                };
                dashboard.value = { ...dashboard.value, orders: { ...dashboard.value.orders, by_status: byStatus, submitted: Object.values(byStatus).reduce((total, count) => total + count, 0) } };
            }
        };
        const startPolling = async (cursor: string) => {
            try {
                const config = await loadAdminOrderPollingConfig();
                if (!config.enabled) return;
                feed = new PollingOrderChangeFeed(config, { onChanges: onOrderChanges });
                feed.start(cursor);
            } catch {
                // Polling configuration failure must never interrupt dashboard use.
            }
        };
        const manualRefresh = async () => { await refresh(); feed?.resetTimer(); };
        const money = (millimes: number) => `${millimesToDinars(millimes)} DT`;
        const orderRows = computed(() => Object.entries(dashboard.value?.orders.by_status ?? {}));
        const complaintCount = computed(() => dashboard.value?.complaints.length ?? 0);
        onMounted(async () => { const cursor = await refresh(); if (cursor) void startPolling(cursor); });
        onBeforeUnmount(() => { feed?.stop(); refreshController?.abort(); });
        return { period, dateFrom, dateTo, dashboard, loading, refresh, manualRefresh, money, orderRows, complaintCount, statusLabels };
    },
    template: dashboardTemplate,
};

export default DashboardView;
