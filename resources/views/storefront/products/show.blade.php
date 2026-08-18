@php
    $defaultVariant = $product->variants->where('is_active', true)->sortByDesc('is_default')->first();
    $defaultVariantRegular = $defaultVariant?->regular_price_millimes;
    $defaultVariantPromotion = $defaultVariant?->promotional_price_millimes;
    $displayRegularPrice = $defaultVariantRegular ?? $product->regular_price_millimes;
    $displayPromotionPrice = $defaultVariant
        ? ($defaultVariantPromotion ?? ($defaultVariantRegular === null ? $product->promotional_price_millimes : null))
        : $product->promotional_price_millimes;
    $hasDisplayPromotion = $displayPromotionPrice !== null && $displayPromotionPrice < $displayRegularPrice;
    $price = $hasDisplayPromotion ? $displayPromotionPrice : $displayRegularPrice;
    $primaryImage = $product->images->firstWhere('is_primary', true)
        ?? ($defaultVariant ? $product->images->firstWhere('product_variant_id', $defaultVariant->id) : null)
        ?? $product->images->first();
    $isAvailable = $product->has_variants
        ? $product->variants->contains(fn ($variant) => $variant->is_active && $variant->stock_quantity > 0)
        : $product->stock_quantity > 0;
    $structuredData = [
        '@context' => 'https://schema.org', '@type' => 'Product', 'name' => $product->name,
        'description' => strip_tags($product->short_description ?: $product->full_description ?: $product->name),
        'url' => route('storefront.product', $product->slug),
        'offers' => ['@type' => 'Offer', 'priceCurrency' => 'TND', 'price' => number_format($price / 1000, 3, '.', ''), 'availability' => 'https://schema.org/'.($isAvailable ? 'InStock' : 'OutOfStock')],
    ];
    $breadcrumbStructuredData = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Accueil', 'item' => route('storefront.home')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => $product->category->name, 'item' => route('storefront.category', $product->category->slug)],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $product->name, 'item' => route('storefront.product', $product->slug)],
    ]];
    $variantsForClient = $product->variants->map(function ($variant) use ($product): array {
        $regularPrice = $variant->regular_price_millimes ?? $product->regular_price_millimes;
        $promotionPrice = $variant->promotional_price_millimes
            ?? ($variant->regular_price_millimes === null ? $product->promotional_price_millimes : null);

        return [
            'public_id' => $variant->public_id,
            'sku' => $variant->sku,
            'stock_quantity' => $variant->stock_quantity,
            'is_active' => $variant->is_active,
            'is_default' => $variant->is_default,
            'regular_price_millimes' => $regularPrice,
            'promotional_price_millimes' => $promotionPrice !== null && $promotionPrice < $regularPrice ? $promotionPrice : null,
            'value_ids' => $variant->values->pluck('id')->values(),
            'image_url' => $product->images->firstWhere('product_variant_id', $variant->id)?->public_url,
        ];
    });
    if ($primaryImage?->public_url) $structuredData['image'] = $primaryImage->public_url;
