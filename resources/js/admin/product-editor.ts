import { computed, onBeforeUnmount, onMounted, ref, watch, type Component } from 'vue';
import { RouterLink, onBeforeRouteLeave, useRoute, useRouter } from 'vue-router';
import { confirmAction, showError, showToast } from './feedback';
import '../../css/admin-product-editor.css';

type Category = { public_id: string; name: string };
type Value = { id: number; value: string; parent_product_option_value_id?: number | null; parent_value?: { id: number; value: string } | null };
type Variant = {
    public_id: string;
    sku: string | null;
    stock_quantity: number;
    low_stock_threshold: number | null;
    is_active: boolean;
    is_default?: boolean;
    regular_price_millimes?: number | null;
    promotional_price_millimes?: number | null;
    values: Value[];
};
type Group = { id: number; name: string; values: Value[] };
type MediaRole = 'primary' | 'secondary' | 'variant';
type Media = {
    public_id: string;
    path: string | null;
    alt_text: string | null;
    is_primary: boolean;
    processing_status: string;
    public_url?: string | null;
    variant?: { public_id: string } | null;
    role?: MediaRole;
    variant_public_id?: string;
    variantIndex?: string;
};
type Product = {
    public_id: string;
    lock_version: number;
    name: string;
    slug: string;
    meta_catalog_id: string | null;
    is_active: boolean;
    has_variants: boolean;
    stock_quantity: number | null;
    low_stock_threshold: number | null;
    regular_price_millimes: number;
    promotional_price_millimes: number | null;
    short_description: string | null;
    full_description: string | null;
    seo_title: string | null;
    seo_description: string | null;
    category?: Category;
    images: Media[];
    variants: Variant[];
    option_groups: Group[];
};
type VariantDraft = {
    public_id?: string;
    keys: string[];
    sku: string;
    stock_quantity: number;
    low_stock_threshold: number;
    is_active: boolean;
    is_default: boolean;
    regular_price_dt: string;
    promotional_price_dt: string;
};
type QueuedMedia = {
    id: string;
    file: File;
    preview: string;
    alt: string;
    role: MediaRole;
    variantIndex: string;
};
type ProductListMediaPreview = {
    product_public_id: string;
    preview_url: string;
};
type Page<T> = { data: T[] };
const MAX_PRODUCT_IMAGE_BYTES = 2 * 1024 * 1024;
const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content || '';
const dt = (value: number | null) =>
    value === null ? '' : (value / 1000).toFixed(3).replace('.', ',');
const millimes = (value: string) =>
    Math.round(Number(value.replace(',', '.')) * 1000);
const mediaUrl = (image: Media) => image.public_url || '';
type ApiError = { message?: string; errors?: Record<string, string[]> };
const errorFromResponse = async (response: Response, fallback: string): Promise<string> => {
    const raw = await response.text();
    let data: ApiError | null = null;
    try {
        data = JSON.parse(raw) as ApiError;
    } catch {
        const start = raw.indexOf('{');
        const end = raw.lastIndexOf('}');
        if (start >= 0 && end > start) {
            try {
                data = JSON.parse(raw.slice(start, end + 1)) as ApiError;
            } catch {
                data = null;
            }
        }
    }

    return data?.errors
        ? Object.values(data.errors).flat().join(' ')
        : data?.message || fallback;
};
async function api<T>(
    path: string,
    method = 'GET',
    body?: unknown,
): Promise<T> {
    const response = await fetch(`/api/v1/admin/${path}`, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            ...(method === 'GET'
                ? {}
                : {
                      'Content-Type': 'application/json',
                      'X-CSRF-TOKEN': csrf(),
                  }),
        },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    });
    if (!response.ok) {
        throw new Error(await errorFromResponse(response, 'Enregistrement impossible.'));
    }
    return response.json() as Promise<T>;
}

