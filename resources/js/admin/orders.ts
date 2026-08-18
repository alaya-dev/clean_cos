import { computed, onBeforeUnmount, onMounted, ref, watch, type Component } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { adminApi } from './api';
import CustomerOrderHistoryPopover from './customer-order-history-popover';
import { confirmAction, showError, showToast } from './feedback';
import SelectControl from './select-control';
import { loadAdminOrderPollingConfig, PollingOrderChangeFeed, type AdminOrderChangePayload } from './order-change-feed';
import { adminNewOrderCount, adminOrderAttentionPulse, pulseAdminOrderAttention, setAdminNewOrderCount } from './order-attention';

type Order = { public_reference: string; customer_name: string; customer_phone: string; status: string; total_millimes: number; created_at: string; items_count: number; is_returning_customer?: boolean; product_names?: string[]; product_thumbnail_url?: string | null; navex_delivery?: { status: string; label: string; tracking_code: string | null } | null };
type Draft = { record_type: 'checkout_draft'; token: string; customer_data: Record<string, string | null>; items: Array<{ name: string; variant_label?: string | null; image_url?: string | null; quantity: number }>; estimated_total_millimes: number; last_activity_at: string };
type Page<T> = { data: T[]; current_page: number; last_page: number; per_page: number; total: number };
type OrderListFilters = { search: string; product_public_id: string; product_name: string; status: string; navex_status: string; archived: string; date_from: string; date_to: string; min_total_dt: string; max_total_dt: string; sort: string };
type LoadOptions = { background?: boolean; establishCursor?: boolean };

const money = (value: number) => `${(value / 1000).toFixed(3).replace('.', ',')} DT`;
const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
const toMillimes = (value: string) => value ? Math.round(Number(value.replace(',', '.')) * 1000) : null;
const statusMeta = (value: string) => ({ nouvelle: { label: 'Nouvelle', tone: 'new' }, confirmee: { label: 'Confirmée', tone: 'confirmed' }, tentative_1: { label: 'Tentative 1', tone: 'incident' }, tentative_2: { label: 'Tentative 2', tone: 'incident' }, tentative_3: { label: 'Tentative 3', tone: 'incident' }, annulee: { label: 'Annulée', tone: 'cancelled' } }[value] || { label: value, tone: 'muted' });
const statusOptions = [{ value: '', label: 'Choisir un statut' }, { value: 'nouvelle', label: 'Nouvelle' }, { value: 'tentative_1', label: 'Tentative 1' }, { value: 'tentative_2', label: 'Tentative 2' }, { value: 'tentative_3', label: 'Tentative 3' }, { value: 'confirmee', label: 'Confirmée' }, { value: 'annulee', label: 'Annulée' }];
const statusFilterOptions = [{ value: '', label: 'Tous les statuts' }, { value: 'nouvelle', label: 'Nouvelle' }, { value: 'tentatives', label: 'Tentatives (1, 2 ou 3)' }, { value: 'confirmee', label: 'Confirmée' }, { value: 'annulee', label: 'Annulée' }];
const tentativeStatuses = ['tentative_1', 'tentative_2', 'tentative_3'];
let activeAdminMutationCount = 0;

async function api<T>(path: string, method = 'GET', body?: unknown): Promise<T> {
    const isMutation = method !== 'GET';
    if (isMutation) activeAdminMutationCount += 1;
    try {
        const response = await fetch(`/api/v1/admin/${path}`, { method, credentials: 'same-origin', headers: { Accept: 'application/json', ...(method === 'GET' ? {} : { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }) }, ...(body === undefined ? {} : { body: JSON.stringify(body) }) });
        const payload = await response.json().catch(() => null) as { message?: string } | null;
        if (!response.ok) throw new Error(payload?.message || 'Action impossible. Réessayez dans un instant.');

        return payload as T;
    } finally {
        if (isMutation) activeAdminMutationCount = Math.max(0, activeAdminMutationCount - 1);
    }
}

