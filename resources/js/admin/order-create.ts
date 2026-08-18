import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch, type Component } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { showError, showToast } from './feedback';
import SelectControl from './select-control';
import { dinarsToMillimes } from './api';

type Variant = { public_id: string; sku: string | null; stock_quantity: number; is_active: boolean; is_default?: boolean; values?: { value: string }[] };
type Product = { public_id: string; name: string; has_variants: boolean; stock_quantity: number; variants: Variant[]; images?: { public_url?: string | null }[] };
type Line = { product_public_id: string; variant_public_id: string | null; quantity: number; product: Product; variant: Variant | null };
type CheckoutField = { key: string; label: string; type: string; is_required: boolean; options?: Array<string | { label?: string; value?: string }> };

const manualOrderExcludedFieldKeys = new Set([
    'delivery_note',
    'parcel_opening_option',
    'exchange',
    'echange',
    'article_designation',
    'article_count',
    'exchange_article_designation',
    'exchange_article_count',
]);

const isManualOrderExcludedField = (field: CheckoutField): boolean => {
    const normalizedLabel = field.label.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('fr');

    return manualOrderExcludedFieldKeys.has(field.key)
        || normalizedLabel.includes('indication de livraison')
        || normalizedLabel.includes('ouvrir le colis')
        || normalizedLabel === 'echange';
};

const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
const variantLabel = (variant: Variant) => variant.sku || variant.values?.map((value) => value.value).join(' · ') || 'Variante';
const fixedKeys = new Set(['full_name', 'phone', 'city', 'governorate', 'address']);
const idempotencyKey = () => {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
        const random = Math.floor(Math.random() * 16);
        return (character === 'x' ? random : ((random & 0x3) | 0x8)).toString(16);
    });
};

