import { computed, onMounted, ref, type Component } from 'vue';
import { RouterLink } from 'vue-router';
import { confirmAction, showError, showToast } from './feedback';
import SelectControl from './select-control';
import '../../css/admin-list-pages.css';

type Category = {
    public_id: string;
    name: string;
    slug: string;
    description?: string | null;
    image_url?: string | null;
    is_active: boolean;
    sort_order: number;
    products_count: number;
};
type OrderedProduct = {
    public_id: string;
    name: string;
    is_active: boolean;
    images: { public_url: string | null }[];
};
type Page<T> = { data: T[]; current_page: number; last_page: number; total: number };
const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content || '';

async function jsonApi<T>(path: string, method = 'GET', body?: unknown): Promise<T> {
    const response = await fetch(`/api/v1/admin/${path}`, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            ...(method === 'GET' ? {} : { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }),
        },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    });
    if (!response.ok) {
        const failure = (await response.json().catch(() => null)) as { message?: string } | null;
        throw new Error(failure?.message || 'Opération impossible.');
    }
    return response.json() as Promise<T>;
}

const CategoriesView: Component = {
    components: { RouterLink, SelectControl },
    setup() {
        const rows = ref<Category[]>([]);
        const page = ref({ current_page: 1, last_page: 1, total: 0 });
        const loading = ref(true);
        const saving = ref(false);
        const search = ref('');
        const active = ref('');
        const activeOptions = [{ value: '', label: 'Tous les états' }, { value: 'true', label: 'Visible' }, { value: 'false', label: 'Masquée' }];
        const selected = ref<string[]>([]);
        const allSelected = computed(() => rows.value.length > 0 && selected.value.length === rows.value.length);
        const summary = computed(() => [
            { label: 'Catégories', value: page.value.total },
            { label: 'Visibles sur cette page', value: rows.value.filter((category) => category.is_active).length },
            { label: 'Masquées sur cette page', value: rows.value.filter((category) => !category.is_active).length },
            { label: 'Produits rattachés', value: rows.value.reduce((count, category) => count + category.products_count, 0) },
        ]);
        const editing = ref<Category | 'new' | null>(null);
        const imageFile = ref<File | null>(null);
        const form = ref({ name: '', slug: '', description: '', is_active: true });
        const orderingCategory = ref<Category | null>(null);
        const orderedProducts = ref<OrderedProduct[]>([]);
        const orderLoading = ref(false);
        const orderSaving = ref(false);
        const draggedProductIndex = ref<number | null>(null);

        const load = async (requestedPage = 1) => {
            loading.value = true;
            try {
                const query = new URLSearchParams({ per_page: '25', page: String(requestedPage) });
                if (search.value) query.set('search', search.value);
                if (active.value) query.set('is_active', active.value === 'true' ? '1' : '0');
                const response = (await jsonApi<{ data: Page<Category> }>(`categories?${query}`)).data;
                rows.value = response.data;
                page.value = { current_page: response.current_page, last_page: response.last_page, total: response.total };
                selected.value = selected.value.filter((publicId) => rows.value.some((category) => category.public_id === publicId));
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Erreur de chargement.');
            } finally {
                loading.value = false;
            }
        };
        let timer: number | undefined;
        const queueSearch = () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => void load(1), 280);
        };
        const reset = () => { search.value = ''; active.value = ''; void load(1); };
        const open = (category?: Category) => {
            imageFile.value = null;
            editing.value = category || 'new';
            form.value = category
                ? { name: category.name, slug: category.slug, description: category.description || '', is_active: category.is_active }
                : { name: '', slug: '', description: '', is_active: true };
        };
        const selectImage = (event: Event) => {
            imageFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
        };
        const uploadImage = async (publicId: string) => {
            if (!imageFile.value) return;
            const body = new FormData();
            body.append('image', imageFile.value);
            const response = await fetch(`/api/v1/admin/categories/${publicId}/image`, {
                method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() }, body,
            });
            if (!response.ok) throw new Error('L’image de la catégorie n’a pas pu être traitée.');
        };
        const save = async () => {
            if (!form.value.name.trim()) return showError('Le nom de la catégorie est obligatoire.');
            const existing = typeof editing.value === 'object' ? editing.value : null;
            saving.value = true;
            try {
                const saved = await jsonApi<{ data: Category }>(existing ? `categories/${existing.public_id}` : 'categories', existing ? 'PATCH' : 'POST', {
                    ...form.value, sort_order: existing?.sort_order ?? rows.value.length,
                });
                await uploadImage(saved.data.public_id);
                showToast('success', existing ? 'Catégorie mise à jour.' : 'Catégorie créée.');
                editing.value = null;
                await load();
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Enregistrement impossible.');
            } finally {
                saving.value = false;
            }
        };
        const remove = async (category: Category) => {
            const relatedProducts = category.products_count;
            const message = relatedProducts
                ? `« ${category.name} » et ses ${relatedProducts} produit${relatedProducts > 1 ? 's' : ''} seront retirés du catalogue. Les commandes passées resteront intactes. Cette action est irréversible.`
                : `« ${category.name} » sera retirée du catalogue. Cette action est irréversible.`;
            if (!await confirmAction('Supprimer cette catégorie ?', message, 'Supprimer', 'danger')) return;
            try {
                await jsonApi(`categories/${category.public_id}`, 'DELETE');
                showToast('success', 'Catégorie supprimée.');
                await load();
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Suppression impossible.');
            }
        };
        const toggle = (publicId: string) => { selected.value = selected.value.includes(publicId) ? selected.value.filter((item) => item !== publicId) : [...selected.value, publicId]; };
        const toggleAll = () => { selected.value = allSelected.value ? [] : rows.value.map((category) => category.public_id); };
        const bulkStatus = async (isActive: boolean) => {
            const verb = isActive ? 'afficher' : 'masquer';
            if (!await confirmAction(`${isActive ? 'Afficher' : 'Masquer'} les catégories sélectionnées ?`, `Vous allez ${verb} ${selected.value.length} catégorie(s).${isActive ? '' : ' Les produits associés seront également masqués.'}`, isActive ? 'Afficher' : 'Masquer')) return;
            try { await jsonApi('categories/bulk-status', 'POST', { public_ids: selected.value, is_active: isActive }); showToast('success', 'Catégories mises à jour.'); selected.value = []; await load(); } catch (cause) { showError(cause instanceof Error ? cause.message : 'Mise à jour impossible.'); }
        };
        const productImageUrl = (product: OrderedProduct) => product.images[0]?.public_url ?? null;
        const moveOrderedProduct = (fromIndex: number, toIndex: number) => {
            if (toIndex < 0 || toIndex >= orderedProducts.value.length || fromIndex === toIndex) return;
            const [product] = orderedProducts.value.splice(fromIndex, 1);
            orderedProducts.value.splice(toIndex, 0, product);
        };
        const openProductOrder = async (category: Category) => {
            orderingCategory.value = category;
            orderedProducts.value = [];
            orderLoading.value = true;
            try {
                orderedProducts.value = (await jsonApi<{ data: OrderedProduct[] }>(`categories/${category.public_id}/product-order`)).data;
            } catch (cause) {
                orderingCategory.value = null;
                showError(cause instanceof Error ? cause.message : 'Impossible de charger les produits de cette catégorie.');
            } finally {
                orderLoading.value = false;
            }
        };
        const startProductDrag = (event: DragEvent, index: number) => {
            draggedProductIndex.value = index;
            event.dataTransfer?.setData('text/plain', orderedProducts.value[index].public_id);
            if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
        };
        const dropProduct = (index: number) => {
            if (draggedProductIndex.value !== null) moveOrderedProduct(draggedProductIndex.value, index);
            draggedProductIndex.value = null;
        };
        const saveProductOrder = async () => {
            if (!orderingCategory.value) return;
            orderSaving.value = true;
            try {
                await jsonApi(`categories/${orderingCategory.value.public_id}/product-order`, 'PUT', { product_public_ids: orderedProducts.value.map((product) => product.public_id) });
                showToast('success', 'Ordre de la grille enregistré dans la boutique.');
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'L’ordre de la grille n’a pas pu être enregistré.');
            } finally {
                orderSaving.value = false;
            }
        };
        onMounted(load);
        return { rows, page, loading, saving, search, active, activeOptions, allSelected, bulkStatus, editing, form, load, queueSearch, open, remove, reset, save, selectImage, selected, summary, toggle, toggleAll, draggedProductIndex, dropProduct, moveOrderedProduct, openProductOrder, orderedProducts, orderLoading, orderingCategory, orderSaving, productImageUrl, saveProductOrder, startProductDrag };
    },
    template: `<section class="admin-page admin-list-page categories-list-page">
      <header><div><p class="admin-eyebrow">Catalogue / Catégories</p><h1>Catégories</h1><p class="admin-subtitle">Organisez la navigation et la découverte dans la boutique.</p></div><button class="admin-action" @click="open()">Nouvelle catégorie</button></header>
      <section class="list-summary-strip" aria-label="Résumé des catégories"><article v-for="item in summary" :key="item.label"><small>{{ item.label }}</small><strong>{{ item.value }}</strong></article></section>
      <div class="admin-filter-bar list-filter-toolbar"><label class="admin-search"><span class="sr-only">Rechercher une catégorie</span><input v-model.trim="search" @input="queueSearch" placeholder="Rechercher une catégorie…"></label><SelectControl v-model="active" :options="activeOptions" @change="load" /><button class="text-link" type="button" @click="reset">Réinitialiser</button></div>
      <p class="list-instruction" role="note">Sélectionnez une ou plusieurs catégories pour les afficher ou les masquer en une seule action.</p>
      <section v-if="selected.length" class="bulk-bar" aria-live="polite"><strong>{{ selected.length }} sélectionnée{{ selected.length > 1 ? 's' : '' }}</strong><div class="bulk-actions"><button class="admin-outline" type="button" @click="bulkStatus(true)">Afficher</button><button class="admin-outline" type="button" @click="bulkStatus(false)">Masquer</button></div><small>Masquer une catégorie masque aussi ses produits.</small></section>
      <section v-if="orderingCategory" class="category-grid-ordering" aria-labelledby="category-grid-ordering-title"><header><div><h2 id="category-grid-ordering-title">Organiser la grille · {{ orderingCategory.name }}</h2><p>Glissez les produits à la position voulue. Les flèches offrent la même action au clavier et sur mobile.</p></div><button class="text-link" type="button" :disabled="orderSaving" @click="orderingCategory = null">Fermer</button></header><p class="list-instruction" role="note">Cet ordre apparaît par défaut dans cette catégorie et dans la boutique. Les tris choisis par le client restent prioritaires.</p><p v-if="orderLoading" class="admin-loading">Chargement des produits…</p><p v-else-if="!orderedProducts.length" class="admin-empty">Cette catégorie ne contient aucun produit à organiser.</p><ol v-else class="category-product-grid" aria-label="Produits à organiser"><li v-for="(product,index) in orderedProducts" :key="product.public_id" :class="{ 'is-dragging': draggedProductIndex === index }" :draggable="!orderSaving" @dragstart="startProductDrag($event,index)" @dragend="draggedProductIndex = null" @dragover.prevent @drop="dropProduct(index)"><span class="category-product-position">{{ index + 1 }}</span><img v-if="productImageUrl(product)" :src="productImageUrl(product)" alt=""><span v-else class="category-product-placeholder" aria-hidden="true">PC</span><div><strong>{{ product.name }}</strong><small>{{ product.is_active ? 'Visible dans la boutique' : 'Masqué de la boutique' }}</small></div><div class="category-product-order-actions"><button class="admin-icon-action" type="button" :disabled="orderSaving || index === 0" :aria-label="'Monter ' + product.name" title="Monter" @click="moveOrderedProduct(index,index - 1)">↑</button><button class="admin-icon-action" type="button" :disabled="orderSaving || index === orderedProducts.length - 1" :aria-label="'Descendre ' + product.name" title="Descendre" @click="moveOrderedProduct(index,index + 1)">↓</button></div></li></ol><footer v-if="!orderLoading && orderedProducts.length"><button class="text-link" type="button" :disabled="orderSaving" @click="orderingCategory = null">Annuler</button><button class="admin-action" type="button" :disabled="orderSaving" @click="saveProductOrder">{{ orderSaving ? 'Enregistrement…' : 'Enregistrer l’ordre' }}</button></footer></section>
      <form v-if="editing !== null" class="category-form" @submit.prevent="save"><header><div><p class="admin-eyebrow">{{ editing.public_id ? 'Modifier' : 'Nouvelle' }}</p><h2>{{ editing.public_id ? editing.name : 'Créer une catégorie' }}</h2></div><button class="text-link" type="button" @click="editing = null">Fermer</button></header><div class="form-grid"><label>Nom <b aria-hidden="true">*</b><input v-model.trim="form.name" required></label><label>Slug<input v-model.trim="form.slug"></label><label class="inline-check">Visible dans la boutique <input v-model="form.is_active" type="checkbox"></label><label class="full">Description<textarea v-model="form.description"></textarea></label><label class="full">Image circulaire<input type="file" accept="image/jpeg,image/png,image/webp" @change="selectImage"><small>JPEG, PNG ou WebP. L’image est sécurisée et réencodée.</small></label><img v-if="editing.image_url" class="admin-image-preview" :src="editing.image_url" :alt="'Aperçu de ' + editing.name"></div><footer><button class="text-link" type="button" @click="editing = null">Annuler</button><button class="admin-action" :disabled="saving">{{ saving ? 'Traitement et enregistrement…' : 'Enregistrer' }}</button></footer></form>
      <p v-if="loading" class="admin-loading">Chargement des catégories…</p><p v-else-if="!rows.length" class="admin-empty">Aucune catégorie ne correspond à ces critères.</p>
      <div v-else class="admin-table categories-table admin-entity-list"><div class="admin-table-head"><label><input type="checkbox" :checked="allSelected" @change="toggleAll"><span>Catégorie</span></label><span>Produits</span><span>Statut</span><span>Actions</span></div><article v-for="category in rows" :key="category.public_id"><label class="admin-category-identity"><input type="checkbox" :checked="selected.includes(category.public_id)" @change="toggle(category.public_id)"><span><img v-if="category.image_url" class="admin-category-thumb" :src="category.image_url" alt=""><strong>{{ category.name }}</strong><small>{{ category.slug }}</small></span></label><span>{{ category.products_count }} produit{{ category.products_count > 1 ? 's' : '' }}</span><span :class="category.is_active ? 'admin-badge is-published' : 'admin-badge'">{{ category.is_active ? 'Visible' : 'Masquée' }}</span><span class="admin-row-actions"><button v-if="category.products_count" class="admin-outline" type="button" @click="openProductOrder(category)">Organiser la grille</button><button class="admin-icon-action" type="button" title="Modifier la catégorie" :aria-label="'Modifier ' + category.name" @click="open(category)">✎</button><RouterLink v-if="category.products_count" class="admin-icon-action" :to="{ path: '/products', query: { category_id: category.public_id } }" title="Voir les produits" :aria-label="'Voir les produits de ' + category.name">↗</RouterLink><button class="admin-icon-action is-danger" type="button" title="Supprimer la catégorie" :aria-label="'Supprimer ' + category.name" @click="remove(category)">×</button></span></article></div><nav v-if="page.last_page > 1" class="admin-pagination" aria-label="Pagination des catégories"><span>{{ page.total }} catégorie{{ page.total > 1 ? 's' : '' }} · Page {{ page.current_page }} sur {{ page.last_page }}</span><div><button class="admin-outline" type="button" :disabled="loading || page.current_page <= 1" @click="load(page.current_page - 1)">Précédent</button><button class="admin-outline" type="button" :disabled="loading || page.current_page >= page.last_page" @click="load(page.current_page + 1)">Suivant</button></div></nav>
    </section>`,
};
export default CategoriesView;
