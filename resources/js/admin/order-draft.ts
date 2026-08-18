import { computed, onMounted, ref, watch, type Component } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { adminApi } from './api';
import { confirmAction, showError, showToast } from './feedback';
import SelectControl from './select-control';

type Variant = { public_id: string; sku: string | null; stock_quantity: number; is_active: boolean; is_default?: boolean; values?: { value: string }[] };
type Product = { public_id: string; name: string; has_variants: boolean; stock_quantity: number; variants: Variant[] };
type Line = { product_public_id: string; variant_public_id: string | null; quantity: number; name: string; variant_label: string | null; image_url?: string | null; product?: Product; variant?: Variant | null };
type Draft = { public_token: string; customer_data: Record<string, string | null>; cart_snapshot: Array<{ product_public_id: string; variant_public_id?: string | null; name?: string; variant_label?: string | null; image_url?: string | null; quantity: number; effective_price_millimes?: number }>; last_activity_at: string; checkout_data?: Record<string, string | null>; attribution_snapshot?: Record<string, unknown> | null; converted_at?: string | null };
type CheckoutField = { key: string; options?: Array<string | { label?: string; value?: string }> };
const idempotencyKey = () => typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
const variantLabel = (variant?: Variant | null) => variant?.sku || variant?.values?.map((value) => value.value).join(' · ') || 'Variante';
const money = (value: number) => `${(value / 1000).toFixed(3).replace('.', ',')} DT`;

const api = <T>(path: string, method = 'GET', body?: unknown) => adminApi<T>(path, method, body);

