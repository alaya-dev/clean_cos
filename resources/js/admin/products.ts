import { computed, onBeforeUnmount, onMounted, ref, watch, type Component } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { adminApi } from './api';
import { confirmAction, showError, showToast } from './feedback';
import SelectControl from './select-control';
import '../../css/admin-list-pages.css';

type Product = {
    public_id: string;
    name: string;
    is_active: boolean;
    regular_price_millimes: number;
    stock_quantity: number | null;
    low_stock_threshold?: number | null;
    active_variant_stock_quantity?: number | null;
    has_variants: boolean;
    category?: { name: string };
    images?: { public_url?: string | null; processing_status?: string; is_primary?: boolean }[];
};
type ProductListMediaPreview = { product_public_id: string; preview_url: string };
type Category = { public_id: string; name: string };
type Page<T> = { data: T[]; current_page: number; last_page: number; per_page: number; total: number };
type ImportRow = {
    line: number;
    meta_catalog_id: string | null;
    name: string | null;
    price_millimes: number | null;
    description: string | null;
    category: string | null;
    provided_fields: string[];
    product_public_id: string | null;
    operation: 'update' | 'create' | 'skipped' | 'conflict';
    conflict: boolean;
    message: string | null;
};
type ImportReport = {
    rows: ImportRow[];
    summary: { total: number; ready: number; conflicts: number; unmatched: number; skipped: number };
};
type ProductAction = 'publish' | 'hide' | 'archive' | 'restore' | 'delete';
type BulkTool = 'stock' | 'promotion';

const money = (value: number) => `${(value / 1000).toFixed(3).replace('.', ',')} DT`;
const api = <T>(path: string, method = 'GET', body?: unknown) => adminApi<T>(path, method, body);

const productFilterKeys = ['search', 'category_id', 'is_active', 'has_variants', 'stock_state', 'is_promotional', 'sort', 'archived'] as const;
type ProductFilterKey = typeof productFilterKeys[number];
type ProductFilters = Record<ProductFilterKey, string>;

const defaultProductFilters = (): ProductFilters => ({
    search: '', category_id: '', is_active: '', has_variants: '', stock_state: '',
    is_promotional: '', sort: '-created_at', archived: '0',
});

const productFilterValues: Record<Exclude<ProductFilterKey, 'search' | 'category_id'>, string[]> = {
    is_active: ['', '0', '1'],
    has_variants: ['', '0', '1'],
    stock_state: ['', 'in_stock', 'low_stock', 'out_of_stock'],
    is_promotional: ['', '0', '1'],
    sort: ['-created_at', 'created_at', 'name', '-name', 'regular_price_millimes', '-regular_price_millimes'],
    archived: ['0', '1'],
};

