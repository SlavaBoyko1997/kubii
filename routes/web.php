<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\GoogleMerchantFeedController;
use App\Http\Controllers\LiqPayController;
use App\Http\Controllers\MonobankController;
use App\Http\Controllers\NovaPoshtaController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProductAdminToolsController;
use App\Http\Controllers\ProductListController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SocialImageController;
use App\Http\Controllers\StoreController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

$statelessMiddleware = [
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    PreventRequestForgery::class,
];

Route::get('/robots.txt', [SitemapController::class, 'robots'])->withoutMiddleware($statelessMiddleware)->name('robots');
Route::get('/google-merchant-feed.xml', GoogleMerchantFeedController::class)->withoutMiddleware($statelessMiddleware)->name('feeds.google-merchant');
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->withoutMiddleware($statelessMiddleware)->name('sitemap.index');
Route::get('/sitemap-static.xml', [SitemapController::class, 'static'])->withoutMiddleware($statelessMiddleware)->name('sitemap.static');
Route::get('/sitemap-blog.xml', [SitemapController::class, 'blog'])->withoutMiddleware($statelessMiddleware)->name('sitemap.blog');
Route::get('/sitemap-categories.xml', [SitemapController::class, 'categories'])->withoutMiddleware($statelessMiddleware)->name('sitemap.categories');
Route::get('/sitemap-products-{page}.xml', [SitemapController::class, 'products'])->whereNumber('page')->withoutMiddleware($statelessMiddleware)->name('sitemap.products');
Route::get('/social-images/products/{product}/{version}.jpg', [SocialImageController::class, 'product'])
    ->whereNumber(['product', 'version'])
    ->withoutMiddleware($statelessMiddleware)
    ->name('social-images.product');
Route::get('/social-images/categories/{category}/{version}.jpg', [SocialImageController::class, 'category'])
    ->whereNumber(['category', 'version'])
    ->withoutMiddleware($statelessMiddleware)
    ->name('social-images.category');

Route::prefix('api/nova-poshta')->middleware('throttle:120,1')->group(function (): void {
    Route::get('/cities', [NovaPoshtaController::class, 'cities'])->name('api.nova-poshta.cities');
    Route::get('/streets', [NovaPoshtaController::class, 'streets'])->name('api.nova-poshta.streets');
    Route::get('/warehouses', [NovaPoshtaController::class, 'warehouses'])->name('api.nova-poshta.warehouses');
    Route::get('/postomats', [NovaPoshtaController::class, 'postomats'])->name('api.nova-poshta.postomats');
    Route::get('/delivery-price', [NovaPoshtaController::class, 'deliveryPrice'])->name('api.nova-poshta.delivery-price');
});

Route::post('/payment/liqpay/callback', [LiqPayController::class, 'callback'])
    ->withoutMiddleware($statelessMiddleware)
    ->middleware('throttle:180,1')
    ->name('payment.liqpay.callback');
Route::get('/payment/liqpay/checkout/{order:number}', [LiqPayController::class, 'checkout'])
    ->name('payment.liqpay.checkout');
Route::get('/payment/liqpay/result/{order:number}', [LiqPayController::class, 'result'])
    ->name('payment.liqpay.result');
Route::post('/payment/mono/webhook', [MonobankController::class, 'webhook'])
    ->withoutMiddleware($statelessMiddleware)
    ->middleware('throttle:180,1')
    ->name('payment.mono.webhook');
Route::get('/payment/mono/checkout/{order:number}', [MonobankController::class, 'checkout'])
    ->name('payment.mono.checkout');
Route::get('/payment/mono/result/{order:number}', [MonobankController::class, 'result'])
    ->name('payment.mono.result');