@endphp
<x-layouts.storefront :title="($product->seo_title ?: $product->name).' | ToutDispo'" :description="$product->seo_description ?: ($product->short_description ?: Str::limit(strip_tags($product->full_description ?: ''), 155))" :canonical="route('storefront.product', $product->slug)" :og-image="$primaryImage?->public_url" og-type="product">
    @push('head')<script type="application/ld+json">@json($structuredData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)</script><script type="application/ld+json">@json($breadcrumbStructuredData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)</script>@endpush
    <section class="product-page section" data-product-detail data-product-public-id="{{ $product->public_id }}" data-product-stock="{{ $product->stock_quantity ?? 0 }}" data-product-variants='@json($variantsForClient)'>
        <nav class="breadcrumb" aria-label="Fil d’Ariane"><a href="{{ route('storefront.home') }}">Accueil</a><span>/</span><a href="{{ route('storefront.category', $product->category->slug) }}">{{ $product->category->name }}</a><span>/</span><span aria-current="page">{{ $product->name }}</span></nav>
        <div class="product-layout">
            <div class="product-gallery" data-gallery>
                @if($product->images->count() > 1)<div class="gallery-thumbnails" data-gallery-thumbnails tabindex="0" aria-label="Autres images du produit">@foreach($product->images as $image)<button type="button" data-gallery-image="{{ $image->public_url }}" aria-label="Voir l’image {{ $loop->iteration }}"><img src="{{ $image->public_url }}" width="96" height="96" loading="lazy" alt=""></button>@endforeach</div>@endif
                <div class="product-main-image">@if($primaryImage && $primaryImage->public_url)<img src="{{ $primaryImage->public_url }}" width="{{ $primaryImage->width }}" height="{{ $primaryImage->height }}" loading="eager" fetchpriority="high" alt="{{ $primaryImage->alt_text ?: $product->name }}" data-gallery-main>@else<span class="product-image-placeholder">PC</span>@endif</div>
            </div>
            <div class="product-details">
                <a class="product-category" href="{{ route('storefront.category', $product->category->slug) }}">{{ $product->category->name }}</a>
                <h1>{{ $product->name }}</h1>
                @if($product->short_description)<p class="product-lead">{{ $product->short_description }}</p>@endif
                <p class="price price-large" data-product-price><strong data-product-effective-price>{{ number_format($price / 1000, 3, ',', ' ') }} TND</strong><del data-product-regular-price @class(['is-hidden' => ! $hasDisplayPromotion])>{{ number_format($displayRegularPrice / 1000, 3, ',', ' ') }} TND</del><span class="sale-badge {{ $hasDisplayPromotion ? '' : 'is-hidden' }}" data-product-sale>Offre</span></p>
                @if($product->has_variants)
                    <div class="variant-picker" data-variant-picker>
                        @foreach($product->optionGroups as $group)<fieldset data-option-group="{{ $group->id }}"><legend>{{ $group->name }}</legend><div>@foreach($group->values as $value)<button type="button" data-option-value="{{ $value->id }}" data-option-parent="{{ $value->parent_product_option_value_id ?? '' }}">{{ $value->value }}</button>@endforeach</div></fieldset>@endforeach
                        <p class="stock-message" data-stock-message role="status" aria-live="polite"><strong data-stock-status>Sélectionnez vos options.</strong><span data-stock-detail></span></p>
                    </div>
                @else
                    <p class="stock-message {{ $product->stock_quantity > 0 ? 'in-stock' : 'out-stock' }}" data-stock-message role="status" aria-live="polite"><strong data-stock-status>{{ $product->stock_quantity > 0 ? 'En stock' : 'Bientôt de retour' }}</strong><span data-stock-detail>@if($product->stock_quantity <= 0)Ce produit sera de nouveau disponible prochainement.@endif</span></p>
                @endif
                <div class="product-actions"><label class="quantity-control">Quantité <span><button type="button" data-quantity-minus aria-label="Réduire la quantité" @disabled(! $isAvailable)>−</button><input data-quantity type="number" min="1" max="{{ $product->has_variants ? 1 : max(1, $product->stock_quantity) }}" value="1" inputmode="numeric" aria-label="Quantité" @disabled(! $isAvailable)><button type="button" data-quantity-plus aria-label="Augmenter la quantité" @disabled(! $isAvailable)>+</button></span></label><div class="product-action-buttons"><button @class(['button', 'button-dark', 'button-stock-unavailable' => ! $isAvailable]) type="button" disabled data-add-to-cart>{{ $isAvailable ? 'Ajouter au panier' : 'Bientôt de retour' }}</button><button class="button button-outline" type="button" @disabled(! $isAvailable) data-buy-now>Commander maintenant</button></div></div>
                <div class="reassurance-row"><span>Paiement à la livraison | </span><span>Confirmation par téléphone | </span><span>Livraison partout en Tunisie</span></div>
                @if($product->full_description)<div class="product-description"><h2>À propos de ce soin</h2><div>{!! nl2br(e($product->full_description)) !!}</div></div>@endif
            </div>
        </div>
        <section class="express-checkout-panel" data-express-checkout hidden aria-labelledby="express-checkout-title"><header><div><p class="eyebrow">Commande directe</p><h2 id="express-checkout-title">Commander ce produit</h2></div><button class="text-link" type="button" data-express-close>Fermer</button></header><p class="express-checkout-notice" data-express-cart-notice hidden>Commande express — ce produit uniquement. Les articles déjà présents dans votre panier ne seront pas inclus.</p><div class="express-checkout-content" data-express-checkout-content></div></section>
    </section>
    @if($relatedProducts->isNotEmpty())<section class="section related-products"><div class="section-heading"><div><p class="eyebrow">À associer</p><h2>Dans la même collection</h2></div></div><div class="product-grid">@foreach($relatedProducts as $related)<x-storefront.product-card :product="$related" />@endforeach</div></section>@endif
</x-layouts.storefront>