const ProductsView: Component = {
    components: { RouterLink, SelectControl },
    setup() {
        const route = useRoute();
        const router = useRouter();
        const listReturnTo = computed(() => route.fullPath.startsWith('/products') ? route.fullPath : '/products');
        const page = ref<Page<Product> | null>(null);
        const categories = ref<Category[]>([]);
        const loading = ref(true);
        const selected = ref<string[]>([]);
        const extra = ref(false);
        let requestId = 0;
        const localMediaPreviews = ref<Record<string, string>>({});
        const mediaPollTimers = new Map<string, number>();
        const mediaPollAttempts = new Map<string, number>();
        const mediaPollRequests = new Set<string>();
        let viewActive = true;

        const canImport = true;
        const importOpen = ref(false);
        const importFile = ref<File | null>(null);
        const importReport = ref<ImportReport | null>(null);
        const importBusy = ref(false);
        const bulkTool = ref<BulkTool | null>(null);
        const bulkStockQuantity = ref<number | null>(null);
        const bulkPromotionPercentage = ref<number | null>(null);
        const filters = ref<ProductFilters>(defaultProductFilters());

        const routePage = () => {
            const rawPage = route.query.page;
            const parsedPage = typeof rawPage === 'string' ? Number(rawPage) : Number.NaN;

            return Number.isInteger(parsedPage) && parsedPage > 0 ? parsedPage : 1;
        };

        const applyFiltersFromRoute = () => {
            const next = defaultProductFilters();

            productFilterKeys.forEach((key) => {
                const rawValue = route.query[key];
                const value = typeof rawValue === 'string' ? rawValue : '';

                if (key === 'search' || key === 'category_id') {
                    next[key] = value;
                    return;
                }

                if (productFilterValues[key].includes(value)) {
                    next[key] = value;
                }
            });

            Object.assign(filters.value, next);
        };

        const listRouteQuery = (requestedPage: number) => {
            const query: Record<string, string> = {};

            productFilterKeys.forEach((key) => {
                const value = filters.value[key];
                if (value) query[key] = value;
            });

            if (requestedPage > 1) query.page = String(requestedPage);
            return query;
        };

        const syncListRoute = async (requestedPage: number) => {
            const query = listRouteQuery(requestedPage);
            const target = { path: '/products', query };

            if (router.resolve(target).fullPath === route.fullPath) return true;

            await router.replace(target);
            return false;
        };

        const archivedView = computed(() => filters.value.archived === '1');
        const allSelected = computed(() => !!page.value?.data.length && selected.value.length === page.value.data.length);
        const summary = computed(() => {
            const products = page.value?.data || [];
            const lowStock = products.filter((product) => !product.has_variants
                && product.low_stock_threshold != null
                && (product.stock_quantity || 0) > 0
                && (product.stock_quantity || 0) <= product.low_stock_threshold).length;

            return [
                { label: 'Résultats', value: page.value?.total || 0 },
                { label: 'Publiés sur cette page', value: products.filter((product) => product.is_active).length },
                { label: 'Masqués sur cette page', value: products.filter((product) => !product.is_active).length },
                { label: 'Stock faible sur cette page', value: lowStock },
            ];
        });

        const load = async (requestedPage = 1) => {
            if (!await syncListRoute(requestedPage)) return;

            const currentRequest = ++requestId;
            loading.value = true;

            try {
                const query = new URLSearchParams({
                    per_page: '25', page: String(requestedPage), sort: filters.value.sort, archived: filters.value.archived,
                });
                (['search', 'category_id', 'is_active', 'has_variants', 'stock_state', 'is_promotional'] as const)
                    .forEach((key) => { if (filters.value[key]) query.set(key, filters.value[key]); });
                const response = await api<{ data: Page<Product> }>(`products?${query}`);
                if (currentRequest !== requestId) return;
                page.value = response.data;
                selected.value = selected.value.filter((id) => page.value?.data.some((product) => product.public_id === id));
            } catch (cause) {
                if (currentRequest === requestId) showError(cause instanceof Error ? cause.message : 'Impossible de charger les produits.');
            } finally {
                if (currentRequest === requestId) loading.value = false;
            }
        };
        const loadCategories = async () => {
            try {
                categories.value = (await api<{ data: Page<Category> }>('categories?per_page=100&is_active=1')).data.data;
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Impossible de charger les catégories.');
            }
        };
        const releaseLocalMediaPreview = (productId: string) => {
            const preview = localMediaPreviews.value[productId];
            if (preview) URL.revokeObjectURL(preview);
            delete localMediaPreviews.value[productId];
        };
        const stopMediaPolling = (productId: string, releasePreview = false) => {
            const timer = mediaPollTimers.get(productId);
            if (timer !== undefined) window.clearTimeout(timer);
            mediaPollTimers.delete(productId);
            mediaPollAttempts.delete(productId);
            mediaPollRequests.delete(productId);
            if (releasePreview) releaseLocalMediaPreview(productId);
        };
        const reconcileMediaPreview = async (productId: string) => {
            if (!viewActive || !localMediaPreviews.value[productId] || mediaPollRequests.has(productId)) return;
            const attempts = mediaPollAttempts.get(productId) || 0;
            if (attempts >= 30) return;
            mediaPollAttempts.set(productId, attempts + 1);
            mediaPollRequests.add(productId);
            try {
                const response = await api<{ data: Pick<Product, 'public_id' | 'images'> }>(`products/${encodeURIComponent(productId)}/media-status`);
                if (!viewActive) return;
                const images = response.data.images || [];
                const primary = images.find((image) => image.is_primary && image.processing_status === 'ready' && image.public_url);
                const row = page.value?.data.find((product) => product.public_id === productId);
                if (primary) {
                    if (row) row.images = [primary];
                    stopMediaPolling(productId, true);
                    return;
                }
                if (images.some((image) => image.is_primary && image.processing_status === 'failed')) {
                    stopMediaPolling(productId, true);
                    return;
                }
            } catch {
                // The image worker may still be finishing; the bounded retry remains silent.
            } finally {
                mediaPollRequests.delete(productId);
            }
            if (viewActive && localMediaPreviews.value[productId] && (mediaPollAttempts.get(productId) || 0) < 30) {
                mediaPollTimers.set(productId, window.setTimeout(() => {
                    mediaPollTimers.delete(productId);
                    void reconcileMediaPreview(productId);
                }, 1000));
            }
        };
        const acceptNavigationMediaPreview = () => {
            const candidate = window.history.state?.productMediaPreview as Partial<ProductListMediaPreview> | undefined;
            if (!candidate || typeof candidate.product_public_id !== 'string' || typeof candidate.preview_url !== 'string') return;
            localMediaPreviews.value[candidate.product_public_id] = candidate.preview_url;
            const state = window.history.state as Record<string, unknown>;
            const { productMediaPreview: _productMediaPreview, ...retainedState } = state;
            window.history.replaceState(retainedState, '', window.location.href);
            void reconcileMediaPreview(candidate.product_public_id);
        };
        const imageUrl = (product: Product) => localMediaPreviews.value[product.public_id] || product.images?.[0]?.public_url || '';
        const selectImportFile = (event: Event) => {
            importFile.value = (event.target as HTMLInputElement).files?.[0] || null;
            importReport.value = null;
        };
        const previewImport = async () => {
            if (!importFile.value) return showError('Choisissez un fichier CSV ou XLSX avant la simulation.');
            importBusy.value = true;
            try {
                const form = new FormData();
                form.append('file', importFile.value);
                importReport.value = (await adminApi<{ data: ImportReport }>('meta/catalogue/import/dry-run', 'POST', form)).data;
                showToast('success', 'Simulation terminée. Vérifiez les lignes avant l’import.');
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Simulation impossible.');
            } finally {
                importBusy.value = false;
            }
        };
        const commitImport = async () => {
            if (!importReport.value || importReport.value.summary.ready === 0 || importReport.value.summary.conflicts > 0) return;
            importBusy.value = true;
            try {
                const response = await api<{ data: { result: { updated: number; created: number; skipped: number } } }>('meta/catalogue/import/commit', 'POST', { rows: importReport.value.rows });
                const result = response.data.result;
                showToast('success', `Import terminé : ${result.updated} mis à jour, ${result.created} créé(s), ${result.skipped} ignoré(s).`);
                importReport.value = null;
                importFile.value = null;
                importOpen.value = false;
                await load();
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Import impossible.');
            } finally {
                importBusy.value = false;
            }
        };
        const toggle = (id: string) => {
            selected.value = selected.value.includes(id) ? selected.value.filter((item) => item !== id) : [...selected.value, id];
        };
        const toggleAll = () => {
            selected.value = allSelected.value ? [] : (page.value?.data.map((product) => product.public_id) || []);
        };
        const reset = () => {
            Object.assign(filters.value, defaultProductFilters());
            extra.value = false;
            void load(1);
        };
        const bulk = async (action: ProductAction) => {
            if (!selected.value.length) return;
            const messages: Record<ProductAction, string> = {
                publish: `Publier ${selected.value.length} produit(s) dans la boutique ?`,
                hide: `Masquer ${selected.value.length} produit(s) de la boutique ?`,
                archive: `Supprimer ${selected.value.length} produit(s) ? Ils resteront restaurables et leurs références seront conservées.`,
                restore: `Restaurer ${selected.value.length} produit(s) ? Ils resteront masqués jusqu’à leur publication.`,
                delete: `Supprimer définitivement ${selected.value.length} produit(s) ? Cette action est irréversible et n’est possible que sans historique.`,
            };
            const confirmed = action === 'archive'
                ? await confirmAction(
                    'Supprimer les produits sélectionnés ?',
                    `${selected.value.length} produit(s) seront retirés du catalogue. Ils resteront restaurables depuis les Archives et l’historique des commandes sera conservé.`,
                    'Supprimer les produits',
                    'danger',
                )
                : window.confirm(messages[action]);
            if (!confirmed) return;
            const path = action === 'archive' ? 'products/bulk-archive'
                : action === 'restore' ? 'products/bulk-restore'
                    : action === 'delete' ? 'products/bulk-force-delete' : 'products/bulk-status';
            const body = action === 'publish' || action === 'hide'
                ? { public_ids: selected.value, is_active: action === 'publish' }
                : { public_ids: selected.value };
            try {
                await api(path, 'POST', body);
                showToast('success', action === 'delete' ? 'Produits supprimés définitivement.' : action === 'archive' ? 'Produits retirés du catalogue. Ils restent restaurables depuis les Archives.' : action === 'restore' ? 'Produits restaurés et masqués.' : 'Action groupée appliquée.');
                selected.value = [];
                await load();
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Action groupée impossible.');
            }
        };
        const setBulkStock = async () => {
            const quantity = bulkStockQuantity.value;
            if (typeof quantity !== 'number' || !Number.isInteger(quantity) || quantity < 0) return showError('Indiquez un stock entier égal ou supérieur à zéro.');
            if (!window.confirm(`Définir le stock de ${selected.value.length} produit(s) à ${quantity} unité(s) ?`)) return;
            try {
                await api('products/bulk-set-stock', 'POST', { public_ids: selected.value, stock_quantity: quantity });
                showToast('success', 'Stock groupé mis à jour.');
                bulkTool.value = null;
                await load();
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Mise à jour du stock impossible.');
            }
        };
        const setBulkPromotion = async () => {
            const percentage = bulkPromotionPercentage.value;
            if (typeof percentage !== 'number' || !Number.isInteger(percentage) || percentage < 1 || percentage > 99) return showError('Indiquez un pourcentage entre 1 et 99.');
            if (!window.confirm(`Appliquer une offre de ${percentage} % à ${selected.value.length} produit(s) ?`)) return;
            try {
                await api('products/bulk-set-promotion', 'POST', { public_ids: selected.value, percentage });
                showToast('success', 'Offre appliquée aux produits sélectionnés.');
                bulkTool.value = null;
                await load();
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Application de l’offre impossible.');
            }
        };
        const remove = async (product: Product) => {
            const confirmed = await confirmAction(
                'Supprimer ce produit du catalogue ?',
                `« ${product.name} » sera retiré du catalogue actif. Les commandes passées et leurs détails restent conservés. Cette action est irréversible depuis cette liste.`,
                'Supprimer le produit',
                'danger',
            );
            if (!confirmed) return;
            try {
                await api(`products/${product.public_id}`, 'DELETE');
                selected.value = selected.value.filter((publicId) => publicId !== product.public_id);
                showToast('success', 'Produit retiré du catalogue. L’historique des commandes est conservé.');
                await load(page.value?.current_page || 1);
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Suppression du produit impossible.');
            }
        };
        let timer: number | undefined;
        const queue = () => {
            clearTimeout(timer);
            timer = window.setTimeout(() => void load(1), 280);
        };
        const stockLabel = (product: Product) => `${product.has_variants ? product.active_variant_stock_quantity || 0 : product.stock_quantity || 0} unités`;
        onMounted(() => { acceptNavigationMediaPreview(); applyFiltersFromRoute(); void load(routePage()); void loadCategories(); });
        watch(() => route.fullPath, () => { acceptNavigationMediaPreview(); applyFiltersFromRoute(); void load(routePage()); });
        onBeforeUnmount(() => {
            viewActive = false;
            Object.keys(localMediaPreviews.value).forEach((productId) => stopMediaPolling(productId, true));
        });

        return {
            allSelected, archivedView, bulk, bulkPromotionPercentage, bulkStockQuantity, bulkTool, canImport, categories, imageUrl,
            commitImport, extra, filters, importBusy, importFile, importOpen, importReport, load, loading, money, page,
            previewImport, queue, remove, reset, selectImportFile, selected, setBulkPromotion, setBulkStock, stockLabel,
            summary, toggle, toggleAll, listReturnTo,
        };
    },
    template: `
      <section class="admin-page products-page">
        <header class="admin-page-header">
          <div><p class="admin-eyebrow">Catalogue</p><h1>Produits</h1><p class="admin-subtitle">Gérez les produits, prix et disponibilités.</p></div>
          <div class="products-page-actions"><button v-if="canImport" class="admin-outline" type="button" :aria-expanded="importOpen" @click="importOpen = !importOpen">Importer un fichier</button><RouterLink class="admin-action" :to="{ path: '/products/new', query: { returnTo: listReturnTo } }">Nouveau produit</RouterLink></div>
        </header>
        <Transition name="orders-filter">
          <section v-if="importOpen" class="product-import-panel" aria-labelledby="product-import-title">
            <header><div><p class="admin-eyebrow">Import</p><h2 id="product-import-title">Importer des produits</h2><p>Simulez le fichier avant toute modification. Les colonnes absentes ne remplacent jamais les données existantes.</p></div><button class="text-link" type="button" @click="importOpen = false">Fermer</button></header>
            <div class="product-import-layout"><label class="product-import-upload"><strong>Fichier CSV ou XLSX</strong><span>Jusqu’à 10 Mo. CSV séparé par une virgule ou un point-virgule.</span><input type="file" accept=".csv,.txt,.xlsx" @change="selectImportFile"><small v-if="importFile">{{ importFile.name }}</small></label><section class="product-import-columns"><strong>Colonnes reconnues</strong><code>meta_catalog_id, name, description, price, category</code><p><b>Mise à jour :</b> indiquez un <code>meta_catalog_id</code> ou un nom unique ; seules les colonnes renseignées changent.</p><p><b>Création :</b> nom, prix et catégorie sont requis. L’identifiant Meta reste facultatif.</p><p><b>Catégorie :</b> utilisez son nom ou son slug.</p></section></div>
            <footer><button class="admin-action" type="button" :disabled="importBusy || !importFile" @click="previewImport">{{ importBusy ? 'Simulation…' : 'Simuler l’import' }}</button><small>Le fichier n’est enregistré qu’après votre confirmation.</small></footer>
            <section v-if="importReport" class="product-import-report" aria-live="polite"><header><div><h3>Résultat de la simulation</h3><p>{{ importReport.summary.ready }} prêt(es) · {{ importReport.summary.conflicts }} conflit(s) · {{ importReport.summary.skipped || 0 }} ignorée(s)</p></div><button class="admin-action" type="button" :disabled="importBusy || importReport.summary.ready === 0 || importReport.summary.conflicts > 0" @click="commitImport">{{ importBusy ? 'Import…' : 'Confirmer l’import' }}</button></header><p v-if="importReport.summary.conflicts" class="admin-alert is-error">Corrigez les conflits avant de confirmer l’import.</p><ol><li v-for="row in importReport.rows" :key="row.line"><span>#{{ row.line }}</span><strong>{{ row.name || 'Sans nom' }}</strong><em :class="'is-' + row.operation">{{ row.operation === 'update' ? 'Mise à jour' : row.operation === 'create' ? 'Création' : row.operation === 'conflict' ? 'Conflit' : 'Ignorée' }}</em><small>{{ row.message || row.provided_fields.join(', ') }}</small></li></ol></section>
          </section>
        </Transition>
        <section class="list-summary-strip" aria-label="Résumé des produits"><article v-for="item in summary" :key="item.label"><small>{{ item.label }}</small><strong>{{ item.value }}</strong></article></section>
        <section class="product-filter-panel list-filter-toolbar" aria-label="Filtres des produits"><label class="admin-search"><span class="sr-only">Rechercher un produit</span><input v-model.trim="filters.search" @input="queue" placeholder="Rechercher un produit…"></label><label class="toolbar-select"><span>Catégorie</span><SelectControl v-model="filters.category_id" :options="[{ value: '', label: 'Toutes les catégories' }, ...categories.map(category => ({ value: category.public_id, label: category.name }))]" @change="load"/></label><label class="toolbar-select"><span>Visibilité</span><SelectControl v-model="filters.is_active" :options="[{ value: '', label: 'Tous les produits' }, { value: '1', label: 'Publiés' }, { value: '0', label: 'Masqués' }]" @change="load"/></label><label class="toolbar-select"><span>Affichage</span><SelectControl v-model="filters.archived" :options="[{ value: '0', label: 'Catalogue actif' }, { value: '1', label: 'Archives' }]" @change="load"/></label><label class="toolbar-select"><span>Trier par</span><SelectControl v-model="filters.sort" :options="[{ value: '-created_at', label: 'Plus récents' }, { value: 'created_at', label: 'Plus anciens' }, { value: 'name', label: 'Nom A-Z' }, { value: '-name', label: 'Nom Z-A' }, { value: 'regular_price_millimes', label: 'Prix croissant' }, { value: '-regular_price_millimes', label: 'Prix décroissant' }]" @change="load"/></label><button class="admin-outline product-more" type="button" :aria-expanded="extra" @click="extra = !extra">{{ extra ? 'Moins de filtres' : 'Plus de filtres' }}</button><button class="text-link product-reset" type="button" @click="reset">Réinitialiser</button><Transition name="orders-filter"><div v-if="extra" class="product-extra-filters"><label class="toolbar-select"><span>Stock</span><SelectControl v-model="filters.stock_state" :options="[{ value: '', label: 'Tous les états' }, { value: 'in_stock', label: 'En stock' }, { value: 'low_stock', label: 'Stock faible' }, { value: 'out_of_stock', label: 'Rupture' }]" @change="load"/></label><label class="toolbar-select"><span>Type</span><SelectControl v-model="filters.has_variants" :options="[{ value: '', label: 'Tous les types' }, { value: '0', label: 'Stock unique' }, { value: '1', label: 'Avec variantes' }]" @change="load"/></label><label class="toolbar-select"><span>Promotion</span><SelectControl v-model="filters.is_promotional" :options="[{ value: '', label: 'Toutes' }, { value: '1', label: 'En promotion' }, { value: '0', label: 'Sans promotion' }]" @change="load"/></label></div></Transition></section>
        <p class="list-instruction" role="note"><strong>Gestion groupée :</strong> Pour Publier, Masquer, Définir le stock, Créer une offre ou Supprimer, vous devez d'abord cocher la case à gauche du nom du produit.</p>
        <section v-if="selected.length" class="bulk-bar" aria-live="polite"><strong>{{ selected.length }} sélectionné{{ selected.length > 1 ? 's' : '' }}</strong><template v-if="!archivedView"><div class="bulk-actions"><button class="admin-outline" type="button" @click="bulk('publish')">Publier</button><button class="admin-outline" type="button" @click="bulk('hide')">Masquer</button><button class="admin-outline" type="button" @click="bulkTool = bulkTool === 'stock' ? null : 'stock'">Définir le stock</button><button class="admin-outline" type="button" @click="bulkTool = bulkTool === 'promotion' ? null : 'promotion'">Créer une offre</button></div><button class="text-link danger" type="button" @click="bulk('archive')">Supprimer</button><section v-if="bulkTool === 'stock'" class="bulk-tool"><div><strong>Stock fixe</strong><p>La même quantité est appliquée au produit simple, ou à chacune de ses variantes.</p></div><label>Quantité<input v-model.number="bulkStockQuantity" type="number" min="0" step="1" inputmode="numeric"></label><button class="admin-action" type="button" @click="setBulkStock">Appliquer le stock</button></section><section v-if="bulkTool === 'promotion'" class="bulk-tool"><div><strong>Offre en pourcentage</strong><p>Le prix promotionnel est recalculé depuis le prix normal. La promotion est activée.</p></div><label class="bulk-percentage">Réduction<input v-model.number="bulkPromotionPercentage" type="number" min="1" max="99" step="1" inputmode="numeric"><span>%</span></label><button class="admin-action" type="button" @click="setBulkPromotion">Appliquer l’offre</button></section></template><template v-else><div class="bulk-actions"><button class="admin-outline" type="button" @click="bulk('restore')">Restaurer</button></div><button class="text-link danger" type="button" @click="bulk('delete')">Supprimer définitivement</button></template></section>
        <p v-if="loading" class="admin-loading">Chargement…</p><section v-else-if="!page?.data.length" class="admin-empty"><strong>{{ archivedView ? 'Aucun produit archivé.' : 'Aucun produit ne correspond à ces filtres.' }}</strong><button v-if="!archivedView" class="text-link" type="button" @click="reset">Réinitialiser les filtres</button></section>
        <template v-else><div class="admin-table admin-product-table admin-entity-list"><div class="admin-table-head"><label><input type="checkbox" :checked="allSelected" @change="toggleAll"><span>Produit</span></label><span>Prix</span><span>Stock</span><span>Statut</span><span>Actions</span></div><article v-for="product in page.data" :key="product.public_id"><label class="admin-product-identity"><input type="checkbox" :checked="selected.includes(product.public_id)" @change="toggle(product.public_id)"><span class="admin-product-thumb"><img v-if="imageUrl(product)" :src="imageUrl(product)" :alt="''"><span v-else aria-hidden="true">PC</span></span><RouterLink class="admin-product-orders-link" :to="{ path: '/orders', query: { product_public_id: product.public_id, product_name: product.name } }"><strong>{{ product.name }}</strong><small>{{ product.category?.name || 'Sans catégorie' }} · Voir les commandes associées</small></RouterLink></label><span class="admin-product-price">{{ money(product.regular_price_millimes) }}</span><span class="admin-product-stock">{{ stockLabel(product) }}</span><span class="admin-badge" :class="{ 'is-published': product.is_active }">{{ archivedView ? 'Archivé' : product.is_active ? 'Publié' : 'Masqué' }}</span><span class="admin-row-actions"><RouterLink class="admin-icon-action" :to="'/products/' + product.public_id" title="Modifier le produit" :aria-label="'Modifier ' + product.name">✎</RouterLink><button v-if="!archivedView" class="admin-icon-action is-danger" type="button" title="Supprimer le produit" :aria-label="'Supprimer ' + product.name" @click="remove(product)">×</button></span></article></div><nav v-if="page.last_page > 1" class="admin-pagination" aria-label="Pagination des produits"><span>{{ page.total }} produit{{ page.total > 1 ? 's' : '' }} · Page {{ page.current_page }} sur {{ page.last_page }}</span><div><button class="admin-outline" type="button" :disabled="loading || page.current_page <= 1" @click="load(page.current_page - 1)">Précédent</button><button class="admin-outline" type="button" :disabled="loading || page.current_page >= page.last_page" @click="load(page.current_page + 1)">Suivant</button></div></nav></template></section>`,
};

ProductsView.template = (ProductsView.template as string).replace(
    ':to="\'/products/\' + product.public_id"',
    ':to="{ path: \'/products/\' + product.public_id, query: { returnTo: listReturnTo } }"',
);

export default ProductsView;
