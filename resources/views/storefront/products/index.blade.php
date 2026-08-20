@push('head')
    @if ($products->previousPageUrl())<link rel="prev" href="{{ $products->previousPageUrl() }}">@endif
    @if ($products->nextPageUrl())<link rel="next" href="{{ $products->nextPageUrl() }}">@endif
@endpush

<x-layouts.storefront title="Tous les soins | Clean’Cos" description="Explorez tous les soins Clean’Cos.">
    <section class="catalogue-hero">
        <div class="catalogue-container"><h1>Tous les soins</h1><p>Explorez chaque univers, puis trouvez le soin adapté à votre rituel.</p></div>
    </section>
    <section class="catalogue-page section">
        <div class="catalogue-container">
            <div class="catalogue-toolbar"><nav class="category-pills" aria-label="Sous-catégories">@foreach($categories as $category)<a href="{{ route('storefront.category', $category->slug) }}">{{ $category->name }}</a>@endforeach</nav><p class="result-count" aria-live="polite">{{ $products->total() }} {{ Str::plural('produit', $products->total()) }}</p></div>
            <x-storefront.catalogue-filters :categories="$categories" />
            @if($products->isNotEmpty())
                @php($groupsByParent = $productsBySubcategory->groupBy(fn ($subcategoryProducts) => $subcategoryProducts->first()->category->parent?->name ?? $subcategoryProducts->first()->category->name))
                <div class="catalogue-groups" data-catalogue-grid>
                    @foreach($groupsByParent as $parentName => $subcategoryGroups)
                        <section class="catalogue-group"><h2>{{ $parentName }}</h2>
                            @foreach($subcategoryGroups as $subcategoryProducts)
                                @php($subcategory = $subcategoryProducts->first()->category)
                                <h3>{{ $subcategory->name }}</h3><div class="product-grid">@foreach($subcategoryProducts as $product)<x-storefront.product-card :product="$product" />@endforeach</div>
                            @endforeach
                        </section>
                    @endforeach
                </div>
                @if($products->hasMorePages())<div class="catalogue-more"><button class="button button-outline" type="button" data-catalogue-more data-next-url="{{ $products->nextPageUrl() }}">Voir plus de produits</button><p class="sr-only" data-catalogue-more-status aria-live="polite"></p></div>@endif
            @else
                <div class="catalogue-empty"><h2>Aucun produit ne correspond à ces filtres.</h2><a class="text-link" href="{{ route('storefront.products') }}">Réinitialiser les filtres</a></div>
            @endif
        </div>
    </section>
</x-layouts.storefront>
