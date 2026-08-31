import { computed, onBeforeUnmount, onMounted, ref, type Component } from 'vue';
import '../../css/admin-dashboard.css';
import { adminApi, millimesToDinars } from './api';
import { showError, showToast } from './feedback';
import { loadAdminOrderPollingConfig, PollingOrderChangeFeed, type AdminOrderChangePayload } from './order-change-feed';
import { pulseAdminOrderAttention, setAdminNewOrderCount } from './order-attention';

type Summary = { orders: number; total_millimes: number };
type TrendPoint = { date: string; orders: number; drafts: number; total_millimes: number };
type SalesSummary = { orders: number; total_millimes: number; product_millimes: number; shipping_millimes: number };
type SalesTrendPoint = { date: string; total_millimes: number };
type Dashboard = {
    data: {
        orders: {
            submitted: number;
            by_status: Record<string, number>;
            delivered_orders: number;
            delivered_revenue_millimes: number;
            average_delivered_order_millimes: number;
            best_sellers: Array<{ name: string; quantity: number }>;
            summary: { today: Summary; week: Summary; month: Summary; all: Summary };
            trend: TrendPoint[];
        };
        sales: {
            summary: { today: SalesSummary; week: SalesSummary; month: SalesSummary; all: SalesSummary };
            trend: SalesTrendPoint[];
        };
        order_changes_cursor?: string;
    };
};

const statusLabels: Record<string, string> = {
    nouvelle: 'Nouvelle', confirmee: 'Confirmée', tentative_1: 'Tentative 1', tentative_2: 'Tentative 2', tentative_3: 'Tentative 3', annulee: 'Annulée',
};

