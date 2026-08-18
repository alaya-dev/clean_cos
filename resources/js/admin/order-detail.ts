import { computed, onBeforeUnmount, onMounted, ref, watch, type Component } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { confirmAction, showError, showToast } from './feedback';
import SelectControl from './select-control';
import { refreshAdminNewOrderCount } from './order-attention';
import { dinarsToMillimes, millimesToDinars } from './api';

type Variant = { public_id: string; sku: string | null; is_active: boolean; is_default?: boolean; stock_quantity?: number; values?: { value: string }[] };
type ProductImage = { public_url?: string | null };
type Product = { public_id: string; name?: string; has_variants?: boolean; stock_quantity?: number; variants: Variant[]; images?: ProductImage[] };
type Item = { id: number; product_name_snapshot: string; quantity: number; line_total_millimes: number; product: Product | null; variant: Variant | null };
type Order = {
    public_reference: string; designation: string; lock_version: number; customer_name: string; customer_phone: string;
    customer_city: string; customer_governorate: string | null; customer_address: string; customer_previous_order_at: string | null; is_exchange: boolean; exchange_article_designation: string | null; exchange_article_count: number | null; status: string; total_millimes: number; manual_total_millimes: number | null;
    created_at?: string; items: Item[]; notes: { body: string; created_at?: string }[];
    status_history: { from_status: string | null; to_status: string; reason: string | null; created_at?: string; changed_by?: { name: string; role: string } | null }[];
};
type NavexShipment = { status: string; status_label: string; display_status_label: string; tracking_code: string | null; raw_status: string | null; raw_reason: string | null; last_synchronized_at: string | null; last_error_classification: string | null; status_history: { status: string; raw_status: string | null; recorded_at: string }[] };
type Detail = { order: Order; is_editable: boolean; is_delivery_editable: boolean; allowed_transitions: string[]; navex: { ready: { ready: boolean; reasons: string[]; mode: string }; shipment: NavexShipment | null; manual_update_required: boolean } };
type Line = { product_public_id: string; variant_public_id: string | null; quantity: number; label: string; variants: Variant[]; image_url: string | null };
type CustomerForm = { full_name: string; phone: string; city: string; governorate: string; address: string; is_exchange: string; exchange_article_designation: string; exchange_article_count: number | null };

const money = (value: number) => `${(value / 1000).toFixed(3).replace('.', ',')} DT`;
const variantLabel = (variant: Variant | null | undefined) => variant?.sku || (Array.isArray(variant?.values) ? variant.values.map((value) => value.value).join(' · ') : '') || 'Variante';
const statusMeta = (value: string) => ({
    brouillon: { label: 'Brouillon', tone: 'muted' }, nouvelle: { label: 'Nouvelle', tone: 'new' }, confirmee: { label: 'Confirmée', tone: 'confirmed' },
    tentative_1: { label: 'Tentative 1', tone: 'incident' }, tentative_2: { label: 'Tentative 2', tone: 'incident' },
    tentative_3: { label: 'Tentative 3', tone: 'incident' }, annulee: { label: 'Annulée', tone: 'cancelled' },
})[value] || { label: value, tone: 'muted' };

async function api<T>(path: string, method = 'GET', body?: unknown): Promise<T> {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
    const response = await fetch(`/api/v1/admin/${path}`, {
        method, credentials: 'same-origin', headers: { Accept: 'application/json', ...(method === 'GET' ? {} : { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token }) },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    });
    if (!response.ok) {
        const data = (await response.json().catch(() => null)) as { message?: string } | null;
        throw new Error(data?.message || 'Opération impossible.');
    }
    return response.json() as Promise<T>;
}

