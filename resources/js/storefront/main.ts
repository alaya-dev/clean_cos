type CartItem = {
    product_public_id: string;
    variant_public_id: string | null;
    quantity: number;
    name?: string;
    image_url?: string | null;
    variant_label?: string | null;
};
type Money = { millimes: number; formatted: string };
type QuoteLine = { product_public_id: string; variant_public_id: string | null; name: string; variant_label: string | null; image_url: string | null; quantity_requested: number; quantity_available: number; is_available: boolean; effective_unit_price: Money; line_total: Money; messages: string[] };
type Quote = { data: { items: QuoteLine[]; pricing: { subtotal: Money; promo_code: null | { code: string; discount: Money }; shipping: { fee: Money }; total: Money }; can_checkout: boolean } };
type CheckoutField = { key: string; label: string; type: 'text' | 'textarea' | 'number' | 'select' | 'radio' | 'checkbox'; options: string[] | null; is_required: boolean };
type CheckoutFieldsResponse = { data: CheckoutField[]; meta: { schema_version: string; promo_code_field_visible: boolean } };
type Suggestion = { name: string; slug: string };
type Variant = { public_id: string; sku: string | null; stock_quantity: number; is_active: boolean; is_default?: boolean; regular_price_millimes?: number; promotional_price_millimes?: number | null; value_ids: number[]; image_url: string | null };

const CART_KEY = 'pc_cart_v2';
const CART_TTL = 7 * 24 * 60 * 60 * 1000;
const escapeHtml = (text: string) => { const node = document.createElement('span'); node.textContent = text; return node.innerHTML; };
function cart(): CartItem[] { try { const stored = JSON.parse(window.localStorage?.getItem(CART_KEY) ?? '{}') as { version?: number; expiresAt?: number; items?: CartItem[] }; return stored.version === 2 && stored.expiresAt && stored.expiresAt > Date.now() && Array.isArray(stored.items) ? stored.items.filter((item) => typeof item.product_public_id === 'string' && (item.variant_public_id === null || typeof item.variant_public_id === 'string') && Number.isInteger(item.quantity) && item.quantity > 0) : []; } catch { return []; } }
function saveCart(items: CartItem[]) { const previous = cart(); try { window.localStorage?.setItem(CART_KEY, JSON.stringify({ version: 2, expiresAt: Date.now() + CART_TTL, items })); } catch { /* The cart remains usable for this page when browser storage is blocked. */ } updateDrawerQuoteOptimistically(previous, items); updateCartCount(true); if (cartDrawer?.classList.contains('is-open')) void renderCartDrawer(); }
function updateCartCount(announce = false) { const quantity = cart().reduce((sum, line) => sum + line.quantity, 0); const label = `${quantity} ${quantity === 1 ? 'article' : 'articles'} dans votre panier`; document.querySelectorAll<HTMLElement>('[data-cart-count]').forEach((node) => { node.textContent = String(quantity); node.setAttribute('aria-label', label); }); if (!announce) return; let feedback = document.querySelector<HTMLElement>('[data-cart-feedback]'); if (!feedback) { feedback = document.createElement('div'); feedback.className = 'sr-only'; feedback.dataset.cartFeedback = ''; feedback.setAttribute('role', 'status'); feedback.setAttribute('aria-live', 'polite'); document.body.append(feedback); } feedback.textContent = `Panier mis à jour : ${label}.`; }
async function addToCart(line: CartItem, trigger?: HTMLElement | null): Promise<boolean> {
    const previous = cart().map((item) => ({ ...item }));
    const items = cart();
    const current = items.find((item) => item.product_public_id === line.product_public_id && item.variant_public_id === line.variant_public_id);
    if (current) {
        current.quantity = Math.min(99, current.quantity + line.quantity);
        current.name ??= line.name;
        current.image_url ??= line.image_url;
        current.variant_label ??= line.variant_label;
    } else items.push(line);
    saveCart(items);
    try {
        const quoted = await quote(items);
        const target = quoted.data.items.find((candidate) => candidate.product_public_id === line.product_public_id && candidate.variant_public_id === line.variant_public_id);
        if (!target || !target.is_available || target.quantity_requested < (items.find((item) => item.product_public_id === line.product_public_id && item.variant_public_id === line.variant_public_id)?.quantity ?? line.quantity)) {
            reconcileStoredCart(items, quoted);
            return false;
        }
        drawerQuoteCache = { signature: cartSignature(items), quote: quoted, expiresAt: Date.now() + 60_000 };
        openCartDrawer(trigger);
        void trackMetaEvent('AddToCart', { product_public_id: line.product_public_id, variant_public_id: line.variant_public_id, quantity: line.quantity });
        return true;
    } catch {
        saveCart(previous);
        return false;
    }
}
function showStorefrontToast(message: string) { let stack = document.querySelector<HTMLElement>('[data-storefront-toast-stack]'); if (!stack) { stack = document.createElement('div'); stack.className = 'storefront-toast-stack'; stack.dataset.storefrontToastStack = ''; stack.setAttribute('aria-live', 'polite'); stack.setAttribute('aria-atomic', 'true'); document.body.append(stack); } const toast = document.createElement('div'); toast.className = 'storefront-toast'; const label = document.createElement('span'); label.textContent = message; const action = document.createElement('button'); action.type = 'button'; action.textContent = 'Voir le panier'; action.addEventListener('click', () => { toast.remove(); openCartDrawer(); }); toast.append(label, action); stack.append(toast); const dismiss = () => { toast.classList.add('is-leaving'); window.setTimeout(() => toast.remove(), 180); }; const timeout = window.setTimeout(dismiss, 4200); toast.addEventListener('pointerenter', () => window.clearTimeout(timeout), { once: true }); }
function authoritativeCartItems(items: CartItem[]): Array<Pick<CartItem, 'product_public_id' | 'variant_public_id' | 'quantity'>> { return items.map(({ product_public_id, variant_public_id, quantity }) => ({ product_public_id, variant_public_id, quantity })); }
async function quote(items = cart(), promoCode?: string, signal?: AbortSignal): Promise<Quote> { const response = await fetch('/api/v1/public/cart/quote', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, signal, body: JSON.stringify({ items: authoritativeCartItems(items), ...(promoCode ? { promo_code: promoCode } : {}) }) }); const payload = await response.json().catch(() => null) as Quote & { message?: string } | null; if (!response.ok || !payload) throw new Error(payload?.message || 'Le panier est momentanément indisponible.'); return payload; }
updateCartCount();

type NetworkConnection = { saveData?: boolean; effectiveType?: string };
function allowsNonCriticalNetworkWork(): boolean {
    const connection = (navigator as Navigator & { connection?: NetworkConnection }).connection;
    return !connection?.saveData && !['slow-2g', '2g'].includes(connection?.effectiveType ?? '') && !matchMedia('(prefers-reduced-data: reduce)').matches;
}
function scheduleWhenIdle(work: () => void): void {
    if (!allowsNonCriticalNetworkWork()) return;
    const idleWindow = window as Window & { requestIdleCallback?: (callback: IdleRequestCallback, options?: IdleRequestOptions) => number };
    if (idleWindow.requestIdleCallback) {
        idleWindow.requestIdleCallback(() => work(), { timeout: 2500 });
        return;
    }
    window.setTimeout(work, 1200);
}

const searchForm = document.querySelector<HTMLFormElement>('[data-global-search]');
const searchInput = document.querySelector<HTMLInputElement>('[data-search-input]');
const suggestionTarget = document.querySelector<HTMLElement>('[data-search-suggestions]');
document.querySelector('[data-search-trigger]')?.addEventListener('click', () => { searchForm?.classList.toggle('is-open'); searchInput?.focus(); });
let searchTimer: ReturnType<typeof setTimeout> | undefined;
let searchAbort: AbortController | undefined;
let searchRequestId = 0;
let previousSearchQuery = '';
searchInput?.addEventListener('input', () => {
    const query = searchInput.value.trim();
    clearTimeout(searchTimer);
    if (query.length < 2) { searchAbort?.abort(); previousSearchQuery = ''; if (suggestionTarget) { suggestionTarget.innerHTML = ''; suggestionTarget.classList.remove('is-visible'); } return; }
    if (query === previousSearchQuery) return;
    searchTimer = setTimeout(async () => {
        searchAbort?.abort(); searchAbort = new AbortController(); const requestId = ++searchRequestId; previousSearchQuery = query;
        if (suggestionTarget) { suggestionTarget.textContent = 'Recherche en cours…'; suggestionTarget.classList.add('is-visible'); }
        try {
            const response = await fetch(`/api/v1/public/search/suggestions?q=${encodeURIComponent(query)}&limit=6`, { signal: searchAbort.signal, headers: { Accept: 'application/json' } });
            if (!response.ok || !suggestionTarget || requestId !== searchRequestId) return;
            const payload = await response.json() as { data: { products: Suggestion[]; categories: Suggestion[] } };
            suggestionTarget.innerHTML = [...payload.data.products.map((entry) => `<a href="/produits/${encodeURIComponent(entry.slug)}">${escapeHtml(entry.name)} <small>Produit</small></a>`), ...payload.data.categories.map((entry) => `<a href="/categories/${encodeURIComponent(entry.slug)}">${escapeHtml(entry.name)} <small>Catégorie</small></a>`)].join('') || '<p>Aucun résultat.</p>';
            suggestionTarget.classList.add('is-visible');
        } catch (cause) { if (!(cause instanceof DOMException && cause.name === 'AbortError') && suggestionTarget && requestId === searchRequestId) { suggestionTarget.textContent = 'La recherche est indisponible.'; suggestionTarget.classList.add('is-visible'); } }
    }, 180);
});

const catalogueGrid = document.querySelector<HTMLElement>('[data-catalogue-grid]');
const catalogueMore = document.querySelector<HTMLButtonElement>('[data-catalogue-more]');
const catalogueMoreStatus = document.querySelector<HTMLElement>('[data-catalogue-more-status]');
let catalogueMoreLoading = false;

const normalizeCatalogueUrl = (value: string): string => {
    try {
        const url = new URL(value, window.location.href);

        // Laravel may generate pagination links with the origin scheme seen
        // behind the reverse proxy. Keep pagination on the current storefront
        // origin so HTTPS pages never attempt a blocked HTTP fetch.
        if (url.hostname === window.location.hostname) {
            url.protocol = window.location.protocol;
            url.host = window.location.host;
        }

        return url.toString();
    } catch {
        return value;
    }
};

const productCardKey = (card: Element) =>
    card.querySelector<HTMLAnchorElement>('.product-card-image')?.href || '';

catalogueGrid?.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element) || target.closest('a,button')) return;

    const card = target.closest<HTMLElement>('[data-product-card]');
    const productLink = card?.querySelector<HTMLAnchorElement>('.product-card-image');
    if (productLink) window.location.assign(productLink.href);
});

