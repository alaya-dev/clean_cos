@push('head')
    @if ($products->previousPageUrl())<link rel="prev" href="{{ $products->previousPageUrl() }}">@endif
    @if ($products->nextPageUrl())<link rel="next" href="{{ $products->nextPageUrl() }}">@endif
@endpush
<x-layouts.storefront title="Tous les soins | ToutDispo" description="Explorez tous les soins ToutDispo.">
    <section class="catalogue-hero">
        <div class="catalogue-container">
            <p class="eyebrow">La boutique</p>
            <h1>Tous les soins</h1>
            <p>Explorez une sélection de produits pour accompagner chaque geste du quotidien.</p>
        </div>
    </section>
    <section class="catalogue-page section">
        <div class="catalogue-container">
            <div class="catalogue-toolbar">
                <nav class="category-pills" aria-label="Catégories">@foreach($categories as $category)<a href="{{ route('storefront.category', $category->slug) }}">{{ $category->name }}</a>@endforeach</nav>
                <p class="result-count" aria-live="polite">{{ $products->total() }} {{ Str::plural('produit', $products->total()) }}</p>
            </div>
            <x-storefront.catalogue-filters :categories="$categories" />
            @if($products->isNotEmpty())
                <div class="product-grid" data-catalogue-grid>@foreach($products as $product)<x-storefront.product-card :product="$product" />@endforeach</div>
                @if($products->hasMorePages())
                    <div class="catalogue-more"><button class="button button-outline" type="button" data-catalogue-more data-next-url="{{ $products->nextPageUrl() }}">Voir plus de produits <span aria-hidden="true">⌄</span></button><p class="sr-only" data-catalogue-more-status aria-live="polite"></p></div>
                @endif
            @else
                <div class="catalogue-empty"><h2>Aucun produit ne correspond à ces filtres.</h2><a class="text-link" href="{{ route('storefront.products') }}">Réinitialiser les filtres</a></div>
            @endif
        </div>
    </section>
</x-layouts.storefront>