const ProductEditorView: Component = {
    components: { RouterLink },
    setup() {
        const route = useRoute();
        const router = useRouter();
        const isNew = computed(() => route.path.endsWith('/new'));
        const safeReturnTo = computed(() => {
            const candidate = typeof route.query.returnTo === 'string' ? route.query.returnTo : '';
            const isProductListPath = candidate === '/products' || candidate.startsWith('/products?');

            return isProductListPath && !candidate.startsWith('//')
                ? candidate
                : '/products';
        });
        const product = ref<Product | null>(null);
        const categories = ref<Category[]>([]);
        const loading = ref(true);
        const saving = ref(false);
        const error = ref('');
        const notice = ref('');
        const dirty = ref(false);
        const hydrating = ref(false);
        const variantMode = ref(false);
        const groups = ref<{ name: string; valuesText: string }[]>([]);
        const variants = ref<VariantDraft[]>([]);
        const queued = ref<QueuedMedia[]>([]);
        const form = ref({
            category_public_id: '',
            name: '',
            slug: '',
            meta_catalog_id: '',
            meta_catalog_id_confirmation: false,
            short_description: '',
            full_description: '',
            regular_price_dt: '',
            promotional_price_dt: '',
            stock_quantity: 0,
            low_stock_threshold: 0,
            promotion: false,
            stock_alert: false,
            is_active: false,
            seo_title: '',
            seo_description: '',
        });
        const key = (group: number, value: string, parent = '') =>
            `${group}:${parent.toLocaleLowerCase()}:${value.toLocaleLowerCase()}`;
        const optionValues = () => groups.value.map((group, groupIndex) => group.valuesText
            .split(',')
            .map((entry) => entry.trim())
            .filter(Boolean)
            .map((entry) => {
                const [rawValue, rawParent] = entry.split('@', 2).map((part) => part.trim());
                const parent = groupIndex === 0 ? '' : (rawParent || '');
                return { value: rawValue, parentClientKey: parent ? key(groupIndex - 1, parent) : null, clientKey: key(groupIndex, rawValue, parent) };
            }));
        const variantLabel = (row: VariantDraft) =>
            row.keys
                .map((item) => item.split(':').slice(1).join(':'))
                .join(' / ');
        const totalStock = computed(() =>
            variants.value
                .filter((row) => row.is_active)
                .reduce((total, row) => total + row.stock_quantity, 0),
        );
        const hydrate = (item: Product) => {
            hydrating.value = true;
            product.value = item;
            variantMode.value = item.has_variants;
            form.value = {
                category_public_id: item.category?.public_id || '',
                name: item.name,
                slug: item.slug,
                meta_catalog_id: item.meta_catalog_id || '',
                meta_catalog_id_confirmation: false,
                short_description: item.short_description || '',
                full_description: item.full_description || '',
                regular_price_dt: dt(item.regular_price_millimes),
                promotional_price_dt: dt(item.promotional_price_millimes),
                stock_quantity: item.stock_quantity || 0,
                low_stock_threshold: item.low_stock_threshold || 0,
                promotion: item.promotional_price_millimes !== null,
                stock_alert: (item.low_stock_threshold || 0) > 0,
                is_active: item.is_active,
                seo_title: item.seo_title || '',
                seo_description: item.seo_description || '',
            };
            groups.value = item.option_groups.map((group) => ({
                name: group.name,
                valuesText: group.values.map((value) => value.parent_value ? `${value.value} @ ${value.parent_value.value}` : value.value).join(', '),
            }));
            variants.value = item.has_variants ? item.variants.map((row) => ({
                public_id: row.public_id,
                keys: row.values
                    .map((value) => {
                        const groupIndex = item.option_groups.findIndex(
                            (group) =>
                                group.values.some(
                                    (candidate) => candidate.id === value.id,
                                ),
                        );
                        return key(groupIndex, value.value, value.parent_value?.value || '');
                    })
                    .sort(),
                stock_quantity: row.stock_quantity,
                sku: row.sku || '',
                low_stock_threshold: row.low_stock_threshold || 0,
                is_active: row.is_active,
                is_default: row.is_default === true,
                regular_price_dt: dt(row.regular_price_millimes ?? null),
                promotional_price_dt: dt(row.promotional_price_millimes ?? null),
            })) : [];
            item.images.forEach((image) => {
                image.variant_public_id = image.variant?.public_id || '';
                image.variantIndex = String(
                    item.variants.findIndex(
                        (variant) => variant.public_id === image.variant_public_id,
                    ),
                );
                if (image.variantIndex === '-1') image.variantIndex = '';
                image.role = image.is_primary
                    ? 'primary'
                    : image.variant_public_id
                      ? 'variant'
                      : 'secondary';
            });
            queueMicrotask(() => {
                dirty.value = false;
                hydrating.value = false;
            });
        };
        const load = async () => {
            try {
                const [categoryResult, detail] = await Promise.all([
                    api<{ data: Page<Category> }>('categories?per_page=100&leaf_only=1'),
                    isNew.value
                        ? Promise.resolve(null)
                        : api<{ data: Product }>(
                              `products/${route.params.reference}`,
                          ),
                ]);
                categories.value = categoryResult.data.data;
                if (detail) hydrate(detail.data);
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Chargement impossible.');
            } finally {
                loading.value = false;
            }
        };
        const regenerate = () => {
            const sets = optionValues();
            if (!sets.length || sets.some((set) => !set.length)) {
                variants.value = [];
                return;
            }
            const old = new Map(
                variants.value.map((row) => [row.keys.join('|'), row]),
            );
            const combinations = sets.reduce<string[][]>(
                (all, set, index) =>
                    all.flatMap((combination) =>
                        set.filter((value) => value.parentClientKey === null || combination.includes(value.parentClientKey)).map((value) => [...combination, value.clientKey]),
                    ),
                [[]],
            );
            variants.value = combinations.map(
                (keys) =>
                    old.get(keys.join('|')) || {
                        keys,
                        sku: '',
                        stock_quantity: 0,
                        low_stock_threshold: 0,
                        is_active: true,
                        is_default: variants.value.length === 0 && combinations.length === 1,
                        regular_price_dt: '',
                        promotional_price_dt: '',
                    },
            );
        };
        const addGroup = () => {
            groups.value.push({ name: '', valuesText: '' });
        };
        const setDefaultVariant = (target: VariantDraft) => {
            variants.value.forEach((variant) => {
                variant.is_default = variant === target;
            });
        };
        const toggleVariants = () => {
            const enable = !variantMode.value;
            variantMode.value = enable;
            if (enable && !groups.value.length) addGroup();
            if (!enable) {
                variants.value = [];
                product.value?.images.forEach((image) => {
                    if (image.role === 'variant') {
                        image.role = 'secondary';
                        image.variant_public_id = '';
                        image.variantIndex = '';
                    }
                });
            }
        };
        const variantPayload = () => ({
            option_groups: groups.value.map((group, index) => ({
                name: group.name.trim(),
                values: optionValues()[index].map((value) => ({
                    client_key: value.clientKey,
                    value: value.value,
                    parent_client_key: value.parentClientKey,
                })),
            })),
            variants: variants.value.map((row) => ({
                public_id: row.public_id || null,
                option_value_client_keys: row.keys,
                sku: row.sku.trim() || null,
                regular_price_millimes: row.regular_price_dt ? millimes(row.regular_price_dt) : null,
                promotional_price_millimes: row.promotional_price_dt ? millimes(row.promotional_price_dt) : null,
                stock_quantity: row.stock_quantity,
                low_stock_threshold: row.low_stock_threshold,
                is_active: row.is_active,
                is_default: row.is_default,
            })),
        });
        const addMedia = (event: Event) => {
            const input = event.target as HTMLInputElement;
            Array.from(input.files || []).forEach((file, index) => {
                if (file.size > MAX_PRODUCT_IMAGE_BYTES) {
                    showError(`« ${file.name} » est trop volumineuse. La taille maximale est de 2 Mo par image.`);
                    return;
                }

                queued.value.push({
                    id: `${Date.now()}-${index}`,
                    file,
                    preview: URL.createObjectURL(file),
                    alt: form.value.name,
                    role:
                        queued.value.length ||
                        product.value?.images.some(
                            (image) => image.role === 'primary',
                        )
                            ? 'secondary'
                            : 'primary',
                    variantIndex: '',
                });
            });
            input.value = '';
        };
        const removeQueued = (item: QueuedMedia) => {
            URL.revokeObjectURL(item.preview);
            queued.value = queued.value.filter(
                (candidate) => candidate.id !== item.id,
            );
        };
        const moveQueued = (index: number, direction: number) => {
            const destination = index + direction;
            if (destination < 0 || destination >= queued.value.length) return;
            const [media] = queued.value.splice(index, 1);
            queued.value.splice(destination, 0, media);
        };
        const updateMedia = (image: Media) => {
            image.variantIndex = String(
                product.value?.variants.findIndex(
                    (variant) => variant.public_id === image.variant_public_id,
                ) ?? -1,
            );
            if (image.role !== 'variant') {
                image.variant_public_id = '';
                image.variantIndex = '';
            }
        };
        const removeMedia = async (image: Media) => {
            if (!product.value) return;
            const confirmed = await confirmAction('Retirer cette image ?', 'La suppression sera appliquée à l’enregistrement du produit.', 'Retirer', 'danger');
            if (!confirmed) return;
            product.value.images = product.value.images.filter(
                (candidate) => candidate.public_id !== image.public_id,
            );
        };
        const moveMedia = (index: number, direction: number) => {
            if (!product.value) return;
            const destination = index + direction;
            if (destination < 0 || destination >= product.value.images.length) return;
            const [media] = product.value.images.splice(index, 1);
            product.value.images.splice(destination, 0, media);
        };
        const validationError = () => {
            if (
                !form.value.category_public_id ||
                !form.value.name.trim() ||
                !form.value.slug.trim() ||
                !form.value.regular_price_dt
            )
                return 'Complétez les champs obligatoires.';
            if (
                variantMode.value &&
                (!groups.value.length ||
                    groups.value.some((group) => !group.name.trim()) ||
                    !variants.value.length)
            )
                return 'Ajoutez les options puis générez les variantes.';
            if (
                queued.value.some(
                    (media) =>
                        media.role === 'variant' && media.variantIndex === '',
                )
            )
                return 'Choisissez une variante pour chaque image de variante.';
            if (
                product.value?.images.some(
                    (image) => image.role === 'variant' && !image.variantIndex,
                )
            )
                return 'Choisissez une variante pour chaque image de variante.';
            const primaryImages = [
                ...(product.value?.images || []),
                ...queued.value,
            ].filter((image) => image.role === 'primary');
            if (primaryImages.length > 1)
                return 'Choisissez une seule image principale.';
            if (!isNew.value && !product.value)
                return 'Rechargez le produit avant de l’enregistrer.';
            return '';
        };
        const productFields = () => ({
            category_public_id: form.value.category_public_id,
            name: form.value.name.trim(),
            slug: form.value.slug.trim(),
            meta_catalog_id: form.value.meta_catalog_id.trim() || null,
            meta_catalog_id_confirmation: form.value.meta_catalog_id_confirmation,
            short_description: form.value.short_description || null,
            full_description: form.value.full_description || null,
            regular_price_millimes: millimes(form.value.regular_price_dt),
            promotional_price_millimes:
                form.value.promotion && form.value.promotional_price_dt
                    ? millimes(form.value.promotional_price_dt)
                    : null,
            is_active: form.value.is_active,
            seo_title: form.value.seo_title || null,
            seo_description: form.value.seo_description || null,
            stock_quantity: variantMode.value ? null : form.value.stock_quantity,
            low_stock_threshold: variantMode.value
                ? null
                : form.value.stock_alert
                  ? form.value.low_stock_threshold
                  : null,
        });
        const galleryPayload = () => [
            ...(product.value?.images || []).map((image) => ({
                existing_public_id: image.public_id,
                upload_key: null,
                alt_text: image.alt_text || null,
                role: image.role || 'secondary',
                variant_index:
                    image.role === 'variant' && image.variantIndex !== ''
                        ? Number(image.variantIndex)
                        : null,
            })),
            ...queued.value.map((media) => ({
                existing_public_id: null,
                upload_key: media.id,
                alt_text: media.alt || null,
                role: media.role,
                variant_index:
                    media.role === 'variant' && media.variantIndex !== ''
                        ? Number(media.variantIndex)
                        : null,
            })),
        ];
        const saveEditor = async () => {
            const payload = {
                ...productFields(),
                product_public_id: product.value?.public_id || null,
                lock_version: product.value?.lock_version || null,
                has_variants: variantMode.value,
                ...(variantMode.value
                    ? variantPayload()
                    : { option_groups: [], variants: [] }),
                gallery: galleryPayload(),
            };
            const request = new FormData();
            request.append('payload', JSON.stringify(payload));
            queued.value.forEach((media) => {
                request.append(`uploads[${media.id}]`, media.file);
            });
            const response = await fetch('/api/v1/admin/products/editor-save', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: request,
            });
            if (!response.ok) {
                if (response.status === 413) {
                    throw new Error('Le serveur bloque ce fichier avant son traitement. Vérifiez la limite d’envoi Nginx.');
                }
                throw new Error(await errorFromResponse(response, 'Enregistrement impossible.'));
            }
            return (await response.json()) as { data: Product };
        };
        const save = async () => {
            error.value = validationError();
            notice.value = '';
            if (error.value) {
                showError(error.value);
                error.value = '';
                return;
            }
            saving.value = true;
            try {
                const primaryPreview = queued.value.find((media) => media.role === 'primary')?.preview || null;
                const savedProduct = (await saveEditor()).data;
                const pendingPrimaryImage = savedProduct.images.some(
                    (image) => image.is_primary && image.processing_status === 'pending',
                );
                queued.value.forEach((media) => {
                    if (media.preview !== primaryPreview || !pendingPrimaryImage) {
                        URL.revokeObjectURL(media.preview);
                    }
                });
                queued.value = [];
                hydrate(savedProduct);
                showToast(
                    product.value?.images.some((image) => image.is_primary)
                        ? 'success'
                        : 'warning',
                    product.value?.images.some((image) => image.is_primary)
                        ? 'Produit enregistré.'
                        : 'Produit enregistré. Ajoutez une image principale pour éviter l’image de remplacement.',
                );
                const destination = router.resolve(safeReturnTo.value);
                const mediaPreview: ProductListMediaPreview | undefined = primaryPreview && pendingPrimaryImage
                    ? { product_public_id: savedProduct.public_id, preview_url: primaryPreview }
                    : undefined;
                await router.push({
                    path: destination.path,
                    query: destination.query,
                    hash: destination.hash,
                    ...(mediaPreview ? { state: { productMediaPreview: mediaPreview } } : {}),
                });
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Enregistrement impossible.');
            } finally {
                saving.value = false;
            }
        };
        watch(
            [form, groups, variants, queued, product, variantMode],
            () => {
                if (!hydrating.value && !saving.value) dirty.value = true;
            },
            { deep: true },
        );
        const beforeUnload = (event: BeforeUnloadEvent) => {
            if (!dirty.value) return;
            event.preventDefault();
            event.returnValue = '';
        };
        onMounted(load);
        onMounted(() => window.addEventListener('beforeunload', beforeUnload));
        onBeforeUnmount(() =>
            queued.value.forEach((media) => URL.revokeObjectURL(media.preview)),
        );
        onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload));
        onBeforeRouteLeave(() => {
            if (!dirty.value || saving.value) return true;
            return window.confirm('Abandonner les modifications non enregistrées ?');
        });
        return {
            isNew,
            product,
            categories,
            loading,
            saving,
            error,
            notice,
            variantMode,
            groups,
            variants,
            queued,
            form,
            totalStock,
            variantLabel,
            mediaUrl,
            regenerate,
            addGroup,
            setDefaultVariant,
            toggleVariants,
            addMedia,
            removeQueued,
            moveQueued,
            updateMedia,
            removeMedia,
            moveMedia,
            save,
            safeReturnTo,
        };
    },
    template:
        '<section class="admin-page product-editor"><header class="editor-toolbar"><RouterLink class="back-button" to="/products">← Produits</RouterLink><div><p class="admin-eyebrow">{{ isNew ? \'Nouveau produit\' : \'Modifier le produit\' }}</p><h1>{{ isNew ? \'Créer un produit\' : form.name }}</h1></div><label class="visibility-control"><input v-model="form.is_active" type="checkbox"><span>Visible dans la boutique</span></label></header><p v-if="loading" class="admin-loading">Chargement…</p><template v-else><p v-if="error" class="admin-alert" role="alert">{{ error }}</p><p v-if="notice" class="admin-notice" role="status">{{ notice }}</p><form class="product-form" @submit.prevent="save"><section><h2>Informations</h2><div class="form-grid"><label>Nom <b>*</b><input v-model="form.name" required></label><label>Catégorie <b>*</b><select v-model="form.category_public_id" required><option value="">Choisir</option><option v-for="category in categories" :key="category.public_id" :value="category.public_id">{{ category.name }}</option></select></label><label>Slug <b>*</b><input v-model="form.slug" required><small>Mot court utilisé dans l’adresse, par exemple « huile-argan ».</small></label><label class="full">Description courte<textarea v-model="form.short_description"></textarea></label><label class="full">Description complète<textarea v-model="form.full_description"></textarea></label></div></section><section><h2>Tarification</h2><div class="form-grid"><label>Prix normal <b>*</b><span class="price-input"><input v-model="form.regular_price_dt" required inputmode="decimal"><em>DT</em></span></label><label class="switch-row"><span><strong>En promotion</strong><small>Afficher un prix réduit.</small></span><input v-model="form.promotion" type="checkbox" role="switch"></label><label v-if="form.promotion">Prix promotionnel <span class="price-input"><input v-model="form.promotional_price_dt" inputmode="decimal"><em>DT</em></span></label></div></section><section class="media-section"><div class="section-heading"><div><h2>Médias</h2><p>Ajoutez les images dès la création, puis choisissez leur rôle.</p></div><label class="admin-action upload-control"><input type="file" multiple accept="image/jpeg,image/png,image/webp" @change="addMedia">Ajouter des images</label></div><div v-if="!queued.length && !product?.images.length" class="admin-empty">Aucune image ajoutée.</div><div class="media-grid"><article v-for="item in queued" :key="item.id" class="media-card"><img :src="item.preview" :alt="item.alt"><label>Rôle<select v-model="item.role"><option value="primary">Image principale</option><option value="secondary">Galerie secondaire</option><option v-if="variantMode" value="variant">Image de variante</option></select></label><label v-if="item.role === \'variant\'">Variante<select v-model="item.variantIndex"><option value="">Choisir</option><option v-for="(variant, index) in variants" :key="index" :value="String(index)">{{ variantLabel(variant) }}</option></select></label><label>Texte alternatif<input v-model="item.alt"></label><button class="list-action danger" type="button" @click="removeQueued(item)">Retirer</button></article><article v-for="image in product?.images" :key="image.public_id" class="media-card"><img v-if="mediaUrl(image)" :src="mediaUrl(image)" :alt="image.alt_text || form.name"><div v-else class="media-processing" :class="{ \'is-failed\': image.processing_status === \'failed\' }">{{ image.processing_status === \'failed\' ? \'Traitement impossible. Retirez puis ajoutez de nouveau l’image.\' : \'Image enregistrée — traitement en cours.\' }}</div><label>Rôle<select v-model="image.role" @change="updateMedia(image)"><option value="primary">Image principale</option><option value="secondary">Galerie secondaire</option><option v-if="variantMode" value="variant">Image de variante</option></select></label><label v-if="image.role === \'variant\'">Variante<select v-model="image.variant_public_id" @change="updateMedia(image)"><option value="">Choisir</option><option v-for="variant in product?.variants" :key="variant.public_id" :value="variant.public_id">{{ variant.values.map(value => value.value).join(\' / \') }}</option></select></label><label>Texte alternatif<input v-model="image.alt_text" @change="updateMedia(image)"></label><button class="list-action danger" type="button" @click="removeMedia(image)">Retirer</button></article></div></section><section><div class="switch-panel"><div><h2>Stock et variantes</h2><p>{{ variantMode ? \'Un stock distinct pour chaque déclinaison.\' : \'Un seul stock pour ce produit.\' }}</p></div><label class="mode-switch"><input :checked="variantMode" type="checkbox" role="switch" :disabled="saving" @change="toggleVariants"><span>Ce produit possède des variantes</span></label></div><div v-if="!variantMode" class="form-grid"><label>Stock <b>*</b><input v-model.number="form.stock_quantity" type="number" min="0"></label><label class="switch-row"><span><strong>Alerte stock faible</strong></span><input v-model="form.stock_alert" type="checkbox" role="switch"></label><label v-if="form.stock_alert">Seuil<input v-model.number="form.low_stock_threshold" type="number" min="0"></label></div><div v-else class="variant-workspace"><p class="stock-summary">Stock total vendable <strong>{{ totalStock }} unités</strong></p><div class="option-list"><article v-for="(group, index) in groups" :key="index"><label>Option <input v-model="group.name" placeholder="Couleur"></label><label>Valeurs <input v-model="group.valuesText" placeholder="Rose, Nude" @change="regenerate"></label><button class="list-action danger" type="button" @click="groups.splice(index,1); regenerate()">Retirer</button></article></div><div class="variant-actions"><button class="list-action" type="button" :disabled="groups.length >= 5" @click="addGroup">Ajouter une option</button><button class="list-action primary" type="button" @click="regenerate">Générer les variantes</button></div><div v-if="variants.length" class="variant-cards"><article v-for="variant in variants" :key="variant.keys.join(\'|\')"><strong>{{ variantLabel(variant) }}</strong><label>Stock <input v-model.number="variant.stock_quantity" type="number" min="0"></label><label>Seuil <input v-model.number="variant.low_stock_threshold" type="number" min="0"></label><label class="inline-check"><input v-model="variant.is_active" type="checkbox"> Active</label></article></div><p v-else class="admin-empty">Ajoutez une option et ses valeurs, puis générez les variantes.</p></div></section><details><summary>Référencement</summary><div class="form-grid"><label>Titre SEO<input v-model="form.seo_title"></label><label>Description SEO<textarea v-model="form.seo_description"></textarea></label></div></details><footer class="sticky-actions"><span>{{ saving ? \'Enregistrement…\' : \'Prêt à enregistrer\' }}</span><RouterLink class="list-action" to="/products">Annuler</RouterLink><button class="admin-action" :disabled="saving">{{ isNew ? \'Créer le produit\' : \'Enregistrer\' }}</button></footer></form></template></section>',
};
const productTemplate = ProductEditorView.template as string;
ProductEditorView.template = productTemplate
    .replace(
        '<label class="full">Description courte',
        '<label>Identifiant catalogue Meta<input v-model.trim="form.meta_catalog_id" maxlength="120" placeholder="Ex. 100"><small>Catalogue Meta : {{ form.meta_catalog_id ? \'configuré\' : \'non configuré\' }}</small></label><label v-if="product?.meta_catalog_id && product.meta_catalog_id !== form.meta_catalog_id" class="inline-check"><input v-model="form.meta_catalog_id_confirmation" type="checkbox"> Je confirme le remplacement de l’identifiant existant</label><label class="full">Description courte',
    )
    .replace(
        '<label>Stock <input v-model.number="variant.stock_quantity" type="number" min="0"></label>',
        '<label>SKU<input v-model.trim="variant.sku" maxlength="80"></label><label>Stock <input v-model.number="variant.stock_quantity" type="number" min="0"></label>',
    )
    .replace('v-for="item in queued"', 'v-for="(item, queueIndex) in queued"')
    .replace(
        '<button class="list-action danger" type="button" @click="removeQueued(item)">Retirer</button>',
        '<div class="media-order-actions"><button class="list-action" type="button" :disabled="queueIndex === 0" @click="moveQueued(queueIndex, -1)">↑</button><button class="list-action" type="button" :disabled="queueIndex === queued.length - 1" @click="moveQueued(queueIndex, 1)">↓</button><button class="list-action danger" type="button" @click="removeQueued(item)">Retirer</button></div>',
    )
    .replace('v-for="image in product?.images"', 'v-for="(image, imageIndex) in product?.images"')
    .replace(
        '<button class="list-action danger" type="button" @click="removeMedia(image)">Retirer</button>',
        '<div class="media-order-actions"><button class="list-action" type="button" :disabled="imageIndex === 0" @click="moveMedia(imageIndex, -1)">↑</button><button class="list-action" type="button" :disabled="imageIndex === product?.images.length - 1" @click="moveMedia(imageIndex, 1)">↓</button><button class="list-action danger" type="button" @click="removeMedia(image)">Retirer</button></div>',
    );

