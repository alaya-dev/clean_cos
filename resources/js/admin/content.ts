import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
    type Component,
} from 'vue';
import { adminApi } from './api';
import SelectControl from './select-control';
import { showError, showToast } from './feedback';
import '../../css/admin-content.css';

type Ordered = { public_id: string; sort_order: number };
type Hero = Ordered & {
    admin_label: string;
    eyebrow: string | null;
    heading: string;
    supporting_text: string | null;
    cta_label: string | null;
    cta_url: string | null;
    is_active: boolean;
    desktop_image_path: string | null;
    mobile_image_path: string | null;
};
type Section = Ordered & {
    type: string;
    eyebrow: string | null;
    title: string;
    description: string | null;
    is_active: boolean;
    filters_enabled: boolean;
    products: Product[];
};
type Product = {
    public_id: string;
    name: string;
    is_active: boolean;
    regular_price_millimes: number;
    promotional_price_millimes?: number | null;
    stock_quantity: number | null;
    active_variant_stock_quantity?: number | null;
    has_variants: boolean;
    category?: Category;
    images?: Array<{ path: string; processing_status: string }>;
};
type Category = { public_id: string; name: string };
type PaginatedProducts = { data: Product[]; last_page: number };
type Tile = Ordered & {
    label: string;
    is_active: boolean;
    desktop_image_path: string | null;
    mobile_image_path: string | null;
    category: Category;
};
type SocialItem = Ordered & {
    url: string;
    alt_text: string;
    image_path: string | null;
    is_active: boolean;
};
type Reassurance = Ordered & {
    icon_key: string;
    title: string;
    text: string;
    is_active: boolean;
};
type Editorial = {
    id?: number;
    eyebrow: string;
    heading: string;
    description: string;
    cta_label: string;
    cta_url: string;
    image_path?: string | null;
    is_active: boolean;
    products?: Product[];
};
type Brand = {
    id?: number;
    heading: string;
    content: string;
    is_active: boolean;
};
type StoreForm = {
    phone: string;
    email: string;
    address: string;
    whatsapp_url: string;
    announcement_text: string;
    footer_statement: string;
    hero_autoplay_enabled: boolean;
    social_links: Record<string, string>;
};
const emptySocialLinks = () => ({ instagram: '', facebook: '' });

