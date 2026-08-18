<x-layouts.storefront title="ToutDispo | Soins choisis avec intention" description="Découvrez les soins, produits de beauté et rituels ToutDispo. Commandez en ligne avec paiement à la livraison en Tunisie.">
    @if($heroSlides->isNotEmpty())
        <section class="home-hero" aria-label="Carrousel principal" data-hero-carousel data-autoplay="{{ $store['hero_autoplay_enabled'] ? 'true' : 'false' }}" tabindex="0">
            <div class="home-hero-slides">
                @foreach($heroSlides as $slide)
                    <article class="home-hero-slide {{ $loop->first ? 'is-active' : '' }}" data-hero-slide aria-hidden="{{ $loop->first ? 'false' : 'true' }}">
                        <picture>
                            @if($slide->mobile_image_path)<source media="(max-width: 767px)" srcset="{{ app(\App\Support\Media\PublicMediaUrl::class)->forPath($slide->mobile_image_path) }}">@endif
                            <img src="{{ app(\App\Support\Media\PublicMediaUrl::class)->forPath($slide->desktop_image_path) }}" alt="{{ $slide->heading }}" width="1600" height="900" {{ $loop->first ? 'fetchpriority=high loading=eager' : 'loading=lazy' }}>
                        </picture>
                        <div class="home-hero-copy"><p class="eyebrow">{{ $slide->eyebrow }}</p><h1>{{ $slide->heading }}</h1>@if($slide->supporting_text)<p>{{ $slide->supporting_text }}</p>@endif @if($slide->cta_label && $slide->cta_url)<a class="button button-light home-hero-cta" href="{{ $slide->cta_url }}">{{ $slide->cta_label }} <span aria-hidden="true">→</span></a>@endif</div>
                    </article>
                @endforeach
            </div>
            @if($heroSlides->count() > 1)<div class="home-hero-controls"><button type="button" data-hero-prev aria-label="Diapositive précédente">←</button><div class="home-hero-dots">@foreach($heroSlides as $slide)<button type="button" data-hero-dot="{{ $loop->index }}" class="{{ $loop->first ? 'is-active' : '' }}" aria-label="Afficher la diapositive {{ $loop->iteration }}"></button>@endforeach</div><button type="button" data-hero-next aria-label="Diapositive suivante">→</button></div>@endif
        </section>
    @endif

    <section class="home-section category-explorer" aria-labelledby="category-explorer-title">
        <div class="home-section-heading"><p class="eyebrow">Explorer</p><h2 id="category-explorer-title">Nos univers</h2></div>
        <div class="circular-category-rail">
            @forelse($categories as $category)<a href="{{ route('storefront.category', $category->slug) }}"><span class="circular-category-image">@if($category->image_url)<img src="{{ $category->image_url }}" alt="{{ $category->name }}" width="180" height="180" loading="lazy">@endif</span><strong>{{ $category->name }}</strong></a>@empty<p class="empty-inline">Les catégories seront bientôt disponibles.</p>@endforelse
        </div>
    </section>

    @if($featuredSection = $productSections->first())
        <section class="home-section bestsellers-intro" aria-labelledby="featured-title"><p class="eyebrow">{{ $featuredSection->eyebrow }}</p><h2 id="featured-title">{{ $featuredSection->title }}</h2>@if($featuredSection->description)<p>{{ $featuredSection->description }}</p>@endif</section>
        <section class="home-section products-section featured-products" aria-label="Produits mis en avant">@if($featuredSection->products->isNotEmpty())<div class="product-grid">@foreach($featuredSection->products as $product)<x-storefront.product-card :product="$product" />@endforeach</div>@else<p class="empty-inline">Cette sélection sera bientôt disponible.</p>@endif</section>
    @endif

    @foreach($productSections->skip(1) as $section)
        <section class="home-section products-section" aria-labelledby="section-{{ $section->public_id }}"><div class="home-section-heading"><div><p class="eyebrow">{{ $section->eyebrow }}</p><h2 id="section-{{ $section->public_id }}">{{ $section->title }}</h2>@if($section->description)<p>{{ $section->description }}</p>@endif</div><a class="text-link" href="{{ route('storefront.products') }}">Voir la boutique →</a></div>@if($section->products->isNotEmpty())<div class="product-grid">@foreach($section->products as $product)<x-storefront.product-card :product="$product" />@endforeach</div>@else<p class="empty-inline">Cette sélection sera bientôt disponible.</p>@endif</section>
    @endforeach

    @if($visualTiles->isNotEmpty())<section class="home-section" aria-labelledby="visual-tiles-title"><div class="home-section-heading"><p class="eyebrow">Explorer autrement</p><h2 id="visual-tiles-title">Chaque moment a son rituel</h2></div><div class="visual-tile-rail">@foreach($visualTiles as $tile)<a class="visual-category-tile" href="{{ route('storefront.category', $tile->category->slug) }}">@if($tile->desktop_image_path || $tile->category->image_path)<picture>@if($tile->mobile_image_path)<source media="(max-width: 767px)" srcset="{{ app(\App\Support\Media\PublicMediaUrl::class)->forPath($tile->mobile_image_path) }}">@endif<img src="{{ app(\App\Support\Media\PublicMediaUrl::class)->forPath($tile->desktop_image_path ?: $tile->category->image_path) }}" alt="{{ $tile->label }}" width="900" height="1100" loading="lazy"></picture>@endif<span>{{ $tile->label }} →</span></a>@endforeach</div></section>@endif

    @if($editorial)<section class="home-section must-have-section"><div class="must-have-feature">@if($editorial->image_path)<img src="{{ app(\App\Support\Media\PublicMediaUrl::class)->forPath($editorial->image_path) }}" alt="{{ $editorial->heading }}" width="1100" height="900" loading="lazy">@endif<div><p class="eyebrow">{{ $editorial->eyebrow }}</p><h2>{{ $editorial->heading }}</h2><p>{{ $editorial->description }}</p>@if($editorial->cta_label && $editorial->cta_url)<a class="button button-light" href="{{ $editorial->cta_url }}">{{ $editorial->cta_label }}</a>@endif</div></div><div class="compact-product-list">@foreach($editorial->products as $product)<x-storefront.product-card :product="$product" />@endforeach</div></section>@endif

    @if($reassuranceItems->isNotEmpty())<section class="home-section reassurance-section" aria-labelledby="reassurance-title"><div class="home-section-heading"><p class="eyebrow">Nos engagements</p><h2 id="reassurance-title">Une expérience simple et rassurante</h2></div><div class="reassurance-grid">@foreach($reassuranceItems as $item)<article><span class="reassurance-icon" aria-hidden="true" data-icon="{{ $item->icon }}">{{ ['payment' => '✓', 'phone' => '☎', 'delivery' => '⌂', 'quality' => '✦'][$item->icon] ?? '•' }}</span><h3>{{ $item->title }}</h3><p>{{ $item->text }}</p></article>@endforeach</div></section>@endif

    @if($socialItems->isNotEmpty())<section class="home-section social-section" aria-labelledby="social-title"><div class="home-section-heading"><p class="eyebrow">Suivez-nous</p><h2 id="social-title">L’univers Passion</h2></div><div class="social-rail">@foreach($socialItems as $item)<a href="{{ $item->url }}" rel="noopener noreferrer"><img src="{{ app(\App\Support\Media\PublicMediaUrl::class)->forPath($item->image_path) }}" alt="{{ $item->alt_text }}" width="600" height="600" loading="lazy"></a>@endforeach</div></section>@endif

    @if($brandContent)<section class="home-section brand-seo-section"><h2>{{ $brandContent->heading }}</h2><div class="rich-content">{!! $brandContent->content !!}</div></section>@endif
</x-layouts.storefront>