$registerStoreRoutes = function (string $suffix = '') use ($statelessMiddleware): void {
    $name = fn (string $base): string => $base.$suffix;
    $publicProduct = '{product:slug}';

    Route::get('/', [StoreController::class, 'home'])->name($name('home'));
    Route::get('/search', [SearchController::class, 'index'])->name($name('search.index'));
    Route::get('/search/suggestions', [SearchController::class, 'suggestions'])->middleware('throttle:180,1')->name($name('search.suggestions'));
    Route::post('/search/click', [SearchController::class, 'click'])->middleware('throttle:180,1')->name($name('search.click'));
    Route::get('/catalog-menu', [StoreController::class, 'catalogMenu'])
        ->withoutMiddleware($statelessMiddleware)
        ->name($name('catalog.menu'));
    Route::post('/filter-click', [StoreController::class, 'recordFilterClick'])->middleware('throttle:120,1')->name($name('catalog.filter-click'));
    Route::post('/admin/category-filters/disable', [StoreController::class, 'disableCategoryFilter'])->middleware('auth')->name($name('catalog.admin.disable-filter'));
    Route::get('/products/'.$publicProduct, [StoreController::class, 'legacyProduct'])->name($name('products.legacy'));
    Route::get('/{rootCategory}/products/'.$publicProduct, [StoreController::class, 'productPath'])->name($name('products.show'));
    Route::get('/blog', [BlogController::class, 'index'])->name($name('blog.index'));
    Route::get('/blog/{blogPost:slug}', [BlogController::class, 'show'])->name($name('blog.show'));
    Route::get('/pages/{page}', [PageController::class, 'show'])->name($name('pages.show'));

    Route::get('/cart', [CartController::class, 'index'])->name($name('cart.index'));
    Route::post('/cart/{product}', [CartController::class, 'store'])->whereNumber('product')->name($name('cart.store'));
    Route::patch('/cart/{product}', [CartController::class, 'update'])->whereNumber('product')->name($name('cart.update'));
    Route::delete('/cart/{product}', [CartController::class, 'destroy'])->whereNumber('product')->name($name('cart.destroy'));
    Route::delete('/cart', [CartController::class, 'clear'])->name($name('cart.clear'));

    Route::get('/favorites', [ProductListController::class, 'favorites'])->name($name('favorites.index'));
    Route::post('/favorites/{product}', [ProductListController::class, 'toggleFavorite'])->name($name('favorites.toggle'));
    Route::get('/comparison', [ProductListController::class, 'comparison'])->name($name('comparison.index'));
    Route::post('/comparison/{product}', [ProductListController::class, 'toggleComparison'])->name($name('comparison.toggle'));

    Route::get('/checkout', [CheckoutController::class, 'create'])->name($name('checkout.create'));
    Route::post('/checkout/account-check', [CheckoutController::class, 'accountCheck'])->middleware('throttle:30,1')->name($name('checkout.account-check'));
    Route::post('/checkout', [CheckoutController::class, 'store'])->name($name('checkout.store'));
    Route::post('/quick-order', [CheckoutController::class, 'quick'])->middleware('throttle:8,1')->name($name('checkout.quick'));
    Route::get('/checkout/success/{order}', [CheckoutController::class, 'success'])->name($name('checkout.success'));

    Route::middleware('guest')->group(function () use ($name): void {
        Route::get('/auth/google/redirect', [AuthController::class, 'redirectToGoogle'])->name($name('auth.google.redirect'));
        Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])->name($name('auth.google.callback'));
        Route::get('/login', [AuthController::class, 'showLogin'])->name($name('login'));
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:8,1')->name($name('login.store'));
        Route::get('/register', [AuthController::class, 'showRegister'])->name($name('register'));
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name($name('register.store'));
    });

    Route::middleware('auth')->group(function () use ($name, $publicProduct): void {
        Route::get('/cart/merge', [CartController::class, 'showMerge'])->name($name('cart.merge.show'));
        Route::post('/cart/merge', [CartController::class, 'merge'])->name($name('cart.merge'));
        Route::post('/cart/keep-device', [CartController::class, 'keepDevice'])->name($name('cart.keep-device'));
        Route::post('/cart/keep-account', [CartController::class, 'keepAccount'])->name($name('cart.keep-account'));
        Route::get('/account', [AccountController::class, 'index'])->name($name('account.index'));
        Route::post('/account/profile', [AccountController::class, 'storeProfile'])->middleware('throttle:6,1')->name($name('account.profile.store'));
        Route::post('/account/phone', [AccountController::class, 'storePhone'])->middleware('throttle:6,1')->name($name('account.phone.store'));
        Route::post('/logout', [AuthController::class, 'logout'])->name($name('logout'));
        Route::post('/products/{product}/reviews', [ReviewController::class, 'store'])->middleware('throttle:10,1')->name($name('reviews.store'));
        Route::post('/reviews/{review}/replies', [ReviewController::class, 'reply'])->middleware('throttle:15,1')->name($name('reviews.replies.store'));
        Route::post('/reviews/{review}/vote', [ReviewController::class, 'vote'])->middleware('throttle:30,1')->name($name('reviews.vote'));
        Route::get('/products/'.$publicProduct.'/admin/download-images', [ProductAdminToolsController::class, 'downloadImages'])
            ->name($name('products.admin.download-images'));
    });

    Route::get('/{categoryPath}', [StoreController::class, 'catalogPath'])
        ->where('categoryPath', '.*')
        ->name($name('categories.path'));
};

Route::prefix('ru')->middleware('locale:ru')->group(fn () => $registerStoreRoutes('.ru'));
Route::middleware('locale:uk')->group(fn () => $registerStoreRoutes());
