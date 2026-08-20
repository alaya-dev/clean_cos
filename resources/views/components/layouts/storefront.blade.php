<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if($facebookDomainVerification = app(\App\Domain\MetaTracking\Services\MetaConfigurationService::class)->facebookDomainVerification())
        <meta name="facebook-domain-verification" content="{{ $facebookDomainVerification }}">
    @endif
    <title>{{ $title ?? 'Clean’Cos' }}</title>
    <meta name="description" content="{{ $description ?? 'Découvrez des soins et rituels de beauté choisis avec soin.' }}">
    <link rel="canonical" href="{{ $canonical ?? url()->current() }}">
    <meta property="og:locale" content="fr_TN">
    <meta property="og:title" content="{{ $ogTitle ?? $title ?? 'Clean’Cos' }}">
    <meta property="og:description" content="{{ $ogDescription ?? $description ?? 'Découvrez des soins et rituels de beauté choisis avec soin.' }}">
    <meta property="og:type" content="{{ $ogType ?? 'website' }}">
    <meta property="og:url" content="{{ $canonical ?? url()->current() }}">
    <meta property="og:image" content="{{ $ogImage ?? url('/images/social-preview.svg') }}">
    <meta name="twitter:card" content="summary_large_image">
    @vite(['resources/css/storefront.css', 'resources/js/storefront/main.ts'])
    <style>
        .drawer-backdrop{position:fixed;z-index:90;inset:0;background:#31282b80;opacity:1;transition:opacity 180ms cubic-bezier(.23,1,.32,1)}
        .drawer-backdrop[hidden]{display:block;opacity:0;pointer-events:none}
        html,body{max-width:100%;overflow-x:clip}.mobile-drawer,.cart-drawer{transition:opacity 220ms cubic-bezier(.32,.72,0,1),transform 220ms cubic-bezier(.32,.72,0,1),visibility 0s linear}
        .mobile-drawer:not(.is-open),.cart-drawer:not(.is-open){visibility:hidden;opacity:0;pointer-events:none;transform:translateX(100%)}
        .cart-drawer{position:fixed;z-index:100;top:0;right:0;bottom:0;display:grid;grid-template-rows:auto 1fr;width:min(100%,24rem);max-width:100dvw;padding:1rem;background:#fffaf7;box-shadow:-1rem 0 2rem #31282b33;contain:layout paint;transform:translateX(0)}
        .cart-drawer>header{display:flex;justify-content:space-between;align-items:center;padding-bottom:.75rem;border-bottom:1px solid var(--line)}
        .cart-drawer>header button{width:44px;height:44px;border:0;background:transparent;font-size:1.5rem}.cart-drawer>div{overflow:auto;padding-top:1rem}
        .cart-drawer-lines article{display:flex;justify-content:space-between;gap:.75rem;padding:.75rem 0;border-bottom:1px solid var(--line)}.cart-drawer-lines small{display:block;color:var(--muted);font-size:.78rem}
        .cart-drawer-product{display:flex;min-width:0;gap:.6rem}.cart-drawer-product img{width:3.5rem;height:3.5rem;object-fit:cover;background:var(--cream)}.cart-drawer-product div{min-width:0}.cart-drawer-lines span{display:block;color:var(--muted);font-size:.78rem}.cart-drawer-line-total{display:grid;justify-items:end;gap:.3rem;white-space:nowrap}.cart-drawer-line-total .text-link{min-height:30px;border:0;background:transparent;color:var(--accent-dark);font-size:.72rem}
        .announcement-bar{max-width:100%;overflow:hidden;contain:paint;white-space:nowrap}.announcement-bar-track{display:flex;width:max-content;animation:announcement-ticker 42s linear infinite}.announcement-bar-group{display:flex;gap:4rem;padding-right:4rem}.announcement-bar-group span{flex:0 0 auto}.announcement-bar:focus-within .announcement-bar-track,.announcement-bar:hover .announcement-bar-track,.announcement-bar.is-offscreen .announcement-bar-track{animation-play-state:running}@keyframes announcement-ticker{to{transform:translateX(-50%)}}
        .storefront-toast-stack{position:fixed;z-index:120;right:1rem;bottom:1rem;display:grid;gap:.6rem;width:min(23rem,calc(100vw - 2rem));pointer-events:none}.storefront-toast{display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:.85rem 1rem;border:1px solid var(--line);background:var(--paper);box-shadow:0 1rem 2.4rem rgb(30 23 25 / .16);pointer-events:auto;transition:opacity 180ms cubic-bezier(.23,1,.32,1),transform 180ms cubic-bezier(.23,1,.32,1)}.storefront-toast.is-leaving{opacity:0;transform:translateY(.5rem)}.storefront-toast button{min-height:36px;border:0;background:transparent;color:var(--accent-dark);font:700 .76rem var(--sans)}
        .cart-line,.cart-drawer-lines article{transition:opacity 180ms var(--ease-out),transform 180ms var(--ease-out)}.cart-line.is-removing,.cart-drawer-lines article.is-removing{opacity:0;pointer-events:none;transform:translateX(.5rem)}.cart-summary{transition:opacity 180ms var(--ease-out),transform 180ms var(--ease-out)}.cart-summary.is-updating{opacity:.76;transform:translateY(.12rem)}.search-suggestions{opacity:0;transform:translateY(-.25rem);transition:opacity 180ms var(--ease-out),transform 180ms var(--ease-out)}.search-suggestions.is-visible{opacity:1;transform:translateY(0)}.product-gallery [data-gallery-main]{transition:opacity 160ms var(--ease-out),transform 160ms var(--ease-out)}.product-gallery [data-gallery-main].is-switching{opacity:.58;transform:scale(.992)}
        .checkout-order-items{display:grid;gap:.65rem;margin-bottom:1rem;padding-bottom:1rem;border-bottom:1px solid #d598ad}.checkout-order-items h2{font-size:.9rem}.checkout-order-items article{display:grid;grid-template-columns:3rem minmax(0,1fr) auto;gap:.6rem;align-items:center}.checkout-order-items img{width:3rem;height:3rem;object-fit:cover;background:var(--cream)}.checkout-order-items article div{display:grid;min-width:0;gap:.1rem}.checkout-order-items small{color:var(--muted);font-size:.75rem}.checkout-order-items article>strong{font-size:.8rem;white-space:nowrap}
        @media(max-width:899px){.store-header{grid-template-columns:44px minmax(0,1fr) auto;gap:.25rem;padding-block:.55rem}.brand{justify-self:center}.header-actions{justify-self:end;gap:0}.header-cart{display:grid;position:relative}.header-cart span{position:absolute;top:.15rem;right:-.15rem}.global-search{grid-column:1/-1}.store-footer{grid-template-columns:repeat(2,minmax(0,1fr))!important}.store-footer>div{min-width:0}.footer-brand,.footer-bottom{grid-column:1/-1}.cart-line{grid-template-columns:4.5rem minmax(0,1fr) auto;align-items:start}.cart-line>img{width:4.5rem;height:4.5rem;object-fit:cover}.cart-line .cart-stepper{grid-column:1/-1}.cart-summary{padding:1.15rem}.announcement-bar-track{animation-duration:38s}}
        @media(min-width:900px){.mobile-drawer,.drawer-backdrop[data-drawer-backdrop]{display:none!important}}
        @media(prefers-reduced-motion:reduce),(max-width:360px){.announcement-bar-track{animation:none;transform:none}.storefront-toast{transition:opacity 120ms ease}.mobile-drawer,.cart-drawer,.cart-line,.cart-drawer-lines article,.cart-summary,.search-suggestions,.product-gallery [data-gallery-main]{transition:none!important}.cart-line.is-removing,.cart-drawer-lines article.is-removing,.cart-summary.is-updating{transform:none}}
    </style>
    <style>
        .store-header .brand-lockup{display:block}.store-header .brand-logo{flex:0 0 auto}.desktop-nav a[href$="/produits"]::after,.mobile-drawer nav a[href$="/produits"]::after{content:' · Tous les produits';font-size:.78em;opacity:.72}
    </style>
    <style>.category-nav-item{position:relative}.category-nav-item summary{display:flex;align-items:center;gap:.3rem;min-height:44px;cursor:pointer;list-style:none}.category-nav-item summary::-webkit-details-marker{display:none}.category-nav-item summary::after{content:'⌄';font-size:.75em;transition:transform 180ms var(--ease-out)}.category-nav-item[open] summary::after{transform:rotate(180deg)}.category-nav-panel{display:grid;grid-template-rows:0fr;position:absolute;z-index:30;top:100%;left:-.75rem;min-width:14rem;border:1px solid var(--line);background:var(--paper);box-shadow:0 .75rem 1.5rem rgb(30 23 25 / .12);transition:grid-template-rows 180ms var(--ease-out)}.category-nav-panel>div{overflow:hidden}.category-nav-item[open] .category-nav-panel{grid-template-rows:1fr}.category-nav-panel a{display:block;padding:.7rem .85rem!important;border-top:1px solid var(--line)}@media(max-width:899px){.mobile-drawer .category-nav-item{position:static}.mobile-drawer .category-nav-item summary{padding:.7rem 0;font:inherit}.mobile-drawer .category-nav-panel{position:static;min-width:0;border:0;box-shadow:none;background:transparent}.mobile-drawer .category-nav-panel a{padding:.7rem 0 .7rem 1rem!important;border-top:0}.mobile-drawer .category-nav-item[open] .category-nav-panel{border-left:2px solid var(--pink)}}@media(prefers-reduced-motion:reduce){.category-nav-item summary::after,.category-nav-panel{transition:none}}</style>
    @stack('head')
</head>
<body class="storefront-body">
    <a class="skip-link" href="#contenu">Aller au contenu</a>
    @php($announcement = $storeContext['announcement_text'] ?: $storeContext['shipping_announcement'])
    <div class="announcement-bar" data-announcement-bar>
        <div class="announcement-bar-track" aria-hidden="true">
            @for($group = 0; $group < 2; $group++)
                <div class="announcement-bar-group">@for($copy = 0; $copy < 4; $copy++)<span>{{ $announcement }}</span>@endfor</div>
            @endfor
        </div>
        <span class="sr-only">{{ $announcement }}</span>
    </div>
    <header class="store-header">
        <button class="icon-button mobile-menu-button" type="button" data-drawer-open aria-label="Ouvrir le menu" aria-expanded="false"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
        <a class="brand" href="{{ route('storefront.home') }}" aria-label="Clean’Cos">
            <span class="brand-lockup">
                <img class="brand-logo" src="{{ asset('images/brand/cleancos-logo.jpg') }}" alt="Clean’Cos" width="595" height="595">
            </span>
        </a>
        <nav class="desktop-nav" aria-label="Navigation principale"><a href="{{ route('storefront.home') }}">Accueil</a><a href="{{ route('storefront.products') }}">Boutique</a>@foreach($navigationCategories->take(7) as $category)<details class="category-nav-item"><summary>{{ $category->name }}</summary><div class="category-nav-panel"><div>@forelse($category->subcategories as $subcategory)<a href="{{ route('storefront.category', $subcategory->slug) }}">{{ $subcategory->name }}</a>@empty<a href="{{ route('storefront.category', $category->slug) }}">Découvrir la catégorie</a>@endforelse</div></div></details>@endforeach @if($navigationCategories->count() > 7)<details class="desktop-category-overflow"><summary>Plus de catégories</summary><div>@foreach($navigationCategories->skip(7) as $category)<a href="{{ route('storefront.category', $category->slug) }}">{{ $category->name }}</a>@endforeach</div></details>@endif</nav>
        <div class="header-actions">
            <button class="icon-button" type="button" data-search-trigger aria-label="Rechercher"><svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="11" cy="11" r="6"/><path d="m16 16 4 4"/></svg></button>
            <button class="icon-button header-cart" type="button" data-cart-open aria-label="Ouvrir le panier"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 5h2l2 11h10l2-8H7"/><circle cx="10" cy="20" r="1"/><circle cx="18" cy="20" r="1"/></svg><span data-cart-count aria-live="polite">0</span></button>
        </div>
        <form class="global-search" action="{{ route('storefront.search') }}" role="search" data-global-search><label class="sr-only" for="global-search-input">Rechercher un produit ou une catégorie</label><input id="global-search-input" name="q" type="search" autocomplete="off" placeholder="Rechercher un soin, une catégorie…" data-search-input><button type="submit">Voir les résultats</button><div class="search-suggestions" data-search-suggestions role="status" aria-live="polite"></div></form>
    </header>
    <div class="drawer-backdrop" data-drawer-backdrop hidden></div>
    <aside class="mobile-drawer" data-mobile-drawer aria-hidden="true" aria-label="Menu"><button type="button" data-drawer-close aria-label="Fermer le menu">×</button><nav aria-label="Navigation mobile"><a href="{{ route('storefront.home') }}">Accueil</a><a href="{{ route('storefront.products') }}">Boutique</a>@foreach($navigationCategories as $category)<details class="category-nav-item"><summary>{{ $category->name }}</summary><div class="category-nav-panel"><div>@forelse($category->subcategories as $subcategory)<a href="{{ route('storefront.category', $subcategory->slug) }}">{{ $subcategory->name }}</a>@empty<a href="{{ route('storefront.category', $category->slug) }}">Découvrir la catégorie</a>@endforelse</div></div></details>@endforeach<a href="{{ route('storefront.complaint') }}">Réclamations</a></nav><div class="drawer-service"><a href="{{ route('storefront.complaint') }}">Besoin d’aide ?</a>@if($storeContext['phone'])<a href="tel:{{ preg_replace('/\s+/', '', $storeContext['phone']) }}">{{ $storeContext['phone'] }}</a>@endif</div></aside>
    <div class="drawer-backdrop" data-cart-backdrop hidden></div>
    <aside class="cart-drawer" data-cart-drawer aria-hidden="true" aria-labelledby="cart-drawer-title"><header><h2 id="cart-drawer-title">Votre panier</h2><button type="button" data-cart-close aria-label="Fermer le panier">×</button></header><p class="sr-only" data-cart-drawer-status role="status" aria-live="polite"></p><div data-cart-drawer-content></div></aside>
    <main id="contenu">{{ $slot }}</main>
    @php($footerIcons = ['instagram' => 'instagram.png', 'facebook' => 'facebook.png', 'whatsapp' => 'whatsapp.png'])
    <footer class="store-footer">
        <div class="footer-brand">
            <a class="footer-logo" href="{{ route('storefront.home') }}" aria-label="Clean’Cos">
                <img src="{{ asset('images/brand/cleancos-logo.jpg') }}" alt="Clean’Cos" width="595" height="595" loading="lazy">
            </a>
            <p class="footer-brand-name">Clean’Cos</p>
            <p>{{ $storeContext['footer_statement'] }}</p>
        </div>
        <div class="footer-links">
            <h2>Aide</h2>
            @foreach($footerPages as $page)<a href="{{ route('storefront.page', $page->slug) }}">{{ $page->title }}</a>@endforeach
            <a href="{{ route('storefront.complaint') }}">Faire une réclamation</a>
        </div>
        <div class="footer-contact-list">
            <h2>Contact</h2>
            @if($storeContext['address'])<p class="footer-contact"><img src="{{ asset('images/adress.png') }}" alt="" width="22" height="22" decoding="async"><span>{{ $storeContext['address'] }}</span></p>@endif
            @if($storeContext['phone'])<a class="footer-contact" href="tel:{{ preg_replace('/\s+/', '', $storeContext['phone']) }}"><img src="{{ asset('images/phone-call.png') }}" alt="" width="22" height="22" decoding="async"><span>{{ $storeContext['phone'] }}</span></a>@endif
            @if($storeContext['email'])<a class="footer-contact" href="mailto:{{ $storeContext['email'] }}"><img src="{{ asset('images/email.png') }}" alt="" width="22" height="22" decoding="async"><span>{{ $storeContext['email'] }}</span></a>@endif
            @foreach($storeContext['social_links'] as $network => $url)
                @if(isset($footerIcons[strtolower($network)]))<a class="footer-contact" href="{{ $url }}" rel="noopener noreferrer"><img src="{{ asset('images/'.$footerIcons[strtolower($network)]) }}" alt="" width="22" height="22" decoding="async"><span>{{ ucfirst($network) }}</span></a>@endif
            @endforeach
        </div>
        <div class="footer-bottom"><span>© {{ now()->year }} Clean’Cos. Tous droits réservés.</span></div>
    </footer>
    <section class="consent-banner" data-consent-banner hidden aria-labelledby="consent-title" role="dialog" aria-modal="true"><div><p id="consent-title" class="eyebrow">Acceptez les cookies pour autoriser la publicité et améliorer votre expérience.</p><div class="consent-actions"><button type="button" class="button button-dark" data-consent-accept>J’accepte</button></div><p class="consent-error" data-consent-error role="status" aria-live="polite"></p></div></section>
</body>
</html>
