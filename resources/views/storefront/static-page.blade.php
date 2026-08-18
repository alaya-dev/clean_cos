<x-layouts.storefront :title="($page->seo_title ?: $page->title).' | ToutDispo'" :description="$page->seo_description ?: ''" :canonical="route('storefront.page', $page->slug)">
    @push('head')
        <meta property="og:title" content="{{ $page->seo_title ?: $page->title }}">
        <meta property="og:description" content="{{ $page->seo_description }}">
        <meta property="og:url" content="{{ route('storefront.page', $page->slug) }}">
        <meta property="og:type" content="article">
    @endpush
    <article class="static-page section">
        <p class="eyebrow">ToutDispo</p>
        <h1>{{ $page->title }}</h1>
        @if(in_array($page->key, ['terms', 'privacy', 'delivery', 'returns_complaints'], true))
            <p class="static-page-updated">Dernière mise à jour : <time datetime="{{ $page->updated_at->toDateString() }}">{{ $page->updated_at->locale('fr')->translatedFormat('j F Y') }}</time></p>
        @endif
        <div class="rich-content">{!! $page->content !!}</div>
    </article>
</x-layouts.storefront>