const OrdersView: Component = {
    components: { RouterLink, SelectControl, CustomerOrderHistoryPopover },
    setup() {
        const route = useRoute();
        const page = ref<Page<Order> | null>(null);
        const draftPage = ref<Page<Draft> | null>(null);
        const loading = ref(true);
        const extra = ref(false);
        const selected = ref<string[]>([]);
        const bulkManualStatus = ref('');
        const orderChangesCursor = ref('');
        const freshAvailable = ref(false);
        const manualRefreshing = ref(false);
        let feed: PollingOrderChangeFeed | null = null;
        const notifiedOrders = new Set<string>();
        const defaultFilters = (): OrderListFilters => ({ search: '', product_public_id: '', product_name: '', status: '', navex_status: '', archived: '0', date_from: '', date_to: '', min_total_dt: '', max_total_dt: '', sort: '-created_at' });
        const filters = ref(defaultFilters());
        const draftOnly = computed(() => filters.value.archived === 'drafts');
        const showInlineDrafts = computed(() => draftOnly.value || (!filters.value.search && !filters.value.product_public_id && !filters.value.status && !filters.value.navex_status && filters.value.archived === '0' && !filters.value.date_from && !filters.value.date_to && !filters.value.min_total_dt && !filters.value.max_total_dt));
        const listPage = computed(() => draftOnly.value ? draftPage.value : page.value);
        const listCountLabel = computed(() => {
            const count = listPage.value?.total ?? 0;
            if (draftOnly.value) return `${count} panier${count > 1 ? 's' : ''} abandonné${count > 1 ? 's' : ''}`;

            return `${count} commande${count > 1 ? 's' : ''}`;
        });
        const allSelected = computed(() => !!page.value?.data.length && selected.value.length === page.value.data.length);
        const summaries = computed(() => {
            const orders = page.value?.data || [];
            const count = (...statuses: string[]) => orders.filter((order) => statuses.includes(order.status)).length;

            return [
                { label: 'Nouvelles', value: adminNewOrderCount.value, tone: 'new', icon: '○', filterStatus: 'nouvelle' },
                { label: 'Confirmées', value: count('confirmee'), tone: 'confirmed', icon: '✓', filterStatus: 'confirmee' },
                { label: 'Tentatives', value: count('tentative_1', 'tentative_2', 'tentative_3'), tone: 'incident', icon: '!', filterStatus: 'tentatives' },
                { label: 'Annulées', value: count('annulee'), tone: 'cancelled', icon: '×', filterStatus: 'annulee' },
            ];
        });
        const load = async (requestedPage = 1, options: LoadOptions = {}) => {
            if (!options.background) loading.value = true;
            try {
                if (draftOnly.value) {
                    const response = await api<{ data: Page<Draft> }>(`checkout-drafts?per_page=25&page=${requestedPage}`);
                    draftPage.value = response.data;
                    page.value = { data: [], current_page: response.data.current_page, last_page: response.data.last_page, per_page: response.data.per_page, total: response.data.total };
                    selected.value = [];
                    if (!options.background) freshAvailable.value = false;
                    return;
                }
                if (!showInlineDrafts.value) draftPage.value = null;
                const query = new URLSearchParams({ per_page: '25', page: String(requestedPage), sort: filters.value.sort, archived: filters.value.archived });
                if (filters.value.search) query.set('search', filters.value.search);
                if (filters.value.product_public_id) query.set('product_public_id', filters.value.product_public_id);
                if (filters.value.status === 'tentatives') tentativeStatuses.forEach((status) => query.append('statuses[]', status));
                else if (filters.value.status) query.set('status', filters.value.status);
                if (filters.value.navex_status) query.set('navex_status', filters.value.navex_status);
                if (filters.value.date_from) query.set('date_from', filters.value.date_from);
                if (filters.value.date_to) query.set('date_to', filters.value.date_to);
                const min = toMillimes(filters.value.min_total_dt); const max = toMillimes(filters.value.max_total_dt);
                if (min !== null) query.set('min_total_millimes', String(min));
                if (max !== null) query.set('max_total_millimes', String(max));
                const draftRequest = showInlineDrafts.value
                    ? api<{ data: Page<Draft> }>('checkout-drafts?per_page=100&page=1')
                    : Promise.resolve(null);
                const [orderResult, draftResult] = await Promise.allSettled([
                    api<{ data: Page<Order>; meta?: { order_changes_cursor?: string; new_orders_count?: number } }>(`orders?${query}`),
                    draftRequest,
                ]);
                if (orderResult.status === 'rejected') throw orderResult.reason;
                const response = orderResult.value;
                page.value = response.data;
                draftPage.value = draftResult.status === 'fulfilled' ? draftResult.value?.data ?? null : null;
                if (response.meta?.new_orders_count !== undefined) setAdminNewOrderCount(response.meta.new_orders_count);
                if (!options.background) freshAvailable.value = false;
                if ((!options.background || options.establishCursor) && response.meta?.order_changes_cursor) {
                    orderChangesCursor.value = response.meta.order_changes_cursor;
                    feed?.setCursor(orderChangesCursor.value);
                }
                selected.value = selected.value.filter((reference) => page.value?.data.some((order) => order.public_reference === reference));
            } catch (cause) { if (options.background) freshAvailable.value = true; else showError(cause instanceof Error ? cause.message : 'Impossible de charger les commandes.'); } finally { if (!options.background) loading.value = false; }
        };
        const changesAffectCurrentList = (payload: AdminOrderChangePayload) => {
            const visible = new Set(page.value?.data.map((order) => order.public_reference) || []);
            if ((payload.updated_ids || []).some((reference) => visible.has(reference))) return true;
            if ((payload.deleted_ids || []).some((reference) => visible.has(reference))) return true;

            return (payload.created_ids || []).length > 0 && filters.value.status === 'nouvelle';
        };
        const onOrderChanges = async (payload: AdminOrderChangePayload) => {
            if (!payload.changed) return;
            const newOrders = (payload.created_ids || []).filter((reference) => !notifiedOrders.has(reference));
            newOrders.forEach((reference) => notifiedOrders.add(reference));
            if (payload.counts?.new !== undefined) setAdminNewOrderCount(payload.counts.new);
            if (newOrders.length) {
                pulseAdminOrderAttention();
                showToast('info', newOrders.length === 1 ? 'Nouvelle commande reçue' : `${newOrders.length} nouvelles commandes reçues`);
            }
            if (loading.value || activeAdminMutationCount > 0 || !changesAffectCurrentList(payload)) {
                freshAvailable.value = true;
                return;
            }
            await load(page.value?.current_page || 1, { background: true });
        };
        const startPolling = async () => {
            if (!orderChangesCursor.value) return;
            try {
                const config = await loadAdminOrderPollingConfig();
                if (!config.enabled) return;
                feed = new PollingOrderChangeFeed(config, { onChanges: onOrderChanges });
                feed.start(orderChangesCursor.value);
            } catch {
                // Polling configuration failure must never interrupt order management.
            }
        };
        const manualRefresh = async () => {
            manualRefreshing.value = true;
            try {
                if (draftOnly.value) {
                    await load(draftPage.value?.current_page || 1, { background: true });
                    freshAvailable.value = false;
                } else if (freshAvailable.value || !feed) {
                    await load(page.value?.current_page || 1, { background: true, establishCursor: true });
                    freshAvailable.value = false;
                } else {
                    await feed.checkNow();
                }
                feed?.resetTimer();
            } finally {
                manualRefreshing.value = false;
            }
        };
        let timer: number | undefined;
        const search = () => { clearTimeout(timer); timer = window.setTimeout(load, 280); };
        const reset = () => { filters.value = defaultFilters(); void load(); showToast('info', 'Filtres réinitialisés.'); };
        const applySummaryFilter = async (status: string) => {
            filters.value.status = status;
            if (status) filters.value.archived = '0';
            selected.value = [];
            await load(1);
        };
        const showNewOrders = () => { void applySummaryFilter('nouvelle'); };
        const toggle = (reference: string) => selected.value = selected.value.includes(reference) ? selected.value.filter((item) => item !== reference) : [...selected.value, reference];
        const toggleAll = () => selected.value = allSelected.value ? [] : (page.value?.data.map((order) => order.public_reference) || []);
        const bulkTransition = async () => { if (!selected.value.length || !bulkManualStatus.value || !window.confirm(`Appliquer le statut « ${statusMeta(bulkManualStatus.value).label} » à ${selected.value.length} commande(s) ? Le passage à « Confirmée » met les commandes éligibles dans la file Navex automatique, sans créer de doublon.`)) return; try { const result = await api<{ data: { updated: number; skipped: number } }>('orders/bulk-transition', 'POST', { references: selected.value, to_status: bulkManualStatus.value }); const { updated, skipped } = result.data; showToast('success', `Statut modifié pour ${updated} commande${updated > 1 ? 's' : ''}${skipped ? ` ; ${skipped} ignorée${skipped > 1 ? 's' : ''}.` : '.'}`); selected.value = []; bulkManualStatus.value = ''; await load(); } catch (cause) { showError(cause instanceof Error ? cause.message : 'Mise à jour groupée impossible.'); } };
        const bulkArchive = async () => { if (!selected.value.length || !window.confirm(`Archiver ${selected.value.length} commande(s) ? Elles resteront consultables dans les archives et leur historique sera préservé.`)) return; try { await api('orders/bulk-archive', 'POST', { references: selected.value }); showToast('success', 'Commandes archivées, leur historique est préservé.'); selected.value = []; await load(); } catch (cause) { showError(cause instanceof Error ? cause.message : 'Archivage groupé impossible.'); } };
        const bulkRestore = async () => { if (!selected.value.length || !window.confirm(`Restaurer ${selected.value.length} commande(s) dans la liste active ?`)) return; try { await api('orders/bulk-restore', 'POST', { references: selected.value }); showToast('success', 'Commandes restaurées dans la liste active.'); selected.value = []; await load(); } catch (cause) { showError(cause instanceof Error ? cause.message : 'Restauration groupée impossible.'); } };
        const destroyOrders = async (references: string[]) => { const confirmed = await confirmAction(references.length === 1 ? 'Supprimer cette commande ?' : 'Supprimer définitivement les commandes ?', `Cette action supprimera ${references.length} commande(s) et leurs détails. Elle est irréversible.`, 'Supprimer définitivement', 'danger'); if (!confirmed) return; try { await api('orders/bulk', 'DELETE', { references }); showToast('success', references.length === 1 ? 'La commande a été supprimée définitivement.' : 'Les commandes ont été supprimées définitivement.'); selected.value = selected.value.filter((reference) => !references.includes(reference)); await load(); } catch (cause) { showError(cause instanceof Error ? cause.message : 'Suppression définitive impossible.'); } };
        const bulkDestroy = async () => { if (selected.value.length) await destroyOrders(selected.value); };
        const archiveOrder = async (order: Order) => { if (!window.confirm('Archiver cette commande ? Elle restera consultable dans les archives et son historique sera préservé.')) return; try { await api('orders/bulk-archive', 'POST', { references: [order.public_reference] }); showToast('success', 'La commande a été archivée.'); selected.value = selected.value.filter((reference) => reference !== order.public_reference); await load(); } catch (cause) { showError(cause instanceof Error ? cause.message : 'Archivage impossible.'); } };
        const destroyOrder = async (order: Order) => { await destroyOrders([order.public_reference]); };
        const deleteDraft = async (draft: Draft) => {
            const confirmed = await confirmAction('Supprimer ce panier abandonné ?', 'Le brouillon et ses informations seront définitivement supprimés. Aucun stock ni commande ne sera modifié.', 'Supprimer', 'danger');
            if (!confirmed) return;
            try {
                await adminApi(`checkout-drafts/${draft.token}`, 'DELETE');
                showToast('success', 'Le panier abandonné a été supprimé.');
                await load(page.value?.current_page || 1);
            } catch (cause) { showError(cause instanceof Error ? cause.message : 'Suppression du panier impossible.'); }
        };
        const exportCsv = () => { const query = new URLSearchParams(); if (filters.value.status) query.set('status', filters.value.status); if (filters.value.date_from) query.set('date_from', filters.value.date_from); if (filters.value.date_to) query.set('date_to', filters.value.date_to); window.location.assign(`/api/v1/admin/orders/export?${query}`); };
        const routeValue = (name: string) => typeof route.query[name] === 'string' ? route.query[name] : '';
        const applyFiltersFromRoute = () => {
            const next = defaultFilters();
            (Object.keys(next) as (keyof OrderListFilters)[]).forEach((key) => { const value = routeValue(`orders_${key}`); if (!value || (key === 'archived' && !['0', '1', 'drafts'].includes(value))) return; next[key] = value; });
            next.product_public_id = routeValue('product_public_id') || next.product_public_id;
            next.product_name = routeValue('product_name') || next.product_name;
            filters.value = next;
        };
        const savedPage = () => Math.max(1, Number.parseInt(routeValue('orders_page'), 10) || 1);
        const orderLink = (order: Order) => ({ path: `/orders/${order.public_reference}`, query: { ...route.query, ...Object.fromEntries((Object.entries(filters.value) as [keyof OrderListFilters, string][]).map(([key, value]) => [`orders_${key}`, value || undefined])), orders_page: page.value?.current_page && page.value.current_page > 1 ? String(page.value.current_page) : undefined } });
        const orderItemSummary = (order: Order) => {
            const names = order.product_names || [];
            if (!names.length) return `${order.items_count} article${order.items_count > 1 ? 's' : ''}`;
            if (names.length === 1) return `${names[0]} · ${order.items_count} article${order.items_count > 1 ? 's' : ''}`;

            return `${names[0]} + ${names.length - 1} autre${names.length > 2 ? 's' : ''}`;
        };
        onMounted(async () => { applyFiltersFromRoute(); await load(savedPage()); void startPolling(); });
        onBeforeUnmount(() => { clearTimeout(timer); feed?.stop(); });
        watch(() => route.query.product_public_id, () => { applyFiltersFromRoute(); void load(1); });
        return { route, page, draftPage, draftOnly, showInlineDrafts, listPage, listCountLabel, loading, extra, selected, filters, summaries, allSelected, bulkManualStatus, statusOptions, statusFilterOptions, freshAvailable, manualRefreshing, newOrdersCount: adminNewOrderCount, orderAttentionPulse: adminOrderAttentionPulse, load, search, reset, manualRefresh, applySummaryFilter, showNewOrders, toggle, toggleAll, bulkTransition, bulkArchive, bulkRestore, bulkDestroy, archiveOrder, destroyOrder, deleteDraft, exportCsv, money, statusMeta, orderLink, orderItemSummary };
    },
    template: `<section class="admin-page orders-page">
      <header class="admin-page-header"><div><p class="admin-eyebrow">Opérations</p><h1>Commandes</h1><p class="admin-subtitle">Recherchez, filtrez et suivez vos commandes.</p></div><button class="admin-outline orders-export" :disabled="!page?.data.length" @click="exportCsv">↓ <span>Exporter CSV</span></button></header>
      <section class="order-summary-grid" aria-label="Aperçu des commandes chargées"><component v-for="summary in summaries" :is="summary.filterStatus ? 'button' : 'article'" :key="summary.label" class="order-summary-card" :class="['is-' + summary.tone, { 'is-interactive': summary.filterStatus, 'is-active': summary.filterStatus && filters.status === summary.filterStatus, 'is-pulsing': summary.tone === 'new' && orderAttentionPulse }]" :type="summary.filterStatus ? 'button' : undefined" :aria-pressed="summary.filterStatus ? filters.status === summary.filterStatus : undefined" @click="summary.filterStatus ? applySummaryFilter(summary.filterStatus) : undefined"><span aria-hidden="true">{{ summary.icon }}</span><div><small>{{ summary.label }}</small><strong>{{ summary.value }}</strong><em>commande{{ summary.value > 1 ? 's' : '' }}</em></div></component></section>
      <p v-if="newOrdersCount > 0" class="orders-attention-banner" role="status" aria-live="polite"><span><strong>{{ newOrdersCount }} nouvelle{{ newOrdersCount > 1 ? 's' : '' }} commande{{ newOrdersCount > 1 ? 's' : '' }}</strong> nécessitent votre attention.</span><button class="admin-outline" type="button" @click="showNewOrders">Voir les nouvelles commandes</button></p>
      <p v-if="filters.product_public_id" class="list-instruction product-order-filter" role="status">Commandes contenant le produit : <strong>{{ filters.product_name || 'produit sélectionné' }}</strong><button class="text-link" type="button" @click="reset">Retirer ce filtre</button></p>
      <section class="orders-filter-card" aria-label="Filtres des commandes"><label class="orders-search"><span class="sr-only">Rechercher une commande</span><span aria-hidden="true">⌕</span><input v-model.trim="filters.search" @input="search" placeholder="Référence, client, téléphone ou produit…"></label><label class="toolbar-select"><span>Statut</span><SelectControl v-model="filters.status" :options="statusFilterOptions" @change="load()"/></label><label class="toolbar-select"><span>Livraison Navex</span><SelectControl v-model="filters.navex_status" :options="[{ value: '', label: 'Tous les envois' }, { value: 'en_attente_envoi', label: 'En attente d’envoi' }, { value: 'acceptee_navex', label: 'Acceptée par Navex' }, { value: 'en_attente_navex', label: 'En attente Navex' }, { value: 'en_cours_livraison', label: 'En cours de livraison' }, { value: 'livree_payee', label: 'Livrée et payée' }, { value: 'retournee', label: 'Retournée' }, { value: 'action_manuelle_requise', label: 'Action requise' }]" @change="load()"/></label><label class="toolbar-select"><span>Affichage</span><SelectControl v-model="filters.archived" :options="[{ value: '0', label: 'Commandes actives' }, { value: '1', label: 'Archives' }, { value: 'drafts', label: 'Paniers abandonnés' }]" @change="load()"/></label><label class="toolbar-select"><span>Trier par</span><SelectControl v-model="filters.sort" :options="[{ value: '-created_at', label: 'Plus récentes' }, { value: 'created_at', label: 'Plus anciennes' }, { value: '-total_millimes', label: 'Total décroissant' }, { value: 'total_millimes', label: 'Total croissant' }]" @change="load()"/></label><button class="admin-outline orders-more" type="button" :aria-expanded="extra" @click="extra = !extra">⌄ {{ extra ? 'Moins de filtres' : 'Plus de filtres' }}</button><button class="text-link orders-reset" type="button" @click="reset">Réinitialiser</button><Transition name="orders-filter"><div v-if="extra" class="orders-extra"><label>Du<input v-model="filters.date_from" type="date" @change="load()"></label><label>Au<input v-model="filters.date_to" type="date" @change="load()"></label><label>Total minimum (DT)<input v-model="filters.min_total_dt" inputmode="decimal" @change="load()"></label><label>Total maximum (DT)<input v-model="filters.max_total_dt" inputmode="decimal" @change="load()"></label></div></Transition></section>
      <section v-if="selected.length" class="bulk-bar" aria-live="polite"><strong>{{ selected.length }} sélectionnée{{ selected.length > 1 ? 's' : '' }}</strong><template v-if="filters.archived === '0'"><div class="bulk-status-control"><label><span>Mettre à jour le statut de la sélection</span><SelectControl v-model="bulkManualStatus" :options="statusOptions"/></label><button class="admin-outline" type="button" :disabled="!bulkManualStatus" @click="bulkTransition">Appliquer le statut</button><small>Choisissez le statut opérationnel souhaité. Vers « Confirmée », Navex automatique est déclenché une seule fois si la commande est éligible.</small></div><button class="text-link danger" type="button" @click="bulkArchive">Archiver</button><button class="text-link danger" type="button" @click="bulkDestroy">Supprimer définitivement</button></template><template v-else><span class="bulk-help">La restauration remet les commandes dans la liste opérationnelle, sans modifier leur statut.</span><button class="admin-outline" type="button" @click="bulkRestore">Restaurer</button><button class="text-link danger" type="button" @click="bulkDestroy">Supprimer définitivement</button></template></section>
      <p v-if="loading" class="admin-loading">Chargement des commandes…</p><section v-else-if="!page?.data.length" class="admin-empty orders-empty"><strong>Aucune commande ne correspond à ces filtres.</strong><span>Modifiez les filtres ou affichez toutes les commandes.</span><button class="text-link" type="button" @click="reset">Réinitialiser les filtres</button></section>
      <section v-else class="orders-list-card"><div class="orders-table-head"><label><input type="checkbox" :checked="allSelected" @change="toggleAll"><span class="sr-only">Sélectionner les commandes affichées</span></label><span>Produit</span><span>Client</span><span>Date</span><span>Total</span><span>Statut</span><span>Livraison Navex</span><span>Action</span></div><article v-for="order in page.data" :key="order.public_reference" class="order-row"><label class="order-select"><input type="checkbox" :checked="selected.includes(order.public_reference)" @change="toggle(order.public_reference)"><span class="sr-only">Sélectionner la commande {{ order.public_reference }}</span></label><RouterLink class="order-product-link" :to="orderLink(order)" :aria-label="'Ouvrir la commande ' + order.public_reference"><img v-if="order.product_thumbnail_url" class="order-product-thumb" :src="order.product_thumbnail_url" alt="" decoding="async"><span v-else class="order-product-fallback" aria-hidden="true">P</span></RouterLink><RouterLink class="order-customer" :to="orderLink(order)" :aria-label="'Ouvrir la commande ' + order.public_reference"><div><strong class="order-customer-name">{{ order.customer_name }}</strong><small class="order-reference">{{ order.public_reference }} · {{ order.items_count }} article{{ order.items_count > 1 ? 's' : '' }}</small><strong class="order-phone">{{ order.customer_phone }}</strong></div></RouterLink><time :datetime="order.created_at">{{ new Date(order.created_at).toLocaleDateString('fr-TN') }}<small>{{ new Date(order.created_at).toLocaleTimeString('fr-TN', { hour: '2-digit', minute: '2-digit' }) }}</small></time><strong class="order-total">{{ money(order.total_millimes) }}</strong><span class="order-status" :class="'is-' + statusMeta(order.status).tone">{{ statusMeta(order.status).label }}</span><span class="order-navex" :class="{ 'is-pending': order.navex_delivery, 'is-empty': !order.navex_delivery }">{{ order.navex_delivery?.label || 'Non envoyée' }}</span><span class="order-row-actions"><RouterLink class="admin-icon-action" :to="orderLink(order)" aria-label="Voir la commande" title="Voir la commande">◉</RouterLink><button class="admin-icon-action is-danger" type="button" :aria-label="'Supprimer la commande ' + order.public_reference" title="Supprimer la commande" @click="destroyOrder(order)">×</button></span></article><footer class="orders-pagination"><span>{{ listCountLabel }}</span><span>Page {{ listPage?.current_page || 1 }} sur {{ listPage?.last_page || 1 }}</span><div><button type="button" :disabled="(listPage?.current_page || 1) <= 1" aria-label="Page précédente" @click="load((listPage?.current_page || 1) - 1)">‹</button><button type="button" :disabled="(listPage?.current_page || 1) >= (listPage?.last_page || 1)" aria-label="Page suivante" @click="load((listPage?.current_page || 1) + 1)">›</button></div></footer></section>
    </section>`,
};