const OrderDraftView: Component = {
    components: { RouterLink, SelectControl },
    setup() {
        const route = useRoute();
        const router = useRouter();
        const draft = ref<Draft | null>(null);
        const lines = ref<Line[]>([]);
        const customer = ref<Record<string, string>>({ full_name: '', phone: '', city: '', governorate: '', address: '' });
        const governorates = ref<string[]>([]);
        const governorateQuery = ref('');
        const selectedGovernorate = ref('');
        const governorateOpen = ref(false);
        const governorateIndex = ref(-1);
        const status = ref('nouvelle');
        const products = ref<Product[]>([]);
        const search = ref('');
        const searchOpen = ref(false);
        const pendingProduct = ref<Product | null>(null);
        const loading = ref(true);
        const saving = ref(false);
        const deleting = ref(false);
        const filteredGovernorates = computed(() => {
            const query = governorateQuery.value.trim().toLocaleLowerCase('fr');
            return governorates.value.filter((governorate) => !query || governorate.toLocaleLowerCase('fr').includes(query));
        });
        const totalItems = computed(() => lines.value.reduce((sum, line) => sum + Math.max(1, Number(line.quantity) || 1), 0));
        const loadProducts = async () => {
            if (!search.value.trim()) { products.value = []; return; }
            try { products.value = (await api<{ data: Product[] }>(`orders/available-products?search=${encodeURIComponent(search.value.trim())}`)).data; } catch (cause) { showError(cause instanceof Error ? cause.message : 'Impossible de rechercher les produits.'); }
        };
        let searchTimer: number | undefined;
        const onSearch = () => { window.clearTimeout(searchTimer); searchTimer = window.setTimeout(() => void loadProducts(), 220); };
        const chooseProduct = (product: Product) => {
            if (product.has_variants) { pendingProduct.value = product; searchOpen.value = false; return; }
            addLine(product, null);
        };
        const addLine = (product: Product, variant: Variant | null) => {
            const key = `${product.public_id}|${variant?.public_id || ''}`;
            if (lines.value.some((line) => `${line.product_public_id}|${line.variant_public_id || ''}` === key)) { showError('Cet article est déjà présent dans la commande.'); return; }
            lines.value.push({ product_public_id: product.public_id, variant_public_id: variant?.public_id || null, quantity: 1, name: product.name, variant_label: variant ? variantLabel(variant) : null, product, variant });
            pendingProduct.value = null; search.value = ''; products.value = []; searchOpen.value = false;
        };
        const removeLine = (index: number) => { if (lines.value.length > 1) lines.value.splice(index, 1); else showError('Une commande doit conserver au moins un article.'); };
        const chooseGovernorate = (value: string) => { selectedGovernorate.value = value; customer.value.governorate = value; governorateQuery.value = value; governorateOpen.value = false; };
        watch(governorateQuery, (value) => { governorateIndex.value = -1; if (value !== selectedGovernorate.value) { selectedGovernorate.value = ''; customer.value.governorate = ''; } });
        const governorateKeydown = (event: KeyboardEvent) => {
            if (event.key === 'ArrowDown') { event.preventDefault(); governorateOpen.value = true; governorateIndex.value = Math.min(governorateIndex.value + 1, filteredGovernorates.value.length - 1); }
            if (event.key === 'ArrowUp') { event.preventDefault(); governorateOpen.value = true; governorateIndex.value = Math.max(governorateIndex.value - 1, 0); }
            if (event.key === 'Escape') governorateOpen.value = false;
            if (event.key === 'Enter') {
                const exact = filteredGovernorates.value.find((item) => item.toLocaleLowerCase('fr') === governorateQuery.value.trim().toLocaleLowerCase('fr'));
                const active = filteredGovernorates.value[governorateIndex.value];
                if (exact || active) { event.preventDefault(); chooseGovernorate(exact || active); }
            }
        };
        const optionValue = (option: string | { label?: string; value?: string }) => typeof option === 'string' ? option : option.value || option.label || '';
        const deleteDraft = async () => {
            if (!draft.value || deleting.value) return;
            const confirmed = await confirmAction('Supprimer ce panier abandonné ?', 'Le brouillon et ses informations seront définitivement supprimés. Aucun stock ni commande ne sera modifié.', 'Supprimer', 'danger');
            if (!confirmed) return;
            deleting.value = true;
            try {
                await api(`checkout-drafts/${draft.value.public_token}`, 'DELETE');
                showToast('success', 'Le panier abandonné a été supprimé.');
                await router.push({ path: '/orders', query: route.query });
            } catch (cause) { showError(cause instanceof Error ? cause.message : 'Suppression du panier impossible.'); } finally { deleting.value = false; }
        };
        const submit = async () => {
            if (!draft.value || !lines.value.length) return;
            if (!selectedGovernorate.value) { showError('Veuillez sélectionner un gouvernorat dans la liste.'); return; }
            saving.value = true;
            try {
                const response = await api<{ data: { order: { public_reference: string } } }>(`checkout-drafts/${draft.value.public_token}/convert`, 'POST', { idempotency_key: idempotencyKey(), status: status.value, customer: customer.value, items: lines.value.map((line) => ({ product_public_id: line.product_public_id, variant_public_id: line.variant_public_id, quantity: Math.max(1, Number(line.quantity) || 1) })) });
                showToast('success', 'Le panier abandonné a été converti en commande.');
                await router.push({ path: `/orders/${response.data.order.public_reference}`, query: route.query });
            } catch (cause) { showError(cause instanceof Error ? cause.message : 'Conversion du panier impossible.'); } finally { saving.value = false; }
        };
        onMounted(async () => {
            try {
                const [draftResponse, fieldsResponse] = await Promise.all([
                    api<{ data: Draft }>(`checkout-drafts/${route.params.token}`),
                    api<{ data: CheckoutField[] }>('/api/v1/public/checkout-fields'),
                ]);
                draft.value = draftResponse.data;
                governorates.value = fieldsResponse.data.find((field) => field.key === 'governorate')?.options?.map(optionValue) || [];
                customer.value = Object.assign(customer.value, draftResponse.data.customer_data, draftResponse.data.checkout_data || {});
                const savedGovernorate = customer.value.governorate || '';
                governorateQuery.value = savedGovernorate;
                selectedGovernorate.value = governorates.value.includes(savedGovernorate) ? savedGovernorate : '';
                lines.value = draftResponse.data.cart_snapshot.map((item) => ({ product_public_id: item.product_public_id, variant_public_id: item.variant_public_id || null, quantity: item.quantity, name: item.name || 'Produit', variant_label: item.variant_label || null, image_url: item.image_url || null }));
            } catch (cause) { showError(cause instanceof Error ? cause.message : 'Ce panier abandonné est indisponible.'); } finally { loading.value = false; }
        });
        return { route, draft, lines, customer, status, products, search, searchOpen, pendingProduct, loading, saving, deleting, totalItems, governorateQuery, governorateOpen, governorateIndex, filteredGovernorates, governorateKeydown, chooseGovernorate, onSearch, chooseProduct, addLine, removeLine, variantLabel, money, submit, deleteDraft };
    },
    template: `<section class="admin-page order-detail-page order-draft-page">
      <RouterLink class="back-link" :to="{ path: '/orders', query: route.query }">‹ <span>Retour à la liste des commandes</span></RouterLink>
      <p v-if="loading" class="admin-loading">Chargement du panier abandonné…</p>
      <template v-else-if="draft">
        <header class="admin-page-header order-detail-header"><div><p class="admin-eyebrow">Récupération</p><h1>Panier abandonné</h1><p class="admin-subtitle">Dernière activité : {{ new Date(draft.last_activity_at).toLocaleString('fr-TN') }}. Vérifiez les informations avant de créer la commande.</p></div><button class="text-link danger draft-delete-action" type="button" :disabled="deleting || saving" @click="deleteDraft">Supprimer ce panier abandonné</button></header>
        <form class="manual-order-detail-layout" @submit.prevent="submit"><main class="order-detail-main">
          <section class="order-panel"><div class="order-panel-heading"><div><h2>Articles</h2><p>Les prix et le stock seront recalculés par le serveur lors de la conversion.</p></div></div><div class="manual-order-lines"><article v-for="(line, index) in lines" :key="line.product_public_id + (line.variant_public_id || '')" class="manual-order-line"><div class="order-item-fallback" aria-hidden="true">P</div><div class="manual-order-item"><strong>{{ line.name }}</strong><small v-if="line.variant_label">{{ line.variant_label }}</small></div><label><span>Quantité</span><input v-model.number="line.quantity" type="number" min="1" max="99" required></label><button class="text-link danger" type="button" :disabled="lines.length === 1" @click="removeLine(index)">Retirer</button></article></div><div class="order-add-product"><div><strong>Ajouter un produit</strong><p>Recherchez un article ou une variante à ajouter.</p></div><label class="order-product-search"><span class="sr-only">Rechercher un produit</span><input v-model.trim="search" autocomplete="off" placeholder="Nom du produit" @focus="searchOpen = true" @input="onSearch"><div v-if="searchOpen && search" class="order-product-search-options"><button v-for="product in products" :key="product.public_id" type="button" @mousedown.prevent="chooseProduct(product)"><strong>{{ product.name }}</strong><small>{{ product.has_variants ? 'Choisir une variante' : 'Produit simple' }}</small></button><p v-if="!products.length" class="order-product-search-empty">Aucun produit actif ne correspond à cette recherche.</p></div></label></div><section v-if="pendingProduct" class="order-add-variant-picker"><strong>{{ pendingProduct.name }}</strong><div class="order-add-variant-options"><button v-for="variant in pendingProduct.variants" :key="variant.public_id" type="button" :disabled="!variant.is_active || variant.stock_quantity < 1" @click="addLine(pendingProduct, variant)">{{ variantLabel(variant) }}<small>{{ variant.stock_quantity }} en stock</small></button></div><button class="text-link" type="button" @click="pendingProduct = null">Fermer</button></section></section>
          <section class="order-panel"><div class="order-panel-heading"><div><h2>Livraison</h2><p>Coordonnées récupérées du panier, modifiables avant conversion.</p></div></div><div class="delivery-form manual-delivery-form"><label>Nom complet<input v-model.trim="customer.full_name" required></label><label>Téléphone<input v-model.trim="customer.phone" required inputmode="tel"></label><label>Ville<input v-model.trim="customer.city" required></label><div class="admin-combobox"><label>Gouvernorat<input v-model="governorateQuery" role="combobox" aria-autocomplete="list" :aria-expanded="governorateOpen" aria-controls="admin-governorate-options" autocomplete="off" required @focus="governorateOpen = true" @keydown="governorateKeydown"></label><div v-if="governorateOpen" id="admin-governorate-options" class="admin-combobox-options" role="listbox"><button v-for="(governorate, index) in filteredGovernorates" :key="governorate" type="button" :class="{ 'is-active': governorateIndex === index }" role="option" :aria-selected="selectedGovernorate === governorate" @mousedown.prevent="chooseGovernorate(governorate)">{{ governorate }}</button></div><small v-if="customer.governorate === '' && governorateQuery !== ''">Veuillez sélectionner un gouvernorat dans la liste.</small></div><label class="full">Adresse<textarea v-model.trim="customer.address" required></textarea></label></div></section>
        </main><aside class="order-detail-side"><section class="order-panel manual-order-summary"><div class="order-panel-heading"><div><h2>Créer la commande</h2><p>Une seule conversion sera autorisée pour ce brouillon.</p></div></div><label>Statut initial<SelectControl v-model="status" :options="[{ value: 'nouvelle', label: 'Nouvelle' }, { value: 'tentative_1', label: 'Tentative 1' }, { value: 'tentative_2', label: 'Tentative 2' }, { value: 'tentative_3', label: 'Tentative 3' }, { value: 'confirmee', label: 'Confirmée' }]"/></label><dl><div><dt>Articles</dt><dd>{{ totalItems }}</dd></div><div><dt>Meta / Navex</dt><dd>Selon le statut choisi</dd></div></dl><button class="admin-action" :disabled="saving || !lines.length">{{ saving ? 'Création…' : 'Créer la commande' }}</button></section></aside></form>
      </template>
    </section>`,
};

OrderDraftView.template = (OrderDraftView.template as string).replace(
    '<div class="order-item-fallback" aria-hidden="true">P</div><div class="manual-order-item">',
    '<img v-if="line.image_url" class="order-item-image" :src="line.image_url" alt="" decoding="async"><div v-else class="order-item-fallback" aria-hidden="true">P</div><div class="manual-order-item">',
);

export default OrderDraftView;