catalogueMore?.addEventListener('click', async () => {
    const nextUrl = catalogueMore.dataset.nextUrl
        ? normalizeCatalogueUrl(catalogueMore.dataset.nextUrl)
        : undefined;
    if (!catalogueGrid || !nextUrl || catalogueMoreLoading) return;

    catalogueMoreLoading = true;
    catalogueMore.disabled = true;
    catalogueMore.textContent = 'Chargement…';
    catalogueMoreStatus!.textContent = 'Chargement de produits supplémentaires.';

    try {
        const response = await fetch(nextUrl, {
            headers: { Accept: 'text/html' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error('Catalogue indisponible.');

        const nextDocument = new DOMParser().parseFromString(
            await response.text(),
            'text/html',
        );
        const existing = new Set(
            [...catalogueGrid.querySelectorAll('[data-product-card]')].map(
                productCardKey,
            ),
        );
        const cards = [
            ...nextDocument.querySelectorAll('[data-catalogue-grid] [data-product-card]'),
        ].filter((card) => {
            const cardKey = productCardKey(card);
            return cardKey !== '' && !existing.has(cardKey);
        });
        cards.forEach((card, index) => {
            card.classList.add('catalogue-card-enter');
            card.setAttribute('style', `--catalogue-card-index: ${index}`);
            catalogueGrid.append(card);
        });
        requestAnimationFrame(() => {
            cards.forEach((card) => card.classList.add('is-visible'));
        });

        const nextButton = nextDocument.querySelector<HTMLButtonElement>(
            '[data-catalogue-more]',
        );
        if (!nextButton?.dataset.nextUrl) {
            catalogueMore.parentElement?.remove();
            return;
        }

        catalogueMore.dataset.nextUrl = normalizeCatalogueUrl(nextButton.dataset.nextUrl);
        catalogueMoreStatus!.textContent = `${cards.length} produit${cards.length > 1 ? 's' : ''} ajouté${cards.length > 1 ? 's' : ''}.`;
    } catch {
        catalogueMoreStatus!.textContent = 'Impossible de charger plus de produits. Réessayez.';
    } finally {
        catalogueMoreLoading = false;
        if (catalogueMore.isConnected) {
            catalogueMore.disabled = false;
            catalogueMore.textContent = 'Voir plus de produits  ⌄';
        }
    }
});

const announcementBar = document.querySelector<HTMLElement>('[data-announcement-bar]');
if (announcementBar && 'IntersectionObserver' in window) new IntersectionObserver(([entry]) => announcementBar.classList.toggle('is-offscreen', !entry.isIntersecting)).observe(announcementBar);

const drawer = document.querySelector<HTMLElement>('[data-mobile-drawer]');
const drawerOpen = document.querySelector<HTMLButtonElement>('[data-drawer-open]');
const drawerClose = document.querySelector<HTMLButtonElement>('[data-drawer-close]');
const drawerBackdrop = document.querySelector<HTMLElement>('[data-drawer-backdrop]');
const cartDrawer = document.querySelector<HTMLElement>('[data-cart-drawer]');
const cartOpen = document.querySelector<HTMLButtonElement>('[data-cart-open]');
const cartClose = document.querySelector<HTMLButtonElement>('[data-cart-close]');
const cartBackdrop = document.querySelector<HTMLElement>('[data-cart-backdrop]');
let panelTrigger: HTMLElement | null = null;
function closePanel(panel: HTMLElement | null, backdrop: HTMLElement | null, trigger?: HTMLElement | null) { panel?.classList.remove('is-open'); panel?.setAttribute('aria-hidden', 'true'); if (backdrop) backdrop.hidden = true; document.body.classList.remove('is-locked'); (trigger ?? panelTrigger)?.focus(); panelTrigger = null; }
function openPanel(panel: HTMLElement | null, backdrop: HTMLElement | null, trigger: HTMLElement | null) { if (!panel) return; panelTrigger = trigger; panel.classList.add('is-open'); panel.setAttribute('aria-hidden', 'false'); if (backdrop) backdrop.hidden = false; document.body.classList.add('is-locked'); panel.querySelector<HTMLElement>('button, a, input')?.focus(); }
function closeDrawer() { closePanel(drawer, drawerBackdrop, drawerOpen); drawerOpen?.setAttribute('aria-expanded', 'false'); }
drawerOpen?.addEventListener('click', () => { openPanel(drawer, drawerBackdrop, drawerOpen); drawerOpen.setAttribute('aria-expanded', 'true'); });
drawerClose?.addEventListener('click', closeDrawer);
drawerBackdrop?.addEventListener('click', closeDrawer);
drawer?.querySelectorAll<HTMLAnchorElement>('a').forEach((link) => link.addEventListener('click', closeDrawer));
function closeCartDrawer() { closePanel(cartDrawer, cartBackdrop, cartOpen); }
function openCartDrawer(trigger: HTMLElement | null = cartOpen) { openPanel(cartDrawer, cartBackdrop, trigger); void renderCartDrawer(); }
cartOpen?.addEventListener('click', () => openCartDrawer());
cartClose?.addEventListener('click', closeCartDrawer);
cartBackdrop?.addEventListener('click', closeCartDrawer);
document.addEventListener('keydown', (event) => { if (event.key === 'Escape') { if (drawer?.classList.contains('is-open')) closeDrawer(); if (cartDrawer?.classList.contains('is-open')) closeCartDrawer(); } const panel = drawer?.classList.contains('is-open') ? drawer : cartDrawer?.classList.contains('is-open') ? cartDrawer : null; if (event.key !== 'Tab' || !panel) return; const focusable = [...panel.querySelectorAll<HTMLElement>('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled])')]; if (!focusable.length) return; const first = focusable[0]; const last = focusable[focusable.length - 1]; if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); } });

const hero = document.querySelector<HTMLElement>('[data-hero-carousel]');
if (hero) {
    const slides = [...hero.querySelectorAll<HTMLElement>('[data-hero-slide]')];
    const dots = [...hero.querySelectorAll<HTMLButtonElement>('[data-hero-dot]')];
    const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
    let index = 0;
    let timer: ReturnType<typeof setInterval> | undefined;
    const show = (next: number) => { index = (next + slides.length) % slides.length; slides.forEach((slide, position) => { slide.classList.toggle('is-active', position === index); slide.setAttribute('aria-hidden', String(position !== index)); }); dots.forEach((dot, position) => dot.classList.toggle('is-active', position === index)); };
    const stop = () => clearInterval(timer);
    const start = () => { stop(); if (!reducedMotion && hero.dataset.autoplay === 'true' && slides.length > 1) timer = setInterval(() => show(index + 1), 8000); };
    hero.querySelector('[data-hero-prev]')?.addEventListener('click', () => { show(index - 1); start(); });
    hero.querySelector('[data-hero-next]')?.addEventListener('click', () => { show(index + 1); start(); });
    dots.forEach((dot) => dot.addEventListener('click', () => { show(Number(dot.dataset.heroDot)); start(); }));
    hero.addEventListener('mouseenter', stop); hero.addEventListener('mouseleave', start); hero.addEventListener('focusin', stop); hero.addEventListener('focusout', start);
    document.addEventListener('visibilitychange', () => { if (document.hidden) stop(); else start(); });
    hero.addEventListener('keydown', (event) => { if (event.key === 'ArrowLeft') show(index - 1); if (event.key === 'ArrowRight') show(index + 1); });
    start();
}

const detail = document.querySelector<HTMLElement>('[data-product-detail]');
if (detail) {
    let quantity = 1; let variantId: string | null = null; let expressRefresh: (() => Promise<void>) | null = null;
    const quantityInput = detail.querySelector<HTMLInputElement>('[data-quantity]');
    const setQuantity = (next: number, limit = 99) => { const requested = Number.isFinite(next) ? Math.floor(next) : 1; quantity = Math.max(1, Math.min(limit, requested)); if (quantityInput) quantityInput.value = String(quantity); const plus = detail.querySelector<HTMLButtonElement>('[data-quantity-plus]'); if (plus) plus.disabled = limit < 1 || quantity >= limit; if (requested > limit && stockMessage) { const status = stockMessage.querySelector<HTMLElement>('[data-stock-status]'); const detailText = stockMessage.querySelector<HTMLElement>('[data-stock-detail]'); if (status && detailText) { status.textContent = `Il ne reste que ${limit} unités disponibles.`; detailText.textContent = ''; } else stockMessage.textContent = `Il ne reste que ${limit} unités disponibles.`; } if (expressRefresh) void expressRefresh(); };
    detail.querySelector('[data-quantity-minus]')?.addEventListener('click', () => setQuantity(quantity - 1));
    detail.querySelector('[data-quantity-plus]')?.addEventListener('click', () => setQuantity(quantity + 1, Number(quantityInput?.max || 99)));
    quantityInput?.addEventListener('change', () => setQuantity(Number(quantityInput.value), Number(quantityInput.max || 99)));
    const setGalleryImage = (source: string | undefined) => { const image = detail.querySelector<HTMLImageElement>('[data-gallery-main]'); if (!image || !source || image.currentSrc === source || image.src === source) return; image.classList.add('is-switching'); image.src = source; window.requestAnimationFrame(() => window.requestAnimationFrame(() => image.classList.remove('is-switching'))); };
    detail.querySelectorAll<HTMLButtonElement>('[data-gallery-image]').forEach((button) => button.addEventListener('click', () => setGalleryImage(button.dataset.galleryImage)));
    const thumbnailRail = detail.querySelector<HTMLElement>('[data-gallery-thumbnails]');
    if (thumbnailRail && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
        let thumbnailTimer: number | undefined;
        const stopThumbnailLoop = () => { if (thumbnailTimer) window.clearInterval(thumbnailTimer); thumbnailTimer = undefined; };
        const startThumbnailLoop = () => {
            stopThumbnailLoop();
            if (thumbnailRail.scrollWidth <= thumbnailRail.clientWidth + 4) return;
            thumbnailTimer = window.setInterval(() => {
                const next = thumbnailRail.scrollLeft + Math.max(96, Math.round(thumbnailRail.clientWidth * 0.45));
                thumbnailRail.scrollTo({ left: next >= thumbnailRail.scrollWidth - thumbnailRail.clientWidth - 4 ? 0 : next, behavior: 'smooth' });
            }, 4200);
        };
        thumbnailRail.addEventListener('pointerenter', stopThumbnailLoop);
        thumbnailRail.addEventListener('pointerleave', startThumbnailLoop);
        thumbnailRail.addEventListener('focusin', stopThumbnailLoop);
        thumbnailRail.addEventListener('focusout', startThumbnailLoop);
        startThumbnailLoop();
    }
    const selected = new Set<number>();
    const variants = JSON.parse(detail.dataset.productVariants ?? '[]') as Variant[];
    const addButton = detail.querySelector<HTMLButtonElement>('[data-add-to-cart]');
    const buyNowButton = detail.querySelector<HTMLButtonElement>('[data-buy-now]');
    const stockMessage = detail.querySelector<HTMLElement>('[data-stock-message]');
    const stockStatus = detail.querySelector<HTMLElement>('[data-stock-status]');
    const stockDetail = detail.querySelector<HTMLElement>('[data-stock-detail]');
    const effectivePrice = detail.querySelector<HTMLElement>('[data-product-effective-price]');
    const regularPrice = detail.querySelector<HTMLElement>('[data-product-regular-price]');
    const saleBadge = detail.querySelector<HTMLElement>('[data-product-sale]');
    const setStockMessage = (available: boolean, status: string, detailText = '') => {
        if (!stockMessage) return;
        stockMessage.classList.toggle('in-stock', available);
        stockMessage.classList.toggle('out-stock', !available);
        if (stockStatus && stockDetail) {
            stockStatus.textContent = status;
            stockDetail.textContent = detailText;
            return;
        }
        stockMessage.textContent = detailText ? `${status} ${detailText}` : status;
    };
    const activeVariants = () => variants.filter((candidate) => candidate.is_active);
    const groupValuesFor = (button: HTMLButtonElement) =>
        [...(button.closest('fieldset')?.querySelectorAll<HTMLButtonElement>('[data-option-value]') ?? [])]
            .map((item) => Number(item.dataset.optionValue));
    const formatVariantPrice = (millimes: number) => `${(millimes / 1000).toLocaleString('fr-TN', { minimumFractionDigits: 3, maximumFractionDigits: 3 })} TND`;
    const updatePrice = (variant?: Variant) => {
        if (!variant || !effectivePrice) return;
        const regular = variant.regular_price_millimes;
        const promotional = variant.promotional_price_millimes;
        const hasPromotion = promotional !== null && promotional !== undefined && regular !== undefined && promotional < regular;
        const effective = hasPromotion ? promotional : regular;
        if (effective !== undefined) effectivePrice.textContent = formatVariantPrice(effective);
        if (!regularPrice || !saleBadge) return;
        regularPrice.textContent = regular === undefined ? '' : formatVariantPrice(regular);
        regularPrice.classList.toggle('is-hidden', !hasPromotion);
        saleBadge.classList.toggle('is-hidden', !hasPromotion);
    };
    const syncVariant = () => {
        const variant = variants.find((candidate) => candidate.value_ids.length === selected.size && candidate.value_ids.every((id) => selected.has(id)));
        const available = Boolean(variant?.is_active && variant.stock_quantity > 0);
        variantId = variant?.public_id ?? null;
        if (addButton) { addButton.disabled = !available; addButton.classList.toggle('button-stock-unavailable', !available); addButton.textContent = available ? 'Ajouter au panier' : 'Bientôt de retour'; }
        if (buyNowButton) { buyNowButton.disabled = !available; buyNowButton.classList.toggle('button-stock-unavailable', !available); }
        setStockMessage(available, !variant ? 'Sélectionnez vos options.' : available ? 'En stock' : 'Rupture de stock', !variant || available ? '' : 'Cette variante est actuellement indisponible.');
        detail.querySelectorAll<HTMLButtonElement>('[data-quantity-minus], [data-quantity-plus]').forEach((button) => { button.disabled = !available; });
        if (quantityInput) { quantityInput.disabled = !available; quantityInput.max = String(available ? variant!.stock_quantity : 1); setQuantity(quantity, Number(quantityInput.max)); }
        if (variant?.image_url) setGalleryImage(variant.image_url);
        updatePrice(variant);
        detail.querySelectorAll<HTMLButtonElement>('[data-option-value]').forEach((button) => {
            const valueId = Number(button.dataset.optionValue);
            const parentId = Number(button.dataset.optionParent || 0);
            const groupValues = groupValuesFor(button);
            const compatible = activeVariants().some((candidate) => candidate.value_ids.includes(valueId) && [...selected].filter((id) => !groupValues.includes(id)).every((id) => candidate.value_ids.includes(id)));
            const parentSelected = parentId === 0 || selected.has(parentId);
            button.hidden = !parentSelected;
            button.disabled = !compatible || !parentSelected;
            button.classList.toggle('is-out-of-stock', compatible && !activeVariants().some((candidate) => candidate.value_ids.includes(valueId) && candidate.stock_quantity > 0));
            button.setAttribute('aria-disabled', String(!compatible || !parentSelected));
        });
    };
    const selectVariant = (variant: Variant) => {
        selected.clear();
        variant.value_ids.forEach((valueId) => selected.add(valueId));
        detail.querySelectorAll<HTMLButtonElement>('[data-option-value]').forEach((button) => button.setAttribute('aria-pressed', String(selected.has(Number(button.dataset.optionValue)))));
        syncVariant();
    };
    if (!variants.length && addButton) { const stock = Number(detail.dataset.productStock || 0); const available = stock > 0; addButton.disabled = !available; addButton.classList.toggle('button-stock-unavailable', !available); addButton.textContent = available ? 'Ajouter au panier' : 'Bientôt de retour'; if (buyNowButton) { buyNowButton.disabled = !available; buyNowButton.classList.toggle('button-stock-unavailable', !available); } setStockMessage(available, available ? 'En stock' : 'Bientôt de retour', available ? '' : 'Ce produit sera de nouveau disponible prochainement.'); if (quantityInput) { quantityInput.disabled = !available; quantityInput.max = String(Math.max(1, stock)); setQuantity(quantity, Math.max(1, stock)); } detail.querySelectorAll<HTMLButtonElement>('[data-quantity-minus], [data-quantity-plus]').forEach((button) => { button.disabled = !available; }); }
    const firstActiveVariant = variants.find((variant) => variant.is_active && variant.is_default) ?? variants.find((variant) => variant.is_active);
    if (firstActiveVariant) selectVariant(firstActiveVariant);
    detail.querySelectorAll<HTMLButtonElement>('[data-option-value]').forEach((button) => button.addEventListener('click', () => {
        const fieldsets = [...detail.querySelectorAll('fieldset')];
        const currentFieldset = button.closest('fieldset');
        const currentIndex = currentFieldset ? fieldsets.indexOf(currentFieldset) : -1;
        fieldsets.slice(currentIndex + 1).forEach((fieldset) => fieldset.querySelectorAll<HTMLButtonElement>('[data-option-value]').forEach((child) => {
            selected.delete(Number(child.dataset.optionValue));
            child.setAttribute('aria-pressed', 'false');
        }));
        button.closest('fieldset')?.querySelectorAll<HTMLButtonElement>('[data-option-value]').forEach((other) => { selected.delete(Number(other.dataset.optionValue)); other.setAttribute('aria-pressed', 'false'); });
        selected.add(Number(button.dataset.optionValue)); button.setAttribute('aria-pressed', 'true');
        const selectedWithoutCurrentGroup = [...selected].filter((id) => !groupValuesFor(button).includes(id));
        const next = activeVariants().find((candidate) => selectedWithoutCurrentGroup.every((id) => candidate.value_ids.includes(id)) && candidate.value_ids.includes(Number(button.dataset.optionValue)));
        if (next) selectVariant(next); else syncVariant();
    }));
    addButton?.addEventListener('click', async () => { if (!detail.dataset.productPublicId || (variants.length && !variantId) || addButton.disabled) return; addButton.disabled = true; const success = await addToCart({ product_public_id: detail.dataset.productPublicId, variant_public_id: variantId, quantity, name: detail.querySelector('h1')?.textContent?.trim(), image_url: detail.querySelector<HTMLImageElement>('[data-gallery-main]')?.currentSrc ?? null }, addButton); if (!success) { showStorefrontToast('Le produit n’a pas pu être ajouté. Vérifiez le stock puis réessayez.'); if (variants.length) syncVariant(); else addButton.disabled = false; return; } addButton.disabled = false; addButton.textContent = 'Ajouté au panier'; window.setTimeout(() => { addButton.textContent = 'Ajouter au panier'; if (variants.length) syncVariant(); }, 1200); });

    const expressPanel = detail.querySelector<HTMLElement>('[data-express-checkout]');
    const expressContent = detail.querySelector<HTMLElement>('[data-express-checkout-content]');
    const expressNotice = detail.querySelector<HTMLElement>('[data-express-cart-notice]');
    let expressStarted = false;
    let expressRequest = 0;
    let expressRefreshTimer: ReturnType<typeof setTimeout> | undefined;
    let expressQuoteAbort: AbortController | null = null;
    let expressFieldsResponse: Promise<Response> | null = null;
    const expressItem = (): CartItem | null => {
        const productPublicId = detail.dataset.productPublicId;
        if (!productPublicId || (variants.length && !variantId)) return null;
        return { product_public_id: productPublicId, variant_public_id: variantId, quantity, name: detail.querySelector('h1')?.textContent?.trim(), image_url: detail.querySelector<HTMLImageElement>('[data-gallery-main]')?.currentSrc ?? null, variant_label: variants.length ? [...detail.querySelectorAll<HTMLButtonElement>('[data-option-value][aria-pressed="true"]')].map((button) => button.textContent?.trim()).filter(Boolean).join(' / ') : null };
    };
    const renderExpress = async (restoreValues?: Record<string, FormDataEntryValue>) => {
        if (!expressPanel || !expressContent) return;
        const requestId = ++expressRequest;
        const item = expressItem();
        if (!item) return;
        const existingForm = expressContent.querySelector<HTMLFormElement>('[data-express-order-form]');
        const existingSummary = expressContent.querySelector<HTMLElement>('[data-express-summary]');
        if (existingForm && existingSummary) {
            expressQuoteAbort?.abort();
            expressQuoteAbort = new AbortController();
            existingSummary.setAttribute('aria-busy', 'true');
            existingSummary.classList.add('is-refreshing');
            existingForm.querySelectorAll<HTMLButtonElement>('button[type="submit"]').forEach((button) => { button.disabled = true; });
            const promoCode = existingForm.dataset.expressAppliedPromoCode || undefined;
            try {
                const quoted = await quote([item], promoCode, expressQuoteAbort.signal);
                if (requestId !== expressRequest) return;
                const line = quoted.data.items.find((candidate) => candidate.product_public_id === item.product_public_id && candidate.variant_public_id === item.variant_public_id);
                if (!quoted.data.can_checkout || !line?.is_available || line.quantity_requested < item.quantity) throw new Error(line?.messages?.[0] || 'Ce produit n’est plus disponible dans cette quantité.');
                existingSummary.innerHTML = checkoutOrderSummary(quoted);
                existingForm.querySelectorAll<HTMLButtonElement>('button[type="submit"]').forEach((button) => { button.disabled = false; });
            } catch (cause) {
                if (requestId !== expressRequest || (cause instanceof DOMException && cause.name === 'AbortError')) return;
                existingSummary.innerHTML = `<p class="commerce-alert" data-express-error>${escapeHtml(cause instanceof Error ? cause.message : 'La commande directe est momentanément indisponible.')}</p>`;
            } finally {
                if (requestId === expressRequest) { existingSummary.setAttribute('aria-busy', 'false'); existingSummary.classList.remove('is-refreshing'); }
            }
            return;
        }
        expressContent.innerHTML = '<p class="commerce-loading" aria-live="polite">Préparation de votre commande…</p>';
        try {
            const [fieldResponse, quoted] = await Promise.all([expressFieldsResponse ??= fetch('/api/v1/public/checkout-fields', { headers: { Accept: 'application/json' } }), quote([item])]);
            if (requestId !== expressRequest) return;
            const line = quoted.data.items.find((candidate) => candidate.product_public_id === item.product_public_id && candidate.variant_public_id === item.variant_public_id);
            if (!fieldResponse.ok || !quoted.data.can_checkout || !line?.is_available || line.quantity_requested < item.quantity) throw new Error(line?.messages?.[0] || 'Ce produit n’est plus disponible dans cette quantité.');
            const fields = await fieldResponse.json() as CheckoutFieldsResponse;
            const values = restoreValues ?? {};
            expressContent.innerHTML = `<div class="checkout-layout express-checkout-layout"><form class="checkout-form" data-express-order-form novalidate><div class="form-errors" data-form-errors aria-live="assertive"></div>${fields.data.map(checkoutField).join('')}<p class="privacy-note">Vos informations servent uniquement à traiter et confirmer votre commande.</p><div class="checkout-submit-area"><button class="button button-dark" type="submit">Confirmer la commande</button></div></form><aside class="cart-summary" data-express-summary aria-label="Récapitulatif de commande">${checkoutOrderSummary(quoted)}</aside></div>`;
            const form = expressContent.querySelector<HTMLFormElement>('[data-express-order-form]');
            if (!form) return;
            if (fields.meta.promo_code_field_visible) form.querySelector('.checkout-submit-area')?.insertAdjacentHTML('afterbegin', '<fieldset class="promo-field"><legend>Code promo</legend><div><input name="promo_code" maxlength="80" autocomplete="off"><button type="button" data-express-promo-apply>Appliquer</button></div><p data-express-promo-message aria-live="polite"></p><button class="text-link" type="button" data-express-promo-remove hidden>Retirer le code</button></fieldset>');
            mountGovernorateCombobox(form);
            mountCheckoutDraftAutosave(form, fields, [item]);
            form.querySelector<HTMLInputElement>('[name="promo_code"]')?.addEventListener('input', () => { delete form.dataset.expressAppliedPromoCode; });
            Object.entries(values).forEach(([key, value]) => { const control = form.elements.namedItem(key); if (control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement || control instanceof HTMLSelectElement) control.value = String(value); });
            const governorate = String(values.governorate ?? '');
            if (governorate) form.querySelector<HTMLButtonElement>(`[data-governorate-option="${CSS.escape(governorate)}"]`)?.click();
            if (!expressStarted) { expressStarted = true; void trackMetaEvent('InitiateCheckout', { items: authoritativeCartItems([item]), checkout_source: 'buy_now' }); }
            let appliedPromoCode = '';
            let invalidPromoCode = false;
            const rememberAppliedExpressPromo = () => { form.dataset.expressAppliedPromoCode = appliedPromoCode; };
            const forgetAppliedExpressPromo = () => { delete form.dataset.expressAppliedPromoCode; };
            const promoMessage = form.querySelector<HTMLElement>('[data-express-promo-message]');
            if (promoMessage) new MutationObserver(() => {
                if (promoMessage.textContent === 'Code promo appliqué.') rememberAppliedExpressPromo(); else forgetAppliedExpressPromo();
            }).observe(promoMessage, { childList: true, characterData: true, subtree: true });
            form.querySelector('[data-express-promo-apply]')?.addEventListener('click', async () => { const input = form.elements.namedItem('promo_code') as HTMLInputElement; const message = form.querySelector<HTMLElement>('[data-express-promo-message]'); const remove = form.querySelector<HTMLButtonElement>('[data-express-promo-remove]'); const code = input.value.trim(); if (!code) return; try { const updated = await quote([item], code); const summary = expressContent.querySelector<HTMLElement>('[data-express-summary]'); if (summary) summary.innerHTML = checkoutOrderSummary(updated); appliedPromoCode = code; invalidPromoCode = false; if (message) message.textContent = 'Code promo appliqué.'; if (remove) remove.hidden = false; } catch { appliedPromoCode = ''; invalidPromoCode = true; if (message) message.textContent = 'Ce code promotionnel n’est pas valide.'; if (remove) remove.hidden = false; } });
            form.querySelector<HTMLInputElement>('[name="promo_code"]')?.addEventListener('input', () => { appliedPromoCode = ''; invalidPromoCode = false; });
            form.querySelector('[data-express-promo-remove]')?.addEventListener('click', async () => { const input = form.elements.namedItem('promo_code') as HTMLInputElement; const message = form.querySelector<HTMLElement>('[data-express-promo-message]'); const remove = form.querySelector<HTMLButtonElement>('[data-express-promo-remove]'); input.value = ''; appliedPromoCode = ''; invalidPromoCode = false; try { const updated = await quote([item]); const summary = expressContent.querySelector<HTMLElement>('[data-express-summary]'); if (summary) summary.innerHTML = checkoutOrderSummary(updated); if (message) message.textContent = 'Code promo retiré.'; if (remove) remove.hidden = true; } catch { if (message) message.textContent = 'Le panier est momentanément indisponible.'; } });
            form.addEventListener('submit', (event) => { event.preventDefault(); const input = form.elements.namedItem('promo_code') as HTMLInputElement | null; const code = input?.value.trim() ?? ''; const message = form.querySelector<HTMLElement>('[data-express-promo-message]'); if (code && appliedPromoCode !== code) { if (message) message.textContent = invalidPromoCode ? 'Ce code promotionnel n’est pas valide.' : 'Appliquez ce code promotionnel avant de confirmer la commande.'; input?.focus(); return; } void submitOrder(form, fields, [item], { preserveCart: true, source: 'buy_now', storageKey: 'pc_buy_now_checkout_key' }); });
        } catch (cause) {
            if (requestId !== expressRequest) return;
            expressContent.innerHTML = `<p class="commerce-alert" data-express-error>${escapeHtml(cause instanceof Error ? cause.message : 'La commande directe est momentanément indisponible.')}</p>`;
        }
    };
    expressRefresh = async () => { if (!expressPanel || expressPanel.hidden) return; if (expressRefreshTimer) clearTimeout(expressRefreshTimer); expressRefreshTimer = setTimeout(() => { expressRefreshTimer = undefined; void renderExpress(); }, 280); };
    window.setTimeout(() => { expressFieldsResponse ??= fetch('/api/v1/public/checkout-fields', { headers: { Accept: 'application/json' } }); }, 700);
    buyNowButton?.addEventListener('click', () => { if (!expressPanel) return; if (variants.length && !variantId) { showStorefrontToast('Sélectionnez toutes les options avant de commander.'); detail.querySelector<HTMLButtonElement>('[data-option-value]:not([disabled])')?.focus(); return; } if (buyNowButton.disabled) return; expressPanel.hidden = false; if (expressNotice) expressNotice.hidden = cart().length === 0; expressPanel.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' }); void renderExpress(); });
    detail.querySelector('[data-express-close]')?.addEventListener('click', () => { if (expressPanel) expressPanel.hidden = true; if (expressRefreshTimer) clearTimeout(expressRefreshTimer); expressQuoteAbort?.abort(); expressRequest += 1; });
}

let cartRenderRequest = 0;
let cartReconciliationTimer: ReturnType<typeof setTimeout> | undefined;
let lastConfirmedCart = cart();
type DrawerQuoteCache = { signature: string; quote: Quote; expiresAt: number; needsRefresh?: boolean };
let drawerQuoteCache: DrawerQuoteCache | null = null;
let drawerQuotePending: { signature: string; request: Promise<Quote> } | null = null;
const formatMillimes = (millimes: number) => `${(millimes / 1000).toLocaleString('fr-TN', { minimumFractionDigits: 3, maximumFractionDigits: 3 })} TND`;
const cartSignature = (items: CartItem[]) => items.map((item) => `${item.product_public_id}:${item.variant_public_id ?? ''}:${item.quantity}`).sort().join('|');
function invalidateDrawerQuote() { drawerQuoteCache = null; }
function moneyFromMillimes(millimes: number): Money { return { millimes, formatted: formatMillimes(millimes) }; }
function cartLineKey(item: Pick<CartItem, 'product_public_id' | 'variant_public_id'>): string { return `${item.product_public_id}:${item.variant_public_id ?? ''}`; }
function optimisticPricing(pricing: Quote['data']['pricing'], subtotalDelta: number): Quote['data']['pricing'] {
    const nextSubtotal = pricing.subtotal.millimes + subtotalDelta;
    const discountDelta = pricing.promo_code && pricing.subtotal.millimes > 0 ? Math.round(subtotalDelta * pricing.promo_code.discount.millimes / pricing.subtotal.millimes) : 0;
    return {
        ...pricing,
        subtotal: moneyFromMillimes(nextSubtotal),
        promo_code: pricing.promo_code ? { ...pricing.promo_code, discount: moneyFromMillimes(pricing.promo_code.discount.millimes + discountDelta) } : null,
        total: moneyFromMillimes(pricing.total.millimes + subtotalDelta - discountDelta),
    };
}
function updateDrawerQuoteOptimistically(previous: CartItem[], next: CartItem[]): void {
    if (!drawerQuoteCache || drawerQuoteCache.signature !== cartSignature(previous)) return;
    const nextByKey = new Map(next.map((item) => [cartLineKey(item), item]));
    const existingKeys = new Set(drawerQuoteCache.quote.data.items.map((line) => cartLineKey(line)));
    if ([...nextByKey.keys()].some((key) => !existingKeys.has(key))) { invalidateDrawerQuote(); return; }
    let subtotalDelta = 0;
    const items = drawerQuoteCache.quote.data.items.flatMap((line) => {
        const nextItem = nextByKey.get(cartLineKey(line));
        const nextQuantity = nextItem?.quantity ?? 0;
        subtotalDelta += line.effective_unit_price.millimes * (nextQuantity - line.quantity_requested);
        return nextQuantity > 0 ? [{ ...line, quantity_requested: nextQuantity, line_total: moneyFromMillimes(line.effective_unit_price.millimes * nextQuantity) }] : [];
    });
    const pricing = optimisticPricing(drawerQuoteCache.quote.data.pricing, subtotalDelta);
    drawerQuoteCache = {
        signature: cartSignature(next),
        expiresAt: Date.now() + 60_000,
        needsRefresh: true,
        quote: { data: { ...drawerQuoteCache.quote.data, items, pricing } },
    };
}
function drawerQuoteFor(items: CartItem[], forceRefresh = false): Promise<Quote> {
    const signature = cartSignature(items);
    if (!forceRefresh && drawerQuoteCache?.signature === signature && drawerQuoteCache.expiresAt > Date.now() && !drawerQuoteCache.needsRefresh) return Promise.resolve(drawerQuoteCache.quote);
    if (drawerQuotePending?.signature === signature) return drawerQuotePending.request;
    const request = quote(items).then((quoted) => { drawerQuoteCache = { signature, quote: quoted, expiresAt: Date.now() + 60_000 }; return quoted; }).finally(() => { if (drawerQuotePending?.request === request) drawerQuotePending = null; });
    drawerQuotePending = { signature, request };
    return request;
}
function primeDrawerQuote() { const items = cart(); if (items.length) void drawerQuoteFor(items); }
if (cart().length) scheduleWhenIdle(primeDrawerQuote);
const cartPage = document.querySelector<HTMLElement>('[data-cart-page]');
if (cartPage) void renderCart(cartPage);
function applyOptimisticCartUpdate(host: HTMLElement, quoteData: Quote, position: number, quantity: number) {
    const line = quoteData.data.items[position]; if (!line) return;
    const article = host.querySelector<HTMLElement>(`[data-cart-line="${position}"]`); article?.classList.add('is-syncing');
    article?.querySelector('output')?.replaceChildren(String(quantity));
    const lineTotal = article?.querySelector<HTMLElement>('[data-cart-line-total]'); if (lineTotal) lineTotal.textContent = formatMillimes(line.effective_unit_price.millimes * quantity);
    const quantityChange = quantity - line.quantity_requested; const pricing = optimisticPricing(quoteData.data.pricing, line.effective_unit_price.millimes * quantityChange);
    const subtotalNode = host.querySelector<HTMLElement>('[data-cart-subtotal]'); if (subtotalNode) subtotalNode.textContent = pricing.subtotal.formatted;
    const shippingNode = host.querySelector<HTMLElement>('[data-cart-shipping]'); if (shippingNode) { shippingNode.textContent = 'Mise à jour…'; shippingNode.setAttribute('aria-label', 'Livraison en cours de mise à jour'); }
    const totalNode = host.querySelector<HTMLElement>('[data-cart-total]'); if (totalNode) totalNode.textContent = pricing.total.formatted;
    if (shippingNode) { shippingNode.textContent = quoteData.data.pricing.shipping.fee.formatted; shippingNode.removeAttribute('aria-label'); }
    const summary = host.querySelector<HTMLElement>('.cart-summary'); summary?.classList.add('is-updating'); window.setTimeout(() => summary?.classList.remove('is-updating'), 180);
}
function renderQuotedCart(host: HTMLElement, quoted: Quote) {
    host.innerHTML = `<div class="cart-layout"><div class="cart-lines">${quoted.data.items.map((line, position) => `<article class="cart-line" data-cart-line="${position}">${line.image_url ? `<img src="${escapeHtml(line.image_url)}" alt="">` : ''}<div><h2>${escapeHtml(line.name)}</h2><p>${escapeHtml(line.variant_label ?? '')}</p><small>${line.effective_unit_price.formatted} l’unité</small>${line.messages.map((message) => `<p class="commerce-alert">${escapeHtml(message)}</p>`).join('')}</div><strong data-cart-line-total>${line.line_total.formatted}</strong><div class="cart-stepper"><button type="button" data-cart-change="${position}" data-delta="-1" aria-label="Réduire la quantité">−</button><output>${line.quantity_requested}</output><button type="button" data-cart-change="${position}" data-delta="1" aria-label="Augmenter la quantité" ${!line.is_available || line.quantity_requested >= line.quantity_available ? 'disabled' : ''}>+</button><button type="button" data-cart-remove="${position}">Retirer</button></div></article>`).join('')}</div><aside class="cart-summary">${checkoutSummary(quoted)}${quoted.data.can_checkout ? '<a class="button button-dark" href="/commande">Finaliser ma commande</a>' : '<p class="commerce-alert">Mettez votre panier à jour avant de commander.</p>'}</aside></div>`;
    host.querySelectorAll<HTMLButtonElement>('[data-cart-change]').forEach((button) => button.addEventListener('click', () => {
        const position = Number(button.dataset.cartChange); const requested = cart(); const line = requested[position]; const quoteLine = quoted.data.items[position]; if (!line || !quoteLine) return;
        const nextQuantity = Math.max(1, Math.min(Math.min(99, quoteLine.quantity_available), line.quantity + Number(button.dataset.delta))); if (nextQuantity === line.quantity) return;
        const updated = requested.map((item) => ({ ...item })); updated[position].quantity = nextQuantity; saveCart(updated); applyOptimisticCartUpdate(host, quoted, position, nextQuantity); scheduleCartReconciliation(host);
    }));
    host.querySelectorAll<HTMLButtonElement>('[data-cart-remove]').forEach((button) => button.addEventListener('click', () => { const position = Number(button.dataset.cartRemove); const line = host.querySelector<HTMLElement>(`[data-cart-line="${position}"]`); button.disabled = true; line?.classList.add('is-removing'); window.setTimeout(() => { const updated = cart().filter((_, itemPosition) => itemPosition !== position); applyOptimisticCartUpdate(host, quoted, position, 0); line?.remove(); saveCart(updated); scheduleCartReconciliation(host); }, 180); }));
}
function scheduleCartReconciliation(host: HTMLElement) { const request = ++cartRenderRequest; clearTimeout(cartReconciliationTimer); cartReconciliationTimer = setTimeout(() => { void reconcileCart(host, request); }, 220); }
function reconcileStoredCart(items: CartItem[], quoted: Quote): CartItem[] {
    const next: CartItem[] = [];
    let changed = false;
    quoted.data.items.forEach((line, position) => {
        const item = items[position];
        if (!item) return;
        if (line.quantity_requested < 1) {
            changed = true;
            showStorefrontToast('Ce produit n’est momentanément plus disponible et a été retiré de votre panier.');
            return;
        }
        if (item.quantity !== line.quantity_requested) {
            changed = true;
            showStorefrontToast(`Vous aviez demandé ${item.quantity} unités, mais seulement ${line.quantity_requested} sont encore disponibles. La quantité a été ajustée.`);
        }
        next.push({ ...item, quantity: line.quantity_requested });
    });
    if (changed) saveCart(next);
    return next;
}
async function reconcileCart(host: HTMLElement, request: number) {
    const items = cart(); if (!items.length) { if (request === cartRenderRequest) { lastConfirmedCart = []; host.innerHTML = '<p class="catalogue-empty">Votre panier est vide. <a class="text-link" href="/produits">Découvrir les soins</a></p>'; } return; }
    try { const quoted = await quote(items); if (request === cartRenderRequest) { const reconciled = reconcileStoredCart(items, quoted); lastConfirmedCart = reconciled.map((item) => ({ ...item })); renderQuotedCart(host, quoted); } } catch (cause) { if (request !== cartRenderRequest) return; saveCart(lastConfirmedCart); host.innerHTML = `<p class="commerce-alert">${escapeHtml(cause instanceof Error ? cause.message : 'Panier indisponible.')}</p><button class="button button-outline" type="button" data-cart-retry>Réessayer</button>`; host.querySelector('[data-cart-retry]')?.addEventListener('click', () => { void renderCart(host); }); }
}
async function renderCart(host: HTMLElement) { const request = ++cartRenderRequest; await reconcileCart(host, request); }

async function renderCartDrawer() {
    const host = document.querySelector<HTMLElement>('[data-cart-drawer-content]');
    if (!host) return;
    const items = cart();
    if (!items.length) { host.innerHTML = '<p class="catalogue-empty">Votre panier est vide.</p><a class="button button-dark" href="/produits">Découvrir les soins</a>'; return; }
    const signature = cartSignature(items);
    const cacheEntry = drawerQuoteCache?.signature === signature && drawerQuoteCache.expiresAt > Date.now() ? drawerQuoteCache : null;
    const cached = cacheEntry?.quote ?? null;
    if (cached) renderDrawerQuote(host, cached);
    else renderDrawerLocalItems(host, items);
    if (cached && !cacheEntry?.needsRefresh) return;
    try {
        const quoted = await drawerQuoteFor(items);
        if (cartSignature(cart()) === signature) renderDrawerQuote(host, quoted);
    } catch (cause) {
        if (cached) return;
        host.innerHTML = `<p class="commerce-alert">${escapeHtml(cause instanceof Error ? cause.message : 'Panier indisponible.')}</p><button class="button button-outline" type="button" data-cart-retry>Réessayer</button>`;
        host.querySelector('[data-cart-retry]')?.addEventListener('click', () => { void renderCartDrawer(); });
    }
}

function renderDrawerLocalItems(host: HTMLElement, items: CartItem[]): void {
    host.innerHTML = `<div class="cart-drawer-lines">${items.map((item) => `<article><div class="cart-drawer-product">${item.image_url ? `<img src="${escapeHtml(item.image_url)}" alt="" loading="lazy">` : ''}<div><strong>${escapeHtml(item.name ?? 'Article du panier')}</strong>${item.variant_label ? `<small>${escapeHtml(item.variant_label)}</small>` : ''}<span>Quantité : ${item.quantity}</span></div></div><div class="cart-drawer-line-total"><small>Mise à jour…</small></div></article>`).join('')}</div><p class="commerce-loading" aria-live="polite">Mise à jour des prix et de la livraison…</p>`;
}

function renderDrawerQuote(host: HTMLElement, quoted: Quote) {
    host.innerHTML = `<div class="cart-drawer-lines">${quoted.data.items.map((line, position) => `<article><div class="cart-drawer-product">${line.image_url ? `<img src="${escapeHtml(line.image_url)}" alt="" loading="lazy">` : ''}<div><strong>${escapeHtml(line.name)}</strong><small>${escapeHtml(line.variant_label ?? '')}</small><small>${line.effective_unit_price.formatted} l’unité</small><span>Quantité : ${line.quantity_requested}</span></div></div><div class="cart-drawer-line-total"><strong>${line.line_total.formatted}</strong><button class="text-link" type="button" data-cart-drawer-remove="${position}">Retirer</button></div></article>`).join('')}</div><div class="cart-drawer-summary">${checkoutSummary(quoted)}</div><div class="cart-drawer-actions"><button class="button button-ghost" type="button" data-cart-drawer-continue>Continuer mes achats</button><a class="button button-outline" href="/panier">Voir le panier</a>${quoted.data.can_checkout ? '<a class="button button-dark" href="/commande">Commander</a>' : '<a class="button button-dark" href="/panier">Mettre à jour</a>'}</div>`;
    host.querySelector<HTMLButtonElement>('[data-cart-drawer-continue]')?.addEventListener('click', closeCartDrawer);
    const status = document.querySelector<HTMLElement>('[data-cart-drawer-status]');
    if (status) status.textContent = `Panier mis à jour : ${quoted.data.items.reduce((total, line) => total + line.quantity_requested, 0)} article(s), sous-total ${quoted.data.pricing.subtotal.formatted}.`;
    host.querySelectorAll<HTMLButtonElement>('[data-cart-drawer-remove]').forEach((button) => button.addEventListener('click', () => { const position = Number(button.dataset.cartDrawerRemove); const line = button.closest<HTMLElement>('article'); button.disabled = true; line?.classList.add('is-removing'); window.setTimeout(() => { const updated = cart().filter((_, itemPosition) => itemPosition !== position); saveCart(updated); void renderCartDrawer(); }, 180); }));
}

const checkoutPage = document.querySelector<HTMLElement>('[data-checkout-page]');
if (checkoutPage) void renderCheckout(checkoutPage);
async function renderCheckout(host: HTMLElement) {
    let items = cart(); if (!items.length) { host.innerHTML = '<p class="catalogue-empty">Votre panier est vide.</p>'; return; }
    try {
        const [fieldResponse, quoted] = await Promise.all([fetch('/api/v1/public/checkout-fields', { headers: { Accept: 'application/json' } }), quote(items)]);
        items = reconcileStoredCart(items, quoted);
        if (!items.length || !fieldResponse.ok || !quoted.data.can_checkout) throw new Error('Votre panier doit être mis à jour.');
        void trackMetaEvent('InitiateCheckout', { items: authoritativeCartItems(items) });
        const fields = await fieldResponse.json() as CheckoutFieldsResponse;
        const promo = fields.meta.promo_code_field_visible ? '<fieldset class="promo-field"><legend>Code promo</legend><div><input name="promo_code" maxlength="80" autocomplete="off"><button type="button" data-promo-apply>Appliquer</button></div><p data-promo-message aria-live="polite"></p><button class="text-link" type="button" data-promo-remove hidden>Retirer le code</button></fieldset>' : '';
         host.innerHTML = `<div class="checkout-layout"><form class="checkout-form" data-order-form novalidate><div class="form-errors" data-form-errors aria-live="assertive"></div>${fields.data.map(checkoutField).join('')}<p class="privacy-note">Vos informations servent uniquement à traiter et confirmer votre commande.</p><div class="checkout-submit-area">${promo}<button class="button button-dark" type="submit">Confirmer la commande</button></div></form><aside class="cart-summary" data-checkout-summary aria-label="Récapitulatif de commande">${checkoutOrderSummary(quoted)}</aside></div>`;
         const form = host.querySelector<HTMLFormElement>('[data-order-form]');
         if (form) mountGovernorateCombobox(form);
        if (form) mountCheckoutDraftAutosave(form, fields, items);
        let appliedPromoCode = '';
        let invalidPromoCode = false;
        form?.querySelector('[data-promo-apply]')?.addEventListener('click', async () => { const input = form.elements.namedItem('promo_code') as HTMLInputElement; const message = form.querySelector<HTMLElement>('[data-promo-message]'); const remove = form.querySelector<HTMLButtonElement>('[data-promo-remove]'); const code = input.value.trim(); if (!code) return; try { const updated = await quote(items, code); const summary = host.querySelector<HTMLElement>('[data-checkout-summary]'); if (summary) summary.innerHTML = checkoutOrderSummary(updated); appliedPromoCode = code; invalidPromoCode = false; if (message) message.textContent = 'Code promo appliqué.'; if (remove) remove.hidden = false; } catch { appliedPromoCode = ''; invalidPromoCode = true; if (message) message.textContent = 'Ce code promotionnel n’est pas valide. Retirez-le ou saisissez un autre code pour continuer.'; if (remove) remove.hidden = false; } });
        form?.querySelector<HTMLInputElement>('[name="promo_code"]')?.addEventListener('input', () => { appliedPromoCode = ''; invalidPromoCode = false; });
        form?.querySelector('[data-promo-remove]')?.addEventListener('click', async () => { const input = form.elements.namedItem('promo_code') as HTMLInputElement; const message = form.querySelector<HTMLElement>('[data-promo-message]'); const remove = form.querySelector<HTMLButtonElement>('[data-promo-remove]'); input.value = ''; appliedPromoCode = ''; invalidPromoCode = false; try { const updated = await quote(items); const summary = host.querySelector<HTMLElement>('[data-checkout-summary]'); if (summary) summary.innerHTML = checkoutOrderSummary(updated); if (message) message.textContent = 'Code promo retiré.'; if (remove) remove.hidden = true; } catch (cause) { if (message) message.textContent = cause instanceof Error ? cause.message : 'Le panier est momentanément indisponible.'; } });
        form?.addEventListener('submit', (event) => { event.preventDefault(); const input = form.elements.namedItem('promo_code') as HTMLInputElement | null; const message = form.querySelector<HTMLElement>('[data-promo-message]'); const code = input?.value.trim() ?? ''; if (code && appliedPromoCode !== code) { if (message) message.textContent = invalidPromoCode ? 'Ce code promotionnel n’est pas valide. Retirez-le ou saisissez un autre code pour continuer.' : 'Appliquez ce code promotionnel avant de confirmer la commande.'; input?.focus(); return; } void submitOrder(form, fields, items); });
    } catch (cause) { host.innerHTML = `<p class="commerce-alert">${escapeHtml(cause instanceof Error ? cause.message : 'Commande indisponible.')}</p>`; }
}
function checkoutErrorId(key: string): string { return `checkout-error-${key.replace(/[^a-z0-9_-]/gi, '-')}`; }
function checkoutFieldError(key: string): string { return `<small id="${checkoutErrorId(key)}" class="checkout-field-error" data-checkout-field-error="${escapeHtml(key)}" role="alert" hidden></small>`; }
function checkoutField(field: CheckoutField): string {
    const required = field.is_required ? ' required' : '';
    const label = `${escapeHtml(field.label)}${field.is_required ? ' *' : ''}`;
    const describedBy = ` aria-describedby="${checkoutErrorId(field.key)}"`;
    if (field.type === 'textarea') return `<label>${label}<textarea name="${escapeHtml(field.key)}"${required}${describedBy}></textarea>${checkoutFieldError(field.key)}</label>`;
    if (field.key === 'governorate' && (field.options ?? []).length > 0) {
        const options = (field.options ?? []).map((option, index) => `<button id="checkout-governorate-option-${index}" type="button" role="option" class="checkout-combobox-option" data-governorate-option="${escapeHtml(option)}" aria-selected="false">${escapeHtml(option)}</button>`).join('');
        return `<div class="checkout-combobox" data-governorate-combobox><label>${label}<input type="search" autocomplete="off" autocapitalize="words" spellcheck="false" inputmode="search" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="checkout-governorate-options" aria-describedby="${checkoutErrorId('governorate')}" data-governorate-query placeholder="Rechercher un gouvernorat…"${field.is_required ? ' aria-required="true"' : ''}></label><input type="hidden" name="governorate" data-governorate-value><div id="checkout-governorate-options" class="checkout-combobox-options" role="listbox" hidden>${options}</div><small class="checkout-field-hint">Saisissez les premières lettres, puis choisissez un gouvernorat proposé.</small><small id="${checkoutErrorId('governorate')}" class="checkout-field-error" data-governorate-error data-checkout-field-error="governorate" role="alert" hidden>Veuillez sélectionner un gouvernorat dans la liste.</small></div>`;
    }
    if (field.type === 'select') return `<label>${label}<select name="${escapeHtml(field.key)}"${required}${describedBy}><option value="">Choisir…</option>${(field.options ?? []).map((option) => `<option>${escapeHtml(option)}</option>`).join('')}</select>${checkoutFieldError(field.key)}</label>`;
    if (field.type === 'radio') return `<fieldset><legend>${label}</legend>${(field.options ?? []).map((option) => `<label class="inline-check"><input type="radio" name="${escapeHtml(field.key)}" value="${escapeHtml(option)}"${required}> ${escapeHtml(option)}</label>`).join('')}${checkoutFieldError(field.key)}</fieldset>`;
    if (field.type === 'checkbox') return `<label class="inline-check"><input type="checkbox" name="${escapeHtml(field.key)}" value="true"${required}${describedBy}> ${label}${checkoutFieldError(field.key)}</label>`;
    const type = field.type === 'number' ? 'number' : field.key === 'phone' ? 'tel' : 'text';
    const phoneAttrs = field.key === 'phone' ? ' inputmode="tel" autocomplete="tel"' : '';
    return `<label>${label}<input name="${escapeHtml(field.key)}" type="${type}"${phoneAttrs}${required}${describedBy}>${checkoutFieldError(field.key)}</label>`;
}
function mountGovernorateCombobox(form: HTMLFormElement): void {
    const root = form.querySelector<HTMLElement>('[data-governorate-combobox]');
    if (!root || root.dataset.mounted === 'true') return;
    root.dataset.mounted = 'true';
    const query = root.querySelector<HTMLInputElement>('[data-governorate-query]');
    const value = root.querySelector<HTMLInputElement>('[data-governorate-value]');
    const list = root.querySelector<HTMLElement>('[data-governorate-options], #checkout-governorate-options');
    const error = root.querySelector<HTMLElement>('[data-governorate-error]');
    const options = [...root.querySelectorAll<HTMLButtonElement>('[data-governorate-option]')];
    let activeIndex = -1;
    let selectedLabel = '';
    const normalized = (input: string) => input.trim().toLocaleLowerCase('fr');
    const setExpanded = (expanded: boolean) => {
        if (!list || !query) return;
        list.hidden = !expanded;
        query.setAttribute('aria-expanded', String(expanded));
    };
    const clearSelection = () => {
        selectedLabel = '';
        if (value) value.value = '';
        options.forEach((option) => option.setAttribute('aria-selected', 'false'));
    };
    const showError = (visible: boolean) => {
        if (error) error.hidden = !visible;
        if (query) query.setAttribute('aria-invalid', String(visible));
    };
    const select = (option: HTMLButtonElement) => {
        selectedLabel = option.dataset.governorateOption || '';
        if (value) value.value = selectedLabel;
        if (query) query.value = selectedLabel;
        options.forEach((candidate) => candidate.setAttribute('aria-selected', String(candidate === option)));
        activeIndex = options.indexOf(option);
        showError(false);
        setExpanded(false);
    };
    const filter = () => {
        const term = normalized(query?.value || '');
        if (selectedLabel && normalized(selectedLabel) !== term) clearSelection();
        activeIndex = -1;
        let visible = 0;
        options.forEach((option) => {
            const matches = term === '' || normalized(option.dataset.governorateOption || '').includes(term);
            option.hidden = !matches;
            if (matches) visible += 1;
        });
        setExpanded(true);
        if (query) query.setAttribute('aria-activedescendant', '');
        if (visible === 0) setExpanded(false);
    };
    const move = (delta: number) => {
        const visible = options.filter((option) => !option.hidden);
        if (!visible.length) return;
        activeIndex = (activeIndex + delta + visible.length) % visible.length;
        visible.forEach((option, index) => option.classList.toggle('is-active', index === activeIndex));
        query?.setAttribute('aria-activedescendant', visible[activeIndex]?.id ?? '');
        visible[activeIndex]?.scrollIntoView({ block: 'nearest' });
    };
    query?.addEventListener('focus', () => { filter(); });
    query?.addEventListener('input', () => { showError(false); filter(); });
    query?.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') { event.preventDefault(); setExpanded(true); move(1); }
        else if (event.key === 'ArrowUp') { event.preventDefault(); setExpanded(true); move(-1); }
        else if (event.key === 'Enter') {
            const visible = options.filter((option) => !option.hidden);
            const exact = visible.find((option) => normalized(option.dataset.governorateOption || '') === normalized(query?.value || ''));
            if (exact) { event.preventDefault(); select(exact); }
            else if (activeIndex >= 0 && visible[activeIndex]) { event.preventDefault(); select(visible[activeIndex]); }
        } else if (event.key === 'Escape') { setExpanded(false); }
        else if (event.key === 'Tab') { setExpanded(false); }
    });
    options.forEach((option) => option.addEventListener('click', () => select(option)));
    document.addEventListener('click', (event) => { if (!root.contains(event.target as Node)) setExpanded(false); });
}
function validateGovernorate(form: HTMLFormElement): boolean {
    const root = form.querySelector<HTMLElement>('[data-governorate-combobox]');
    if (!root) return true;
    const query = root.querySelector<HTMLInputElement>('[data-governorate-query]');
    const value = root.querySelector<HTMLInputElement>('[data-governorate-value]');
    const valid = Boolean(value?.value && query?.value === value.value);
    const error = root.querySelector<HTMLElement>('[data-governorate-error]');
    if (error) error.hidden = valid;
    query?.setAttribute('aria-invalid', String(!valid));
    return valid;
}
function validatePhone(form: HTMLFormElement): boolean {
    const phone = form.elements.namedItem('phone') as HTMLInputElement | null;
    if (!phone) return true;
    const digits = phone.value.replace(/\D/g, '');
    const valid = /^[+]?[0-9()\s-]*$/.test(phone.value.trim()) && digits.length >= 8 && digits.length <= 15;
    const error = form.querySelector<HTMLElement>('[data-checkout-field-error="phone"]');
    if (error) { error.hidden = valid; error.textContent = valid ? '' : 'Le numéro de téléphone doit contenir au moins 8 chiffres.'; }
    phone.setAttribute('aria-invalid', String(!valid));
    return valid;
}
function focusCheckoutError(form: HTMLFormElement, preferred?: HTMLElement | null): void {
    const target = preferred ?? form.querySelector<HTMLElement>('[aria-invalid="true"]:not([disabled])');
    const summary = form.querySelector<HTMLElement>('[data-form-errors]');
    const element = target && !target.hidden ? target : summary;
    if (!element) return;
    const collapsed = element.closest<HTMLDetailsElement>('details:not([open])');
    if (collapsed) collapsed.open = true;
    if (element === summary) summary?.setAttribute('tabindex', '-1');
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    element.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
    const offset = [...document.querySelectorAll<HTMLElement>('.store-header, .announcement-bar')]
        .reduce((total, header) => total + header.getBoundingClientRect().height, 0) + 16;
    window.scrollBy({ top: -offset, behavior: reduceMotion ? 'auto' : 'smooth' });
    element.focus({ preventScroll: true });
}
function checkoutOrderSummary(quoted: Quote): string { return `<section class="checkout-order-items" aria-label="Articles de votre commande"><h2>Votre sélection</h2>${quoted.data.items.map((line) => `<article>${line.image_url ? `<img src="${escapeHtml(line.image_url)}" alt="">` : '<span class="checkout-order-item-image-fallback" aria-hidden="true">PC</span>'}<div><strong>${escapeHtml(line.name)}</strong>${line.variant_label ? `<small>${escapeHtml(line.variant_label)}</small>` : ''}<small>Quantité : ${line.quantity_requested}</small></div><strong>${line.line_total.formatted}</strong></article>`).join('')}</section>${checkoutSummary(quoted)}`; }
function checkoutSummary(quoted: Quote): string { const promo = quoted.data.pricing.promo_code; return `<p>Sous-total <strong data-cart-subtotal>${quoted.data.pricing.subtotal.formatted}</strong></p>${promo ? `<p>Code ${escapeHtml(promo.code)} <strong>− ${promo.discount.formatted}</strong></p>` : ''}<p>Livraison <strong data-cart-shipping>${quoted.data.pricing.shipping.fee.formatted}</strong></p><p class="cart-total">Total <strong data-cart-total>${quoted.data.pricing.total.formatted}</strong></p><p>Paiement à la livraison.</p>`; }
const checkoutKeysInMemory = new Map<string, string>();

function createCheckoutUuid(): string {
    const cryptoApi = window.crypto;
    if (typeof cryptoApi?.randomUUID === 'function') return cryptoApi.randomUUID();
    if (typeof cryptoApi?.getRandomValues === 'function') {
        const bytes = new Uint8Array(16);
        cryptoApi.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    }
    const random = `${Date.now().toString(16)}${Math.random().toString(16).slice(2)}`.padEnd(32, '0').slice(0, 32);
    return `${random.slice(0, 8)}-${random.slice(8, 12)}-4${random.slice(13, 16)}-${((parseInt(random.slice(16, 18), 16) & 0x3f) | 0x80).toString(16).padStart(2, '0')}${random.slice(18, 20)}-${random.slice(20)}`;
}

function checkoutKey(storageKey = 'pc_checkout_key') {
    const memoryKey = checkoutKeysInMemory.get(storageKey);
    if (memoryKey) return memoryKey;
    try {
        const saved = window.sessionStorage?.getItem(storageKey);
        if (saved) {
            checkoutKeysInMemory.set(storageKey, saved);
            return saved;
        }
    } catch { /* Continue with the in-memory fallback. */ }
    const created = createCheckoutUuid();
    checkoutKeysInMemory.set(storageKey, created);
    try { window.sessionStorage?.setItem(storageKey, created); } catch { /* Storage can be unavailable on HTTP or private browsers. */ }
    return created;
}
function showCheckoutErrors(form: HTMLFormElement, errors: Record<string, string[]> | undefined) {
    form.querySelectorAll<HTMLElement>('[aria-invalid="true"]').forEach((field) => field.removeAttribute('aria-invalid'));
    form.querySelectorAll<HTMLElement>('[data-checkout-field-error]').forEach((field) => { field.hidden = true; field.textContent = ''; });
    if (!errors) return '';
    const messages = Object.entries(errors).flatMap(([key, values]) => {
        const fieldKey = key.replace(/^customer\./, '');
        const field = form.elements.namedItem(fieldKey) as HTMLElement | RadioNodeList | null;
        const control = field instanceof RadioNodeList ? field[0] as HTMLElement | undefined : field;
        const combobox = fieldKey === 'governorate' ? form.querySelector<HTMLElement>('[data-governorate-query]') : null;
        const target = combobox ?? control;
        target?.setAttribute('aria-invalid', 'true');
        const fieldError = form.querySelector<HTMLElement>(`[data-checkout-field-error="${CSS.escape(fieldKey)}"]`);
        if (fieldError) { fieldError.hidden = false; fieldError.textContent = values.join(' '); }
        return values;
    });
    focusCheckoutError(form);
    return messages.join(' ');
}
type CheckoutSubmissionOptions = { preserveCart?: boolean; source?: 'cart' | 'buy_now'; storageKey?: string };
const CHECKOUT_DRAFT_STORAGE_KEY = 'pc_checkout_draft_token';
let checkoutDraftTimer: number | undefined;
let checkoutDraftSaveQueue: Promise<void> = Promise.resolve();
function draftCustomer(fields: CheckoutFieldsResponse, form: HTMLFormElement): { customer: Record<string, unknown>; checkout_data: Record<string, unknown> } {
    const values = new FormData(form);
    const fixed = new Set(['full_name', 'phone', 'governorate', 'city', 'address']);
    const entries = fields.data.filter((field) => values.has(field.key)).map((field) => [field.key, values.get(field.key)] as const);
    return { customer: Object.fromEntries(entries.filter(([key]) => fixed.has(key))), checkout_data: Object.fromEntries(entries.filter(([key]) => !fixed.has(key))) };
}
function mountCheckoutDraftAutosave(form: HTMLFormElement, fields: CheckoutFieldsResponse, items: CartItem[]): void {
    const schedule = () => {
        window.clearTimeout(checkoutDraftTimer);
        checkoutDraftTimer = window.setTimeout(() => {
            const captured = draftCustomer(fields, form);
            const customer = captured.customer;
            if (![...Object.values(customer), ...Object.values(captured.checkout_data)].some((value) => String(value ?? '').trim() !== '')) return;
            const promoCode = String((new FormData(form)).get('promo_code') ?? '').trim();
            const body = JSON.stringify({ customer, checkout_data: captured.checkout_data, items: authoritativeCartItems(items), promo_code: promoCode || null });
            checkoutDraftSaveQueue = checkoutDraftSaveQueue.then(async () => {
                let token: string | null = null;
                try { token = window.localStorage?.getItem(CHECKOUT_DRAFT_STORAGE_KEY) ?? null; } catch { /* Storage may be unavailable. */ }
                const response = await fetch(token ? `/api/v1/public/checkout-drafts/${encodeURIComponent(token)}` : '/api/v1/public/checkout-drafts', { method: token ? 'PATCH' : 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body });
                if (!response.ok) return;
                const payload = await response.json() as { data?: { token?: string } };
                if (payload.data?.token) { try { window.localStorage?.setItem(CHECKOUT_DRAFT_STORAGE_KEY, payload.data.token); } catch { /* Storage may be unavailable. */ } }
            }).catch(() => { /* Draft capture must never interrupt checkout. */ });
        }, 1000);
    };
    form.addEventListener('input', schedule);
    form.addEventListener('change', schedule);
}
async function submitOrder(form: HTMLFormElement, fields: CheckoutFieldsResponse, items: CartItem[], options: CheckoutSubmissionOptions = {}) {
    const button = form.querySelector<HTMLButtonElement>('button[type="submit"]'); const errorTarget = form.querySelector<HTMLElement>('[data-form-errors]');
    const values = new FormData(form);
    const governorateValid = validateGovernorate(form);
    const phoneValid = validatePhone(form);
    if (!governorateValid || !phoneValid) {
        if (errorTarget) errorTarget.textContent = !governorateValid ? 'Veuillez sélectionner un gouvernorat dans la liste.' : 'Le numéro de téléphone doit contenir au moins 8 chiffres.';
        focusCheckoutError(form);
        return;
    }
    const customer = Object.fromEntries(fields.data.filter((field) => values.has(field.key)).map((field) => [field.key, values.get(field.key)])); const promoCode = String(values.get('promo_code') ?? '').trim();
    if (button) { button.disabled = true; button.textContent = 'Confirmation en cours…'; }
    let draftToken: string | null = null;
    try { draftToken = window.localStorage?.getItem(CHECKOUT_DRAFT_STORAGE_KEY) ?? null; } catch { /* Storage may be unavailable. */ }
    try {
        const response = await fetch('/api/v1/public/orders', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'Idempotency-Key': checkoutKey(options.storageKey ?? 'pc_checkout_key') }, body: JSON.stringify({ checkout_schema_version: fields.meta.schema_version, customer, items: authoritativeCartItems(items), checkout_source: options.source ?? 'cart', ...(promoCode ? { promo_code: promoCode } : {}), ...(draftToken ? { draft_token: draftToken } : {}) }) });
        const payload = await response.json().catch(() => null) as { data?: { confirmation?: { url: string }; meta?: { browser_event?: BrowserMetaEvent | null } }; message?: string; errors?: Record<string, string[]> } | null;
        if (!response.ok || !payload?.data?.confirmation?.url) {
            const fieldMessage = showCheckoutErrors(form, payload?.errors);
            const retryAfter = response.status === 429 ? response.headers.get('Retry-After') : null;
            const retryMessage = retryAfter ? ` Réessayez dans ${retryAfter} secondes.` : '';
            const sessionMessage = response.status === 419 ? 'Votre session a expiré. Actualisez la page avant de réessayer.' : '';
            throw new Error(fieldMessage || sessionMessage || `${payload?.message || 'La commande n’a pas pu être confirmée.'}${retryMessage}`);
        }
        try { if (!options.preserveCart) window.localStorage?.removeItem(CART_KEY); window.localStorage?.removeItem(CHECKOUT_DRAFT_STORAGE_KEY); window.sessionStorage?.removeItem(options.storageKey ?? 'pc_checkout_key'); if (payload.data.meta?.browser_event) window.sessionStorage?.setItem('pc_meta_purchase', JSON.stringify(payload.data.meta.browser_event)); } catch { /* Confirmation remains valid if browser storage is unavailable. */ }
        window.location.assign(payload.data.confirmation.url);
    } catch (cause) {
        const message = cause instanceof Error ? cause.message : 'La commande n’a pas pu être confirmée.';
        if (promoCode && /promo/i.test(message)) { const promoMessage = form.querySelector<HTMLElement>('[data-promo-message]'); const promoRemove = form.querySelector<HTMLButtonElement>('[data-promo-remove]'); if (promoMessage) promoMessage.textContent = 'Ce code promotionnel n’est pas valide. Retirez-le ou saisissez un autre code pour continuer.'; if (promoRemove) promoRemove.hidden = false; }
        if (errorTarget) errorTarget.textContent = message;
        if (!form.querySelector('[aria-invalid="true"]')) focusCheckoutError(form);
        if (button) { button.disabled = false; button.textContent = 'Confirmer la commande'; }
    }
}