const OrderDetailView: Component = {
    components: { RouterLink, SelectControl },
    setup() {
        const route = useRoute();
        const detail = ref<Detail | null>(null);
        const lines = ref<Line[]>([]);
        const unavailableItemIds = ref<number[]>([]);
        const replaceUnavailableItems = ref(false);
        const products = ref<Product[]>([]);
        const productSearch = ref('');
        const productSearchInput = ref<HTMLInputElement | null>(null);
        const productSearchOpen = ref(false);
        const productSearchActiveIndex = ref(-1);
        const productSearchLoading = ref(false);
        const pendingAddProduct = ref<Product | null>(null);
        const replacingLineIndex = ref<number | null>(null);
        const replacementSearch = ref('');
        const replacementProducts = ref<Product[]>([]);
        const replacementSearchOpen = ref(false);
        const replacementSearchActiveIndex = ref(-1);
        const replacementSearchLoading = ref(false);
        const customer = ref<CustomerForm>({ full_name: '', phone: '', city: '', governorate: '', address: '', is_exchange: 'Non', exchange_article_designation: '', exchange_article_count: null });
        const priceInput = ref('');
        const governorates = ref<string[]>([]);
        const governorateQuery = ref('');
        const governorateOpen = ref(false);
        const governorateActiveIndex = ref(-1);
        const selectedGovernorate = ref('');
        const note = ref('');
        const loading = ref(true);
        const saving = ref(false);
        const nextStatus = ref('');
        let productSearchTimer: number | undefined;
        let replacementSearchTimer: number | undefined;
        let navexRefreshTimer: number | undefined;
        let productSearchRequest = 0;
        let replacementSearchRequest = 0;

        const filteredGovernorates = computed(() => {
            const query = governorateQuery.value.trim().toLocaleLowerCase('fr');
            return governorates.value.filter((option) => !query || option.toLocaleLowerCase('fr').includes(query));
        });
        const statusOptions = computed(() => [
            { value: '', label: detail.value?.allowed_transitions.length ? 'Choisir la prochaine étape' : 'Aucune action disponible' },
            ...(detail.value?.allowed_transitions || []).map((value) => ({ value, label: statusMeta(value).label })),
        ]);
        const selectGovernorate = (value: string) => { selectedGovernorate.value = value; customer.value.governorate = value; governorateQuery.value = value; governorateOpen.value = false; };
        const selectExactGovernorate = () => {
            const exact = filteredGovernorates.value.find((option) => option.toLocaleLowerCase('fr') === governorateQuery.value.trim().toLocaleLowerCase('fr'));
            const active = filteredGovernorates.value[governorateActiveIndex.value];
            if (exact) selectGovernorate(exact); else if (active && governorateOpen.value) selectGovernorate(active);
        };
        watch(governorateQuery, (value) => { governorateActiveIndex.value = -1; if (value !== selectedGovernorate.value) { selectedGovernorate.value = ''; customer.value.governorate = ''; } });

        const refresh = async () => {
            const next = (await api<{ data?: Detail }>(`orders/${route.params.reference}`)).data;
            if (!next?.order || !Array.isArray(next.order.items)) throw new Error('La commande reçue est invalide.');
            detail.value = next;
            nextStatus.value = '';
            priceInput.value = millimesToDinars(next.order.total_millimes);
            const savedGovernorate = next.order.customer_governorate || '';
            customer.value = { full_name: next.order.customer_name || '', phone: next.order.customer_phone || '', city: next.order.customer_city || '', governorate: savedGovernorate, address: next.order.customer_address || '', is_exchange: next.order.is_exchange ? 'Oui' : 'Non', exchange_article_designation: next.order.exchange_article_designation || '', exchange_article_count: next.order.exchange_article_count ?? null };
            selectedGovernorate.value = governorates.value.includes(savedGovernorate) ? savedGovernorate : '';
            governorateQuery.value = savedGovernorate;
            unavailableItemIds.value = next.order.items.filter((item) => !item.product || (item.product.has_variants && !item.variant)).map((item) => item.id);
            replaceUnavailableItems.value = false;
            lines.value = next.order.items.flatMap((item) => item.product && !unavailableItemIds.value.includes(item.id) ? [{
                product_public_id: item.product.public_id,
                variant_public_id: item.variant?.public_id || null,
                quantity: item.quantity,
                label: item.product_name_snapshot,
                variants: (item.product.variants || []).filter((variant) => variant.is_active),
                image_url: item.product.images?.[0]?.public_url || null,
            }] : []);
        };
        watch(() => detail.value?.order.status, (status, previous) => {
            if (status && previous && status !== previous) void refreshAdminNewOrderCount().catch(() => undefined);
        });
        const loadProducts = async (search: string, request: number, target: 'add' | 'replacement') => {
            if (!detail.value) return;
            const query = new URLSearchParams();
            if (search) query.set('search', search);
            try {
                const response = await api<{ data: Product[] }>(`orders/${detail.value.order.public_reference}/available-products?${query}`);
                if (target === 'add' && request === productSearchRequest && search === productSearch.value.trim()) products.value = response.data;
                if (target === 'replacement' && request === replacementSearchRequest && search === replacementSearch.value.trim()) replacementProducts.value = response.data;
            } finally {
                if (target === 'add' && request === productSearchRequest) productSearchLoading.value = false;
                if (target === 'replacement' && request === replacementSearchRequest) replacementSearchLoading.value = false;
            }
        };
        watch(productSearch, (value) => {
            productSearchActiveIndex.value = -1;
            window.clearTimeout(productSearchTimer);
            productSearchRequest++;
            products.value = [];
            productSearchLoading.value = false;
            if (!value.trim()) return;
            productSearchLoading.value = true;
            const request = productSearchRequest;
            const search = value.trim();
            productSearchTimer = window.setTimeout(() => { void loadProducts(search, request, 'add').catch((cause: unknown) => showError(cause instanceof Error ? cause.message : 'Impossible de charger les produits.')); }, 220);
        });
        watch(replacementSearch, (value) => {
            replacementSearchActiveIndex.value = -1;
            window.clearTimeout(replacementSearchTimer);
            replacementSearchRequest++;
            replacementProducts.value = [];
            replacementSearchLoading.value = false;
            if (!value.trim() || replacingLineIndex.value === null) return;
            replacementSearchLoading.value = true;
            const request = replacementSearchRequest;
            const search = value.trim();
            replacementSearchTimer = window.setTimeout(() => { void loadProducts(search, request, 'replacement').catch((cause: unknown) => showError(cause instanceof Error ? cause.message : 'Impossible de charger les produits.')); }, 220);
        });
        const lineFromProduct = (product: Product, selectedVariant: Variant | null = null): Line | null => {
            const activeVariants = (product.variants || []).filter((variant) => variant.is_active);
            const variant = product.has_variants ? selectedVariant || activeVariants.find((candidate) => candidate.is_default && (candidate.stock_quantity || 0) > 0) || activeVariants.find((candidate) => (candidate.stock_quantity || 0) > 0) || activeVariants[0] : null;
            if (product.has_variants && !variant) {
                showError('Ce produit ne possède aucune variante active disponible.');

                return null;
            }

            return { product_public_id: product.public_id, variant_public_id: variant?.public_id || null, quantity: 1, label: product.name || 'Produit', variants: activeVariants, image_url: product.images?.[0]?.public_url || null };
        };
        const applyProduct = (product: Product, replacementIndex: number | null, selectedVariant: Variant | null = null): boolean => {
            const nextLine = lineFromProduct(product, selectedVariant);
            if (!nextLine) return false;
            const key = `${nextLine.product_public_id}|${nextLine.variant_public_id || ''}`;
            if (lines.value.some((line, index) => index !== replacementIndex && `${line.product_public_id}|${line.variant_public_id || ''}` === key)) {
                showError('Cet article est déjà présent dans la commande.');

                return false;
            }
            if (replacementIndex === null) {
                lines.value.push(nextLine);
            } else {
                lines.value.splice(replacementIndex, 1, nextLine);
            }
            return true;
        };
        const chooseProduct = (product: Product) => {
            if (product.has_variants) {
                pendingAddProduct.value = product;
                productSearchOpen.value = false;
                productSearchActiveIndex.value = -1;
                productSearchInput.value?.blur();

                return;
            }
            applyProduct(product, null);
            productSearch.value = '';
            productSearchActiveIndex.value = -1;
            productSearchOpen.value = false;
            productSearchInput.value?.blur();
        };
        const chooseAddProductVariant = (variant: Variant) => {
            if (!pendingAddProduct.value) return;
            if (!applyProduct(pendingAddProduct.value, null, variant)) return;
            pendingAddProduct.value = null;
            productSearch.value = '';
            productSearchActiveIndex.value = -1;
            productSearchOpen.value = false;
            productSearchInput.value?.blur();
        };
        const closeAddProductVariantPicker = () => { pendingAddProduct.value = null; };
        const chooseReplacementProduct = (product: Product) => {
            if (replacingLineIndex.value === null) return;
            applyProduct(product, replacingLineIndex.value);
            closeReplacementSearch();
        };
        const replaceLine = (index: number) => {
            replacingLineIndex.value = index;
            replacementSearch.value = '';
            replacementProducts.value = [];
            replacementSearchActiveIndex.value = -1;
            replacementSearchOpen.value = true;
        };
        const closeProductSearch = () => {
            window.setTimeout(() => { productSearchOpen.value = false; }, 120);
        };
        const closeReplacementSearch = () => {
            replacingLineIndex.value = null;
            replacementSearch.value = '';
            replacementProducts.value = [];
            replacementSearchActiveIndex.value = -1;
            replacementSearchOpen.value = false;
        };
        const handleProductSearchKeydown = (event: KeyboardEvent, target: 'add' | 'replacement') => {
            const query = target === 'add' ? productSearch : replacementSearch;
            const results = target === 'add' ? products : replacementProducts;
            const open = target === 'add' ? productSearchOpen : replacementSearchOpen;
            const activeIndex = target === 'add' ? productSearchActiveIndex : replacementSearchActiveIndex;
            const choose = target === 'add' ? chooseProduct : chooseReplacementProduct;
            if (!query.value.trim() || !results.value.length) {
                if (event.key === 'Escape') {
                    if (target === 'add') productSearchOpen.value = false; else closeReplacementSearch();
                }
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                open.value = true;
                activeIndex.value = Math.min(activeIndex.value + 1, results.value.length - 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                open.value = true;
                activeIndex.value = Math.max(activeIndex.value - 1, 0);
            } else if (event.key === 'Enter' && open.value) {
                event.preventDefault();
                const product = results.value[activeIndex.value] || results.value[0];
                if (product) choose(product);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                if (target === 'add') productSearchOpen.value = false; else closeReplacementSearch();
            }
        };
        const removeLine = (index: number) => { if (lines.value.length > 1) lines.value.splice(index, 1); };
        const run = async (path: string, method: string, body: unknown, successMessage: string) => {
            saving.value = true;
            try { await api(path, method, body); await refresh(); showToast('success', successMessage); return true; }
            catch (cause: unknown) { showError(cause instanceof Error ? cause.message : 'Opération impossible.'); return false; }
            finally { saving.value = false; }
        };
        const saveItems = async () => {
            if (!detail.value || !lines.value.length) return;
            const keys = lines.value.map((line) => `${line.product_public_id}|${line.variant_public_id || ''}`);
            if (new Set(keys).size !== keys.length) { showError('Un produit et sa variante ne peuvent apparaître qu’une seule fois.'); return; }
            const message = detail.value.order.manual_total_millimes !== null
                ? 'Articles recalculés. Le total personnalisé est conservé.'
                : (detail.value.navex.manual_update_required ? 'Articles et total recalculés. Mettez maintenant le colis à jour dans Navex.' : 'Articles et total recalculés.');
            await run(`orders/${detail.value.order.public_reference}/items`, 'PUT', { lock_version: detail.value.order.lock_version, items: lines.value.map(({ product_public_id, variant_public_id, quantity }) => ({ product_public_id, variant_public_id, quantity })), remove_unavailable_item_ids: replaceUnavailableItems.value ? unavailableItemIds.value : [] }, message);
        };
        const saveTotal = async (reset = false) => {
            if (!detail.value) return;
            const total = reset ? null : dinarsToMillimes(priceInput.value);
            if (!reset && total === null) {
                showError('Saisissez un montant valide avec au maximum trois décimales.');

                return;
            }
            await run(`orders/${detail.value.order.public_reference}/total`, 'PATCH', { lock_version: detail.value.order.lock_version, total_millimes: total }, reset ? 'Le total est revenu au calcul des articles.' : 'Le total personnalisé a été enregistré.');
        };
        const refreshNavexStatus = async () => {
            if (!detail.value) return;
            const next = (await api<{ data?: Detail }>(`orders/${detail.value.order.public_reference}`)).data;
            if (!next?.navex) return;
            detail.value.navex = next.navex;
            detail.value.is_editable = next.is_editable;
            detail.value.is_delivery_editable = next.is_delivery_editable;
        };
        const navexNeedsFollowUp = () => ['en_attente_envoi', 'envoi_en_cours', 'resultat_incertain', 'acceptee_navex', 'en_attente_navex'].includes(detail.value?.navex.shipment?.status || '');
        const scheduleNavexRefresh = (attempt = 0) => {
            window.clearTimeout(navexRefreshTimer);
            navexRefreshTimer = window.setTimeout(async () => {
                try {
                    await refreshNavexStatus();
                    if (attempt < 3 && navexNeedsFollowUp()) scheduleNavexRefresh(attempt + 1);
                } catch {
                    // Background status reconciliation must never interrupt the operator.
                }
            }, attempt === 0 ? 1200 : 2500);
        };

        onMounted(async () => {
            try {
                const fieldsResponse = await fetch('/api/v1/public/checkout-fields', { headers: { Accept: 'application/json' } });
                if (fieldsResponse.ok) {
                    const fields = await fieldsResponse.json() as { data?: { key: string; options: string[] | null }[] };
                    governorates.value = fields.data?.find((field) => field.key === 'governorate')?.options ?? [];
                }
                await refresh();
                if (navexNeedsFollowUp()) scheduleNavexRefresh();
            } catch (cause: unknown) { showError(cause instanceof Error ? cause.message : 'Impossible de charger la commande.'); }
            finally { loading.value = false; }
        });
        onBeforeUnmount(() => {
            window.clearTimeout(productSearchTimer);
            window.clearTimeout(replacementSearchTimer);
            window.clearTimeout(navexRefreshTimer);
        });

        return {
            detail, lines, unavailableItemIds, replaceUnavailableItems, products, productSearch, productSearchInput, productSearchOpen, productSearchActiveIndex, productSearchLoading, pendingAddProduct, replacingLineIndex, replacementSearch, replacementProducts, replacementSearchOpen, replacementSearchActiveIndex, replacementSearchLoading, customer, priceInput, note, loading, saving, money, variantLabel, statusMeta, statusOptions, nextStatus,
            governorates, governorateQuery, governorateOpen, governorateActiveIndex, selectedGovernorate, filteredGovernorates, selectGovernorate, selectExactGovernorate,
            backToOrders: { path: '/orders', query: route.query }, chooseProduct, chooseAddProductVariant, closeAddProductVariantPicker, chooseReplacementProduct, closeProductSearch, closeReplacementSearch, handleProductSearchKeydown, replaceLine, removeLine, saveItems, saveTotal,
            saveCustomer: () => detail.value && run(`orders/${detail.value.order.public_reference}`, 'PATCH', { lock_version: detail.value.order.lock_version, customer: customer.value }, detail.value.navex.manual_update_required ? 'Informations mises à jour. Mettez maintenant le colis à jour dans Navex.' : 'Informations de livraison mises à jour.'),
            transition: async () => {
                if (!detail.value || !nextStatus.value) return;
                const status = nextStatus.value;
                const destructive = status === 'annulee';
                const navexMessage = status === 'confirmee' ? 'Si Navex est configuré en mode automatique, un seul colis sera mis en file. Un colis existant ne sera jamais dupliqué.' : '';
                const confirmed = await confirmAction('Changer le statut de la commande ?', `La commande passera à l’état « ${statusMeta(status).label} ». ${navexMessage}`, 'Confirmer', destructive ? 'danger' : 'default');
                if (confirmed) {
                    const updated = await run(`orders/${detail.value.order.public_reference}/transitions`, 'POST', { to_status: status, lock_version: detail.value.order.lock_version, reason: destructive ? 'Décision opérateur' : null }, 'Statut de la commande mis à jour.');
                    if (updated && status === 'confirmee') scheduleNavexRefresh();
                }
            },
            addNote: async () => { if (!detail.value || !note.value.trim()) return; const saved = await run(`orders/${detail.value.order.public_reference}/notes`, 'POST', { body: note.value.trim() }, 'Note ajoutée.'); if (saved) note.value = ''; },
            sendNavex: async () => { if (!detail.value || !await confirmAction('Envoyer ce colis à Navex ?', 'Les informations de livraison seront transmises à Navex pour créer un seul colis.', 'Envoyer')) return; if (await run(`orders/${detail.value.order.public_reference}/navex/send`, 'POST', { confirm_send: true }, 'Colis placé dans la file Navex.')) scheduleNavexRefresh(); },
            synchronizeNavex: async () => {
                if (!detail.value) return;
                const requested = await run(`orders/${detail.value.order.public_reference}/navex/synchronize`, 'POST', {}, 'Synchronisation Navex demandée.');
                if (!requested) return;
                scheduleNavexRefresh();
            },
            reconcileNavex: async () => {
                if (!detail.value) return;
                const requested = await run(`orders/${detail.value.order.public_reference}/navex/reconcile`, 'POST', {}, 'Vérification Navex demandée.');
                if (requested) scheduleNavexRefresh();
            },
            retryNavex: async () => { if (!detail.value || !await confirmAction('Relancer l’envoi Navex ?', 'Le même dossier local sera relancé. Aucun second colis local ne sera créé.', 'Relancer')) return; await run(`orders/${detail.value.order.public_reference}/navex/retry`, 'POST', { confirm_retry: true }, 'Le dossier Navex a été remis dans la file d’envoi.'); },
            cancelNavex: async () => { if (!detail.value || !await confirmAction('Annuler ce colis chez Navex ?', 'Navex doit confirmer l’annulation avant que la commande puisse être annulée localement.', 'Demander l’annulation', 'danger')) return; await run(`orders/${detail.value.order.public_reference}/navex/cancel`, 'POST', { confirm_cancellation: true }, 'Demande d’annulation Navex envoyée.'); },
        };
    },
    template: `<section class="admin-page order-detail-page">
      <RouterLink class="back-link order-back-link" :to="backToOrders">‹ <span>Retour à la liste des commandes</span></RouterLink>
      <p v-if="loading" class="admin-loading">Chargement de la commande…</p>
      <template v-else-if="detail">
        <header class="admin-page-header order-detail-header"><div><h1 :title="detail.order.public_reference">{{ detail.order.public_reference }}</h1></div></header>
        <section class="order-detail-strip" aria-label="Informations principales"><article><small>Statut</small><strong class="order-status" :class="'is-' + statusMeta(detail.order.status).tone">{{ statusMeta(detail.order.status).label }}</strong></article><article><small>Total de la commande</small><strong>{{ money(detail.order.total_millimes) }}</strong></article><article><small>Client</small><strong>{{ detail.order.customer_name }}</strong></article><article class="order-designation-strip"><small>Désignation de la commande</small><strong :title="detail.order.designation">{{ detail.order.designation }}</strong></article></section>
        <div class="order-detail-layout">
          <main class="order-detail-main">
            <section class="order-panel"><div class="order-panel-heading"><div><h2>Articles</h2><p>Ajoutez, retirez ou ajustez les articles avant de recalculer le total.</p></div></div>
              <form v-if="detail.is_editable" class="order-recalculate" @submit.prevent="saveItems">
                <div v-if="unavailableItemIds.length" class="order-editor-notice" role="note"><p>Un article historique n’est plus disponible dans le catalogue. Il reste conservé dans la commande.</p><label><input v-model="replaceUnavailableItems" type="checkbox"> Remplacer cet article historique par les produits ajoutés ci-dessous</label></div>
                <div v-for="(line, index) in lines" :key="line.product_public_id + (line.variant_public_id || '')" class="order-line-editor"><img v-if="line.image_url" class="order-line-thumb" :src="line.image_url" alt="" decoding="async"><span v-else class="order-item-fallback order-line-fallback" aria-hidden="true">P</span><strong>{{ line.label }}</strong><label>Quantité<input v-model.number="line.quantity" type="number" min="1" max="99" required></label><label v-if="line.variants.length">Variante<select v-model="line.variant_public_id" required><option v-for="variant in line.variants" :key="variant.public_id" :value="variant.public_id">{{ variantLabel(variant) }}</option></select></label><div class="order-line-actions"><button class="text-link order-line-replace" type="button" @click="replaceLine(index)">Changer</button><button v-if="lines.length > 1" class="text-link danger order-line-remove" type="button" @click="removeLine(index)">Retirer</button></div><section v-if="replacingLineIndex === index" class="order-inline-replacement" aria-label="Remplacer ce produit"><div><strong>Remplacer ce produit</strong><p>Le produit choisi remplacera cet article. Vous pouvez fermer cette recherche sans modifier la commande.</p></div><div class="order-product-search"><label :for="'order-replacement-search-' + index"><span>Rechercher un produit</span><input :id="'order-replacement-search-' + index" v-model.trim="replacementSearch" role="combobox" aria-autocomplete="list" :aria-expanded="replacementSearchOpen && !!replacementSearch" :aria-controls="'order-replacement-options-' + index" :aria-activedescendant="replacementSearchActiveIndex >= 0 ? 'order-replacement-option-' + replacementSearchActiveIndex : undefined" autocomplete="off" placeholder="Commencez à saisir un nom…" @focus="replacementSearchOpen = true" @keydown="handleProductSearchKeydown($event, 'replacement')"></label><div v-if="replacementSearchOpen && replacementSearch" :id="'order-replacement-options-' + index" class="order-product-search-options" role="listbox" aria-label="Produits de remplacement"><p v-if="replacementSearchLoading" class="order-product-search-empty">Recherche des produits…</p><template v-else><button v-for="(product, productIndex) in replacementProducts" :id="'order-replacement-option-' + productIndex" :key="product.public_id" type="button" role="option" :aria-selected="productIndex === replacementSearchActiveIndex" :class="{ 'is-active': productIndex === replacementSearchActiveIndex }" @mousedown.prevent @click="chooseReplacementProduct(product)"><strong>{{ product.name }}</strong><small v-if="product.has_variants">{{ product.variants.length }} variantes disponibles</small><small v-else>Produit simple</small></button><p v-if="!replacementProducts.length" class="order-product-search-empty">Aucun produit actif ne correspond à cette recherche.</p></template></div></div><button class="text-link" type="button" @click="closeReplacementSearch">Fermer</button></section></div>
                <section class="order-add-product"><div><strong>Ajouter un produit</strong><p>Recherchez puis choisissez le produit à ajouter. Les prix, promotions et stocks sont vérifiés par le serveur au recalcul.</p></div><div class="order-product-search"><label for="order-product-search"><span>Rechercher un produit</span><input id="order-product-search" ref="productSearchInput" v-model.trim="productSearch" role="combobox" aria-autocomplete="list" :aria-expanded="productSearchOpen && !!productSearch" aria-controls="order-product-search-options" :aria-activedescendant="productSearchActiveIndex >= 0 ? 'order-product-option-' + productSearchActiveIndex : undefined" autocomplete="off" placeholder="Commencez à saisir un nom…" @focus="productSearchOpen = true" @blur="closeProductSearch" @keydown="handleProductSearchKeydown($event, 'add')"></label><div v-if="productSearchOpen && productSearch" id="order-product-search-options" class="order-product-search-options" role="listbox" aria-label="Produits disponibles"><p v-if="productSearchLoading" class="order-product-search-empty">Recherche des produits…</p><template v-else><button v-for="(product, index) in products" :id="'order-product-option-' + index" :key="product.public_id" type="button" role="option" :aria-selected="index === productSearchActiveIndex" :class="{ 'is-active': index === productSearchActiveIndex }" @mousedown.prevent @click="chooseProduct(product)"><strong>{{ product.name }}</strong><small v-if="product.has_variants">{{ product.variants.length }} variantes disponibles</small><small v-else>Produit simple</small></button><p v-if="!products.length" class="order-product-search-empty">Aucun produit actif ne correspond à cette recherche.</p></template></div></div></section>
                <section v-if="pendingAddProduct" class="order-add-variant-picker" :aria-label="'Choisir une variante de ' + pendingAddProduct.name"><div><strong>Choisir une variante</strong><p>{{ pendingAddProduct.name }}</p></div><div class="order-add-variant-options"><button v-for="variant in pendingAddProduct.variants" :key="variant.public_id" type="button" :disabled="(variant.stock_quantity || 0) < 1" @click="chooseAddProductVariant(variant)">{{ variantLabel(variant) }}<small v-if="(variant.stock_quantity || 0) < 1">Rupture de stock</small></button></div><button class="text-link" type="button" @click="closeAddProductVariantPicker">Choisir un autre produit</button></section>
                <button class="admin-outline" :disabled="saving">↻ <span>Recalculer les articles</span></button>
              </form>
              <p v-else class="order-readonly">Les articles ne peuvent plus être modifiés lorsque la commande est annulée.</p>
              <div class="order-items-table"><div class="order-items-head"><span>Article</span><span>Qté</span><span>Total</span><span>Statut</span></div><article v-for="item in detail.order.items" :key="item.product_name_snapshot + item.quantity"><img v-if="item.product?.images?.[0]?.public_url" class="order-item-image" :src="item.product.images[0].public_url" alt="" decoding="async"><span v-else class="order-item-fallback" aria-hidden="true">P</span><div><strong>{{ item.product_name_snapshot }}</strong><small v-if="item.variant">{{ variantLabel(item.variant) }}</small></div><span>× {{ item.quantity }}</span><strong>{{ money(item.line_total_millimes) }}</strong><div class="item-actions"><span class="order-status" :class="'is-' + statusMeta(detail.order.status).tone">{{ statusMeta(detail.order.status).label }}</span></div></article></div>
              <section class="order-transition-panel" aria-label="Mise à jour du statut"><div><h3>Mettre à jour le statut</h3><p>Choisissez le statut opérationnel qui reflète la situation réelle. Les changements restent tracés dans l’historique.</p><p class="order-status-path" role="note"><strong>Parcours habituel</strong><span>Nouvelle → Tentative 1 → Tentative 2 → Tentative 3 → Confirmée</span><small>Vous pouvez choisir directement une autre étape. Une annulation rétablit le stock ; sa réactivation le réserve de nouveau si le stock est disponible.</small></p></div><form class="order-status-update" @submit.prevent="transition"><label><span>Nouveau statut</span><SelectControl v-model="nextStatus" :options="statusOptions" :disabled="saving || !detail.allowed_transitions.length"/></label><button class="admin-action" type="submit" :disabled="saving || !nextStatus">Appliquer le statut</button></form></section>
            </section>
            <section class="order-panel"><div class="order-panel-heading"><div><h2>Livraison</h2><p>Coordonnées utilisées pour la livraison de cette commande.</p></div></div><form v-if="detail.is_delivery_editable" class="delivery-form" @submit.prevent="saveCustomer"><label>Nom complet<input v-model.trim="customer.full_name" required></label><label>Téléphone<input v-model.trim="customer.phone" required></label><label>Ville<input v-model.trim="customer.city" required></label><div class="admin-combobox" @keydown.esc="governorateOpen = false"><label>Gouvernorat<input v-model="governorateQuery" role="combobox" aria-autocomplete="list" :aria-expanded="governorateOpen" aria-controls="admin-governorate-options" autocomplete="off" aria-required="true" @focus="governorateOpen = true" @keydown.down.prevent="governorateActiveIndex = Math.min(governorateActiveIndex + 1, filteredGovernorates.length - 1)" @keydown.up.prevent="governorateActiveIndex = Math.max(governorateActiveIndex - 1, 0)" @keydown.enter.prevent="selectExactGovernorate"></label><div v-if="governorateOpen && filteredGovernorates.length" id="admin-governorate-options" class="admin-combobox-options" role="listbox"><button v-for="(option, index) in filteredGovernorates" :key="option" type="button" role="option" :aria-selected="selectedGovernorate === option" :class="{ 'is-active': governorateActiveIndex === index }" @mousedown.prevent @click="selectGovernorate(option)">{{ option }}</button></div><small v-if="customer.governorate === '' && governorateQuery !== ''">Veuillez sélectionner un gouvernorat dans la liste.</small></div><label class="full">Adresse<textarea v-model="customer.address" required></textarea></label><button class="admin-action" :disabled="saving || !selectedGovernorate">Mettre à jour</button></form><dl class="order-print-delivery"><div><dt>Nom complet</dt><dd>{{ detail.order.customer_name }}</dd></div><div><dt>Téléphone</dt><dd>{{ detail.order.customer_phone }}</dd></div><div><dt>Ville</dt><dd>{{ detail.order.customer_city }}</dd></div><div><dt>Gouvernorat</dt><dd>{{ detail.order.customer_governorate || '—' }}</dd></div><div><dt>Adresse</dt><dd>{{ detail.order.customer_address }}</dd></div></dl></section>
            <section class="order-panel"><div class="order-panel-heading"><div><h2>Notes internes</h2><p>Ajoutez un contexte réservé à l’équipe, il ne sera jamais visible par le client.</p></div></div><form class="notes-form" @submit.prevent="addNote"><label class="sr-only" for="order-note">Nouvelle note interne</label><textarea id="order-note" v-model="note" placeholder="Ex. client contacté, créneau de livraison confirmé…" required></textarea><button class="admin-action" :disabled="saving || !note.trim()">Ajouter la note</button></form><ol v-if="detail.order.notes.length" class="order-notes"><li v-for="entry in detail.order.notes" :key="entry.created_at + entry.body"><p>{{ entry.body }}</p><time v-if="entry.created_at">{{ new Date(entry.created_at).toLocaleString('fr-TN') }}</time></li></ol></section>
          </main>
          <aside class="order-detail-side"><section class="order-panel navex-order-panel"><div class="order-panel-heading"><div><h2>{{ detail.navex.shipment ? detail.navex.shipment.status_label : 'Aucun colis Navex' }}</h2><p v-if="detail.navex.shipment?.tracking_code">Suivi : <code>{{ detail.navex.shipment.tracking_code }}</code></p><p v-else-if="detail.navex.ready.ready">Commande prête pour Navex en mode {{ detail.navex.ready.mode === 'automatic' ? 'automatique' : 'manuel' }}.</p><p v-else>{{ detail.navex.ready.reasons.join(' ') }}</p></div></div><dl v-if="detail.navex.shipment" class="navex-order-facts"><div><dt>État Navex</dt><dd><span class="navex-status" :class="'is-' + detail.navex.shipment.status">{{ detail.navex.shipment.status_label }}</span></dd></div><div><dt>Synchronisation</dt><dd>{{ detail.navex.shipment.last_synchronized_at ? new Date(detail.navex.shipment.last_synchronized_at).toLocaleString('fr-TN') : 'Pas encore synchronisée' }}</dd></div></dl><p v-if="detail.navex.shipment?.tracking_code" class="navex-cancellation-help" role="note"><strong>Pourquoi synchroniser ?</strong><span>Cette action interroge Navex pour actualiser le statut du colis. Elle ne crée pas et ne renvoie pas de second colis.</span></p><div class="navex-order-actions"><button v-if="!detail.navex.shipment && detail.navex.ready.ready && detail.navex.ready.mode === 'manual'" class="admin-action" type="button" :disabled="saving" @click="sendNavex">Envoyer à Navex</button><button v-if="detail.navex.shipment?.tracking_code" class="admin-outline" type="button" :disabled="saving" @click="synchronizeNavex">Synchroniser</button><button v-if="detail.navex.shipment?.status === 'resultat_incertain'" class="admin-outline" type="button" :disabled="saving" @click="reconcileNavex">Vérifier avant nouvel envoi</button><button v-if="detail.navex.shipment?.status === 'erreur_synchronisation'" class="admin-action" type="button" :disabled="saving" @click="retryNavex">Réessayer l’envoi</button><button v-if="detail.navex.shipment?.tracking_code && !['livree_payee', 'retournee', 'annulee_navex'].includes(detail.navex.shipment.status)" class="text-link danger" type="button" :disabled="saving" @click="cancelNavex">Annuler le colis Navex</button></div></section><section class="order-panel order-facts"><h2>Résumé de la commande</h2><dl><div><dt>Statut</dt><dd><span class="order-status" :class="'is-' + statusMeta(detail.order.status).tone">{{ statusMeta(detail.order.status).label }}</span></dd></div><div><dt>Nombre d’articles</dt><dd>{{ detail.order.items.length }}</dd></div><div><dt>Total de la commande</dt><dd>{{ money(detail.order.total_millimes) }}</dd></div><div><dt>Date de commande</dt><dd>{{ detail.order.created_at ? new Date(detail.order.created_at).toLocaleString('fr-TN') : '—' }}</dd></div><div><dt>Référence commande</dt><dd class="reference-value">{{ detail.order.public_reference }}</dd></div></dl></section><section class="order-panel order-history"><h2>Historique de la commande</h2><ol v-if="detail.order.status_history.length"><li v-for="(event, index) in detail.order.status_history" :key="index" :class="'is-' + statusMeta(event.to_status).tone"><i aria-hidden="true"></i><div><strong>{{ statusMeta(event.to_status).label }}</strong><p>{{ event.reason || 'Statut de la commande mis à jour.' }}</p></div><time v-if="event.created_at">{{ new Date(event.created_at).toLocaleString('fr-TN') }}</time></li></ol><p v-else class="order-history-empty">Aucun historique n’est encore disponible.</p></section></aside>
        </div>
      </template>
    </section>`,
};

OrderDetailView.template = (OrderDetailView.template as string).replace(
    '<dt>Synchronisation</dt><dd>{{ detail.navex.shipment.last_synchronized_at ? new Date(detail.navex.shipment.last_synchronized_at).toLocaleString(\'fr-TN\') : \'Pas encore synchronisée\' }}</dd>',
    '<dt>Synchronisation</dt><dd>{{ detail.navex.shipment.last_synchronized_at ? new Date(detail.navex.shipment.last_synchronized_at).toLocaleString(\'fr-TN\') : (!detail.navex.shipment.tracking_code && detail.navex.shipment.status === \'acceptee_navex\' ? \'Code Navex en cours de récupération\' : \'Pas encore synchronisée\') }}</dd>',
).replace(
    'v-if="detail.navex.shipment?.tracking_code" class="navex-cancellation-help"',
    'v-if="detail.navex.shipment && (detail.navex.shipment.tracking_code || detail.navex.shipment.status === \'acceptee_navex\')" class="navex-cancellation-help"',
).replace(
    'Cette action interroge Navex pour actualiser le statut du colis. Elle ne crée pas et ne renvoie pas de second colis.',
    'Cette action interroge Navex pour actualiser le statut du colis ou récupérer son code de suivi. Elle ne crée et ne renvoie jamais un second colis.',
).replace(
    'v-if="detail.navex.shipment?.tracking_code" class="admin-outline" type="button" :disabled="saving" @click="synchronizeNavex">Synchroniser</button>',
    'v-if="detail.navex.shipment && (detail.navex.shipment.tracking_code || detail.navex.shipment.status === \'acceptee_navex\')" class="admin-outline" type="button" :disabled="saving" @click="synchronizeNavex">Synchroniser</button>',
).replace(
    '<dl class="order-print-delivery">',
    '<dl class="order-print-delivery" :class="{ \'order-delivery-readonly\': !detail.is_delivery_editable }">',
).replace(
    '<div class="navex-order-actions">',
    '<p v-if="detail.navex.shipment?.status === \'action_manuelle_requise\' && detail.navex.shipment.raw_status === null" class="navex-manual-help" role="note"><strong>Intervention Navex nécessaire</strong><span>Navex n’a pas permis d’identifier ce colis de manière certaine. Vérifiez d’abord la commande dans Navex ; si elle n’y existe pas, créez-la manuellement. Ne relancez pas l’envoi automatique.</span></p><div class="navex-order-actions">',
);

OrderDetailView.template = (OrderDetailView.template as string).replace(
    'v-if="detail.navex.shipment?.tracking_code && ![\'livree_payee\', \'retournee\', \'annulee_navex\'].includes(detail.navex.shipment.status)" class="text-link danger" type="button" :disabled="saving" @click="cancelNavex">Annuler le colis Navex</button>',
    'v-if="detail.navex.shipment?.raw_status?.trim().toLocaleLowerCase(\'fr\') === \'en attente\'" class="text-link danger" type="button" :disabled="saving" @click="cancelNavex">Annuler le colis Navex</button>',
);

OrderDetailView.template = (OrderDetailView.template as string).replace(
    '<label class="full">Adresse<textarea v-model="customer.address" required></textarea></label><button class="admin-action"',
    '<label class="full">Adresse<textarea v-model="customer.address" required></textarea></label><label>Échange<SelectControl v-model="customer.is_exchange" :options="[{ value: \'Non\', label: \'Non\' }, { value: \'Oui\', label: \'Oui\' }]" /></label><label v-if="customer.is_exchange === \'Oui\'" class="full">Désignation des articles à échanger<input v-model.trim="customer.exchange_article_designation" required maxlength="500"></label><label v-if="customer.is_exchange === \'Oui\'">Nombre des articles à échanger<input v-model.number="customer.exchange_article_count" type="number" min="1" step="1" required></label><button class="admin-action"',
);

OrderDetailView.template = (OrderDetailView.template as string).replace(
    '<div><dt>Adresse</dt><dd>{{ detail.order.customer_address }}</dd></div></dl>',
    '<div><dt>Adresse</dt><dd>{{ detail.order.customer_address }}</dd></div><div><dt>Échange</dt><dd>{{ detail.order.is_exchange ? \'Oui\' : \'Non\' }}<span v-if="detail.order.is_exchange && detail.order.exchange_article_designation"> · {{ detail.order.exchange_article_designation }} ({{ detail.order.exchange_article_count }})</span></dd></div></dl>',
);

OrderDetailView.template = (OrderDetailView.template as string).replace(
    '<div><dt>Total de la commande</dt><dd>{{ money(detail.order.total_millimes) }}</dd></div>',
    '<div><dt>Total de la commande</dt><dd>{{ money(detail.order.total_millimes) }}</dd></div><form class="order-total-editor" @submit.prevent="saveTotal()"><label for="order-total-input"><span>Total facturé</span><span class="price-input"><input id="order-total-input" v-model="priceInput" inputmode="decimal" autocomplete="off" aria-describedby="order-total-help" required><em>DT</em></span></label><small id="order-total-help">{{ detail.order.manual_total_millimes !== null ? \'Montant personnalisé enregistré.\' : \'Montant calculé à partir des articles et de la livraison.\' }}</small><small v-if="detail.navex.shipment" class="order-total-editor-note">Une modification locale n\'altère pas le colis déjà transmis à Navex.</small><div class="order-total-editor-actions"><button class="admin-action" type="submit" :disabled="saving">{{ saving ? \'Enregistrement…\' : \'Enregistrer le total\' }}</button><button v-if="detail.order.manual_total_millimes !== null" class="text-link" type="button" :disabled="saving" @click="saveTotal(true)">Revenir au calcul</button></div></form>',
);

OrderDetailView.template = (OrderDetailView.template as string).replace(
    '</p></div><time v-if="event.created_at">',
    '</p><small class="order-history-actor">{{ event.changed_by?.name || (event.from_status === null ? \'Client\' : \'Système\') }}</small></div><time v-if="event.created_at">',
);

OrderDetailView.template = (OrderDetailView.template as string).replace(
    '<form v-if="detail.is_delivery_editable"',
    '<div class="customer-history-notice" :class="detail.order.customer_previous_order_at ? \'is-returning\' : \'is-new\'"><strong>{{ detail.order.customer_previous_order_at ? \'Client existant\' : \'Nouveau client\' }}</strong><span v-if="detail.order.customer_previous_order_at">Commande précédente : {{ new Date(detail.order.customer_previous_order_at).toLocaleDateString(\'fr-TN\') }}</span><span v-else>Aucune commande précédente.</span></div><form v-if="detail.is_delivery_editable"',
);

export default OrderDetailView;