const dashboardTemplate = `<section class="admin-page dashboard-page">
  <header class="admin-page-header">
    <div><p class="admin-eyebrow">Pilotage commercial</p><h1>Tableau de bord</h1><p class="admin-subtitle">Commandes, ventes et produits les plus demandés.</p></div>
    <div class="admin-filter-bar"><label>Période<select v-model="period" @change="period === 'custom' ? null : refresh()"><option value="today">Aujourd’hui</option><option value="7d">7 derniers jours</option><option value="30d">30 derniers jours</option><option value="month">Mois en cours</option><option value="custom">Personnalisée</option></select></label><template v-if="period === 'custom'"><label>Du<input v-model="dateFrom" type="date"></label><label>Au<input v-model="dateTo" type="date"></label><button class="admin-outline" type="button" :disabled="!dateFrom || !dateTo" @click="refresh">Appliquer</button></template><button class="admin-outline" type="button" :disabled="loading" @click="manualRefresh">Actualiser</button></div>
  </header>
  <nav class="dashboard-tabs" aria-label="Vue du tableau de bord"><button type="button" :class="{ 'is-active': activeTab === 'orders' }" :aria-selected="activeTab === 'orders'" @click="activeTab = 'orders'">Commandes</button><button type="button" :class="{ 'is-active': activeTab === 'sales' }" :aria-selected="activeTab === 'sales'" @click="activeTab = 'sales'">Ventes</button></nav>
  <p v-if="loading" class="admin-loading">Chargement des indicateurs…</p>
  <template v-else-if="dashboard">
  <template v-if="activeTab === 'orders'">
    <section class="dashboard-kpis" aria-label="Résumé des commandes et ventes">
      <article class="dashboard-kpi"><span class="dashboard-kpi-icon" aria-hidden="true">⌁</span><div><span>Commandes aujourd’hui</span><strong>{{ dashboard.orders.summary.today.total_millimes ? money(dashboard.orders.summary.today.total_millimes) : '0 DT' }}</strong><small>{{ dashboard.orders.summary.today.orders }} commande{{ dashboard.orders.summary.today.orders > 1 ? 's' : '' }}</small></div></article>
      <article class="dashboard-kpi"><span class="dashboard-kpi-icon" aria-hidden="true">7j</span><div><span>Commandes cette semaine</span><strong>{{ money(dashboard.orders.summary.week.total_millimes) }}</strong><small>{{ dashboard.orders.summary.week.orders }} commande{{ dashboard.orders.summary.week.orders > 1 ? 's' : '' }}</small></div></article>
      <article class="dashboard-kpi"><span class="dashboard-kpi-icon" aria-hidden="true">M</span><div><span>Commandes ce mois-ci</span><strong>{{ money(dashboard.orders.summary.month.total_millimes) }}</strong><small>{{ dashboard.orders.summary.month.orders }} commande{{ dashboard.orders.summary.month.orders > 1 ? 's' : '' }}</small></div></article>
      <article class="dashboard-kpi dashboard-kpi-accent"><span class="dashboard-kpi-icon" aria-hidden="true">DT</span><div><span>Revenus livrés</span><strong>{{ money(dashboard.orders.delivered_revenue_millimes) }}</strong><small>{{ dashboard.orders.delivered_orders }} commande{{ dashboard.orders.delivered_orders > 1 ? 's' : '' }} livrée{{ dashboard.orders.delivered_orders > 1 ? 's' : '' }}</small></div></article>
    </section>
    <div class="dashboard-layout">
      <section class="dashboard-card dashboard-trend-card is-wide"><header><div><p class="admin-eyebrow">Évolution</p><h2>Commandes</h2></div><span class="dashboard-card-caption">{{ orderedTotal }} commande{{ orderedTotal > 1 ? 's' : '' }} sur la période</span></header><div v-if="dashboard.orders.trend.length" class="dashboard-trend" role="img" aria-label="Nombre de commandes par jour"><div v-for="point in dashboard.orders.trend" :key="point.date" class="dashboard-trend-point" :title="point.orders + ' commande(s) · ' + trendLabel(point.date)"><strong>{{ point.orders }}</strong><span class="dashboard-trend-bar"><i :class="{ 'is-empty': point.orders === 0 }" :style="{ height: orderBarHeight(point.orders) }"></i></span><small>{{ trendLabel(point.date) }}</small></div></div><div v-else class="dashboard-empty">Aucune commande sur cette période.</div></section>
      <section class="dashboard-card"><header><div><p class="admin-eyebrow">Répartition</p><h2>Commandes par statut</h2></div></header><div v-if="orderRows.length" class="dashboard-status-donut-layout"><div class="dashboard-donut" :style="statusDonutStyle" role="img" aria-label="Répartition des commandes par statut"><strong>{{ dashboard.orders.submitted }}</strong><small>commandes</small></div><div class="dashboard-status-list"><div v-for="[status,total] in orderRows" :key="status" class="dashboard-status-row"><span><i :class="'status-dot status-' + status"></i>{{ statusLabels[status] || status }}</span><strong>{{ total }}</strong></div></div></div><div v-else class="dashboard-empty">Aucune commande sur cette période.</div></section>
      <section class="dashboard-card"><header><div><p class="admin-eyebrow">Produits</p><h2>Produits les plus commandés</h2></div><span class="dashboard-card-caption">Commandes non annulées</span></header><ol v-if="dashboard.orders.best_sellers.length" class="dashboard-list"><li v-for="(product,index) in dashboard.orders.best_sellers" :key="product.name"><span><b class="dashboard-rank">{{ index + 1 }}</b>{{ product.name }}</span><strong>{{ product.quantity }} unité{{ product.quantity > 1 ? 's' : '' }}</strong></li></ol><div v-else class="dashboard-empty">Aucun article commandé sur cette période.</div></section>
      <section class="dashboard-card dashboard-compare-card"><header><div><p class="admin-eyebrow">Abandon</p><h2>Trafic des commandes</h2></div><span class="dashboard-card-caption">Sur la période</span></header><div class="dashboard-compare-metrics"><div><strong>{{ orderedTotal }}</strong><span>Commandes</span></div><div><strong>{{ draftTotal }}</strong><span>Paniers abandonnés</span></div></div></section>
      <section class="dashboard-card dashboard-order-definition is-wide"><p><strong>Lecture des chiffres :</strong> les cartes de commandes utilisent les montants des commandes non annulées. Les revenus livrés correspondent uniquement aux colis confirmés comme livrés et payés par Navex.</p></section>
    </div>
  </template>
  <template v-else>
    <section class="dashboard-kpis dashboard-sales-kpis" aria-label="Résumé des ventes confirmées">
      <article class="dashboard-kpi"><span class="dashboard-kpi-icon" aria-hidden="true">⌁</span><div><span>Ventes confirmées aujourd’hui</span><strong>{{ money(dashboard.sales.summary.today.total_millimes) }}</strong><small><b>Produits {{ money(dashboard.sales.summary.today.product_millimes) }}</b><i>•</i> Livraison {{ money(dashboard.sales.summary.today.shipping_millimes) }}</small></div></article>
      <article class="dashboard-kpi"><span class="dashboard-kpi-icon" aria-hidden="true">7j</span><div><span>Ventes confirmées cette semaine</span><strong>{{ money(dashboard.sales.summary.week.total_millimes) }}</strong><small><b>Produits {{ money(dashboard.sales.summary.week.product_millimes) }}</b><i>•</i> Livraison {{ money(dashboard.sales.summary.week.shipping_millimes) }}</small></div></article>
      <article class="dashboard-kpi"><span class="dashboard-kpi-icon" aria-hidden="true">M</span><div><span>Ventes confirmées ce mois-ci</span><strong>{{ money(dashboard.sales.summary.month.total_millimes) }}</strong><small><b>Produits {{ money(dashboard.sales.summary.month.product_millimes) }}</b><i>•</i> Livraison {{ money(dashboard.sales.summary.month.shipping_millimes) }}</small></div></article>
      <article class="dashboard-kpi dashboard-kpi-accent"><span class="dashboard-kpi-icon" aria-hidden="true">DT</span><div><span>Total commandes confirmées</span><strong>{{ money(dashboard.sales.summary.all.total_millimes) }}</strong><small><b>Produits {{ money(dashboard.sales.summary.all.product_millimes) }}</b><i>•</i> Livraison {{ money(dashboard.sales.summary.all.shipping_millimes) }}</small></div></article>
    </section>
    <section class="dashboard-card dashboard-trend-card is-wide dashboard-sales-trend"><header><div><p class="admin-eyebrow">Évolution</p><h2>Ventes</h2></div><span class="dashboard-card-caption">{{ money(salesPeriodTotal) }} sur la période</span></header><div v-if="dashboard.sales.trend.length" class="dashboard-trend" role="img" aria-label="Montant des ventes confirmées par jour"><div v-for="point in dashboard.sales.trend" :key="point.date" class="dashboard-trend-point" :title="money(point.total_millimes) + ' · ' + trendLabel(point.date)"><strong>{{ compactMoney(point.total_millimes) }}</strong><span class="dashboard-trend-bar"><i :class="{ 'is-empty': point.total_millimes === 0 }" :style="{ height: salesBarHeight(point.total_millimes) }"></i></span><small>{{ trendLabel(point.date) }}</small></div></div><div v-else class="dashboard-empty">Aucune vente confirmée sur cette période.</div></section>
  </template>
  </template>
</section>`;