const complaintForm = document.querySelector<HTMLFormElement>('[data-complaint-form]');
complaintForm?.addEventListener('submit', async (event) => { event.preventDefault(); const button = complaintForm.querySelector<HTMLButtonElement>('button[type="submit"]'); const errors = complaintForm.querySelector<HTMLElement>('[data-complaint-errors]'); if (button) button.disabled = true; try { const response = await fetch('/api/v1/public/complaints', { method: 'POST', headers: { Accept: 'application/json' }, body: new FormData(complaintForm) }); const payload = await response.json() as { message?: string; errors?: Record<string, string[]> }; if (!response.ok) throw new Error(payload.errors ? Object.values(payload.errors).flat().join(' ') : payload.message || 'Réclamation invalide.'); complaintForm.hidden = true; const success = document.querySelector<HTMLElement>('[data-complaint-success]'); if (success) success.hidden = false; } catch (cause) { if (errors) errors.textContent = cause instanceof Error ? cause.message : 'La réclamation n’a pas pu être envoyée.'; if (button) button.disabled = false; } });

type MarketingConsentState = { necessary: boolean; marketing: boolean; policy_version: number; decided: boolean };
const consentBanner = document.querySelector<HTMLElement>('[data-consent-banner]');
const consentError = document.querySelector<HTMLElement>('[data-consent-error]');
const CONSENT_CACHE_KEY = 'pc_marketing_consent_v1';