OrdersView.template = (OrdersView.template as string).replace(
    /<small class="order-reference">.*?<\/small>/,
    '<small class="order-purchased-items" :title="(order.product_names || []).join(\' · \')">{{ orderItemSummary(order) }}</small>',
);

OrdersView.template = (OrdersView.template as string).replace(
    '</div><button class="admin-outline orders-export"',
    '</div><div class="orders-header-actions"><RouterLink class="admin-action" :to="{ path: \'/orders/new\', query: route.query }">Nouvelle commande</RouterLink><button class="admin-outline orders-export"',
).replace(
    '</button></header>',
    '</button></div></header>',
);

OrdersView.template = (OrdersView.template as string)
    .replace(
        /(<button class="admin-outline orders-export"[^>]*>.*?<\/button>)/,
        '$1<button class="admin-outline orders-refresh" type="button" :disabled="manualRefreshing" @click="manualRefresh">↻ <span>Actualiser</span></button>',
    )
    .replace(
        /(<section class="orders-filter-card"[\s\S]*?<\/section>)/,
        '$1<p v-if="freshAvailable" class="orders-fresh-notice" role="status"><span>Nouvelles données disponibles.</span><button class="text-link" type="button" @click="manualRefresh">Actualiser</button></p>',
    );

OrdersView.template = (OrdersView.template as string).replace(
    ':options="[{ value: \'\', label: \'Tous les statuts\' }, ...statusOptions.slice(1)]"',
    ':options="statusFilterOptions"',
);