const DashboardView: Component = {
    setup() {
        const period = ref('7d');
        const dateFrom = ref('');
        const dateTo = ref('');
        const activeTab = ref<'orders' | 'sales'>('orders');
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
                const response = await adminApi<Dashboard>(`dashboard?${query}`, 'GET', undefined, controller.signal);
                dashboard.value = response.data;
                return response.data.order_changes_cursor || null;
            } catch (cause) {
                if (controller.signal.aborted) return null;
                showError(cause instanceof Error ? cause.message : 'Le tableau de bord est indisponible.');
                return null;
            } finally {
                if (refreshController === controller) loading.value = false;
            }
        };
        const onOrderChanges = (payload: AdminOrderChangePayload) => {
            if (!payload.changed || !dashboard.value) return;
            const newOrders = (payload.created_ids || []).filter((reference) => !notifiedOrders.has(reference));
            newOrders.forEach((reference) => notifiedOrders.add(reference));
            if (newOrders.length) { pulseAdminOrderAttention(); showToast('info', newOrders.length === 1 ? 'Nouvelle commande reçue' : `${newOrders.length} nouvelles commandes reçues`); }
            if (payload.counts) {
                setAdminNewOrderCount(payload.counts.new || 0);
                dashboard.value = { ...dashboard.value, orders: { ...dashboard.value.orders, by_status: { ...dashboard.value.orders.by_status, nouvelle: payload.counts.new || 0, confirmee: payload.counts.confirmed || 0, annulee: payload.counts.cancelled || 0, tentative_1: payload.counts.attempt_1 || 0, tentative_2: payload.counts.attempt_2 || 0, tentative_3: payload.counts.attempt_3 || 0 } } };
            }
            void refresh(true);
        };
        const startPolling = async (cursor: string) => { try { const config = await loadAdminOrderPollingConfig(); if (!config.enabled) return; feed = new PollingOrderChangeFeed(config, { onChanges: onOrderChanges }); feed.start(cursor); } catch { /* Polling must never block dashboard usage. */ } };
        const manualRefresh = async () => { await refresh(); feed?.resetTimer(); };
        const money = (millimes: number) => `${millimesToDinars(millimes)} DT`;
        const orderRows = computed(() => Object.entries(dashboard.value?.orders.by_status ?? {}));
        const orderedTotal = computed(() => dashboard.value?.orders.trend.reduce((total, point) => total + point.orders, 0) ?? 0);
        const draftTotal = computed(() => dashboard.value?.orders.trend.reduce((total, point) => total + point.drafts, 0) ?? 0);
        const orderTrendMax = computed(() => Math.max(1, ...(dashboard.value?.orders.trend.map((point) => point.orders) ?? [1])));
        const orderBarHeight = (value: number) => `${value ? Math.max(8, Math.round((value / orderTrendMax.value) * 100)) : 3}%`;
        const salesPeriodTotal = computed(() => dashboard.value?.sales.trend.reduce((total, point) => total + point.total_millimes, 0) ?? 0);
        const salesTrendMax = computed(() => Math.max(1, ...(dashboard.value?.sales.trend.map((point) => point.total_millimes) ?? [1])));
        const salesBarHeight = (value: number) => `${value ? Math.max(8, Math.round((value / salesTrendMax.value) * 100)) : 3}%`;
        const compactMoney = (millimes: number) => {
            const dinars = millimes / 1000;
            if (dinars >= 1000) return `${(dinars / 1000).toFixed(1).replace('.', ',')} k DT`;
            if (dinars >= 100) return `${Math.round(dinars)} DT`;

            return `${dinars.toFixed(1).replace('.', ',')} DT`;
        };
        const statusDonutStyle = computed(() => {
            const colors: Record<string, string> = { nouvelle: '#d79a3b', tentative_1: '#d36b8e', tentative_2: '#d36b8e', tentative_3: '#d36b8e', confirmee: '#8070c9', annulee: '#b35a67' };
            let offset = 0;
            const stops = orderRows.value.map(([status, total]) => { const end = offset + (total / Math.max(1, dashboard.value?.orders.submitted || 1)) * 100; const segment = `${colors[status] || '#9c9297'} ${offset}% ${end}%`; offset = end; return segment; });
            return { background: `conic-gradient(${stops.join(', ')})` };
        });
        const trendLabel = (date: string) => new Date(`${date}T12:00:00`).toLocaleDateString('fr-TN', { day: '2-digit', month: 'short' });
        onMounted(async () => { const cursor = await refresh(); if (cursor) void startPolling(cursor); });
        onBeforeUnmount(() => { feed?.stop(); refreshController?.abort(); });
        return { period, dateFrom, dateTo, activeTab, dashboard, loading, refresh, manualRefresh, money, compactMoney, orderRows, orderedTotal, draftTotal, orderBarHeight, salesPeriodTotal, salesBarHeight, trendLabel, statusDonutStyle, statusLabels };
    },
    template: dashboardTemplate,
};

export default DashboardView;