function cachedConsent(): MarketingConsentState | null {
    try {
        const cached = JSON.parse(window.localStorage?.getItem(CONSENT_CACHE_KEY) ?? 'null') as MarketingConsentState | null;
        return cached && typeof cached.decided === 'boolean' && typeof cached.marketing === 'boolean' && typeof cached.policy_version === 'number' ? cached : null;
    } catch {
        return null;
    }
}
function cacheConsent(state: MarketingConsentState): void {
    try { window.localStorage?.setItem(CONSENT_CACHE_KEY, JSON.stringify(state)); } catch { /* The server remains the consent source of truth. */ }
}

if (consentBanner) {
    let consentState: MarketingConsentState = { necessary: true, marketing: false, policy_version: 0, decided: false };
    let pendingConsentRequest = false;
    let consentCloseTimer: number | undefined;
    const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
    const clearApplicationMetaCookies = () => {
        for (const name of ['_fbp', '_fbc']) document.cookie = `${name}=; Max-Age=0; Path=/; SameSite=Lax`;
    };
    const setConsentError = (message = '') => { if (consentError) consentError.textContent = message; };
    const setConsentButtonsDisabled = (disabled: boolean) => document.querySelectorAll<HTMLButtonElement>('[data-consent-accept]').forEach((button) => { button.disabled = disabled; });
    const beginConsentClose = () => {
        consentBanner.dataset.consentState = 'closing';
        consentBanner.setAttribute('aria-busy', 'true');
        setConsentButtonsDisabled(true);
        consentCloseTimer = window.setTimeout(() => { consentBanner.hidden = true; }, 180);
    };
    const reopenConsent = () => {
        if (consentCloseTimer) window.clearTimeout(consentCloseTimer);
        consentBanner.hidden = false;
        delete consentBanner.dataset.consentState;
        consentBanner.removeAttribute('aria-busy');
        setConsentButtonsDisabled(false);
    };
    const applyConsentState = (next: MarketingConsentState) => {
        consentState = next;
        cacheConsent(next);
        if (!next.marketing) clearApplicationMetaCookies();
        consentBanner.hidden = next.decided;
        delete consentBanner.dataset.consentState;
        consentBanner.removeAttribute('aria-busy');
        setConsentButtonsDisabled(false);
        document.dispatchEvent(new CustomEvent('pc:marketing-consent', { detail: next }));
    };
    const saveConsent = async () => {
        if (pendingConsentRequest) return;
        pendingConsentRequest = true;
        setConsentError();
        beginConsentClose();
        try {
            const response = await fetch('/api/v1/public/marketing-consent', {
                method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}) },
                body: JSON.stringify({ decision: 'accept_all' }),
            });
            const payload = await response.json().catch(() => null) as { data?: MarketingConsentState; message?: string } | null;
            if (!response.ok || !payload?.data) throw new Error(payload?.message || 'Votre consentement n’a pas pu être enregistré.');
            applyConsentState(payload.data);
        } catch (cause) {
            reopenConsent();
            setConsentError(cause instanceof Error ? cause.message : 'Votre consentement n’a pas pu être enregistré.');
        } finally {
            pendingConsentRequest = false;
        }
    };
    const loadConsent = async () => {
        try {
            const response = await fetch('/api/v1/public/marketing-consent', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const payload = await response.json().catch(() => null) as { data?: MarketingConsentState } | null;
            if (!response.ok || !payload?.data) throw new Error();
            consentState = payload.data;
        } catch {
            consentState = { necessary: true, marketing: false, policy_version: 0, decided: false };
        }
        applyConsentState(consentState);
    };

    document.querySelectorAll<HTMLButtonElement>('[data-consent-accept]').forEach((button) => button.addEventListener('click', () => void saveConsent()));
    const cached = cachedConsent();
    if (cached) applyConsentState(cached);
    else consentBanner.hidden = false;
    scheduleWhenIdle(() => { void loadConsent(); });
}