const ContentView: Component = {
    components: { SelectControl },
    setup() {
        const mode = ref('store');
        const loading = ref(true);
        const saving = ref(false);
        const dirty = ref(false);
        const heroes = ref<Hero[]>([]);
        const sections = ref<Section[]>([]);
        const products = ref<Product[]>([]);
        const categories = ref<Category[]>([]);
        const tiles = ref<Tile[]>([]);
        const social = ref<SocialItem[]>([]);
        const reassurance = ref<Reassurance[]>([]);
        const heroEditing = ref<Hero | 'new' | null>(null);
        const sectionEditing = ref<Section | 'new' | null>(null);
        const sectionProductSearch = ref('');
        const sectionProductCategory = ref('');
        const editorialProductSearch = ref('');
        const editorialProductCategory = ref('');
        const tileEditing = ref<Tile | null>(null);
        const socialEditing = ref<SocialItem | null>(null);
        const reassuranceEditing = ref<Reassurance | null>(null);
        const modeOptions = [
            { value: 'store', label: 'Coordonnées et pied de page' },
            { value: 'heroes', label: 'Diapositives héro' },
            { value: 'sections', label: 'Sections de produits' },
            { value: 'tiles', label: 'Grandes tuiles catégories' },
            { value: 'editorial', label: 'Section des incontournables' },
            { value: 'reassurance', label: 'Réassurance' },
            { value: 'social', label: 'Galerie sociale' },
            { value: 'brand', label: 'Marque et SEO' },
        ];
        const sectionGuidance: Record<
            string,
            { place: string; title: string; description: string }
        > = {
            store: {
                place: 'Barre d’annonce, en-tête et pied de page',
                title: 'Informations permanentes du magasin',
                description:
                    'Coordonnées, message d’annonce et liens de réseaux sociaux visibles sur toutes les pages.',
            },
            heroes: {
                place: '3. Carrousel principal',
                title: 'Première impression de l’accueil',
                description:
                    'Grand visuel avec message et appel à l’action. Limitez-vous aux campagnes réellement actives.',
            },
            sections: {
                place: '5 et 6. Introduction puis grille produits',
                title: 'Produits mis en avant',
                description:
                    'Nouveautés, meilleures ventes ou sélection manuelle dans la grille de l’accueil.',
            },
            tiles: {
                place: '7. Grandes tuiles catégories',
                title: 'Entrées visuelles vers le catalogue',
                description:
                    'Trois grandes catégories destinées à la découverte après les produits vedettes.',
            },
            editorial: {
                place: '8. Les incontournables',
                title: 'Sélection éditoriale',
                description:
                    'Un récit court, une image et quelques produits compacts, après les grandes catégories.',
            },
            reassurance: {
                place: '9. Réassurance',
                title: 'Engagements du magasin',
                description:
                    'Quatre informations concrètes et vérifiables avant la galerie sociale.',
            },
            social: {
                place: '10. Galerie sociale',
                title: 'Inspiration visuelle',
                description:
                    'Images carrées liées à vos réseaux, sans intégrer de flux externe lourd.',
            },
            brand: {
                place: '11. Marque et SEO',
                title: 'Texte de marque',
                description:
                    'Contenu éditorial et liens internes, juste avant le pied de page sombre.',
            },
        };
        const sectionTypeOptions = [
            { value: 'new_products', label: 'Nouveaux produits' },
            { value: 'best_sellers', label: 'Meilleures ventes' },
            { value: 'curated', label: 'Sélection préparée' },
            { value: 'custom', label: 'Sélection manuelle' },
        ];
        const iconOptions = [
            { value: 'ingredients_naturels', label: 'Ingrédients naturels', file: 'ingredients-naturels.webp' },
            { value: 'teste_dermatologiquement', label: 'Testé dermatologiquement', file: 'teste-dermatologiquement.webp' },
            { value: 'livraison_rapide', label: 'Livraison rapide', file: 'livraison-rapide.webp' },
            { value: 'paiement_livraison', label: 'Paiement à la livraison', file: 'paiement-livraison.webp' },
        ];
        const store = ref<StoreForm>({
            phone: '',
            email: '',
            address: '',
            whatsapp_url: '',
            announcement_text: '',
            footer_statement: '',
            hero_autoplay_enabled: true,
            social_links: emptySocialLinks(),
        });
        const heroForm = ref({
            admin_label: '',
            eyebrow: '',
            heading: '',
            supporting_text: '',
            cta_label: '',
            cta_url: '',
            is_active: false,
            sort_order: 0,
            desktop_image: null as File | null,
            mobile_image: null as File | null,
        });
        const sectionForm = ref({
            type: 'custom',
            eyebrow: '',
            title: '',
            description: '',
            is_active: true,
            filters_enabled: false,
            product_public_ids: [] as string[],
        });
        const tileForm = ref({
            category_public_id: '',
            label: '',
            is_active: true,
            desktop_image: null as File | null,
            mobile_image: null as File | null,
        });
        const socialForm = ref({
            url: '',
            alt_text: '',
            is_active: true,
            image: null as File | null,
        });
        const reassuranceForm = ref({
            icon_key: 'ingredients_naturels',
            title: '',
            text: '',
            is_active: true,
        });
        const editorial = ref<Editorial>({
            eyebrow: '',
            heading: '',
            description: '',
            cta_label: '',
            cta_url: '',
            is_active: true,
            products: [],
        });
        const editorialImage = ref<File | null>(null);
        const editorialProducts = ref<string[]>([]);
        const brand = ref<Brand>({ heading: '', content: '', is_active: true });

        const loadedModes = new Set<string>();
        const loadSelectableProducts = async (): Promise<Product[]> => {
            const firstPage = await adminApi<{ data: PaginatedProducts }>(
                'products?per_page=100&sort=name',
            );
            const pages = Array.from(
                { length: Math.max(0, firstPage.data.last_page - 1) },
                (_, index) => index + 2,
            );
            const remainingPages = await Promise.all(
                pages.map((page) =>
                    adminApi<{ data: PaginatedProducts }>(
                        `products?per_page=100&sort=name&page=${page}`,
                    ),
                ),
            );

            return [
                ...firstPage.data.data,
                ...remainingPages.flatMap((response) => response.data.data),
            ];
        };
        const load = async (targetMode = mode.value, force = false) => {
            if (!force && loadedModes.has(targetMode)) return;
            loading.value = true;
            try {
                if (targetMode === 'store') {
                    const payload = await adminApi<{ data: StoreForm }>(
                        'settings/store',
                    );
                    store.value = {
                        ...payload.data,
                        social_links: {
                            ...emptySocialLinks(),
                            ...(payload.data.social_links || {}),
                        },
                    };
                }
                if (targetMode === 'heroes')
                    heroes.value = (
                        await adminApi<{ data: Hero[] }>('content/banners')
                    ).data;
                if (targetMode === 'sections') {
                    const [sectionPayload, productPayload, categoryPayload] =
                        await Promise.all([
                            adminApi<{ data: Section[] }>(
                                'content/homepage-sections',
                            ),
                            loadSelectableProducts(),
                            adminApi<{ data: { data: Category[] } }>(
                                'categories?per_page=100&is_active=1',
                            ),
                        ]);
                    sections.value = sectionPayload.data;
                    products.value = productPayload;
                    categories.value = categoryPayload.data.data;
                }
                if (targetMode === 'tiles') {
                    const [tilePayload, categoryPayload] = await Promise.all([
                        adminApi<{ data: Tile[] }>(
                            'content/items/visual-tiles',
                        ),
                        adminApi<{ data: { data: Category[] } }>(
                            'categories?per_page=100',
                        ),
                    ]);
                    tiles.value = tilePayload.data.map((tile) => ({
                        ...tile,
                        category: tile.category ?? { public_id: '', name: 'Catégorie supprimée' },
                    }));
                    categories.value = categoryPayload.data.data;
                }
                if (targetMode === 'editorial') {
                    const [editorialPayload, productPayload, categoryPayload] =
                        await Promise.all([
                            adminApi<{ data: Editorial[] }>(
                                'content/items/editorial',
                            ),
                            loadSelectableProducts(),
                            adminApi<{ data: { data: Category[] } }>(
                                'categories?per_page=100&is_active=1',
                            ),
                        ]);
                    products.value = productPayload;
                    categories.value = categoryPayload.data.data;
                    if (editorialPayload.data[0]) {
                        editorial.value = editorialPayload.data[0];
                        editorialProducts.value =
                            editorialPayload.data[0].products?.map(
                                (product) => product.public_id,
                            ) || [];
                    }
                }
                if (targetMode === 'reassurance')
                    reassurance.value = (
                        await adminApi<{ data: Reassurance[] }>(
                            'content/items/reassurance',
                        )
                    ).data;
                if (targetMode === 'social')
                    social.value = (
                        await adminApi<{ data: SocialItem[] }>(
                            'content/items/social',
                        )
                    ).data;
                if (targetMode === 'brand') {
                    const payload = await adminApi<{ data: Brand[] }>(
                        'content/items/brand',
                    );
                    if (payload.data[0]) brand.value = payload.data[0];
                }
                loadedModes.add(targetMode);
                dirty.value = false;
            } catch (cause) {
                showError(
                    cause instanceof Error
                        ? cause.message
                        : 'Chargement impossible.',
                );
            } finally {
                loading.value = false;
            }
        };
        const markDirty = () => {
            dirty.value = true;
        };
        const saveStore = async () =>
            save(
                'settings/store',
                'PATCH',
                {
                    ...store.value,
                    social_links: {
                        instagram: store.value.social_links.instagram || null,
                        facebook: store.value.social_links.facebook || null,
                    },
                },
                'Contenu du magasin enregistré.',
            );
        const save = async (
            path: string,
            method: string,
            body: unknown,
            message: string,
        ): Promise<boolean> => {
            saving.value = true;
            try {
                await adminApi(path, method, body);
                showToast('success', message);
                dirty.value = false;
                await load(mode.value, true);
                return true;
            } catch (cause) {
                showError(
                    cause instanceof Error
                        ? cause.message
                        : 'Enregistrement impossible.',
                );
                return false;
            } finally {
                saving.value = false;
            }
        };
        const asFormData = (values: Record<string, unknown>) => {
            const body = new FormData();
            Object.entries(values).forEach(([key, value]) => {
                if (value instanceof File) body.append(key, value);
                else if (Array.isArray(value))
                    value.forEach((item) =>
                        body.append(`${key}[]`, String(item)),
                    );
                else if (value !== null && value !== undefined)
                    body.append(
                        key,
                        typeof value === 'boolean'
                            ? value
                                ? '1'
                                : '0'
                            : String(value),
                    );
            });
            return body;
        };
        const imageUrl = (path: string | null | undefined) =>
            path ? `/storage/${path}` : '';
        const chooseFile = (
            event: Event,
            assign: (file: File | null) => void,
        ) => {
            assign((event.target as HTMLInputElement).files?.[0] || null);
            markDirty();
        };
        const scrollToEditor = (selector: string) => {
            void nextTick(() =>
                document
                    .querySelector<HTMLElement>(selector)
                    ?.scrollIntoView({
                        behavior: window.matchMedia(
                            '(prefers-reduced-motion: reduce)',
                        ).matches
                            ? 'auto'
                            : 'smooth',
                        block: 'start',
                    }),
            );
        };
        const openHero = (hero?: Hero) => {
            heroEditing.value = hero || 'new';
            heroForm.value = hero
                ? {
                      admin_label: hero.admin_label,
                      eyebrow: hero.eyebrow || '',
                      heading: hero.heading,
                      supporting_text: hero.supporting_text || '',
                      cta_label: hero.cta_label || '',
                      cta_url: hero.cta_url || '',
                      is_active: hero.is_active,
                      sort_order: hero.sort_order,
                      desktop_image: null,
                      mobile_image: null,
                  }
                : {
                      admin_label: '',
                      eyebrow: '',
                      heading: '',
                      supporting_text: '',
                      cta_label: '',
                      cta_url: '',
                      is_active: false,
                      sort_order: heroes.value.length,
                      desktop_image: null,
                      mobile_image: null,
                  };
            dirty.value = false;
            scrollToEditor('.content-mode-body .category-form');
        };
        const saveHero = async () => {
            const existing =
                typeof heroEditing.value === 'object'
                    ? heroEditing.value
                    : null;
            if (
                await save(
                    existing
                        ? `content/banners/${existing.public_id}`
                        : 'content/banners',
                    'POST',
                    asFormData(heroForm.value),
                    'Diapositive enregistrée.',
                )
            )
                heroEditing.value = null;
        };
        const deleteHero = async (hero: Hero) => {
            if (
                !window.confirm(
                    `Supprimer définitivement la diapositive « ${hero.admin_label} » ?`,
                )
            )
                return;
            if (
                await save(
                    `content/banners/${hero.public_id}`,
                    'DELETE',
                    undefined,
                    'Diapositive supprimée.',
                )
            ) {
                const editing = heroEditing.value;
                if (
                    editing &&
                    typeof editing === 'object' &&
                    editing.public_id === hero.public_id
                )
                    heroEditing.value = null;
            }
        };
        const openSection = (section?: Section) => {
            sectionEditing.value = section || 'new';
            sectionProductSearch.value = '';
            sectionProductCategory.value = '';
            sectionForm.value = section
                ? {
                      type: section.type,
                      eyebrow: section.eyebrow || '',
                      title: section.title,
                      description: section.description || '',
                      is_active: section.is_active,
                      filters_enabled: section.filters_enabled,
                      product_public_ids: section.products.map(
                          (product) => product.public_id,
                      ),
                  }
                : {
                      type: 'custom',
                      eyebrow: '',
                      title: '',
                      description: '',
                      is_active: true,
                      filters_enabled: false,
                      product_public_ids: [],
                  };
            dirty.value = false;
            scrollToEditor('.section-editor');
        };
        const closeSection = () => {
            if (saving.value) return;
            sectionEditing.value = null;
            sectionProductSearch.value = '';
            sectionProductCategory.value = '';
            dirty.value = false;
        };
        const saveSection = async () => {
            const existing =
                typeof sectionEditing.value === 'object'
                    ? sectionEditing.value
                    : null;
            if (
                await save(
                    existing
                        ? `content/homepage-sections/${existing.public_id}`
                        : 'content/homepage-sections',
                    existing ? 'PATCH' : 'POST',
                    {
                        ...sectionForm.value,
                        sort_order:
                            existing?.sort_order ?? sections.value.length,
                    },
                    'Section enregistrée.',
                )
            )
                closeSection();
        };
        const deleteSection = async (section: Section) => {
            if (
                !window.confirm(
                    `Supprimer définitivement la section « ${section.title} » ?`,
                )
            )
                return;
            if (
                await save(
                    `content/homepage-sections/${section.public_id}`,
                    'DELETE',
                    undefined,
                    'Section supprimée.',
                )
            ) {
                const editing = sectionEditing.value;
                if (
                    editing &&
                    typeof editing === 'object' &&
                    editing.public_id === section.public_id
                )
                    closeSection();
            }
        };
        const openTile = (tile: Tile) => {
            if (!tile.category?.public_id) {
                showError('Cette tuile fait référence à une catégorie qui n’existe plus. Supprimez-la puis recréez-la.');
                return;
            }
            tileEditing.value = tile;
            tileForm.value = {
                category_public_id: tile.category.public_id,
                label: tile.label,
                is_active: tile.is_active,
                desktop_image: null,
                mobile_image: null,
            };
            scrollToEditor('.content-mode-body .category-form');
        };
        const createTile = async () => {
            const editing = tileEditing.value;
            if (
                await save(
                    editing
                        ? `content/items/visual-tiles/${editing.public_id}`
                        : 'content/items/visual-tiles',
                    'POST',
                    asFormData({
                        ...tileForm.value,
                        sort_order: editing?.sort_order ?? tiles.value.length,
                    }),
                    editing ? 'Tuile mise à jour.' : 'Tuile ajoutée.',
                )
            ) {
                tileEditing.value = null;
                tileForm.value = {
                    category_public_id: '',
                    label: '',
                    is_active: true,
                    desktop_image: null,
                    mobile_image: null,
                };
            }
        };
        const openSocial = (item: SocialItem) => {
            socialEditing.value = item;
            socialForm.value = {
                url: item.url,
                alt_text: item.alt_text,
                is_active: item.is_active,
                image: null,
            };
            scrollToEditor('.content-mode-body .category-form');
        };
        const createSocial = async () => {
            const editing = socialEditing.value;
            if (
                await save(
                    editing
                        ? `content/items/social/${editing.public_id}`
                        : 'content/items/social',
                    'POST',
                    asFormData({
                        ...socialForm.value,
                        sort_order: editing?.sort_order ?? social.value.length,
                    }),
                    editing
                        ? 'Image sociale mise à jour.'
                        : 'Image sociale ajoutée.',
                )
            ) {
                socialEditing.value = null;
                socialForm.value = {
                    url: '',
                    alt_text: '',
                    is_active: true,
                    image: null,
                };
            }
        };
        const openReassurance = (item: Reassurance) => {
            reassuranceEditing.value = item;
            reassuranceForm.value = {
                icon_key: item.icon_key,
                title: item.title,
                text: item.text,
                is_active: item.is_active,
            };
            scrollToEditor('.content-mode-body .category-form');
        };
        const createReassurance = async () => {
            const editing = reassuranceEditing.value;
            if (
                await save(
                    editing
                        ? `content/items/reassurance/${editing.public_id}`
                        : 'content/items/reassurance',
                    'POST',
                    {
                        ...reassuranceForm.value,
                        sort_order:
                            editing?.sort_order ?? reassurance.value.length,
                    },
                    editing
                        ? 'Élément mis à jour.'
                        : 'Élément de réassurance ajouté.',
                )
            ) {
                reassuranceEditing.value = null;
                reassuranceForm.value = {
                    icon_key: 'ingredients_naturels',
                    title: '',
                    text: '',
                    is_active: true,
                };
            }
        };
        const saveEditorial = async () => {
            const body = asFormData({
                ...editorial.value,
                image: editorialImage.value,
                product_public_ids: editorialProducts.value,
            });
            if (
                await save(
                    editorial.value.id
                        ? `content/items/editorial/${editorial.value.id}`
                        : 'content/items/editorial',
                    'POST',
                    body,
                    'Section éditoriale enregistrée.',
                )
            )
                editorialImage.value = null;
        };
        const saveBrand = async () =>
            save(
                brand.value.id
                    ? `content/items/brand/${brand.value.id}`
                    : 'content/items/brand',
                'POST',
                brand.value,
                'Contenu de marque enregistré.',
            );
        const updateItem = async (
            type: string,
            item: Ordered & { is_active: boolean },
            values: Record<string, unknown>,
        ) =>
            save(
                `content/items/${type}/${item.public_id}`,
                'POST',
                values,
                'État enregistré.',
            );
        const removeItem = async (type: string, item: Ordered) => {
            if (
                !window.confirm(
                    'Supprimer définitivement cet élément non référencé ?',
                )
            )
                return;
            await save(
                `content/items/${type}/${item.public_id}`,
                'DELETE',
                undefined,
                'Élément supprimé.',
            );
        };
        const move = async (
            type: string,
            items: Ordered[],
            index: number,
            delta: number,
        ) => {
            const target = index + delta;
            if (target < 0 || target >= items.length) return;
            [items[index], items[target]] = [items[target], items[index]];
            await save(
                `content/${type}/reorder`,
                'POST',
                {
                    items: items.map((item, position) => ({
                        public_id: item.public_id,
                        sort_order: position,
                    })),
                },
                'Ordre enregistré.',
            );
        };
        const productName = (publicId: string) =>
            products.value.find((product) => product.public_id === publicId)
                ?.name || 'Produit indisponible';
        const productFor = (publicId: string) =>
            products.value.find((product) => product.public_id === publicId);
        const productPrice = (product: Product) =>
            `${((product.promotional_price_millimes ?? product.regular_price_millimes) / 1000).toFixed(3).replace('.', ',')} DT`;
        const productStock = (product: Product) =>
            product.has_variants
                ? (product.active_variant_stock_quantity ?? 0)
                : (product.stock_quantity ?? 0);
        const productAvailability = (product: Product) =>
            !product.is_active
                ? 'Masqué'
                : productStock(product) > 0
                  ? 'Disponible'
                  : 'Rupture';
        const filteredProducts = (search: string, categoryPublicId: string) => {
            const query = search.trim().toLocaleLowerCase('fr');
            return products.value.filter(
                (product) =>
                    (!query ||
                        product.name.toLocaleLowerCase('fr').includes(query)) &&
                    (!categoryPublicId ||
                        product.category?.public_id === categoryPublicId),
            );
        };
        const visibleSectionProducts = computed(() =>
            filteredProducts(
                sectionProductSearch.value,
                sectionProductCategory.value,
            ),
        );
        const visibleEditorialProducts = computed(() =>
            filteredProducts(
                editorialProductSearch.value,
                editorialProductCategory.value,
            ),
        );
        const selectedSectionProducts = computed(() =>
            sectionForm.value.product_public_ids
                .map(productFor)
                .filter((product): product is Product => !!product),
        );
        const toggleSectionProduct = (publicId: string) => {
            const selected = sectionForm.value.product_public_ids;
            sectionForm.value.product_public_ids = selected.includes(publicId)
                ? selected.filter((id) => id !== publicId)
                : [...selected, publicId];
            dirty.value = true;
        };
        const clearSectionFilters = () => {
            sectionProductSearch.value = '';
            sectionProductCategory.value = '';
        };
        const clearEditorialFilters = () => {
            editorialProductSearch.value = '';
            editorialProductCategory.value = '';
        };
        const toggleEditorialProduct = (publicId: string) => {
            editorialProducts.value = editorialProducts.value.includes(publicId)
                ? editorialProducts.value.filter((id) => id !== publicId)
                : [...editorialProducts.value, publicId];
            dirty.value = true;
        };
        const toggleSection = async (section: Section) => {
            await save(
                `content/homepage-sections/${section.public_id}`,
                'PATCH',
                {
                    type: section.type,
                    eyebrow: section.eyebrow || '',
                    title: section.title,
                    description: section.description || '',
                    is_active: !section.is_active,
                    filters_enabled: section.filters_enabled,
                    product_public_ids: section.products.map(
                        (product) => product.public_id,
                    ),
                    sort_order: section.sort_order,
                },
                section.is_active ? 'Section désactivée.' : 'Section activée.',
            );
        };
        const moveProduct = (
            selected: string[],
            index: number,
            delta: number,
        ) => {
            const target = index + delta;
            if (target < 0 || target >= selected.length) return;
            [selected[index], selected[target]] = [
                selected[target],
                selected[index],
            ];
            dirty.value = true;
        };
        const changeMode = (nextMode: string) => {
            if (nextMode === mode.value) return;
            if (
                dirty.value &&
                !window.confirm(
                    'Des modifications ne sont pas enregistrées. Changer de section les conservera ici, mais elles ne seront pas publiées. Continuer ?',
                )
            )
                return;
            mode.value = nextMode;
        };
        const beforeUnload = (event: BeforeUnloadEvent) => {
            if (!dirty.value) return;
            event.preventDefault();
            event.returnValue = '';
        };
        watch(mode, (nextMode) => {
            void load(nextMode);
        });
        onMounted(() => {
            window.addEventListener('beforeunload', beforeUnload);
            void load();
        });
        onBeforeUnmount(() =>
            window.removeEventListener('beforeunload', beforeUnload),
        );
        return {
            mode,
            modeOptions,
            sectionTypeOptions,
            iconOptions,
            sectionGuidance,
            loading,
            saving,
            store,
            heroes,
            sections,
            products,
            categories,
            tiles,
            social,
            reassurance,
            heroEditing,
            heroForm,
            sectionEditing,
            sectionForm,
            sectionProductSearch,
            sectionProductCategory,
            editorialProductSearch,
            editorialProductCategory,
            visibleSectionProducts,
            visibleEditorialProducts,
            selectedSectionProducts,
            tileEditing,
            socialEditing,
            reassuranceEditing,
            tileForm,
            socialForm,
            reassuranceForm,
            editorial,
            editorialImage,
            editorialProducts,
            brand,
            markDirty,
            saveStore,
            imageUrl,
            chooseFile,
            openHero,
            saveHero,
            deleteHero,
            openSection,
            closeSection,
            saveSection,
            deleteSection,
            toggleSection,
            openTile,
            createTile,
            openSocial,
            createSocial,
            openReassurance,
            createReassurance,
            saveEditorial,
            saveBrand,
            updateItem,
            removeItem,
            move,
            productName,
            productFor,
            productPrice,
            productStock,
            productAvailability,
            toggleSectionProduct,
            toggleEditorialProduct,
            clearSectionFilters,
            clearEditorialFilters,
            moveProduct,
            changeMode,
        };
    },
    template: `<section class="admin-page content-management-page"><header><div><p class="admin-eyebrow">Contenu / Accueil</p><h1>Contenu du magasin</h1><p class="admin-subtitle">Sections structurées, sans constructeur de pages libre.</p></div><a class="admin-outline" href="/" target="_blank" rel="noopener">Aperçu public</a></header><div class="content-workspace"><nav class="content-section-nav" aria-label="Sections du contenu"><button v-for="item in modeOptions" :key="item.value" type="button" :class="{ 'is-selected': mode === item.value }" :aria-current="mode === item.value ? 'page' : undefined" @click="changeMode(item.value)">{{ item.label }}</button></nav><div class="content-mode-body"><div class="content-management-context"><label class="toolbar-select content-mode-select"><span>Section à gérer</span><SelectControl :model-value="mode" :options="modeOptions" @update:model-value="changeMode"/></label></div><p v-if="loading" class="admin-loading">Chargement…</p>
      <form v-else-if="mode === 'store'" class="category-form" @input="markDirty" @submit.prevent="saveStore"><div class="form-grid"><label>Téléphone<input v-model="store.phone"></label><label>E-mail<input v-model="store.email" type="email"></label><label class="full">Adresse<textarea v-model="store.address"></textarea></label><label>WhatsApp HTTPS<input v-model="store.whatsapp_url"></label><label class="full">Message d’annonce<input v-model="store.announcement_text" maxlength="240"></label><label class="full">Déclaration de marque du pied de page<textarea v-model="store.footer_statement"></textarea></label><label>Instagram<input v-model="store.social_links.instagram"></label><label>Facebook<input v-model="store.social_links.facebook"></label><label class="inline-check">Lecture automatique lente du héro <input v-model="store.hero_autoplay_enabled" type="checkbox"></label></div><footer class="sticky-save-bar"><button class="admin-action" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button></footer></form>
      <section v-else-if="mode === 'heroes'" class="hero-slide-manager"><header class="hero-slide-manager__header"><div><p class="admin-eyebrow">Carrousel principal</p><h2>Diapositives héro</h2><p>Gérez les visuels et leur ordre d’apparition sur la page d’accueil.</p></div><button class="admin-action" type="button" @click="openHero()">Nouvelle diapositive</button></header><form v-if="heroEditing" class="category-form" @input="markDirty" @submit.prevent="saveHero"><div class="form-grid"><label>Libellé interne<input v-model="heroForm.admin_label" required></label><label>Sur-titre<input v-model="heroForm.eyebrow"></label><label class="full">Titre<input v-model="heroForm.heading" required></label><label class="full">Texte<textarea v-model="heroForm.supporting_text"></textarea></label><label>Libellé CTA<input v-model="heroForm.cta_label"></label><label>Lien interne ou HTTPS<input v-model="heroForm.cta_url"></label><label>Image bureau<input type="file" accept="image/jpeg,image/png,image/webp" :required="heroEditing === 'new'" @change="chooseFile($event, file => heroForm.desktop_image = file)"></label><label>Image mobile<input type="file" accept="image/jpeg,image/png,image/webp" @change="chooseFile($event, file => heroForm.mobile_image = file)"></label><label class="inline-check">Active <input v-model="heroForm.is_active" type="checkbox"></label></div><p v-if="saving">Traitement sécurisé de l’image…</p><button class="admin-action" :disabled="saving">Enregistrer</button></form><div v-if="heroes.length" class="hero-slide-list" aria-label="Diapositives héro"><article v-for="(hero,index) in heroes" :key="hero.public_id" class="hero-slide-card"><img v-if="hero.desktop_image_path" class="hero-slide-card__image" :src="imageUrl(hero.desktop_image_path)" :alt="hero.heading || hero.admin_label"><span v-else class="hero-slide-card__placeholder" aria-hidden="true">PC</span><div class="hero-slide-card__content"><div class="hero-slide-card__title-row"><span class="hero-slide-card__position">{{ String(index + 1).padStart(2, '0') }}</span><div><h3>{{ hero.admin_label }}</h3><span class="hero-slide-card__type">Image</span></div></div><p class="hero-slide-card__description">{{ hero.supporting_text || 'Visuel du carrousel de la page d’accueil.' }}</p></div><div class="hero-slide-card__status"><span class="admin-badge" :class="hero.is_active ? 'is-published' : 'is-disabled'">{{ hero.is_active ? 'Active' : 'Inactive' }}</span></div><div class="hero-slide-card__actions"><button class="hero-slide-card__icon-action" type="button" :disabled="index === 0" aria-label="Monter la diapositive" @click="move('banners',heroes,index,-1)">↑</button><button class="hero-slide-card__icon-action" type="button" :disabled="index === heroes.length - 1" aria-label="Descendre la diapositive" @click="move('banners',heroes,index,1)">↓</button><button class="hero-slide-card__secondary-action" type="button" @click="openHero(hero)">Modifier</button></div></article></div><section v-else class="admin-empty"><strong>Aucune diapositive héro.</strong><span>Ajoutez un premier visuel pour le carrousel de la page d’accueil.</span></section><p class="hero-slide-list__help">Les flèches modifient l’ordre d’affichage des diapositives sur l’accueil.</p></section>
      <section v-else-if="mode === 'sections'" class="section-management-workspace"><header class="section-management-header"><div><p class="admin-eyebrow">Grille de l’accueil</p><h2>Sections de produits</h2><p>Organisez les sections et composez leurs sélections sans quitter cet espace.</p></div><button class="admin-action" type="button" @click="openSection()">Nouvelle section</button></header><div class="section-management-layout"><ol v-if="sections.length" class="section-management-list" aria-label="Ordre des sections de produits"><li v-for="(section,index) in sections" :key="section.public_id" :class="{ 'is-editing': sectionEditing && sectionEditing !== 'new' && sectionEditing.public_id === section.public_id }"><span class="section-order" aria-hidden="true">{{ String(index + 1).padStart(2, '0') }}</span><div class="section-summary"><strong>{{ section.title }}</strong><small>{{ section.type === 'new_products' ? 'Nouveaux produits' : section.type === 'best_sellers' ? 'Meilleures ventes' : 'Sélection manuelle' }} · {{ section.products.length }} produit{{ section.products.length > 1 ? 's' : '' }}</small></div><span class="admin-badge" :class="section.is_active ? 'status-active' : 'warning'">{{ section.is_active ? 'Visible' : 'Masquée' }}</span><div class="section-row-actions"><button class="admin-icon-button" type="button" :disabled="index === 0" aria-label="Monter la section" @click="move('homepage-sections',sections,index,-1)">↑</button><button class="admin-icon-button" type="button" :disabled="index === sections.length-1" aria-label="Descendre la section" @click="move('homepage-sections',sections,index,1)">↓</button><button class="admin-outline" type="button" @click="openSection(section)">Modifier</button><button class="text-link" type="button" @click="toggleSection(section)">{{ section.is_active ? 'Masquer' : 'Afficher' }}</button><button class="text-link danger" type="button" @click="deleteSection(section)">Supprimer</button></div></li></ol><section v-else class="admin-empty"><strong>Aucune section de produits.</strong><span>Créez une sélection pour faire apparaître une grille sur l’accueil.</span></section><form v-if="sectionEditing" class="section-editor" @input="markDirty" @submit.prevent="saveSection"><header><div><p class="admin-eyebrow">{{ sectionEditing === 'new' ? 'Nouvelle section' : 'Modification' }}</p><h3>{{ sectionEditing === 'new' ? 'Composer la section' : sectionForm.title }}</h3><p>Les changements restent en place si l’enregistrement échoue.</p></div><button class="text-link" type="button" :disabled="saving" @click="closeSection">Fermer</button></header><div class="section-editor-fields"><label class="toolbar-select">Type<SelectControl v-model="sectionForm.type" :options="sectionTypeOptions" /></label><label>Sur-titre<input v-model.trim="sectionForm.eyebrow" maxlength="120" placeholder="Ex. À découvrir"></label><label class="full">Titre<input v-model.trim="sectionForm.title" required maxlength="160" placeholder="Titre visible sur l’accueil"></label><label class="full">Description<textarea v-model.trim="sectionForm.description" rows="3" maxlength="500" placeholder="Une courte introduction facultative"></textarea></label><div class="section-options full"><label class="inline-check">Visible sur l’accueil <input v-model="sectionForm.is_active" type="checkbox"></label><label class="inline-check">Afficher les filtres <input v-model="sectionForm.filters_enabled" type="checkbox"></label></div></div><section class="section-product-composer" aria-labelledby="section-products-title"><header><div><p class="admin-eyebrow">Produits</p><h4 id="section-products-title">Sélection et ordre</h4></div><span class="admin-badge">{{ sectionForm.product_public_ids.length }} sélectionné{{ sectionForm.product_public_ids.length > 1 ? 's' : '' }}</span></header><div class="section-product-columns"><section class="section-product-panel"><header><div><h5>Produits disponibles</h5><p>Ajoutez uniquement les produits pertinents pour cette section.</p></div></header><div class="section-product-filters"><label class="admin-search"><span class="sr-only">Rechercher un produit</span><input v-model.trim="sectionProductSearch" type="search" placeholder="Rechercher un produit…"></label><label class="toolbar-select"><span>Catégorie</span><SelectControl v-model="sectionProductCategory" :options="[{ value: '', label: 'Toutes les catégories' }, ...categories.map(category => ({ value: category.public_id, label: category.name }))]" /></label><button class="text-link" type="button" @click="clearSectionFilters">Réinitialiser</button></div><div class="section-product-options" aria-label="Produits disponibles"><article v-for="product in visibleSectionProducts" :key="product.public_id" class="section-product-option"><img v-if="product.images?.[0]?.path" :src="imageUrl(product.images[0].path)" alt=""><span v-else class="section-product-placeholder" aria-hidden="true">PC</span><div><strong>{{ product.name }}</strong><small>{{ product.category?.name || 'Sans catégorie' }} · {{ productPrice(product) }}</small><small :class="{ 'is-unavailable': productAvailability(product) !== 'Disponible' }">{{ productAvailability(product) }} · {{ productStock(product) }} en stock</small></div><button class="admin-outline" type="button" @click="toggleSectionProduct(product.public_id)">{{ sectionForm.product_public_ids.includes(product.public_id) ? 'Retirer' : 'Ajouter' }}</button></article><p v-if="!visibleSectionProducts.length" class="section-product-empty">Aucun produit ne correspond à votre recherche.</p></div></section><section class="section-product-panel section-product-selected"><header><div><h5>Produits sélectionnés</h5><p>L’ordre ci-dessous est celui de la vitrine.</p></div><span>{{ sectionForm.product_public_ids.length }}</span></header><ol class="section-product-order" aria-label="Ordre des produits sélectionnés"><li v-for="(publicId,index) in sectionForm.product_public_ids" :key="publicId"><span class="section-order" aria-hidden="true">{{ index + 1 }}</span><img v-if="productFor(publicId)?.images?.[0]?.path" :src="imageUrl(productFor(publicId)?.images?.[0]?.path)" alt=""><span v-else class="section-product-placeholder" aria-hidden="true">PC</span><div><strong>{{ productName(publicId) }}</strong><small>{{ productFor(publicId)?.category?.name || 'Sans catégorie' }}</small></div><div class="section-product-actions"><button class="admin-icon-button" type="button" :disabled="index===0" aria-label="Monter ce produit" @click="moveProduct(sectionForm.product_public_ids,index,-1)">↑</button><button class="admin-icon-button" type="button" :disabled="index===sectionForm.product_public_ids.length-1" aria-label="Descendre ce produit" @click="moveProduct(sectionForm.product_public_ids,index,1)">↓</button><button class="text-link danger" type="button" @click="toggleSectionProduct(publicId)">Retirer</button></div></li><li v-if="!sectionForm.product_public_ids.length" class="section-product-empty">Aucun produit sélectionné.</li></ol></section></div></section><footer class="sticky-save-bar"><button class="text-link" type="button" :disabled="saving" @click="closeSection">Annuler</button><button class="admin-action" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer la section' }}</button></footer></form></div></section>
      <div v-else-if="mode === 'tiles'"><form class="category-form" @input="markDirty" @submit.prevent="createTile"><div class="form-grid"><label>Catégorie<SelectControl v-model="tileForm.category_public_id" :options="categories.map(category => ({value:category.public_id,label:category.name}))" /></label><label>Libellé<input v-model="tileForm.label" required></label><label>Image bureau<input type="file" accept="image/jpeg,image/png,image/webp" :required="!tileEditing" @change="chooseFile($event,file => tileForm.desktop_image=file)"></label><label>Image mobile<input type="file" accept="image/jpeg,image/png,image/webp" @change="chooseFile($event,file => tileForm.mobile_image=file)"></label><label class="inline-check">Active <input v-model="tileForm.is_active" type="checkbox"></label></div><button class="admin-action" :disabled="saving">{{ tileEditing ? 'Enregistrer' : 'Ajouter' }}</button></form><div class="admin-table"><article v-for="(tile,index) in tiles" :key="tile.public_id"><img v-if="tile.desktop_image_path" class="admin-image-preview" :src="imageUrl(tile.desktop_image_path)" alt=""><strong>{{ tile.label }}</strong><span>{{ tile.category.name }}</span><button class="text-link" @click="openTile(tile)">Modifier</button><button class="text-link" @click="updateItem('visual-tiles',tile,{is_active:!tile.is_active})">{{ tile.is_active ? 'Désactiver' : 'Activer' }}</button><button class="text-link" @click="move('items/visual-tiles',tiles,index,-1)" :disabled="index===0">Monter</button><button class="text-link" @click="move('items/visual-tiles',tiles,index,1)" :disabled="index===tiles.length-1">Descendre</button><button class="text-link" @click="removeItem('visual-tiles',tile)">Supprimer</button></article></div></div>
      <form v-else-if="mode === 'editorial'" class="category-form editorial-editor" @input="markDirty" @submit.prevent="saveEditorial"><div class="form-grid"><label>Sur-titre<input v-model.trim="editorial.eyebrow" maxlength="160"></label><label>Titre<input v-model.trim="editorial.heading" required maxlength="240"></label><label class="full">Description<textarea v-model.trim="editorial.description" rows="3" maxlength="1500"></textarea></label><label>Libellé CTA<input v-model.trim="editorial.cta_label" maxlength="120"></label><label>Lien interne ou HTTPS<input v-model.trim="editorial.cta_url"></label><label>Image éditoriale<input type="file" accept="image/jpeg,image/png,image/webp" :required="!editorial.id" @change="chooseFile($event, file => editorialImage = file)"></label><img v-if="editorial.image_path" class="admin-image-preview" :src="imageUrl(editorial.image_path)" alt=""><label class="inline-check">Active <input v-model="editorial.is_active" type="checkbox"></label></div><section class="section-product-composer" aria-labelledby="editorial-products-title"><header><div><p class="admin-eyebrow">Produits compacts</p><h4 id="editorial-products-title">Sélection et ordre</h4></div><span class="admin-badge">{{ editorialProducts.length }} sélectionné{{ editorialProducts.length > 1 ? 's' : '' }}</span></header><div class="section-product-columns"><section class="section-product-panel"><header><div><h5>Produits disponibles</h5><p>Choisissez les produits qui accompagnent cet éditorial.</p></div></header><div class="section-product-filters"><label class="admin-search"><span class="sr-only">Rechercher un produit</span><input v-model.trim="editorialProductSearch" type="search" placeholder="Rechercher un produit…"></label><label class="toolbar-select"><span>Catégorie</span><SelectControl v-model="editorialProductCategory" :options="[{ value: '', label: 'Toutes les catégories' }, ...categories.map(category => ({ value: category.public_id, label: category.name }))]" /></label><button class="text-link" type="button" @click="clearEditorialFilters">Réinitialiser</button></div><div class="section-product-options" aria-label="Produits disponibles pour l’éditorial"><article v-for="product in visibleEditorialProducts" :key="product.public_id" class="section-product-option"><img v-if="product.images?.[0]?.path" :src="imageUrl(product.images[0].path)" alt=""><span v-else class="section-product-placeholder" aria-hidden="true">PC</span><div><strong>{{ product.name }}</strong><small>{{ product.category?.name || 'Sans catégorie' }} · {{ productPrice(product) }}</small><small :class="{ 'is-unavailable': productAvailability(product) !== 'Disponible' }">{{ productAvailability(product) }} · {{ productStock(product) }} en stock</small></div><button class="admin-outline" type="button" @click="toggleEditorialProduct(product.public_id)">{{ editorialProducts.includes(product.public_id) ? 'Retirer' : 'Ajouter' }}</button></article><p v-if="!visibleEditorialProducts.length" class="section-product-empty">Aucun produit ne correspond à votre recherche.</p></div></section><section class="section-product-panel section-product-selected"><header><div><h5>Produits sélectionnés</h5><p>L’ordre ci-dessous est celui de la vitrine.</p></div><span>{{ editorialProducts.length }}</span></header><ol class="section-product-order" aria-label="Ordre des produits éditoriaux sélectionnés"><li v-for="(publicId,index) in editorialProducts" :key="publicId"><span class="section-order" aria-hidden="true">{{ index + 1 }}</span><img v-if="productFor(publicId)?.images?.[0]?.path" :src="imageUrl(productFor(publicId)?.images?.[0]?.path)" alt=""><span v-else class="section-product-placeholder" aria-hidden="true">PC</span><div><strong>{{ productName(publicId) }}</strong><small>{{ productFor(publicId)?.category?.name || 'Sans catégorie' }}</small></div><div class="section-product-actions"><button class="admin-icon-button" type="button" :disabled="index === 0" aria-label="Monter ce produit" @click="moveProduct(editorialProducts,index,-1)">↑</button><button class="admin-icon-button" type="button" :disabled="index === editorialProducts.length - 1" aria-label="Descendre ce produit" @click="moveProduct(editorialProducts,index,1)">↓</button><button class="text-link danger" type="button" @click="toggleEditorialProduct(publicId)">Retirer</button></div></li><li v-if="!editorialProducts.length" class="section-product-empty">Aucun produit sélectionné.</li></ol></section></div></section><footer class="sticky-save-bar"><button class="admin-action" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button></footer></form>
       <div v-else-if="mode === 'reassurance'"><form class="category-form" @input="markDirty" @submit.prevent="createReassurance"><div class="form-grid"><fieldset class="full reassurance-icon-picker"><legend>Icône approuvée</legend><div role="radiogroup" aria-label="Icône approuvée"><button v-for="icon in iconOptions" :key="icon.value" type="button" :class="{ 'is-selected': reassuranceForm.icon_key === icon.value }" :aria-pressed="reassuranceForm.icon_key === icon.value" @click="reassuranceForm.icon_key = icon.value; markDirty()"><img :src="'/images/reassurance/' + icon.file" :alt="icon.label" width="56" height="56"><span>{{ icon.label }}</span></button></div></fieldset><label>Titre<input v-model="reassuranceForm.title" required></label><label class="full">Texte court<textarea v-model="reassuranceForm.text" required></textarea></label><label class="inline-check">Actif <input v-model="reassuranceForm.is_active" type="checkbox"></label></div><p class="admin-help">Les quatre illustrations sont fixes et ne peuvent pas être téléversées ou recolorées depuis le CMS.</p><button class="admin-action">{{ reassuranceEditing ? 'Enregistrer' : 'Ajouter' }}</button></form><div class="admin-table"><article v-for="(item,index) in reassurance" :key="item.public_id"><img class="admin-reassurance-icon" :src="'/images/reassurance/' + (iconOptions.find(icon => icon.value === item.icon_key)?.file || 'livraison-rapide.webp')" :alt="iconOptions.find(icon => icon.value === item.icon_key)?.label || 'Icône approuvée'" width="40" height="40"><strong>{{ item.title }}</strong><span>{{ item.is_active ? 'Actif' : 'Inactif' }}</span><button class="text-link" @click="openReassurance(item)">Modifier</button><button class="text-link" @click="updateItem('reassurance',item,{is_active:!item.is_active})">{{ item.is_active ? 'Désactiver' : 'Activer' }}</button><button class="text-link" @click="move('items/reassurance',reassurance,index,-1)" :disabled="index===0">Monter</button><button class="text-link" @click="move('items/reassurance',reassurance,index,1)" :disabled="index===reassurance.length-1">Descendre</button><button class="text-link" @click="removeItem('reassurance',item)">Supprimer</button></article></div></div>
      <div v-else-if="mode === 'social'"><form class="category-form" @input="markDirty" @submit.prevent="createSocial"><div class="form-grid"><label>URL sociale HTTPS<input v-model="socialForm.url" type="url" required></label><label>Texte alternatif<input v-model="socialForm.alt_text" required></label><label>Image carrée<input type="file" accept="image/jpeg,image/png,image/webp" :required="!socialEditing" @change="chooseFile($event,file => socialForm.image=file)"></label><label class="inline-check">Active <input v-model="socialForm.is_active" type="checkbox"></label></div><button class="admin-action" :disabled="saving">{{ socialEditing ? 'Enregistrer' : 'Ajouter' }}</button></form><div class="admin-table"><article v-for="(item,index) in social" :key="item.public_id"><img v-if="item.image_path" class="admin-image-preview" :src="imageUrl(item.image_path)" :alt="item.alt_text"><strong>{{ item.alt_text }}</strong><button class="text-link" @click="openSocial(item)">Modifier</button><button class="text-link" @click="updateItem('social',item,{is_active:!item.is_active})">{{ item.is_active ? 'Désactiver' : 'Activer' }}</button><button class="text-link" @click="move('items/social',social,index,-1)" :disabled="index===0">Monter</button><button class="text-link" @click="move('items/social',social,index,1)" :disabled="index===social.length-1">Descendre</button><button class="text-link" @click="removeItem('social',item)">Supprimer</button></article></div></div>
      <form v-else class="category-form" @input="markDirty" @submit.prevent="saveBrand"><label>Titre français<input v-model="brand.heading" required></label><label>Contenu HTML sécurisé<textarea v-model="brand.content" rows="12" required></textarea></label><label class="inline-check">Actif <input v-model="brand.is_active" type="checkbox"></label><footer class="sticky-save-bar"><button class="admin-action" :disabled="saving">Enregistrer</button></footer></form></div><aside class="content-section-guide" aria-live="polite"><span>Emplacement · {{ sectionGuidance[mode].place }}</span><strong>{{ sectionGuidance[mode].title }}</strong><p>{{ sectionGuidance[mode].description }}</p><p class="content-guide-tip">Cette aide décrit l’emplacement de la section sur l’accueil. Les changements ne sont visibles qu’après enregistrement.</p></aside></div>
    </section>`,
};

const heroTemplate = ContentView as Component & { template: string };
heroTemplate.template = heroTemplate.template.replace(
    '<button class="hero-slide-card__secondary-action" type="button" @click="openHero(hero)">Modifier</button>',
    '<button class="hero-slide-card__secondary-action" type="button" @click="openHero(hero)">Modifier</button><button class="text-link danger" type="button" @click="deleteHero(hero)">Supprimer</button>',
);

export default ContentView;
