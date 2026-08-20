@props(['product'])
@php
    $image = $product->images->first();
    $renditions = $image?->public_renditions ?? [];
    $hasPromotion = $product->promotional_price_millimes !== null
        && $product->promotional_price_millimes < $product->regular_price_millimes;
    $price = $hasPromotion ? $product->promotional_price_millimes : $product->regular_price_millimes;
@endphp
<article class="product-card" data-product-card>
    <a class="product-card-image" href="{{ route('storefront.product', $product->slug) }}" aria-label="Voir {{ $product->name }}">
        @if($image && $image->public_url)
            <img src="{{ $image->public_url }}"
                 @if($renditions) srcset="@foreach($renditions as $width => $url) {{ $url }} {{ $width }}w{{ !$loop->last ? ',' : '' }} @endforeach" sizes="(min-width: 1024px) 25vw, 50vw" @endif
                 width="{{ $image->width }}" height="{{ $image->height }}" loading="lazy" alt="{{ $image->alt_text ?: $product->name }}">
        @else
            <span class="product-image-placeholder" aria-hidden="true">CC</span>
        @endif
        @if($hasPromotion)
            <span class="sale-badge">Offre</span>
        @endif
    </a>
    <div class="product-card-copy">
        <a class="product-category" href="{{ route('storefront.category', $product->category->slug) }}">{{ $product->category->name }}</a>
        <h3><a href="{{ route('storefront.product', $product->slug) }}">{{ $product->name }}</a></h3>
        <p class="price">
            <strong>{{ number_format($price / 1000, 3, ',', ' ') }} TND</strong>
            @if($hasPromotion)<del>{{ number_format($product->regular_price_millimes / 1000, 3, ',', ' ') }} TND</del>@endif
        </p>
    </div>
</article>
