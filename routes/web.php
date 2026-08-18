<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminPasswordResetController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\StaticPageController;
use App\Http\Controllers\StorefrontCatalogController;
use Illuminate\Support\Facades\Route;

Route::get('/', [StorefrontCatalogController::class, 'home'])->name('storefront.home');
Route::get('/robots.txt', RobotsController::class)->name('robots');
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/produits', [StorefrontCatalogController::class, 'products'])->name('storefront.products');
Route::get('/produits/{slug}', [StorefrontCatalogController::class, 'product'])->name('storefront.product');
Route::get('/categories/{slug}', [StorefrontCatalogController::class, 'category'])->name('storefront.category');
Route::get('/recherche', [StorefrontCatalogController::class, 'search'])->name('storefront.search');
Route::get('/panier', [StorefrontCatalogController::class, 'cart'])->name('storefront.cart');
Route::get('/commande', [StorefrontCatalogController::class, 'checkout'])->name('storefront.checkout');
Route::get('/commande/confirmee/{order}', [StorefrontCatalogController::class, 'confirmation'])->middleware('signed')->name('storefront.confirmation');
Route::view('/reclamation', 'storefront.complaint')->name('storefront.complaint');
Route::get('/pages/{slug}', [StaticPageController::class, 'show'])->name('storefront.page');

Route::get('/admin/login', [AdminAuthController::class, 'create'])->name('login');
Route::post('/admin/login', [AdminAuthController::class, 'store'])->middleware('throttle:8,1')->name('admin.login');
Route::get('/admin/mot-de-passe-oublie', [AdminPasswordResetController::class, 'create'])->middleware('guest')->name('admin.password.request');
Route::post('/admin/mot-de-passe-oublie', [AdminPasswordResetController::class, 'send'])->middleware(['guest', 'throttle:5,1'])->name('admin.password.email');
Route::get('/admin/reinitialiser-mot-de-passe/{token}', [AdminPasswordResetController::class, 'edit'])->middleware('guest')->name('password.reset');
Route::post('/admin/reinitialiser-mot-de-passe', [AdminPasswordResetController::class, 'update'])->middleware(['guest', 'throttle:5,1'])->name('admin.password.update');
Route::post('/admin/logout', [AdminAuthController::class, 'destroy'])->middleware('auth')->name('admin.logout');
Route::view('/admin', 'admin.app')->middleware('auth')->name('admin.app');
Route::view('/admin/{path}', 'admin.app')->where('path', '.*')->middleware('auth')->name('admin.spa');