type BrowserMetaEvent = { public_id: string; event_name: 'PageView' | 'ViewContent' | 'Search' | 'AddToCart' | 'InitiateCheckout' | 'Purchase'; event_id: string; event_time: string; source_url: string; context: Record<string, unknown> };
type BrowserMetaEventName = Exclude<BrowserMetaEvent['event_name'], 'Purchase'>;
type PendingMetaEvent = { eventName: BrowserMetaEventName; context: Record<string, unknown>; key: string };
let currentMarketingConsent = false;
let awaitingStoredMarketingConsentConfirmation = cachedConsent()?.marketing === true;
let metaPixelId: string | null = null;
let metaPixelLoaded = false;
let metaPixelInitialization: Promise<boolean> | null = null;
const pendingMetaEvents: PendingMetaEvent[] = [];
const pendingMetaEventKeys = new Set<string>();

document.addEventListener('pc:marketing-consent', (event) => {
    const state = (event as CustomEvent<MarketingConsentState>).detail;
    currentMarketingConsent = state.marketing;
    awaitingStoredMarketingConsentConfirmation = false;
    if (!state.marketing) {
        pendingMetaEvents.splice(0);
        pendingMetaEventKeys.clear();

        return;
    }
    void initializeMetaPixel();
    void trackMeaningfulPageView();
    flushPendingMetaEvents();
    dispatchPendingPurchase();
});