ProductEditorView.template = (ProductEditorView.template as string)
    .replaceAll('to="/products"', ':to="safeReturnTo"')
    .replace(
        '<label>Valeurs <input v-model="group.valuesText" placeholder="Rose, Nude" @change="regenerate"></label>',
        '<label>Valeurs <input v-model="group.valuesText" :placeholder="index === 0 ? \'100 ml, 250 ml\' : \'Rouge @ 100 ml, Noir @ 250 ml\'" @change="regenerate"><small v-if="index > 0">Pour une valeur dépendante, indiquez « valeur @ valeur parente ». Les valeurs non liées restent disponibles pour tous les choix.</small></label>',
    )
    .replace(
        '<label>Seuil <input v-model.number="variant.low_stock_threshold" type="number" min="0"></label><label class="inline-check"><input v-model="variant.is_active" type="checkbox"> Active</label>',
        '<label>Prix de la combinaison <span class="price-input"><input v-model="variant.regular_price_dt" inputmode="decimal" placeholder="Prix du produit"><em>DT</em></span><small>Vide : prix principal.</small></label><label>Prix promo <span class="price-input"><input v-model="variant.promotional_price_dt" inputmode="decimal" placeholder="Optionnel"><em>DT</em></span></label><label>Seuil <input v-model.number="variant.low_stock_threshold" type="number" min="0"></label><label class="inline-check"><input v-model="variant.is_active" type="checkbox"> Active</label><label class="inline-check"><input :checked="variant.is_default" type="radio" name="default-variant" @change="setDefaultVariant(variant)"> Variante par défaut</label>',
    );

export default ProductEditorView;
