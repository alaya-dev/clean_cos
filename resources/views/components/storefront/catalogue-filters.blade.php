@props(['categories', 'currentCategory' => null])

@php
    $selectedCategory = $currentCategory?->slug ?: request('category');
    $minimumPrice = request('min_price_dt');
    $maximumPrice = request('max_price_dt');
    $validMinimumPrice = is_string($minimumPrice) && preg_match('/^\d+(?:[,.]\d{1,3})?$/', $minimumPrice);
    $validMaximumPrice = is_string($maximumPrice) && preg_match('/^\d+(?:[,.]\d{1,3})?$/', $maximumPrice);
    $validCategory = $currentCategory || $categories->contains('slug', $selectedCategory);
    $validSort = in_array(request('sort', 'newest'), ['newest', 'name_asc', 'price_asc', 'price_desc'], true) ? request('sort', 'newest') : 'newest';
    $hasFilters = $validMinimumPrice || $validMaximumPrice || request()->boolean('promotions') || ($selectedCategory && ! $currentCategory && $validCategory) || $validSort !== 'newest';
@endphp

@push('head')
    <style>.catalogue-filter-drawer{margin-block:1rem;border-block:1px solid var(--line)}.catalogue-filter-drawer summary{display:flex;align-items:center;min-height:44px;font-weight:700;cursor:pointer}.catalogue-filter-drawer summary::marker{color:var(--accent-dark)}.catalogue-filters{padding:0 0 1rem}.catalogue-filters label{position:relative}.filter-unit{position:absolute;right:.65rem;bottom:.62rem;color:var(--muted);font-size:.7rem}.catalogue-filter-actions{display:flex;flex-wrap:wrap;align-items:center;gap:1rem}.catalogue-filter-actions .button{margin:0}.active-filter-chips{display:flex;gap:.5rem;overflow:auto;margin-bottom:1rem;padding-bottom:.25rem}.active-filter-chips a{display:flex;align-items:center;gap:.35rem;flex:0 0 auto;min-height:36px;border:1px solid var(--line);border-radius:999px;padding:.3rem .7rem;background:var(--cream);font-size:.76rem}.active-filter-chips span{font-size:1rem}@media(min-width:900px){.catalogue-filter-drawer summary{display:none}.catalogue-filter-drawer{margin-block:0}.catalogue-filter-drawer:not([open]) .catalogue-filters{display:grid}.catalogue-filters{padding-block:1rem}.catalogue-filter-actions{align-self:end}.active-filter-chips{overflow:visible}}</style>
@endpush

<details class="catalogue-filter-drawer" @if($hasFilters) open @endif>
    <summary>Filtrer et trier</summary>
    <form class="catalogue-filters" method="get" aria-label="Filtrer les produits">
        @if($currentCategory)
            <input type="hidden" name="category" value="{{ $currentCategory->slug }}">
        @else
            <label>Catégorie
                <select name="category">
                    <option value="">Toutes les catégories</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->slug }}" @selected($selectedCategory === $category->slug)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <label>Prix minimum <span class="filter-unit">DT</span><input name="min_price_dt" type="text" inputmode="decimal" value="{{ $validMinimumPrice ? $minimumPrice : '' }}" placeholder="0,000"></label>
        <label>Prix maximum <span class="filter-unit">DT</span><input name="max_price_dt" type="text" inputmode="decimal" value="{{ $validMaximumPrice ? $maximumPrice : '' }}" placeholder="100,000"></label>
        <label class="check-label"><input name="promotions" type="checkbox" value="1" @checked(request()->boolean('promotions'))> Offres uniquement</label>
        <label>Trier
            <select name="sort">
                <option value="newest">Nouveautés</option>
                <option value="name_asc" @selected($validSort === 'name_asc')>Nom, A à Z</option>
                <option value="price_asc" @selected($validSort === 'price_asc')>Prix croissant</option>
                <option value="price_desc" @selected($validSort === 'price_desc')>Prix décroissant</option>
            </select>
        </label>
        <div class="catalogue-filter-actions">
            <button class="button button-dark" type="submit">Afficher les produits</button>
            @if($hasFilters)<a class="text-link" href="{{ $currentCategory ? route('storefront.category', $currentCategory->slug) : route('storefront.products') }}">Effacer les filtres</a>@endif
        </div>
    </form>
</details>

@if($hasFilters)
    <div class="active-filter-chips" aria-label="Filtres actifs">
        @if($selectedCategory && ! $currentCategory && $validCategory)<a href="{{ request()->fullUrlWithQuery(['category' => null, 'page' => null]) }}">{{ $categories->firstWhere('slug', $selectedCategory)?->name ?: 'Catégorie' }} <span aria-hidden="true">×</span></a>@endif
        @if($validMinimumPrice)<a href="{{ request()->fullUrlWithQuery(['min_price_dt' => null, 'page' => null]) }}">Dès {{ $minimumPrice }} DT <span aria-hidden="true">×</span></a>@endif
        @if($validMaximumPrice)<a href="{{ request()->fullUrlWithQuery(['max_price_dt' => null, 'page' => null]) }}">Jusqu’à {{ $maximumPrice }} DT <span aria-hidden="true">×</span></a>@endif
        @if(request()->boolean('promotions'))<a href="{{ request()->fullUrlWithQuery(['promotions' => null, 'page' => null]) }}">Offres <span aria-hidden="true">×</span></a>@endif
        @if($validSort !== 'newest')<a href="{{ request()->fullUrlWithQuery(['sort' => null, 'page' => null]) }}">Tri actif <span aria-hidden="true">×</span></a>@endif
    </div>
@endif