async function api<T>(path: string, method = 'GET', body?: unknown): Promise<T> {
    const response = await fetch(path.startsWith('/api/') ? path : `/api/v1/admin/${path}`, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', ...(method === 'GET' ? {} : { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }) },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    });
    const payload = await response.json().catch(() => null) as { message?: string; errors?: Record<string, string[]> } | null;
    if (! response.ok) throw new Error(payload?.errors ? Object.values(payload.errors).flat().join(' ') : payload?.message || 'Opération impossible.');

    return payload as T;
}

const ManualOrderCreateView: Component = {
    components: { RouterLink, SelectControl },
    setup() {
        const router = useRouter();
        const route = useRoute();
        const schemaVersion = ref('');
        const fields = ref<CheckoutField[]>([]);
        const governorates = ref<string[]>([]);
        const governorateQuery = ref('');
        const selectedGovernorate = ref('');
        const governorateOpen = ref(false);
        const governorateIndex = ref(-1);
        const customer = ref<Record<string, unknown>>({ full_name: '', phone: '', city: '', governorate: '', address: '' });
        const exchange = ref({ is_exchange: 'Non', article_designation: '', article_count: null as number | null });
        const status = ref('nouvelle');
        const manualTotalInput = ref('');
        const requestKey = ref(idempotencyKey());
        const lines = ref<Line[]>([]);
        const products = ref<Product[]>([]);
        const productSearch = ref('');
        const productSearchInput = ref<HTMLInputElement | null>(null);
        const productOpen = ref(false);
        const pendingProduct = ref<Product | null>(null);
        const saving = ref(false);
        const loading = ref(true);
        const customerLookup = ref<'idle' | 'loading' | 'found' | 'none' | 'error'>('idle');
        let lookupTimer: number | undefined;
        let lookupVersion = 0;
        let lastAutofill: Record<string, string> = {};
        let searchTimer: number | undefined;
        const extraFields = computed(() => fields.value.filter((field) => !fixedKeys.has(field.key) && !isManualOrderExcludedField(field)));
        const filteredGovernorates = computed(() => {
            const query = governorateQuery.value.trim().toLocaleLowerCase('fr');
            return governorates.value.filter((governorate) => !query || governorate.toLocaleLowerCase('fr').includes(query));
        });
        const totalItems = computed(() => lines.value.reduce((total, line) => total + Math.max(1, Number(line.quantity) || 1), 0));
        const backToOrders = { path: '/orders', query: route.query };
        const chooseGovernorate = (value: string) => {
            selectedGovernorate.value = value;
            customer.value.governorate = value;
            governorateQuery.value = value;
            governorateOpen.value = false;
        };
        watch(governorateQuery, (value) => {
            governorateIndex.value = -1;
            if (value !== selectedGovernorate.value) {
                selectedGovernorate.value = '';
                customer.value.governorate = '';
            }
        });
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
        const loadProducts = async (search: string) => {
            if (!search) { products.value = []; return; }
            const response = await api<{ data: Product[] }>(`orders/available-products?search=${encodeURIComponent(search)}`);
            if (productSearch.value.trim() === search) products.value = response.data;
        };
        watch(productSearch, (value) => {
            window.clearTimeout(searchTimer);
            products.value = [];
            if (!value.trim()) return;
            searchTimer = window.setTimeout(() => void loadProducts(value.trim()).catch((error: unknown) => showError(error instanceof Error ? error.message : 'Impossible de charger les produits.')), 220);
        });
        watch(() => customer.value.phone, (value) => {
            window.clearTimeout(lookupTimer);
            const phone = String(value ?? '').trim();
            const version = ++lookupVersion;
            if (phone.replace(/\D/g, '').length < 8) { customerLookup.value = 'idle'; return; }
            customerLookup.value = 'loading';
            lookupTimer = window.setTimeout(async () => {
                try {
                    const response = await api<{ data: Record<string, string | null> | null }>(`customers/lookup?phone=${encodeURIComponent(phone)}`);
                    if (version !== lookupVersion || String(customer.value.phone ?? '').trim() !== phone) return;
                    if (!response.data) { customerLookup.value = 'none'; return; }
                    (['full_name', 'governorate', 'city', 'address'] as const).forEach((key) => {
                        const current = String(customer.value[key] ?? '');
                        const next = String(response.data?.[key] ?? '');
                        if (!current || current === lastAutofill[key]) { customer.value[key] = next; lastAutofill[key] = next; }
                    });
                    if (response.data.governorate) chooseGovernorate(response.data.governorate);
                    customerLookup.value = 'found';
                } catch {
                    if (version === lookupVersion) customerLookup.value = 'error';
                }
            }, 500);
        });
        const addLine = (product: Product, variant: Variant | null = null) => {
            if (!product.has_variants && product.stock_quantity < 1) {
                showError('Ce produit est actuellement hors stock.');
                return;
            }
            const selected = product.has_variants ? variant : null;
            if (product.has_variants && !selected) {
                if (!product.variants.some((candidate) => candidate.is_active && candidate.stock_quantity > 0)) {
                    showError('Ce produit ne possède aucune variante active en stock.');
                    return;
                }
                pendingProduct.value = product;
                productOpen.value = false;
                return;
            }
            const key = `${product.public_id}|${selected?.public_id || ''}`;
            if (lines.value.some((line) => `${line.product_public_id}|${line.variant_public_id || ''}` === key)) {
                showError('Cette variante est déjà présente dans la commande.');
                return;
            }
            lines.value.push({ product_public_id: product.public_id, variant_public_id: selected?.public_id || null, quantity: 1, product, variant: selected });
            pendingProduct.value = null;
            productSearch.value = '';
            products.value = [];
            // The input may already have focus because product options use
            // mousedown.prevent. Keep its combobox state ready for the next
            // keystroke instead of waiting for a blur/focus cycle.
            productOpen.value = true;
            void nextTick(() => productSearchInput.value?.focus());
        };
        const removeLine = (index: number) => lines.value.splice(index, 1);
        const maxStock = (line: Line) => line.variant?.stock_quantity ?? line.product.stock_quantity;
        const clampQuantity = (line: Line) => line.quantity = Math.max(1, Math.min(maxStock(line), Number(line.quantity) || 1));
        const optionValue = (option: string | { label?: string; value?: string }) => typeof option === 'string' ? option : option.value || option.label || '';
        const optionLabel = (option: string | { label?: string; value?: string }) => typeof option === 'string' ? option : option.label || option.value || '';
        const submit = async () => {
            if (!lines.value.length) { showError('Ajoutez au moins un article à la commande.'); return; }
            if (!selectedGovernorate.value) { showError('Veuillez sélectionner un gouvernorat dans la liste.'); return; }
            const manualTotalMillimes = manualTotalInput.value.trim() === '' ? null : dinarsToMillimes(manualTotalInput.value);
            if (manualTotalInput.value.trim() !== '' && manualTotalMillimes === null) {
                showError('Le total personnalisé doit être un montant valide en dinars.');
                return;
            }
            saving.value = true;
            try {
                const response = await api<{ data: { order: { public_reference: string } } }>('orders', 'POST', {
                    checkout_schema_version: schemaVersion.value,
                    idempotency_key: requestKey.value,
                    customer: customer.value,
                    exchange: exchange.value,
                    manual_total_millimes: manualTotalMillimes,
                    items: lines.value.map((line) => ({ product_public_id: line.product_public_id, variant_public_id: line.variant_public_id, quantity: line.quantity })),
                    status: status.value,
                });
                showToast('success', 'Commande créée. Les prix, le stock et la livraison ont été validés par le serveur.');
                await router.push({ path: `/orders/${response.data.order.public_reference}`, query: route.query });
            } catch (error) {
                showError(error instanceof Error ? error.message : 'Création de la commande impossible.');
            } finally {
                saving.value = false;
            }
        };
        onMounted(async () => {
            try {
                const response = await api<{ data: CheckoutField[]; meta: { schema_version: string } }>('/api/v1/public/checkout-fields');
                fields.value = response.data;
                schemaVersion.value = response.meta.schema_version;
                governorates.value = response.data.find((field) => field.key === 'governorate')?.options?.map(optionValue) || [];
            } catch (error) {
                showError(error instanceof Error ? error.message : 'Impossible de préparer le formulaire.');
            } finally {
                loading.value = false;
            }
        });
        onBeforeUnmount(() => { window.clearTimeout(searchTimer); window.clearTimeout(lookupTimer); });
        return { backToOrders, customer, customerLookup, exchange, status, manualTotalInput, lines, products, productSearch, productSearchInput, productOpen, pendingProduct, saving, loading, extraFields, filteredGovernorates, governorateQuery, governorateOpen, governorateIndex, totalItems, chooseGovernorate, governorateKeydown, addLine, removeLine, maxStock, clampQuantity, optionValue, optionLabel, variantLabel, submit };
    },
    template: `<section class="admin-page order-detail-page manual-order-page">
      <RouterLink class="back-link" :to="backToOrders">‹ <span>Retour à la liste des commandes</span></RouterLink>
      <header class="admin-page-header order-detail-header"><div><p class="admin-eyebrow">Opérations</p><h1>Nouvelle commande</h1><p class="admin-subtitle">Ajoutez les articles et les coordonnées : le serveur vérifie le stock, les tarifs et la livraison avant la création.</p></div></header>
      <p v-if="loading" class="admin-loading">Préparation de la commande…</p>
      <form v-else class="manual-order-detail-layout" @submit.prevent="submit">
        <main class="order-detail-main">
          <section class="order-panel"><div class="order-panel-heading"><div><h2>Articles</h2><p>Ajoutez, retirez ou ajustez les quantités. Les prix et le stock restent contrôlés au moment de créer la commande.</p></div></div>
            <div class="manual-order-lines" :class="{ 'is-empty': !lines.length }"><p v-if="!lines.length" class="manual-order-empty">Aucun article pour le moment. Recherchez un produit ci-dessous.</p><article v-for="(line, index) in lines" :key="line.product_public_id + line.variant_public_id" class="manual-order-line"><div class="order-item-fallback" aria-hidden="true">P</div><div class="manual-order-item"><strong>{{ line.product.name }}</strong><small v-if="line.variant">{{ variantLabel(line.variant) }}</small><small v-else>Produit simple</small></div><label><span>Quantité</span><input v-model.number="line.quantity" type="number" min="1" :max="maxStock(line)" required @change="clampQuantity(line)"></label><button class="text-link danger" type="button" :aria-label="'Retirer ' + line.product.name" @click="removeLine(index)">Retirer</button></article></div>
            <div class="order-add-product"><div><strong>Ajouter un produit</strong><p>Recherchez par nom, puis choisissez la variante si le produit en possède.</p></div><label class="order-product-search"><span class="sr-only">Rechercher un produit</span><input ref="productSearchInput" v-model.trim="productSearch" placeholder="Nom du produit" autocomplete="off" @focus="productOpen = true"><div v-if="productOpen && productSearch" class="order-product-search-options"><button v-for="product in products" :key="product.public_id" type="button" @mousedown.prevent="addLine(product)"><strong>{{ product.name }}</strong><small>{{ product.has_variants ? 'Choisir une variante' : product.stock_quantity + ' en stock' }}</small></button><p v-if="!products.length" class="order-product-search-empty">Aucun produit disponible.</p></div></label></div>
            <div v-if="pendingProduct" class="order-add-variant-picker"><div><strong>{{ pendingProduct.name }}</strong><p>Choisissez la variante à ajouter.</p></div><div class="order-add-variant-options"><button v-for="variant in pendingProduct.variants" :key="variant.public_id" type="button" :disabled="variant.stock_quantity < 1" @click="addLine(pendingProduct, variant)"><strong>{{ variantLabel(variant) }}</strong><small>{{ variant.stock_quantity }} en stock</small></button></div><button class="text-link" type="button" @click="pendingProduct = null">Fermer</button></div>
          </section>
          <section class="order-panel"><div class="order-panel-heading"><div><h2>Livraison</h2><p>Coordonnées utilisées pour préparer l’expédition.</p></div></div><div class="delivery-form manual-delivery-form"><label>Nom complet<input v-model.trim="customer.full_name" required autocomplete="name"></label><label>Téléphone<input v-model.trim="customer.phone" required inputmode="tel" autocomplete="tel"></label><label>Ville<input v-model.trim="customer.city" required autocomplete="address-level2"></label><div class="admin-combobox"><label>Gouvernorat<input v-model="governorateQuery" role="combobox" aria-autocomplete="list" :aria-expanded="governorateOpen" autocomplete="off" required @focus="governorateOpen = true" @keydown="governorateKeydown"></label><div v-if="governorateOpen" class="admin-combobox-options" role="listbox"><button v-for="(governorate, index) in filteredGovernorates" :key="governorate" type="button" :class="{ 'is-active': governorateIndex === index }" role="option" :aria-selected="selectedGovernorate === governorate" @mousedown.prevent="chooseGovernorate(governorate)">{{ governorate }}</button></div></div><label class="full">Adresse<textarea v-model.trim="customer.address" required autocomplete="street-address"></textarea></label><template v-for="field in extraFields" :key="field.key"><label v-if="field.type === 'textarea'" class="full">{{ field.label }}<textarea v-model="customer[field.key]" :required="field.is_required"></textarea></label><label v-else-if="field.type === 'select' || field.type === 'radio'">{{ field.label }}<select v-model="customer[field.key]" :required="field.is_required"><option value="">Choisir</option><option v-for="option in field.options || []" :key="optionValue(option)" :value="optionValue(option)">{{ optionLabel(option) }}</option></select></label><label v-else-if="field.type === 'checkbox'" class="full manual-checkbox"><input v-model="customer[field.key]" type="checkbox"> <span>{{ field.label }}</span></label><label v-else>{{ field.label }}<input v-model="customer[field.key]" :type="field.type === 'number' ? 'number' : 'text'" :required="field.is_required"></label></template></div></section>
        </main>
        <aside class="order-detail-side"><section class="order-panel manual-order-summary"><div class="order-panel-heading"><div><h2>Validation</h2><p>La commande sera créée seulement après contrôle du serveur.</p></div></div><label>Statut initial<SelectControl v-model="status" :options="[{ value: 'nouvelle', label: 'Nouvelle' }, { value: 'tentative_1', label: 'Tentative 1' }, { value: 'tentative_2', label: 'Tentative 2' }, { value: 'tentative_3', label: 'Tentative 3' }, { value: 'confirmee', label: 'Confirmée' }]"/></label><label class="manual-total-create-field"><span>Total facturé personnalisé <small>(facultatif)</small></span><span class="price-input"><input v-model.trim="manualTotalInput" inputmode="decimal" autocomplete="off" placeholder="Ex. 45,000" aria-describedby="manual-total-help"><em>DT</em></span><small id="manual-total-help">Laissez vide pour conserver le total calculé des articles et de la livraison.</small></label><dl><div><dt>Articles</dt><dd>{{ totalItems }}</dd></div><div><dt>Tarifs</dt><dd>{{ manualTotalInput ? 'Total personnalisé' : 'Calculés au serveur' }}</dd></div><div><dt>Livraison</dt><dd>Calculée au serveur</dd></div></dl><p class="manual-order-note">Le Purchase serveur est mis en file avec le numéro du client. Si vous choisissez « Confirmée », le colis Navex est aussi mis en file selon la configuration active.</p><button class="admin-action" :disabled="saving || !lines.length">{{ saving ? 'Création…' : 'Créer la commande' }}</button></section></aside>
      </form>
    </section>`,
};

ManualOrderCreateView.template = (ManualOrderCreateView.template as string).replace(
    '<label class="full">Adresse<textarea v-model.trim="customer.address" required autocomplete="street-address"></textarea></label><template v-for="field in extraFields"',
    '<label class="full">Adresse<textarea v-model.trim="customer.address" required autocomplete="street-address"></textarea></label><label>Échange<SelectControl class="manual-exchange-select" v-model="exchange.is_exchange" :options="[{ value: \'Non\', label: \'Non\' }, { value: \'Oui\', label: \'Oui\' }]" /></label><label v-if="exchange.is_exchange === \'Oui\'" class="full">Désignation des articles à échanger<input v-model.trim="exchange.article_designation" required maxlength="500"></label><label v-if="exchange.is_exchange === \'Oui\'">Nombre des articles à échanger<input v-model.number="exchange.article_count" type="number" min="1" step="1" required></label><template v-for="field in extraFields"',
);

ManualOrderCreateView.template = (ManualOrderCreateView.template as string).replace(
    '<label>Téléphone<input v-model.trim="customer.phone" required inputmode="tel" autocomplete="tel"></label>',
    '<label>Téléphone<input v-model.trim="customer.phone" required inputmode="tel" autocomplete="tel"><small v-if="customerLookup === \'loading\'" class="field-hint">Recherche du client…</small><small v-else-if="customerLookup === \'found\'" class="field-hint is-success">Client existant trouvé — informations préremplies.</small><small v-else-if="customerLookup === \'error\'" class="field-hint">Recherche indisponible, vous pouvez continuer manuellement.</small></label>',
);

export default ManualOrderCreateView;
