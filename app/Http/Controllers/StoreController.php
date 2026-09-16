<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\BlogPost;
use App\Models\HeroSlide;
use App\Models\Product;
use App\Models\ProductVariantGroup;
use App\Services\CatalogFilterHeading;
use App\Services\CatalogSpecificationFacets;
use App\Services\ProductPopularity;
use App\Services\SeoMeta;
use App\Services\SeoSchema;
use App\Support\AppUrl;
use App\Support\CatalogCache;
use App\Support\CatalogPerformanceProfiler;
use App\Support\HomeCatalogSelections;
use App\Support\Locale;
use App\Support\NovaPoshtaShipment;
use App\Support\PaymentOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StoreController extends Controller
{
    private const DYNAMIC_CACHE_TTL_SECONDS = 3 * 60 * 60;

    private const DEFAULT_BASE_FILTERS = [
        'brand',
        'model',
        'price',
    ];

    private const BASE_FILTERS = [
        'sale' => 'Акційні товари',
        'brand' => 'Бренд',
        'model' => 'Модель',
        'price' => 'Ціна',
        'season' => 'Сезон',
        'usage_type' => 'Тип використання',
        'material' => 'Матеріал',
        'weight' => 'Максимальна вага',
        'stock' => 'Тільки в наявності',
    ];

    public function home(CatalogCache $cache, HomeCatalogSelections $selections, SeoSchema $seoSchema, SeoMeta $seoMeta): View
    {
        $data = $cache->remember('home:v6', fn (): array => $selections->resolve());

        return view('store.home', [
            'categories' => collect($cache->homeRootCategories()),
            'heroSlides' => $this->heroSlides(),
            'saleProducts' => $this->productsByCachedIds($data['saleProductIds'] ?? []),
            'topRatedProducts' => $this->productsByCachedIds($data['topRatedProductIds'] ?? []),
            'bestBrandProducts' => $this->productsByCachedIds($data['bestBrandProductIds'] ?? []),
            'popularProducts' => $this->productsByCachedIds($data['popularProductIds'] ?? []),
            'blogPosts' => $this->homeBlogPosts($cache),
            'seoSchema' => $seoSchema->home(),
            'seoMeta' => $seoMeta->home(),
        ]);
    }

    /**
     * @return list<array{title: string, text: ?string, image: string, button_label: ?string, button_url: ?string}>
     */
    private function heroSlides(): array
    {
        $slides = HeroSlide::query()
            ->active()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (HeroSlide $slide): array => [
                'title' => $slide->title,
                'text' => $slide->subtitle,
                'image' => $slide->imageUrl(),
                'button_label' => $slide->button_label,
                'button_url' => $slide->button_url,
            ])
            ->all();

        if ($slides !== []) {
            return $slides;
        }

        return [[
            'title' => __('Спорядження для маршруту і водойми'),
            'text' => __('Туризм, кемпінг і риболовля в одному каталозі Kubii'),
            'image' => asset('images/hero-outdoor.webp'),
            'button_label' => null,
            'button_url' => null,
        ]];
    }

    private function homeBlogPosts(CatalogCache $cache)
    {
        $postIds = $cache->rememberFor('home-blog-posts:v1', self::DYNAMIC_CACHE_TTL_SECONDS, fn (): array => BlogPost::query()
            ->published()
            ->ordered()
            ->limit(8)
            ->pluck('id')
            ->all());

        if ($postIds === []) {
            return collect();
        }

        return BlogPost::query()
            ->whereKey($postIds)
            ->get()
            ->sortBy(fn (BlogPost $post): int => array_search($post->id, $postIds, true))
            ->values();
    }

    public function catalogPath(Request $request, CatalogCache $cache, CatalogSpecificationFacets $specificationFacets, SeoSchema $seoSchema, SeoMeta $seoMeta, CatalogFilterHeading $filterHeading, string $categoryPath): View|JsonResponse|RedirectResponse
    {
        $profiler = app(CatalogPerformanceProfiler::class);
        $profiler->begin();
        $segments = collect(explode('/', trim($categoryPath, '/')))
            ->map(fn (string $segment): string => mb_strtolower($segment))
            ->filter()
            ->values();

        abort_if($segments->isEmpty(), 404);

        $searchIndex = $segments->search('search');
        $filterTokens = [];

        if ($searchIndex !== false) {
            $filterTokens = $segments->slice($searchIndex + 1)->values()->all();
            $segments = $segments->take($searchIndex)->values();

            abort_if($segments->isEmpty() || $filterTokens === [], 404);
        }

        $candidatePath = $segments->implode('/');
        $pathIndex = $cache->categoryPathIndex();
        $categoryId = $pathIndex['canonical'][$candidatePath] ?? null;
        $legacyCategoryId = $categoryId ? null : ($pathIndex['legacy'][$candidatePath] ?? null);
        $category = $categoryId
            ? Category::query()
                ->with([
                    'parentRecursive',
                    'childrenRecursive.parentRecursive',
                    'parent.children.parentRecursive',
                ])
                ->find($categoryId)
            : null;
        $legacyCategory = $legacyCategoryId
            ? Category::query()->with('parentRecursive')->find($legacyCategoryId)
            : null;

        if (! $category && $legacyCategory) {
            return redirect()->to($legacyCategory->catalogUrl($request->query()), 301);
        }

        if (! $category) {
            $redirectCategoryId = DB::table('category_slug_redirects')
                ->where('old_path', $candidatePath)
                ->value('category_id');

            if ($redirectCategoryId) {
                $redirectCategory = Category::query()
                    ->with([
                        'parentRecursive',
                        'childrenRecursive.parentRecursive',
                        'parent.children.parentRecursive',
                    ])
                    ->find($redirectCategoryId);

                if ($redirectCategory && $redirectCategory->is_active) {
                    return redirect()->to($redirectCategory->catalogUrl($request->query()), 301);
                }
            }
        }

        abort_unless($category && $category->is_active && $cache->categoryHasProducts($category->id), 404);
        $profiler->checkpoint('category_route_resolution');

        if ($filterTokens !== []) {
            $normalizedTokens = $this->normalizeFilterTokens($filterTokens);
            abort_unless($this->validPathFilterTokens($normalizedTokens, $category, $specificationFacets, $cache), 404);

            if ($filterTokens !== $normalizedTokens || count($normalizedTokens) > Category::MAX_INDEXABLE_FILTER_TOKENS) {
                $query = $request->query();
                $query['filter'] = implode(';', $normalizedTokens);

                return redirect()->to($category->catalogUrl($query), 301);
            }

            $query = $request->query();
            $query['filter'] = trim(($query['filter'] ?? '').';'.implode(';', $normalizedTokens), ';');
            $request->query->replace($query);
        }

        return $this->catalog($request, $cache, $specificationFacets, $seoSchema, $seoMeta, $filterHeading, $category);
    }

    public function catalog(Request $request, CatalogCache $cache, CatalogSpecificationFacets $specificationFacets, SeoSchema $seoSchema, SeoMeta $seoMeta, CatalogFilterHeading $filterHeading, ?Category $category = null): View|JsonResponse|RedirectResponse
    {
        abort_unless($category, 404);

        $request->validate([
            'filter' => ['nullable', 'string', 'max:1200'],
            'search' => ['nullable', 'string', 'max:120'],
            'brand' => ['nullable', 'array', 'max:20'],
            'brand.*' => ['string', 'max:120'],
            'model' => ['nullable', 'array', 'max:20'],
            'model.*' => ['string', 'max:120'],
            'season' => ['nullable', 'array', 'max:20'],
            'season.*' => ['string', 'max:120'],
            'usage_type' => ['nullable', 'array', 'max:20'],
            'usage_type.*' => ['string', 'max:120'],
            'material' => ['nullable', 'array', 'max:20'],
            'material.*' => ['string', 'max:120'],
            'spec' => ['nullable', 'array', 'max:40'],
            'spec.*' => ['nullable', 'array', 'max:30'],
            'spec.*.*' => ['string', 'max:160'],
            'min_price' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'max_price' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'max_weight' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'in_stock' => ['nullable', 'boolean'],
            'on_sale' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['popular', 'price_asc', 'price_desc', 'newest'])],
            'per_page' => ['nullable', Rule::in([20, 40, 60])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $deferInitialFilters = ! $request->expectsJson()
            && $cache->categoryProductCount($category->id) >= (int) config('performance.large_catalog_threshold', 2000);
        $fastFilters = ($request->expectsJson() && $request->boolean('fast_filters')) || $deferInitialFilters;
        $filtersOnly = $request->expectsJson() && $request->boolean('filters_only');
        $request->query->remove('fast_filters');
        $request->query->remove('filters_only');
        $this->applyCompactFilterQuery($request, $category, $specificationFacets, $cache);
        $profiler = app(CatalogPerformanceProfiler::class);
        $profiler->checkpoint('validation_and_filter_normalization');

        if (! $request->expectsJson()) {
            $canonicalRequestUrl = AppUrl::absoluteIfPossible($this->catalogUrl($category, $request->query()));

            if ($this->comparableUrl($request->fullUrl()) !== $this->comparableUrl($canonicalRequestUrl)) {
                return redirect()->to($canonicalRequestUrl, 301);
            }
        }

        $visibleBaseFilters = $this->visibleBaseFilters($category);
        $configuredSpecFilterKeys = $cache->remember(
            'spec-filter-keys:v1:'.($category ? 'category-'.$category->id : 'all'),
            fn (): array => $this->configuredSpecFilterKeys($category),
        );
        $specFiltersDisabled = $category?->resolvedVisibleSpecFilters() === [];
        $search = trim((string) $request->query('search'));
        $brands = in_array('brand', $visibleBaseFilters, true) ? array_filter((array) $request->query('brand', [])) : [];
        $models = in_array('model', $visibleBaseFilters, true) ? array_filter((array) $request->query('model', [])) : [];
        $seasons = in_array('season', $visibleBaseFilters, true) ? array_filter((array) $request->query('season', [])) : [];
        $usageTypes = in_array('usage_type', $visibleBaseFilters, true) ? array_filter((array) $request->query('usage_type', [])) : [];
        $materials = in_array('material', $visibleBaseFilters, true) ? array_filter((array) $request->query('material', [])) : [];
        $specFilters = $specFiltersDisabled
            ? []
            : collect((array) $request->query('spec', []))
                ->filter(fn ($values, string $key): bool => $specificationFacets->isFilterableKey($key))
                ->when($configuredSpecFilterKeys !== [], fn ($filters) => $filters->only($configuredSpecFilterKeys))
                ->map(fn ($values): array => array_values(array_filter((array) $values)))
                ->filter()
                ->all();
        $minPrice = in_array('price', $visibleBaseFilters, true) ? ($request->integer('min_price') ?: null) : null;
        $maxPrice = in_array('price', $visibleBaseFilters, true) ? ($request->integer('max_price') ?: null) : null;
        $maxWeight = in_array('weight', $visibleBaseFilters, true) ? ($request->integer('max_weight') ?: null) : null;
        $inStock = in_array('stock', $visibleBaseFilters, true) && $request->boolean('in_stock');
        $onSale = $request->boolean('on_sale');
        $sort = in_array($request->query('sort'), ['popular', 'price_asc', 'price_desc', 'newest'], true) ? $request->query('sort') : 'popular';
        $perPage = in_array($request->integer('per_page'), [20, 40, 60], true) ? $request->integer('per_page') : 20;
        $page = $request->integer('page', 1);
        $categoryIds = $category ? [$category->id, ...$category->loadMissing(['childrenRecursiveAll', 'parentRecursive'])->allDescendantIds()] : [];
        $nameColumn = Locale::isRussian() ? 'name_ru' : 'name';
        $seasonColumn = Locale::isRussian() ? 'season_ru' : 'season';
        $usageTypeColumn = Locale::isRussian() ? 'usage_type_ru' : 'usage_type';
        $materialColumn = Locale::isRussian() ? 'material_ru' : 'material';
        $filterScope = $category ? 'category-'.$category->id : 'all';
        $baseFilterLabels = $this->baseFilterLabels($category);
        $filterHeadingSuffix = $filterHeading->suffix([
            ['label' => mb_strtolower($baseFilterLabels['brand']), 'values' => $brands],
            ['label' => mb_strtolower($baseFilterLabels['model']), 'values' => $models],
            ['label' => mb_strtolower($baseFilterLabels['season']), 'values' => $seasons],
            ['label' => mb_strtolower($baseFilterLabels['usage_type']), 'values' => $usageTypes],
            ['label' => mb_strtolower($baseFilterLabels['material']), 'values' => $materials],
            ...collect($specFilters)
                ->map(fn (array $values, string $key): array => ['label' => mb_strtolower($this->filterLabel($category, $key, $key)), 'values' => $values])
                ->values()
                ->all(),
        ], array_filter([
            $inStock ? __('в наявності') : null,
            $onSale ? __('акційні') : null,
        ]));
        $hasDependentFilters = $search !== ''
            || $brands !== []
            || $models !== []
            || $seasons !== []
            || $usageTypes !== []
            || $materials !== []
            || $specFilters !== []
            || $minPrice !== null
            || $maxPrice !== null
            || $maxWeight !== null
            || $inStock
            || $onSale;

        $filteredProductsQuery = function (array $except = [], bool $visibleInCatalog = true) use ($category, $categoryIds, $search, $brands, $models, $seasons, $usageTypes, $materials, $specFilters, $minPrice, $maxPrice, $maxWeight, $inStock, $onSale, $specificationFacets, $nameColumn, $seasonColumn, $usageTypeColumn, $materialColumn): Builder {
            $query = Product::query()
                ->where('is_active', true)
                ->when($visibleInCatalog, fn ($query) => $query->visibleInCatalog())
                ->when($category, fn ($query) => $query->whereIn('category_id', $categoryIds))
                ->when($search, fn ($query) => $query->where(function (Builder $query) use ($search, $nameColumn): void {
                    $query->where($nameColumn, 'like', "%{$search}%");

                    if ($nameColumn !== 'name') {
                        $query->orWhere('name', 'like', "%{$search}%");
                    }
                }))
                ->when(! in_array('brand', $except, true) && $brands, fn ($query) => $query->whereIn('brand', $brands))
                ->when(! in_array('model', $except, true) && $models, fn ($query) => $query->whereIn('model', $models))
                ->when(! in_array('season', $except, true) && $seasons, fn ($query) => $query->whereIn($seasonColumn, $seasons))
                ->when(! in_array('usage_type', $except, true) && $usageTypes, fn ($query) => $query->whereIn($usageTypeColumn, $usageTypes))
                ->when(! in_array('material', $except, true) && $materials, fn ($query) => $query->whereIn($materialColumn, $materials))
                ->when(! in_array('price', $except, true) && $minPrice, fn ($query) => $query->where('price', '>=', $minPrice))
                ->when(! in_array('price', $except, true) && $maxPrice, fn ($query) => $query->where('price', '<=', $maxPrice))
                ->when(! in_array('weight', $except, true) && $maxWeight, fn ($query) => $query->where('weight_grams', '<=', $maxWeight))
                ->when(! in_array('stock', $except, true) && $inStock, fn ($query) => $query->where('stock', '>', 0))
                ->when(! in_array('sale', $except, true) && $onSale, fn ($query) => $query->onSale());

            $activeSpecFilters = collect($specFilters)
                ->reject(fn (array $values, string $key): bool => in_array('spec', $except, true) || in_array('spec:'.$key, $except, true))
                ->all();

            if ($activeSpecFilters !== []) {
                $specificationFacets->applyFilters($query, $activeSpecFilters);
            }

            return $query;
        };

        $cachedData = $cache->rememberFor('catalog-data:v14:'.($fastFilters ? 'fast' : 'full').':'.$filterScope.':'.sha1(json_encode($this->normalizedQuery($request), JSON_UNESCAPED_UNICODE)), self::DYNAMIC_CACHE_TTL_SECONDS, function () use ($cache, $category, $filterScope, $filteredProductsQuery, $specificationFacets, $hasDependentFilters, $specFilters, $configuredSpecFilterKeys, $specFiltersDisabled, $brands, $models, $seasons, $usageTypes, $materials, $minPrice, $maxPrice, $maxWeight, $inStock, $onSale, $visibleBaseFilters, $baseFilterLabels, $sort, $perPage, $page, $fastFilters, $seasonColumn, $usageTypeColumn, $materialColumn, $filterHeadingSuffix): array {
            $filterPopularity = $cache->remember(
                'filter-popularity:v1:'.$filterScope,
                fn (): array => $this->filterPopularity($category),
            );
            $visibleBaseFilters = $this->sortBaseFilters($visibleBaseFilters, $filterPopularity);
            $query = $filteredProductsQuery([], true)
                ->select([
                    'id',
                    'variant_group_id',
                    'stock',
                    'price',
                    'sale_price',
                    'discount_percent',
                    'is_featured',
                    'created_at',
                ]);

            if (! $hasDependentFilters) {
                $this->forceProductIndex($query, 'products_catalog_popular_v2_idx');
            }

            $orderedQuery = $query
                ->orderByRaw('CASE WHEN stock > 0 AND COALESCE(sale_price, price * (100 - discount_percent) / 100.0) > 0 THEN 0 ELSE 1 END')
                ->when($sort === 'popular', fn ($query) => $query->orderByMonthlyPopularity()->latest())
                ->when($sort === 'newest', fn ($query) => $query->latest())
                ->when($sort === 'price_asc', fn ($query) => $query->orderBy('price'))
                ->when($sort === 'price_desc', fn ($query) => $query->orderByDesc('price'));

            // visibleInCatalog() already keeps one public product per approved
            // variant group, so pagination can stay in SQL even with filters.
            // Counting before the popularity join also avoids aggregating the
            // monthly views table twice for every cold catalog request.
            $total = (clone $query)->count('products.id');
            $productIds = $orderedQuery
                ->forPage($page, $perPage)
                ->get()
                ->pluck('id')
                ->all();

            $specificationFacetCounts = $fastFilters || $specFiltersDisabled ? [] : ($hasDependentFilters
                ? $specificationFacets->counts(
                    $specificationFacets->constrainProductsWithSpecFilters(
                        $filteredProductsQuery(['spec']),
                    )
                        ->select($specificationFacets->specFilterProductColumns())
                        ->lazyById(1000),
                    $specFilters,
                    $configuredSpecFilterKeys,
                )
                : $cache->remember('spec-facets:v6:'.$filterScope, fn (): array => $specificationFacets->counts(
                    $specificationFacets->constrainProductsWithSpecFilters(
                        $filteredProductsQuery(['spec']),
                    )
                        ->select($specificationFacets->specFilterProductColumns())
                        ->lazyById(1000),
                    [],
                    $configuredSpecFilterKeys,
                )));

            $baseFacetData = ! $fastFilters && ! $hasDependentFilters
                ? $cache->remember('base-facets:v3:'.$filterScope, function () use ($filteredProductsQuery, $visibleBaseFilters, $seasonColumn, $usageTypeColumn, $materialColumn): array {
                    $priceRange = in_array('price', $visibleBaseFilters, true)
                        ? $this->forceProductIndex(
                            clone $filteredProductsQuery(['price']),
                            'products_catalog_stock_price_v2_idx',
                        )
                            ->selectRaw('MIN(price) as minimum, MAX(price) as maximum')
                            ->first()
                        : null;

                    return [
                        'brands' => in_array('brand', $visibleBaseFilters, true) ? $this->facetCounts($filteredProductsQuery(['brand']), 'brand') : [],
                        'models' => in_array('model', $visibleBaseFilters, true) ? $this->facetCounts($filteredProductsQuery(['model']), 'model') : [],
                        'seasons' => in_array('season', $visibleBaseFilters, true) ? $this->facetCounts($filteredProductsQuery(['season']), $seasonColumn) : [],
                        'usageTypes' => in_array('usage_type', $visibleBaseFilters, true) ? $this->facetCounts($filteredProductsQuery(['usage_type']), $usageTypeColumn) : [],
                        'materials' => in_array('material', $visibleBaseFilters, true) ? $this->facetCounts($filteredProductsQuery(['material']), $materialColumn) : [],
                        'minimumPrice' => $priceRange?->minimum,
                        'maximumPrice' => $priceRange?->maximum,
                        'hasSaleProducts' => $this->forceProductIndex(
                            clone $filteredProductsQuery(['sale']),
                            'products_catalog_sale_v2_idx',
                        )->onSale()->exists(),
                        'hasWeightProducts' => in_array('weight', $visibleBaseFilters, true)
                            && $this->forceProductIndex(
                                clone $filteredProductsQuery(['weight']),
                                'products_catalog_weight_v2_idx',
                            )
                                ->whereNotNull('weight_grams')
                                ->where('weight_grams', '>', 0)
                                ->exists(),
                        'hasOutOfStockProducts' => in_array('stock', $visibleBaseFilters, true)
                            && $this->forceProductIndex(
                                clone $filteredProductsQuery(['stock']),
                                'products_catalog_stock_price_v2_idx',
                            )
                                ->where('stock', '<=', 0)
                                ->exists(),
                    ];
                })
                : null;
            $priceRange = ! $fastFilters && $hasDependentFilters && in_array('price', $visibleBaseFilters, true)
                ? $this->forceProductIndex(
                    clone $filteredProductsQuery(['price']),
                    'products_catalog_stock_price_v2_idx',
                )
                    ->selectRaw('MIN(price) as minimum, MAX(price) as maximum')
                    ->first()
                : null;
            $dynamicSpecFilters = collect($specificationFacetCounts)
                ->when($configuredSpecFilterKeys !== [], fn ($filters) => $filters->only($configuredSpecFilterKeys))
                ->map(fn (array $values, string $key): array => [
                    'key' => $key,
                    'title' => $this->filterLabel($category, $key, $key),
                    'values' => $this->sortFacetValues(
                        $specificationFacets->withSelectedValues($values, $specFilters[$key] ?? []),
                        $key,
                        $filterPopularity,
                    ),
                    'selected' => $specFilters[$key] ?? [],
                ])
                ->filter(fn (array $filter): bool => $filter['values'] !== [])
                ->sortByDesc(fn (array $filter): int => $filterPopularity['filters'][$filter['key']] ?? 0)
                ->values()
                ->all();
            $activeFilterLabels = $this->activeFilterLabels($category, $baseFilterLabels, $brands, $models, $seasons, $usageTypes, $materials, $specFilters, $minPrice, $maxPrice, $maxWeight, $inStock, $onSale);
            $catalogHeading = $category?->filteredPageH1($filterHeadingSuffix) ?? __('Каталог товарів');
            $availableBrands = $fastFilters || ! in_array('brand', $visibleBaseFilters, true) ? [] : $this->sortFacetValues($this->withSelectedFacetValues($baseFacetData['brands'] ?? $this->facetCounts($filteredProductsQuery(['brand']), 'brand'), $brands), 'brand', $filterPopularity);
            $availableModels = $fastFilters || ! in_array('model', $visibleBaseFilters, true) ? [] : $this->sortFacetValues($this->withSelectedFacetValues($baseFacetData['models'] ?? $this->facetCounts($filteredProductsQuery(['model']), 'model'), $models), 'model', $filterPopularity);
            $availableSeasons = $fastFilters || ! in_array('season', $visibleBaseFilters, true) ? [] : $this->sortFacetValues($this->withSelectedFacetValues($baseFacetData['seasons'] ?? $this->facetCounts($filteredProductsQuery(['season']), $seasonColumn), $seasons), 'season', $filterPopularity);
            $availableUsageTypes = $fastFilters || ! in_array('usage_type', $visibleBaseFilters, true) ? [] : $this->sortFacetValues($this->withSelectedFacetValues($baseFacetData['usageTypes'] ?? $this->facetCounts($filteredProductsQuery(['usage_type']), $usageTypeColumn), $usageTypes), 'usage_type', $filterPopularity);
            $availableMaterials = $fastFilters || ! in_array('material', $visibleBaseFilters, true) ? [] : $this->sortFacetValues($this->withSelectedFacetValues($baseFacetData['materials'] ?? $this->facetCounts($filteredProductsQuery(['material']), $materialColumn), $materials), 'material', $filterPopularity);
            $availableMinPrice = $fastFilters ? null : ($baseFacetData['minimumPrice'] ?? $priceRange?->minimum);
            $availableMaxPrice = $fastFilters ? null : ($baseFacetData['maximumPrice'] ?? $priceRange?->maximum);
            $hasSaleProducts = ! $fastFilters && (
                $baseFacetData['hasSaleProducts']
                ?? $this->forceProductIndex(
                    clone $filteredProductsQuery(['sale']),
                    'products_catalog_sale_v2_idx',
                )->onSale()->exists()
            );
            $hasWeightProducts = ! $fastFilters && in_array('weight', $visibleBaseFilters, true) && (
                $baseFacetData['hasWeightProducts']
                ?? $this->forceProductIndex(
                    clone $filteredProductsQuery(['weight']),
                    'products_catalog_weight_v2_idx',
                )
                    ->whereNotNull('weight_grams')
                    ->where('weight_grams', '>', 0)
                    ->exists()
            );
            $hasOutOfStockProducts = ! $fastFilters && in_array('stock', $visibleBaseFilters, true) && (
                $baseFacetData['hasOutOfStockProducts']
                ?? $this->forceProductIndex(
                    clone $filteredProductsQuery(['stock']),
                    'products_catalog_stock_price_v2_idx',
                )
                    ->where('stock', '<=', 0)
                    ->exists()
            );
            $displayBaseFilters = $this->applicableBaseFilters(
                $this->sortBaseFilters(
                    $this->baseFiltersForDisplay($visibleBaseFilters, $hasSaleProducts),
                    $filterPopularity,
                ),
                $availableBrands,
                $availableModels,
                $availableSeasons,
                $availableUsageTypes,
                $availableMaterials,
                $availableMinPrice,
                $availableMaxPrice,
                $hasSaleProducts,
                $hasWeightProducts,
                $hasOutOfStockProducts,
                $brands,
                $models,
                $seasons,
                $usageTypes,
                $materials,
                $minPrice,
                $maxPrice,
                $maxWeight,
                $inStock,
                $onSale,
                $category !== null && $cache->categoryHasProducts($category->id),
            );

            return [
                'productIds' => $productIds,
                'pagination' => [
                    'total' => $total,
                    'perPage' => $perPage,
                    'currentPage' => $page,
                ],
                'availableBrands' => $availableBrands,
                'availableModels' => $availableModels,
                'availableSeasons' => $availableSeasons,
                'availableUsageTypes' => $availableUsageTypes,
                'availableMaterials' => $availableMaterials,
                'dynamicSpecFilters' => $dynamicSpecFilters,
                'baseFilterLabels' => $baseFilterLabels,
                'visibleBaseFilters' => $displayBaseFilters,
                'selectedBrands' => $brands,
                'selectedModels' => $models,
                'selectedSeasons' => $seasons,
                'selectedUsageTypes' => $usageTypes,
                'selectedMaterials' => $materials,
                'selectedSpecFilters' => $specFilters,
                'activeFilterLabels' => $activeFilterLabels,
                'hasActiveFilters' => $activeFilterLabels !== [],
                'catalogHeading' => $catalogHeading,
                'minPrice' => $minPrice,
                'maxPrice' => $maxPrice,
                'availableMinPrice' => $availableMinPrice,
                'availableMaxPrice' => $availableMaxPrice,
                'maxWeight' => $maxWeight,
                'inStock' => $inStock,
                'onSale' => $onSale,
                'hasSaleProducts' => $hasSaleProducts,
                'sort' => $sort,
                'perPage' => $perPage,
            ];
        });
        $profiler->checkpoint('catalog_results_and_facets');

        $showcaseCategories = $request->expectsJson()
            ? collect()
            : collect($cache->categoryShowcase($category->id));
        $activeFilterQuery = array_filter([
            'brand' => $brands,
            'model' => $models,
            'season' => $seasons,
            'usage_type' => $usageTypes,
            'material' => $materials,
            'spec' => $specFilters,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'max_weight' => $maxWeight,
            'in_stock' => $inStock ? 1 : null,
            'on_sale' => $onSale ? 1 : null,
        ], fn ($value): bool => $value !== null && $value !== [] && $value !== false);
        $usesFilterQueryParameter = $this->catalogUsesFilterQueryParameter($category, $request);
        $data = [
            ...$cachedData,
            'deferFilterHydration' => $deferInitialFilters,
            'currentCategory' => $category,
            'search' => $search,
            'canonicalUrl' => $this->canonicalCatalogUrl($category, $request),
            'seoRobots' => $usesFilterQueryParameter ? 'noindex, follow' : 'index, follow',
            'showcaseCategories' => $showcaseCategories,
            'catalogBaseUrl' => $this->catalogUrl($category, []),
            'resetUrl' => $this->catalogUrl($category, Arr::only($request->query(), ['search', 'sort', 'per_page'])),
            'activeFilters' => $this->activeFilterItems($request, $category, $baseFilterLabels, $brands, $models, $seasons, $usageTypes, $materials, $specFilters, $minPrice, $maxPrice, $maxWeight, $inStock, $onSale),
            'relativeUrl' => static fn (?string $url): string => AppUrl::relativePath($url),
            'filterHeadingSuffix' => $filterHeadingSuffix,
            'filterOptionUrl' => fn (string $name, string $value): string => $this->filterOptionUrl($category, $activeFilterQuery, $name, $value),
            'showAdminFilterControls' => (bool) auth()->user()?->is_admin,
            'adminDisableFilterUrl' => Route::has('catalog.admin.disable-filter') ? localized_route('catalog.admin.disable-filter') : null,
        ];
        $filterQueryHash = sha1(json_encode(
            $this->normalizedQuery($request, except: ['page']),
            JSON_UNESCAPED_UNICODE,
        ));
        $data['filterFieldsHtml'] = $fastFilters
            ? null
            : ($data['showAdminFilterControls']
                ? AppUrl::sanitizeHtmlUrls(view('store._catalog-filter-fields', $data)->render())
                : AppUrl::sanitizeHtmlUrls($cache->rememberFor(
                'catalog-filter-fields-html:v2:'.$filterScope.':'.$filterQueryHash,
                self::DYNAMIC_CACHE_TTL_SECONDS,
                fn (): string => view('store._catalog-filter-fields', $data)->render(),
            )));

        if ($filtersOnly) {
            $profiler->checkpoint('filter_view_data');

            return response()->json([
                'filters' => $data['filterFieldsHtml'],
                'url' => AppUrl::forBrowser($this->catalogUrl($category, $request->query())),
            ]);
        }

        $paginationCompact = $this->compactCatalogQuery(Arr::except($request->query(), 'page'));
        $paginationPath = $this->catalogPaginationPath($category, Arr::except($request->query(), 'page'));
        $hydratedProducts = null;
        $loadProducts = function () use (&$hydratedProducts, $cachedData) {
            return $hydratedProducts ??= $this->productsByCachedIds($cachedData['productIds'] ?? []);
        };
        $makePaginator = function ($items) use ($cachedData, $perPage, $page, $paginationPath, $paginationCompact): LengthAwarePaginator {
            $paginator = new LengthAwarePaginator(
                $items,
                $cachedData['pagination']['total'] ?? 0,
                $cachedData['pagination']['perPage'] ?? $perPage,
                $cachedData['pagination']['currentPage'] ?? $page,
                ['path' => $paginationPath, 'pageName' => 'page'],
            );
            $paginator->appends($paginationCompact['query']);
            $paginator->withPath(AppUrl::relativePath($paginator->path()));

            return $paginator;
        };
        $products = $makePaginator(collect());
        $data['products'] = $products;
        $profiler->checkpoint('pagination_view_data');

        $resultsHtmlKey = 'catalog-results-html:v21:'.$filterScope.':'.sha1(json_encode(
            $this->normalizedQuery($request),
            JSON_UNESCAPED_UNICODE,
        ));
        $cachedResultsHtml = $cache->rememberFor($resultsHtmlKey, self::DYNAMIC_CACHE_TTL_SECONDS, function () use ($data, $loadProducts, $makePaginator): string {
            $html = view('store._catalog-results', [
                ...$data,
                'products' => $makePaginator($loadProducts()),
                'cacheSafeProductCards' => true,
                'includePaginationNav' => false,
            ])->render();

            return AppUrl::sanitizeHtmlUrls(str_replace(csrf_token(), '__FISHTRIP_CSRF_TOKEN__', $html));
        });
        $data['catalogResultsHtml'] = AppUrl::sanitizeHtmlUrls(str_replace(
            '__FISHTRIP_CSRF_TOKEN__',
            csrf_token(),
            $cachedResultsHtml,
        )).AppUrl::sanitizeHtmlUrls(view('store._catalog-results-nav', $data)->render());

        if ($request->expectsJson()) {
            $profiler->checkpoint('view_data');

            return response()->json([
                'html' => $data['catalogResultsHtml'],
                'filters' => $data['filterFieldsHtml'],
                'url' => AppUrl::forBrowser($this->catalogUrl($category, $request->query())),
                'heading' => $data['catalogHeading'] ?? null,
                'title' => $category ? $seoMeta->category($category, $data['canonicalUrl'], $filterHeadingSuffix, $page)['title'] : null,
            ]);
        }

        $data['seoSchema'] = $cache->rememberFor(
            'seo-schema:v1:category-'.$category->id.':'.sha1($data['canonicalUrl'].'|'.implode(',', $cachedData['productIds'] ?? [])),
            self::DYNAMIC_CACHE_TTL_SECONDS,
            fn (): array => $seoSchema->category($category, $makePaginator($loadProducts()), $data['canonicalUrl'], $data['catalogHeading'] ?? $category->pageH1()),
        );
        $data['seoMeta'] = $cache->rememberFor(
            'seo-meta:v3:category-'.$category->id.':'.sha1($data['canonicalUrl']),
            self::DYNAMIC_CACHE_TTL_SECONDS,
            fn (): array => $seoMeta->category($category, $data['canonicalUrl'], $filterHeadingSuffix, $page),
        );
        $profiler->checkpoint('seo_and_controller');

        return view('store.catalog', $data);
    }

    public function productPath(string $rootCategory, Product $product, ProductPopularity $popularity, SeoSchema $seoSchema, SeoMeta $seoMeta, Request $request): View|RedirectResponse
    {
        abort_unless($product->is_active, 404);
        abort_unless($product->rootCategorySlug() === mb_strtolower($rootCategory), 404);

        if ($request->segment(count($request->segments())) !== $product->getRawOriginal('slug')) {
            return redirect()->to($product->url(), 301);
        }

        return $this->product($product, $popularity, $seoSchema, $seoMeta);
    }

    public function legacyProduct(Product $product): RedirectResponse
    {
        abort_unless($product->is_active, 404);

        return redirect()->to($product->url(), 301);
    }

    public function product(Product $product, ProductPopularity $popularity, SeoSchema $seoSchema, SeoMeta $seoMeta): View
    {
        abort_unless($product->is_active, 404);
        $popularity->record($product);
        $product = $product->load('category.parentRecursive');
        $shipmentProfile = app(NovaPoshtaShipment::class)->profile(collect([
            ['product' => $product, 'quantity' => 1],
        ]));
        $availablePaymentOptions = app(PaymentOptions::class)->forProduct($product);
        $reviews = $product->visibleReviews()
            ->with(['user', 'replies.user'])
            ->withCount([
                'votes as helpful_votes_count' => fn ($query) => $query->where('is_helpful', true),
                'votes as unhelpful_votes_count' => fn ($query) => $query->where('is_helpful', false),
            ])
            ->orderByRaw('COALESCE(review_date, created_at) DESC')
            ->orderByDesc('id')
            ->paginate(5, ['*'], 'reviews_page')
            ->withQueryString();

        $showAdminProductTools = (bool) auth()->user()?->is_admin;

        return view('store.product', [
            'product' => $product,
            'canonicalUrl' => $product->canonicalUrl(),
            'orderedSpecifications' => $product->orderedDisplaySpecifications(),
            'showAdminProductTools' => $showAdminProductTools,
            'copyableDetailsText' => $showAdminProductTools ? $product->copyableDetailsText() : null,
            'adminDownloadImagesUrl' => $showAdminProductTools && Route::has('products.admin.download-images')
                ? localized_route('products.admin.download-images', $product)
                : null,
            'variantGroup' => $product->variantGroup?->isVisibleOnFrontend() ? $product->variantGroup->load(['products' => fn ($query) => $query->where('is_active', true)->orderByDesc('is_primary_variant')->orderBy('price')]) : null,
            'reviews' => $reviews,
            'relatedProducts' => Product::query()
                ->where('is_active', true)
                ->visibleInCatalog()
                ->purchasable()
                ->with($this->productCardRelations())
                ->whereBelongsTo($product->category)
                ->whereKeyNot($product->id)
                ->take(4)
                ->get(),
            'seoSchema' => $seoSchema->product($product, $reviews->items()),
            'seoMeta' => $seoMeta->product($product),
            'shipmentProfile' => $shipmentProfile,
            'paymentOptions' => $availablePaymentOptions,
        ]);
    }

    public function catalogMenu(CatalogCache $cache): Response
    {
        return response()
            ->view('store._catalog-menu', [
                'menuCategories' => $cache->menu(),
            ])
            ->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=86400')
            ->header('Vary', 'Accept-Encoding');
    }

    public function recordFilterClick(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'filter_key' => ['required', 'string', 'max:120'],
            'filter_value' => ['nullable', 'string', 'max:160'],
        ]);

        foreach ([null, $data['filter_value'] ?? null] as $filterValue) {
            if ($filterValue === '') {
                $filterValue = null;
            }

            $attributes = [
                'category_id' => $data['category_id'] ?? null,
                'filter_key' => trim($data['filter_key']),
                'filter_value' => $filterValue,
            ];

            $updated = DB::table('catalog_filter_clicks')
                ->where($attributes)
                ->increment('clicks', 1, ['updated_at' => now()]);

            if (! $updated) {
                DB::table('catalog_filter_clicks')->insert([
                    ...$attributes,
                    'clicks' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return response()->json(['ok' => true]);
    }

    public function disableCategoryFilter(Request $request, CatalogCache $cache): JsonResponse|RedirectResponse
    {
        abort_unless(auth()->user()?->is_admin, 403);

        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'type' => ['required', Rule::in(['base', 'spec'])],
            'key' => ['required', 'string', 'max:160'],
            'include_children' => ['nullable', 'boolean'],
        ]);

        $category = Category::query()
            ->with(['childrenRecursiveAll', 'parentRecursive'])
            ->findOrFail((int) $data['category_id']);
        $includeChildren = (bool) ($data['include_children'] ?? false);
        $categories = collect([$category]);

        if ($includeChildren) {
            $descendantIds = $category->allDescendantIds();

            if ($descendantIds !== []) {
                $categories = $categories->concat(
                    Category::query()
                        ->with('parentRecursive')
                        ->whereIn('id', $descendantIds)
                        ->get()
                );
            }
        }

        foreach ($categories as $item) {
            $this->disableFilterForCategory($item, (string) $data['type'], trim((string) $data['key']));
        }

        $cache->invalidate();

        $message = $includeChildren
            ? __('Фільтр вимкнено в категорії та дочірніх.')
            : __('Фільтр вимкнено в категорії.');

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'url' => $category->catalogUrl(absolute: false),
            ]);
        }

        return back()->with('success', $message);
    }

    private function categories()
    {
        $cache = app(CatalogCache::class);

        $ids = $cache->remember('root-categories:v3', function () use ($cache): array {
            $counts = $cache->categoryProductCounts();

            return Category::query()
                ->whereNull('parent_id')
                ->where('is_active', true)
                ->where('name', '!=', 'ІБІС Зброя')
                ->orderBy('sort_order')
                ->get(['id'])
                ->filter(fn (Category $category): bool => ($counts[$category->id] ?? 0) > 0)
                ->pluck('id')
                ->all();
        });

        if ($ids === []) {
            return collect();
        }

        return Category::query()
            ->whereKey($ids)
            ->get()
            ->sortBy(fn (Category $category): int => array_search($category->id, $ids, true))
            ->values();
    }

    private function productsByCachedIds(array $ids)
    {
        if ($ids === []) {
            return collect();
        }

        return Product::query()
            ->whereKey($ids)
            ->select([
                'id',
                'category_id',
                'variant_group_id',
                'name',
                'name_ru',
                'slug',
                'sku',
                'brand',
                'price',
                'sale_price',
                'discount_percent',
                'image_url',
                'image_path',
                'stock',
                'is_active',
                'reviews_count',
                'reviews_avg_rating',
            ])
            ->with($this->productCardRelations())
            ->get()
            ->sortBy(fn (Product $product): int => array_search($product->id, $ids, true))
            ->values();
    }

    private function productCardRelations(): array
    {
        return [
            'category.parentRecursive',
            'variantGroup' => fn ($query) => $query
                ->whereIn('status', ProductVariantGroup::FRONTEND_STATUSES)
                ->withCount(['products as active_products_count' => fn ($query) => $query->where('is_active', true)]),
            'variantGroup.products' => fn ($query) => $query
                ->where('is_active', true)
                ->select([
                    'id',
                    'category_id',
                    'variant_group_id',
                    'image_url',
                    'image_path',
                    'variant_options',
                    'variant_options_ru',
                    'specifications',
                    'specifications_ru',
                    'slug',
                    'stock',
                    'price',
                    'sale_price',
                    'discount_percent',
                    'is_primary_variant',
                    'is_active',
                ])
                ->orderByDesc('is_primary_variant')
                ->orderBy('id'),
        ];
    }

    private function facetCounts(Builder $query, string $column): array
    {
        $index = match ($column) {
            'brand' => 'products_catalog_brand_v2_idx',
            'model' => 'products_catalog_model_v2_idx',
            'season' => 'products_catalog_season_v2_idx',
            'season_ru' => 'products_catalog_season_ru_v2_idx',
            'usage_type' => 'products_catalog_usage_v2_idx',
            'usage_type_ru' => 'products_catalog_usage_ru_v2_idx',
            'material' => 'products_catalog_material_v2_idx',
            'material_ru' => 'products_catalog_material_ru_v2_idx',
            default => null,
        };
        $query = clone $query;

        if ($index) {
            $this->forceProductIndex($query, $index);
        }

        return $query
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->selectRaw("{$column} as value, COUNT(*) as aggregate")
            ->groupBy($column)
            ->orderByDesc('aggregate')
            ->limit(100)
            ->pluck('aggregate', 'value')
            ->map(fn ($count): int => (int) $count)
            ->sortKeys()
            ->all();
    }

    private function forceProductIndex(Builder $query, string $index): Builder
    {
        if (! in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $query;
        }

        return $query->from(DB::raw("products FORCE INDEX (`{$index}`)"));
    }

    private function withSelectedFacetValues(array $facets, array $selected): array
    {
        foreach ($selected as $value) {
            $facets[$value] ??= 0;
        }

        ksort($facets);

        return $facets;
    }

    private function filterPopularity(?Category $category): array
    {
        $rows = DB::table('catalog_filter_clicks')
            ->where('category_id', $category?->id)
            ->get(['filter_key', 'filter_value', 'clicks']);

        return [
            'filters' => $rows
                ->whereNull('filter_value')
                ->pluck('clicks', 'filter_key')
                ->map(fn ($clicks): int => (int) $clicks)
                ->all(),
            'values' => $rows
                ->whereNotNull('filter_value')
                ->groupBy('filter_key')
                ->map(fn ($items) => $items
                    ->pluck('clicks', 'filter_value')
                    ->map(fn ($clicks): int => (int) $clicks)
                    ->all())
                ->all(),
        ];
    }

    private function sortBaseFilters(array $filters, array $filterPopularity): array
    {
        $priority = ['sale', 'brand', 'model'];

        usort($filters, function (string $left, string $right) use ($filterPopularity, $priority): int {
            $leftPriority = array_search($left, $priority, true);
            $rightPriority = array_search($right, $priority, true);

            if ($leftPriority !== false || $rightPriority !== false) {
                return ($leftPriority === false ? 99 : $leftPriority) <=> ($rightPriority === false ? 99 : $rightPriority);
            }

            return ($filterPopularity['filters'][$right] ?? 0) <=> ($filterPopularity['filters'][$left] ?? 0);
        });

        return $filters;
    }

    private function sortFacetValues(array $facets, string $filterKey, array $filterPopularity): array
    {
        $valuePopularity = $filterPopularity['values'][$filterKey] ?? [];

        uksort($facets, function (string $left, string $right) use ($facets, $valuePopularity): int {
            $clickCompare = ($valuePopularity[$right] ?? 0) <=> ($valuePopularity[$left] ?? 0);

            if ($clickCompare !== 0) {
                return $clickCompare;
            }

            $countCompare = ($facets[$right] ?? 0) <=> ($facets[$left] ?? 0);

            if ($countCompare !== 0) {
                return $countCompare;
            }

            return strnatcasecmp($left, $right);
        });

        return $facets;
    }

    private function disableFilterForCategory(Category $category, string $type, string $key): void
    {
        if ($type === 'base') {
            $filters = collect($category->resolvedVisibleFilters())
                ->reject(fn (string $filter): bool => $filter === $key)
                ->values()
                ->all();

            if ($filters === []) {
                return;
            }

            $category->forceFill(['visible_filters' => $filters])->save();

            return;
        }

        $filters = $category->resolvedVisibleSpecFilters();

        if ($filters === null) {
            $filters = app(CatalogSpecificationFacets::class)->configuredKeys($category, includeDiscovered: true);
        }

        $filters = collect((array) $filters)
            ->map(fn ($filter): string => trim((string) $filter))
            ->filter(fn (string $filter): bool => $filter !== '' && $filter !== $key)
            ->unique()
            ->values()
            ->all();

        $category->forceFill([
            'visible_spec_filters' => $filters,
            'visible_spec_filters_ru' => $filters,
        ])->save();
    }

    private function applyCompactFilterQuery(Request $request, ?Category $category, CatalogSpecificationFacets $specificationFacets, CatalogCache $cache): void
    {
        $filter = trim((string) $request->query('filter', ''));

        if ($filter === '') {
            $query = $request->query();
            Arr::forget($query, 'filter');
            $request->query->replace($query);

            return;
        }

        $query = $request->query();
        Arr::forget($query, 'filter');

        $map = $this->compactFilterMap($category, $specificationFacets, $cache);

        foreach ($this->normalizeFilterTokens(explode(';', $filter)) as $token) {

            if (preg_match('/^(\d+)-(\d+)$/', $token, $matches)) {
                $query['min_price'] = (int) $matches[1];
                $query['max_price'] = (int) $matches[2];

                continue;
            }

            if ($token === 'in-stock') {
                $query['in_stock'] = 1;

                continue;
            }

            if ($token === 'sale') {
                $query['on_sale'] = 1;

                continue;
            }

            $filterItem = $map[$token] ?? null;

            if (! $filterItem) {
                abort(404);
            }

            if ($filterItem['type'] === 'spec') {
                $query['spec'][$filterItem['key']][] = $filterItem['value'];
            } else {
                $query[$filterItem['key']][] = $filterItem['value'];
            }
        }

        $request->query->replace($query);
    }

    private function validPathFilterTokens(array $tokens, ?Category $category, CatalogSpecificationFacets $specificationFacets, CatalogCache $cache): bool
    {
        if ($tokens === []) {
            return true;
        }

        $map = $this->compactFilterMap($category, $specificationFacets, $cache);

        foreach ($this->normalizeFilterTokens($tokens) as $token) {

            if (preg_match('/^(\d+)-(\d+)$/', $token) || in_array($token, ['in-stock', 'sale'], true)) {
                continue;
            }

            if (! isset($map[$token])) {
                return false;
            }
        }

        return true;
    }

    private function normalizeFilterTokens(array $tokens): array
    {
        return collect($tokens)
            ->map(fn ($token): string => mb_strtolower(trim((string) $token)))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function compactFilterMap(?Category $category, CatalogSpecificationFacets $specificationFacets, CatalogCache $cache): array
    {
        $scope = $category ? 'category-'.$category->id : 'all';

        return $cache->remember('compact-filter-map:v2:'.$scope, function () use ($category, $specificationFacets, $cache): array {
            $categoryIds = $category ? [$category->id, ...$category->loadMissing('childrenRecursive')->descendantIds()] : [];
            $baseQuery = Product::query()
                ->where('is_active', true)
                ->when($category, fn (Builder $query) => $query->whereIn('category_id', $categoryIds));
            $map = [];

            foreach (['brand', 'model', 'season', 'usage_type', 'material'] as $key) {
                $column = in_array($key, ['season', 'usage_type', 'material'], true) && Locale::isRussian()
                    ? $key.'_ru'
                    : $key;

                foreach ($this->facetCounts($baseQuery, $column) as $value => $count) {
                    $map[$this->filterToken($value)] ??= ['type' => 'base', 'key' => $key, 'value' => $value];
                }
            }

            foreach ($specificationFacets->warmBaseFacets($category, $cache) as $key => $values) {
                foreach (array_keys($values) as $value) {
                    $map[$this->filterToken($value)] ??= ['type' => 'spec', 'key' => $key, 'value' => $value];
                }
            }

            return $map;
        });
    }

    private function filterToken(string $value): string
    {
        return Str::slug($value, '-', 'uk') ?: Str::slug($value);
    }

    private function filterOptionUrl(Category $category, array $activeFilterQuery, string $name, string $value): string
    {
        $query = $activeFilterQuery;

        if (preg_match('/^spec\[(.+)\]$/', $name, $matches) === 1) {
            $query['spec'] = array_merge($activeFilterQuery['spec'] ?? [], [$matches[1] => [$value]]);
        } else {
            $query[$name] = [$value];
        }

        return $this->catalogUrl($category, $query);
    }

    private function catalogUrl(?Category $category, array $query, bool $absolute = false): string
    {
        abort_unless($category, 404);

        $path = $this->catalogPaginationPath($category, $query);

        if ($absolute) {
            return AppUrl::absoluteIfPossible($path);
        }

        return $path;
    }

    private function catalogPaginationPath(Category $category, array $query): string
    {
        $compact = $this->compactCatalogQuery($query);
        $query = $compact['query'];
        $path = $category->catalogUrl([], absolute: false);

        if ($compact['pathTokens'] !== []) {
            $path = $category->catalogUrl(['filter' => implode(';', $compact['pathTokens'])], absolute: false);
        }

        return AppUrl::relativePath($this->urlWithQuery($path, $query));
    }

    private function canonicalCatalogUrl(?Category $category, Request $request): string
    {
        if ($this->catalogUsesFilterQueryParameter($category, $request)) {
            return $this->catalogUrl($category, [], absolute: true);
        }

        $query = $request->query();
        Arr::forget($query, ['fast_filters', 'sort', 'per_page']);

        if ((int) ($query['page'] ?? 1) <= 1) {
            Arr::forget($query, 'page');
        }

        return $this->catalogUrl($category, $query, absolute: true);
    }

    private function catalogUsesFilterQueryParameter(?Category $category, Request $request): bool
    {
        abort_unless($category, 404);

        return filled($this->compactCatalogQuery($request->query())['query']['filter'] ?? null);
    }

    private function compactCatalogQuery(array $query): array
    {
        Arr::forget($query, 'fast_filters');
        $tokens = [];

        foreach (['brand', 'model', 'season', 'usage_type', 'material'] as $key) {
            foreach ((array) ($query[$key] ?? []) as $value) {
                $tokens[] = $this->filterToken((string) $value);
            }

            Arr::forget($query, $key);
        }

        foreach ((array) ($query['spec'] ?? []) as $values) {
            foreach ((array) $values as $value) {
                $tokens[] = $this->filterToken((string) $value);
            }
        }

        Arr::forget($query, 'spec');

        if (isset($query['min_price'], $query['max_price'])) {
            $tokens[] = ((int) $query['min_price']).'-'.((int) $query['max_price']);
            Arr::forget($query, ['min_price', 'max_price']);
        }

        if (! empty($query['in_stock'])) {
            $tokens[] = 'in-stock';
            Arr::forget($query, 'in_stock');
        }

        if (! empty($query['on_sale'])) {
            $tokens[] = 'sale';
            Arr::forget($query, 'on_sale');
        }

        $tokens = $this->normalizeFilterTokens($tokens);
        $pathTokens = [];

        if (count($tokens) > 0 && count($tokens) <= Category::MAX_INDEXABLE_FILTER_TOKENS) {
            $pathTokens = $tokens;
        } elseif ($tokens !== []) {
            $query['filter'] = implode(';', $tokens);
        }

        ksort($query);

        return [
            'query' => $query,
            'pathTokens' => $pathTokens,
        ];
    }

    private function urlWithQuery(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        $parts = [];

        foreach ($query as $key => $value) {
            if ($key === 'filter') {
                $parts[] = 'filter='.(string) $value;

                continue;
            }

            $parts[] = http_build_query([$key => $value]);
        }

        $parts = array_filter($parts);

        return $parts === [] ? $url : $url.'?'.implode('&', $parts);
    }

    private function comparableUrl(string $url): string
    {
        $parts = parse_url($url);
        $path = '/'.trim((string) ($parts['path'] ?? ''), '/');

        if ($path === '/') {
            $path = '';
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        ksort($query);

        return mb_strtolower($path).($query === [] ? '' : '?'.http_build_query($query));
    }

    private function activeFilterLabels(?Category $category, array $baseFilterLabels, array $brands, array $models, array $seasons, array $usageTypes, array $materials, array $specFilters, ?int $minPrice, ?int $maxPrice, ?int $maxWeight, bool $inStock, bool $onSale): array
    {
        $labels = [];

        foreach ([
            mb_strtolower($baseFilterLabels['brand']) => $brands,
            mb_strtolower($baseFilterLabels['model']) => $models,
            mb_strtolower($baseFilterLabels['season']) => $seasons,
            mb_strtolower($baseFilterLabels['usage_type']) => $usageTypes,
            mb_strtolower($baseFilterLabels['material']) => $materials,
        ] as $label => $values) {
            foreach ($values as $value) {
                $labels[] = "{$label} {$value}";
            }
        }

        foreach ($specFilters as $key => $values) {
            $label = mb_strtolower($this->filterLabel($category, $key, $key));

            foreach ($values as $value) {
                $labels[] = $label.' '.$value;
            }
        }

        if ($minPrice !== null) {
            $labels[] = __('від').' '.number_format($minPrice, 0, ',', ' ').' ₴';
        }

        if ($maxPrice !== null) {
            $labels[] = __('до').' '.number_format($maxPrice, 0, ',', ' ').' ₴';
        }

        if ($maxWeight !== null) {
            $labels[] = __('вага до').' '.$maxWeight.' '.__('г');
        }

        if ($inStock) {
            $labels[] = __('в наявності');
        }

        if ($onSale) {
            $labels[] = __('акційні');
        }

        return $labels;
    }

    private function activeFilterItems(Request $request, ?Category $category, array $baseFilterLabels, array $brands, array $models, array $seasons, array $usageTypes, array $materials, array $specFilters, ?int $minPrice, ?int $maxPrice, ?int $maxWeight, bool $inStock, bool $onSale): array
    {
        $items = [];

        foreach ([
            'brand' => [mb_strtolower($baseFilterLabels['brand']), $brands],
            'model' => [mb_strtolower($baseFilterLabels['model']), $models],
            'season' => [mb_strtolower($baseFilterLabels['season']), $seasons],
            'usage_type' => [mb_strtolower($baseFilterLabels['usage_type']), $usageTypes],
            'material' => [mb_strtolower($baseFilterLabels['material']), $materials],
        ] as $key => [$label, $values]) {
            foreach ($values as $value) {
                $items[] = [
                    'label' => "{$label} {$value}",
                    'url' => $this->urlWithoutFilter($request, $category, $key, $value),
                ];
            }
        }

        foreach ($specFilters as $key => $values) {
            $label = mb_strtolower($this->filterLabel($category, $key, $key));

            foreach ($values as $value) {
                $items[] = [
                    'label' => $label.' '.$value,
                    'url' => $this->urlWithoutFilter($request, $category, 'spec.'.$key, $value),
                ];
            }
        }

        if ($minPrice !== null || $maxPrice !== null) {
            $items[] = [
                'label' => trim(($minPrice !== null ? __('від').' '.number_format($minPrice, 0, ',', ' ').' ₴ ' : '').($maxPrice !== null ? __('до').' '.number_format($maxPrice, 0, ',', ' ').' ₴' : '')),
                'url' => $this->urlWithoutFilter($request, $category, ['min_price', 'max_price']),
            ];
        }

        if ($maxWeight !== null) {
            $items[] = [
                'label' => __('вага до').' '.$maxWeight.' '.__('г'),
                'url' => $this->urlWithoutFilter($request, $category, 'max_weight'),
            ];
        }

        if ($inStock) {
            $items[] = [
                'label' => __('в наявності'),
                'url' => $this->urlWithoutFilter($request, $category, 'in_stock'),
            ];
        }

        if ($onSale) {
            $items[] = [
                'label' => __('акційні'),
                'url' => $this->urlWithoutFilter($request, $category, 'on_sale'),
            ];
        }

        return $items;
    }

    private function urlWithoutFilter(Request $request, ?Category $category, string|array $key, ?string $value = null): string
    {
        $query = $request->query();
        Arr::forget($query, 'page');

        foreach ((array) $key as $filterKey) {
            if ($value === null) {
                Arr::forget($query, $filterKey);

                continue;
            }

            $current = Arr::get($query, $filterKey, []);
            $current = array_values(array_filter((array) $current, fn ($item): bool => (string) $item !== (string) $value));

            if ($current === []) {
                Arr::forget($query, $filterKey);
            } else {
                Arr::set($query, $filterKey, $current);
            }
        }

        return $this->catalogUrl($category, $query);
    }

    private function visibleBaseFilters(?Category $category): array
    {
        if ($category === null) {
            return self::DEFAULT_BASE_FILTERS;
        }

        return collect($category->resolvedVisibleFilters())
            ->intersect(array_keys(self::BASE_FILTERS))
            ->values()
            ->all();
    }

    private function baseFilterLabels(?Category $category): array
    {
        return collect(self::BASE_FILTERS)
            ->map(fn (string $label, string $key): string => $this->filterLabel($category, $key, __($label)))
            ->all();
    }

    private function filterLabel(?Category $category, string $key, string $default): string
    {
        return $category?->filterLabel($key, $default) ?? $default;
    }

    /**
     * @param  list<string>  $visibleBaseFilters
     * @return list<string>
     */
    private function baseFiltersForDisplay(array $visibleBaseFilters, bool $hasSaleProducts): array
    {
        if ($hasSaleProducts && ! in_array('sale', $visibleBaseFilters, true)) {
            return [...$visibleBaseFilters, 'sale'];
        }

        return $visibleBaseFilters;
    }

    /**
     * @param  list<string>  $configured
     * @param  array<string, int>  $availableBrands
     * @param  array<string, int>  $availableModels
     * @param  array<string, int>  $availableSeasons
     * @param  array<string, int>  $availableUsageTypes
     * @param  array<string, int>  $availableMaterials
     * @param  list<string>  $selectedBrands
     * @param  list<string>  $selectedModels
     * @param  list<string>  $selectedSeasons
     * @param  list<string>  $selectedUsageTypes
     * @param  list<string>  $selectedMaterials
     * @return list<string>
     */
    private function applicableBaseFilters(
        array $configured,
        array $availableBrands,
        array $availableModels,
        array $availableSeasons,
        array $availableUsageTypes,
        array $availableMaterials,
        mixed $availableMinPrice,
        mixed $availableMaxPrice,
        bool $hasSaleProducts,
        bool $hasWeightProducts,
        bool $hasOutOfStockProducts,
        array $selectedBrands,
        array $selectedModels,
        array $selectedSeasons,
        array $selectedUsageTypes,
        array $selectedMaterials,
        ?int $minPrice,
        ?int $maxPrice,
        ?int $maxWeight,
        bool $inStock,
        bool $onSale,
        bool $categoryHasProducts = false,
    ): array {
        return collect($configured)
            ->filter(function (string $filter) use (
                $availableBrands,
                $availableModels,
                $availableSeasons,
                $availableUsageTypes,
                $availableMaterials,
                $availableMinPrice,
                $availableMaxPrice,
                $hasSaleProducts,
                $hasWeightProducts,
                $hasOutOfStockProducts,
                $selectedBrands,
                $selectedModels,
                $selectedSeasons,
                $selectedUsageTypes,
                $selectedMaterials,
                $minPrice,
                $maxPrice,
                $maxWeight,
                $inStock,
                $onSale,
                $categoryHasProducts,
            ): bool {
                return match ($filter) {
                    'brand' => $availableBrands !== [] || $selectedBrands !== [],
                    'model' => $availableModels !== [] || $selectedModels !== [],
                    'price' => $this->hasPriceFilterRange($availableMinPrice, $availableMaxPrice)
                        || $minPrice !== null
                        || $maxPrice !== null
                        || $categoryHasProducts,
                    'season' => $availableSeasons !== [] || $selectedSeasons !== [],
                    'usage_type' => $availableUsageTypes !== [] || $selectedUsageTypes !== [],
                    'material' => $availableMaterials !== [] || $selectedMaterials !== [],
                    'sale' => $hasSaleProducts || $onSale,
                    'weight' => $hasWeightProducts || $maxWeight !== null,
                    'stock' => $hasOutOfStockProducts || $inStock,
                    default => false,
                };
            })
            ->values()
            ->all();
    }

    private function hasPriceFilterRange(mixed $minimum, mixed $maximum): bool
    {
        return $minimum !== null && $maximum !== null;
    }

    private function configuredSpecFilterKeys(?Category $category): array
    {
        return app(CatalogSpecificationFacets::class)->configuredKeys($category, includeDiscovered: true);
    }

    /**
     * @param  list<string>  $except
     */
    private function normalizedQuery(Request $request, array $except = []): array
    {
        $query = $request->query();

        foreach ($except as $key) {
            Arr::forget($query, $key);
        }

        ksort($query);

        return $this->sortNestedQuery($query);
    }

    private function sortNestedQuery(array $query): array
    {
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                ksort($value);
                $query[$key] = $this->sortNestedQuery($value);
            }
        }

        return $query;
    }
}