OrdersView.template = (OrdersView.template as string)
    .replace(
        '<section v-else-if="!page?.data.length" class="admin-empty orders-empty">',
        '<section v-else-if="filters.record_type === \'checkout_drafts\' && !draftPage?.data.length" class="admin-empty orders-empty"><strong>Aucun panier abandonné à récupérer.</strong><span>Les brouillons non finalisés apparaîtront ici après leur délai d’inactivité.</span></section><section v-else-if="filters.record_type === \'checkout_drafts\'" class="orders-list-card checkout-drafts-list"><div class="orders-table-head"><span>Client</span><span>Articles</span><span>Dernière activité</span><span>Total estimé</span><span>Action</span></div><article v-for="draft in draftPage.data" :key="draft.token" class="order-row checkout-draft-row"><div class="order-customer"><div><strong class="order-customer-name">{{ draft.customer_data.full_name || \'Client sans nom\' }}</strong><small class="order-reference">{{ draft.customer_data.phone || \'Téléphone non renseigné\' }}</small></div></div><div class="checkout-draft-items"><span v-for="item in draft.items" :key="item.name + (item.variant_label || \'\')">{{ item.quantity }} × {{ item.name }}<small v-if="item.variant_label">{{ item.variant_label }}</small></span></div><time :datetime="draft.last_activity_at">{{ new Date(draft.last_activity_at).toLocaleString(\'fr-TN\') }}</time><strong class="order-total">{{ money(draft.estimated_total_millimes) }}</strong><RouterLink class="admin-action" :to="{ path: \'/orders/drafts/\' + draft.token, query: route.query }">Récupérer</RouterLink></article><footer class="orders-pagination"><span>{{ draftPage.total }} panier{{ draftPage.total > 1 ? \'s\' : \'\' }} abandonné{{ draftPage.total > 1 ? \'s\' : \'\' }}</span><span>Page {{ draftPage.current_page }} sur {{ draftPage.last_page }}</span><div><button type="button" :disabled="draftPage.current_page <= 1" @click="load(draftPage.current_page - 1)">‹</button><button type="button" :disabled="draftPage.current_page >= draftPage.last_page" @click="load(draftPage.current_page + 1)">›</button></div></footer></section><section v-else-if="filters.record_type === \'orders\' && !page?.data.length" class="admin-empty orders-empty">',
    )
    .replace(
        '<section v-else class="orders-list-card">',
        '<section v-else-if="filters.record_type === \'orders\'" class="orders-list-card">',
    )
    .replace('<label class="orders-search"', '<label v-if="filters.record_type === \'orders\'" class="orders-search"')
    .replace('<label class="toolbar-select"><span>Statut</span>', '<label v-if="filters.record_type === \'orders\'" class="toolbar-select"><span>Statut</span>')
    .replace('<label class="toolbar-select"><span>Livraison Navex</span>', '<label v-if="filters.record_type === \'orders\'" class="toolbar-select"><span>Livraison Navex</span>')
    .replace('<label class="toolbar-select"><span>Affichage</span>', '<label v-if="filters.record_type === \'orders\'" class="toolbar-select"><span>Affichage</span>')
    .replace('<label class="toolbar-select"><span>Trier par</span>', '<label v-if="filters.record_type === \'orders\'" class="toolbar-select"><span>Trier par</span>')
    .replace('<button class="admin-outline orders-more"', '<button v-if="filters.record_type === \'orders\'" class="admin-outline orders-more"')
    .replace('<button class="text-link orders-reset"', '<button v-if="filters.record_type === \'orders\'" class="text-link orders-reset"')
    .replace('<Transition name="orders-filter">', '<Transition v-if="filters.record_type === \'orders\'" name="orders-filter">');