function sourceUrl(): string { return window.location.href; }
function csrfHeaders(): Record<string, string> { const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content; return { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { 'X-CSRF-TOKEN': token } : {}) }; }
function routeType(): 'home' | 'products' | 'category' | 'product' | 'search' | 'cart' | 'checkout' | 'confirmation' | 'static' {
    if (document.querySelector('[data-product-detail]')) return 'product';
    if (document.querySelector('[data-checkout-page]')) return 'checkout';
    if (document.querySelector('[data-cart-page]')) return 'cart';
    if (document.querySelector('.confirmation-page')) return 'confirmation';
    if (window.location.pathname === '/') return 'home';
    if (window.location.pathname.startsWith('/recherche')) return 'search';
    if (window.location.pathname.startsWith('/categories/')) return 'category';
    if (window.location.pathname.startsWith('/produits')) return 'products';
    return 'static';
}
function eventOnce(key: string): boolean { try { if (window.sessionStorage?.getItem(key)) return false; window.sessionStorage?.setItem(key, '1'); } catch { /* The durable server event remains independently deduplicated where required. */ } return true; }
async function initializeMetaPixel(): Promise<boolean> {
    if (!currentMarketingConsent) return false;
    if (metaPixelLoaded) return true;
    if (metaPixelInitialization) return metaPixelInitialization;

    metaPixelInitialization = (async (): Promise<boolean> => {
        const response = await fetch('/api/v1/public/meta/pixel', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const payload = await response.json() as { data?: { pixel_id?: string | null } };
        const pixelId = payload.data?.pixel_id;
        if (!response.ok || !pixelId || !/^\d{5,30}$/.test(pixelId)) return false;
        metaPixelId = pixelId;
        const win = window as Window & { fbq?: (...args: unknown[]) => void; _fbq?: unknown[] };
        let load: Promise<boolean>;
        if (!win.fbq) {
            const queue = (...args: unknown[]) => { (queue as typeof queue & { queue?: unknown[][] }).queue?.push(args); };
            (queue as typeof queue & { queue?: unknown[][]; loaded?: boolean; version?: string }).queue = [];
            (queue as typeof queue & { loaded?: boolean }).loaded = true;
            (queue as typeof queue & { version?: string }).version = '2.0';
            win.fbq = queue;
            win._fbq = (queue as typeof queue & { queue?: unknown[][] }).queue;
            const script = document.createElement('script');
            script.async = true;
            script.dataset.metaPixelLoader = 'true';
            script.src = 'https://connect.facebook.net/en_US/fbevents.js';
            load = new Promise((resolve) => {
                const timeout = window.setTimeout(() => resolve(false), 5_000);
                script.addEventListener('load', () => { window.clearTimeout(timeout); resolve(true); }, { once: true });
                script.addEventListener('error', () => { window.clearTimeout(timeout); resolve(false); }, { once: true });
            });
            document.head.append(script);
        } else {
            load = Promise.resolve(true);
        }
        win.fbq('init', pixelId);
        metaPixelLoaded = await load;
        if (!metaPixelLoaded) {
            document.querySelector<HTMLScriptElement>('script[data-meta-pixel-loader="true"]')?.remove();
            delete win.fbq;
            delete win._fbq;
            metaPixelId = null;
        }

        return metaPixelLoaded;
    })().catch(() => false);

    const loaded = await metaPixelInitialization;
    if (!loaded) metaPixelInitialization = null;

    return loaded;
}
async function trackMetaEvent(eventName: BrowserMetaEventName, context: Record<string, unknown>): Promise<void> {
    if (!currentMarketingConsent) {
        if (awaitingStoredMarketingConsentConfirmation) queuePendingMetaEvent(eventName, context);

        return;
    }
    try {
        const response = await fetch('/api/v1/public/meta/events', { method: 'POST', credentials: 'same-origin', keepalive: true, headers: csrfHeaders(), body: JSON.stringify({ event_name: eventName, source_url: sourceUrl(), route_type: routeType(), ...context }) });
        const payload = await response.json() as { data?: { event?: BrowserMetaEvent | null } };
        const event = payload.data?.event;
        if (!response.ok || !event || !currentMarketingConsent) return;
        await dispatchBrowserMetaEvent(event);
    } catch { /* CAPI remains queued independently when the browser request succeeds. */ }
}
function queuePendingMetaEvent(eventName: BrowserMetaEventName, context: Record<string, unknown>): void {
    const key = `${eventName}:${JSON.stringify(context)}`;
    if (pendingMetaEventKeys.has(key)) return;
    pendingMetaEventKeys.add(key);
    pendingMetaEvents.push({ eventName, context, key });
}
function flushPendingMetaEvents(): void {
    const pending = pendingMetaEvents.splice(0);
    pendingMetaEventKeys.clear();
    pending.forEach(({ eventName, context }) => { void trackMetaEvent(eventName, context); });
}
async function dispatchBrowserMetaEvent(event: BrowserMetaEvent): Promise<void> {
    if (!currentMarketingConsent) return;
    await initializeMetaPixel();
    const win = window as Window & { fbq?: (...args: unknown[]) => void };
    if (!metaPixelLoaded || !metaPixelId || !win.fbq) return;
    const custom = event.context;
    const contents = Array.isArray(custom.contents) ? custom.contents.map((row) => {
        if (!row || typeof row !== 'object') return row;
        const item = row as Record<string, unknown>;
        const millimes = typeof item.item_price_millimes === 'number' ? item.item_price_millimes : null;
        return millimes === null ? row : { id: item.id, quantity: item.quantity, item_price: Number((millimes / 1000).toFixed(3)) };
    }) : undefined;
    win.fbq('track', event.event_name, {
        content_type: Array.isArray(custom.content_ids) && custom.content_ids.length > 0 ? 'product' : undefined,
        content_ids: custom.content_ids,
        contents,
        value: typeof custom.value_millimes === 'number' ? Number((custom.value_millimes / 1000).toFixed(3)) : undefined,
        currency: typeof custom.value_millimes === 'number' ? 'TND' : undefined,
        num_items: custom.item_count,
        search_string: custom.search_term,
    }, { eventID: event.event_id });
    void fetch(`/api/v1/public/meta/events/${encodeURIComponent(event.public_id)}/browser-attempt`, { method: 'POST', credentials: 'same-origin', headers: csrfHeaders() });
}
function trackMeaningfulPageView(): void {
    const route = routeType();
    if (!eventOnce(`pc_meta_page:${window.location.pathname}${window.location.search}`)) return;
    void trackMetaEvent('PageView', { route_type: route });
    const detail = document.querySelector<HTMLElement>('[data-product-detail]');
    if (detail?.dataset.productPublicId && eventOnce(`pc_meta_product:${detail.dataset.productPublicId}`)) void trackMetaEvent('ViewContent', { product_public_id: detail.dataset.productPublicId });
    const query = new URLSearchParams(window.location.search).get('q')?.trim();
    if (route === 'search' && query && eventOnce(`pc_meta_search:${query}`)) void trackMetaEvent('Search', { search_term: query, result_count: document.querySelectorAll('[data-product-card]').length });
}
function dispatchPendingPurchase(): void {
    if (routeType() !== 'confirmation' || !currentMarketingConsent) return;
    try {
        const raw = window.sessionStorage?.getItem('pc_meta_purchase')
            ?? document.querySelector<HTMLElement>('.confirmation-page')?.dataset.metaPurchase;
        if (!raw) return;
        const event = JSON.parse(raw) as BrowserMetaEvent;
        if (event.event_name !== 'Purchase' || !event.event_id || !eventOnce(`pc_meta_purchase:${event.event_id}`)) return;
        void dispatchBrowserMetaEvent(event);
    } catch { /* A browser storage failure cannot affect the confirmed order. */ }
}
