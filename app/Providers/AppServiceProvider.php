<?php

namespace App\Providers;

use App\Models\Category;
use App\Support\AppUrl;
use App\Support\Cart;
use Illuminate\Pagination\Paginator;
use App\Support\CatalogCache;
use App\Support\CatalogPerformanceProfiler;
use App\Support\Locale;
use App\Support\ProductLists;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(Cart::class);
        $this->app->scoped(CatalogPerformanceProfiler::class);
        $this->app->scoped(ProductLists::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        AppUrl::configureFromRequest();

        Paginator::currentPathResolver(function (): string {
            if (! app()->bound('request')) {
                return '/';
            }

            return AppUrl::relativePath(request()->url());
        });

        Model::preventLazyLoading((bool) config('performance.prevent_lazy_loading'));

        if (config('performance.slow_query_log.enabled')) {
            DB::listen(function (QueryExecuted $query): void {
                if ($query->time < config('performance.slow_query_log.threshold_ms')) {
                    return;
                }

                try {
                    Log::channel('slow_queries')->warning('Slow SQL query', [
                        'duration_ms' => $query->time,
                        'connection' => $query->connectionName,
                        'sql' => $query->sql,
                        'bindings' => $query->bindings,
                        'url' => app()->runningInConsole() ? null : request()->fullUrl(),
                    ]);
                } catch (\Throwable) {
                    // Ignore log write failures (e.g. storage/logs owned by another user).
                }
            });
        }

        $catalogEntryUrl = fn (): string => app(CatalogCache::class)->remember(
            'catalog-entry-url:v2:'.Locale::current(),
            fn (): string => Category::query()
                ->whereNull('parent_id')
                ->where('is_active', true)
                ->where('name', '!=', 'ІБІС Зброя')
                ->orderBy('sort_order')
                ->first()
                ?->catalogUrl(absolute: false) ?? localized_route('home', absolute: false),
        );

        View::composer(['store.*', 'account.*'], function ($view) use ($catalogEntryUrl): void {
            $view->with('catalogEntryUrl', $catalogEntryUrl());
        });

        View::composer('layouts.store', function ($view) use ($catalogEntryUrl): void {
            $cart = app(Cart::class);
            $lists = app(ProductLists::class);
            $favoriteIds = $lists->favoriteIds();
            $comparisonIds = $lists->comparisonIds();

            $view->with([
                'catalogEntryUrl' => $catalogEntryUrl(),
                'navCategories' => collect(app(CatalogCache::class)->homeRootCategories())->values(),
                'footerCategories' => collect(app(CatalogCache::class)->homeRootCategories())
                    ->take(10)
                    ->values(),
                'drawerItems' => $cart->items(),
                'drawerTotal' => $cart->total(),
                'cartCount' => $cart->count(),
                'cartProductIds' => $cart->productIds(),
                'favoriteIds' => $favoriteIds,
                'comparisonIds' => $comparisonIds,
                'favoriteCount' => count($favoriteIds),
                'comparisonCount' => count($comparisonIds),
            ]);
        });

        View::composer('store._product-list-actions', function ($view): void {
            $lists = app(ProductLists::class);

            $view->with([
                'favoriteIds' => $lists->favoriteIds(),
                'comparisonIds' => $lists->comparisonIds(),
            ]);
        });

        View::composer('store._product-card', function ($view): void {
            $view->with('cartProductIds', app(Cart::class)->productIds());
        });

        View::composer('store.product', function ($view): void {
            $view->with('cartProductIds', app(Cart::class)->productIds());
        });
    }
}