OrdersView.template = (OrdersView.template as string).replace(
    /(<small class="order-reference">\{\{ draft\.customer_data\.phone[\s\S]*?<\/small>)/,
    '$1<span class="order-status is-abandoned">Commande abandonnée</span>',
);

// Keep drafts in the shared orders layout; the Affichage filter controls which source is loaded.
OrdersView.template = (OrdersView.template as string)
    .replace(/<label class="toolbar-select"><span>Type<\/span><SelectControl[^>]*\/><\/label>/, '')
    .replace(/<section v-else-if="filters\.record_type === 'checkout_drafts'[\s\S]*?<section v-else-if="filters\.record_type === 'orders' && !page\?\.data\.length" class="admin-empty orders-empty">/, '<section v-else-if="!page?.data.length && !draftPage?.data.length" class="admin-empty orders-empty">')
    .replace(/ v-if="filters\.record_type === 'orders'"/g, '')
    .replace('v-else-if="filters.record_type === \'orders\'" class="orders-list-card"', 'v-else class="orders-list-card"')
    .replace(
        '<article v-for="order in page.data"',
        String.raw`<template v-if="showInlineDrafts"><div class="orders-drafts-heading"><strong>Paniers abandonnÃ©s</strong><span>{{ draftPage.total }} Ã  rÃ©cupÃ©rer</span></div><article v-for="draft in draftPage?.data || []" :key="'draft-' + draft.token" class="order-row checkout-draft-row"><span class="order-select order-select-placeholder" aria-hidden="true"></span><RouterLink class="order-product-link" :to="{ path: '/orders/drafts/' + draft.token, query: route.query }" aria-label="RÃ©cupÃ©rer le panier abandonnÃ©"><span class="order-product-fallback" aria-hidden="true">P</span></RouterLink><RouterLink class="order-customer" :to="{ path: '/orders/drafts/' + draft.token, query: route.query }" aria-label="RÃ©cupÃ©rer le panier abandonnÃ©"><div><strong class="order-customer-name">{{ draft.customer_data.full_name || 'Client sans nom' }}</strong><small class="order-purchased-items">{{ draft.customer_data.phone || 'TÃ©lÃ©phone non renseignÃ©' }}</small><span class="order-draft-items-summary">{{ draft.items.length }} article{{ draft.items.length > 1 ? 's' : '' }}</span></div></RouterLink><time v-if="draft.last_activity_at" :datetime="draft.last_activity_at">{{ new Date(draft.last_activity_at).toLocaleDateString('fr-TN') }}<small>{{ new Date(draft.last_activity_at).toLocaleTimeString('fr-TN', { hour: '2-digit', minute: '2-digit' }) }}</small></time><strong class="order-total">{{ money(draft.estimated_total_millimes) }}</strong><span class="order-status is-abandoned">Commande abandonnÃ©e</span><span class="order-navex is-empty">Non applicable</span><span class="order-row-actions"><RouterLink class="admin-action" :to="{ path: '/orders/drafts/' + draft.token, query: route.query }">RÃ©cupÃ©rer</RouterLink><button class="text-link danger" type="button" @click="deleteDraft(draft)">Supprimer</button></span></article></template><article v-for="order in page.data"`,
    );

OrdersView.template = (OrdersView.template as string)
    .replaceAll('Paniers abandonnÃ©s', 'Paniers abandonnés')
    .replaceAll('Ã  rÃ©cupÃ©rer', 'à récupérer')
    .replaceAll('RÃ©cupÃ©rer', 'Récupérer')
    .replaceAll('rÃ©cupÃ©rer', 'récupérer')
    .replaceAll('TÃ©lÃ©phone', 'Téléphone')
    .replaceAll('abandonnÃ©e', 'abandonnée');

OrdersView.template = (OrdersView.template as string).replace(
    '<section v-else-if="!page?.data.length && !draftPage?.data.length" class="admin-empty orders-empty"><strong>Aucune commande ne correspond à ces filtres.</strong><span>Modifiez les filtres ou affichez toutes les commandes.</span><button class="text-link" type="button" @click="reset">Réinitialiser les filtres</button></section>',
    '<section v-else-if="!page?.data.length && !draftPage?.data.length" class="admin-empty orders-empty"><strong>{{ draftOnly ? \'Aucun panier abandonné à récupérer.\' : \'Aucune commande ne correspond à ces filtres.\' }}</strong><span>{{ draftOnly ? \'Les paniers abandonnés apparaîtront ici après leur délai d’inactivité.\' : \'Modifiez les filtres ou affichez toutes les commandes.\' }}</span><button class="text-link" type="button" @click="reset">Réinitialiser les filtres</button></section>',
);

OrdersView.template = (OrdersView.template as string).replace(
    /<RouterLink class="order-customer" :to="orderLink\(order\)" :aria-label="'Ouvrir la commande ' \+ order\.public_reference">[\s\S]*?<\/RouterLink><time/,
    '<div class="order-customer"><RouterLink class="order-customer-main" :to="orderLink(order)" :aria-label="\'Ouvrir la commande \' + order.public_reference"><div><strong class="order-customer-name">{{ order.customer_name }}</strong><small class="order-purchased-items" :title="(order.product_names || []).join(\' · \')">{{ orderItemSummary(order) }}</small><strong class="order-phone">{{ order.customer_phone }}</strong></div></RouterLink><CustomerOrderHistoryPopover v-if="order.is_returning_customer" :order-reference="order.public_reference" /></div><time',
);

OrdersView.template = (OrdersView.template as string).replace(
    '<button class="admin-icon-action is-danger" type="button" :aria-label="\'Supprimer la commande \' + order.public_reference" title="Supprimer la commande" @click="destroyOrder(order)">×</button>',
    '<button v-if="filters.archived === \'1\'" class="admin-icon-action is-danger" type="button" :aria-label="\'Supprimer définitivement la commande \' + order.public_reference" title="Supprimer définitivement" @click="destroyOrder(order)">×</button><button v-else class="admin-icon-action is-danger" type="button" :aria-label="\'Archiver la commande \' + order.public_reference" title="Archiver la commande" @click="archiveOrder(order)">⌫</button>',
);

OrdersView.template = (OrdersView.template as string).replace(
    '<button class="text-link danger" type="button" @click="bulkArchive">Archiver</button><button class="text-link danger" type="button" @click="bulkDestroy">Supprimer définitivement</button></template><template v-else>',
    '<button class="text-link danger" type="button" @click="bulkArchive">Archiver</button></template><template v-else>',
);

OrdersView.template = (OrdersView.template as string).replace(
    '<span class="order-product-fallback" aria-hidden="true">P</span></RouterLink><RouterLink class="order-customer" :to="{ path: \'/orders/drafts/\' + draft.token, query: route.query }"',
    '<img v-if="draft.items[0]?.image_url" class="order-product-thumb" :src="draft.items[0].image_url" alt="" decoding="async"><span v-else class="order-product-fallback" aria-hidden="true">P</span></RouterLink><RouterLink class="order-customer" :to="{ path: \'/orders/drafts/\' + draft.token, query: route.query }"',
);

export default OrdersView;
