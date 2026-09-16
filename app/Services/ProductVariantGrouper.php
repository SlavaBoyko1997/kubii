<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariantGroup;
use App\Support\CatalogCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductVariantGrouper
{
    private const DERIVED_COLOR_KEY = 'Колір';

    private const DERIVED_SHOE_SIZE_KEY = 'Розмір взуття';

    private const NAME_MODIFIER_TOKENS = [
        'Wide',
    ];

    private const PRIMARY_OPTION_HINTS = [
        'Розмір',
        'Розмір взуття',
        'Міжнародний розмір',
        'Международный размер',
        'размер',
        'Довжина',
        'Тест',
        'Обʼєм',
        'Об’єм',
        'Висота',
        'Модифікація',
        'Вага',
        'Маса',
        'Діаметр',
        'Ємність',
        'Кількість',
    ];

    private const NOISE_SPEC_KEYS = [
        'Артикул',
        'Артикул виробника',
        'Код',
        'Код товару',
        'Штрихкод',
        'Довжина в упаковці, м',
        'Гарантія, міс',
        'Країна походження',
        'Ширина в упаковці, м',
        'Висота в упаковці, м',
        'Вага в упаковці, кг',
        'Розмір пакування',
        'Колір',
        'Тест (грам), Min',
        'Тест (грам), Max',
        'Транспортувальна довжина, см',
        'Опис',
        'Фото',
        'URL',
    ];

    private const SPLIT_DUPLICATE_OPTION_KEYS = [
        'Колір товару',
        'Колір',
    ];

    public function discover(?Category $category = null, ?callable $progress = null, bool $includeInactive = false): array
    {
        return $this->runDiscover($this->resolveCategoryScope($category), $includeInactive, $progress);
    }

    /**
     * @param  list<int>  $categoryIds
     */
    public function discoverScoped(array $categoryIds, bool $includeInactive = false, ?callable $progress = null): array
    {
        $categoryScope = array_values(array_unique(array_map(intval(...), $categoryIds)));

        if ($categoryScope === []) {
            return ['created' => 0, 'skipped' => 0, 'categories_scanned' => 0];
        }

        return $this->runDiscover($categoryScope, $includeInactive, $progress);
    }

    /**
     * @param  list<int>|null  $categoryScope
     */
    private function runDiscover(?array $categoryScope, bool $includeInactive, ?callable $progress): array
    {
        $categoriesQuery = Category::query()
            ->when(
                $categoryScope === null,
                fn ($query) => $query->whereIn('id', $this->categoryIdsWithGroupableProducts($includeInactive)),
            )
            ->when($categoryScope !== null, fn ($query) => $query->whereIn('id', $categoryScope))
            ->orderBy('id');

        $created = 0;
        $skipped = 0;
        $categoriesScanned = 0;
        $wasMuted = ProductVariantGroup::$muteCatalogSideEffects;
        ProductVariantGroup::$muteCatalogSideEffects = true;

        try {
            foreach ($categoriesQuery->cursor() as $currentCategory) {
                $categoriesScanned++;
                $reservedProductIds = [];

                $ungroupedProducts = $this->productsInCategory($currentCategory, $includeInactive)
                    ->where(function ($query): void {
                        $query
                            ->whereNull('variant_group_id')
                            ->orWhereDoesntHave('variantGroup', fn ($query) => $query->whereIn('status', ['published', 'rejected']));
                    })
                    ->get($this->discoveryProductColumns());

                Product::query()
                    ->whereKey($ungroupedProducts->pluck('id'))
                    ->whereHas('variantGroup', fn ($query) => $query->whereIn('status', ['auto_suggested', 'needs_review', 'approved']))
                    ->update([
                        'variant_group_id' => null,
                        'is_primary_variant' => false,
                        'is_visible_in_catalog' => true,
                    ]);

                foreach ($this->feedGroupCandidates($currentCategory, $ungroupedProducts) as $candidate) {
                    if ($this->applyCandidate($candidate, $created, $skipped)) {
                        $reservedProductIds = array_merge($reservedProductIds, $candidate['product_ids']);
                    }
                }

                $singleFeedGroupVariantIds = $ungroupedProducts
                    ->filter(fn (Product $product): bool => $this->isFeedGroupVariantId($product->variant_id))
                    ->groupBy('variant_id')
                    ->filter(fn (Collection $products): bool => $products->count() === 1)
                    ->keys()
                    ->values()
                    ->all();

                unset($ungroupedProducts);

                $brands = $this->productsInCategory($currentCategory, $includeInactive)
                    ->when($reservedProductIds !== [], fn ($query) => $query->whereNotIn('id', $reservedProductIds))
                    ->whereNotNull('brand')
                    ->where('brand', '!=', '')
                    ->distinct()
                    ->orderBy('brand')
                    ->pluck('brand');

                foreach ($brands as $brand) {
                    Product::query()
                        ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
                        ->whereBelongsTo($currentCategory)
                        ->where('brand', $brand)
                        ->when($reservedProductIds !== [], fn ($query) => $query->whereNotIn('id', $reservedProductIds))
                        ->whereHas('variantGroup', fn ($query) => $query->whereIn('status', ['auto_suggested', 'needs_review', 'approved']))
                        ->update([
                            'variant_group_id' => null,
                            'is_primary_variant' => false,
                            'is_visible_in_catalog' => true,
                        ]);

                    $brandProducts = $this->productsInCategory($currentCategory, $includeInactive)
                        ->where('brand', $brand)
                        ->when($reservedProductIds !== [], fn ($query) => $query->whereNotIn('id', $reservedProductIds))
                        ->where(function ($query) use ($singleFeedGroupVariantIds): void {
                            $query
                                ->whereNull('variant_id')
                                ->orWhere('variant_id', 'not like', '%-group-%')
                                ->when($singleFeedGroupVariantIds !== [], fn ($query) => $query->orWhereIn('variant_id', $singleFeedGroupVariantIds));
                        })
                        ->where(function ($query): void {
                            $query
                                ->whereNull('variant_group_id')
                                ->orWhereDoesntHave('variantGroup', fn ($query) => $query->whereIn('status', ['published', 'rejected']));
                        })
                        ->get($this->discoveryProductColumns());

                    foreach ($brandProducts->groupBy(fn (Product $product): string => (string) ($product->source ?? '')) as $sourceProducts) {
                        foreach ($this->candidates($currentCategory, $sourceProducts->values()) as $candidate) {
                            if ($this->applyCandidate($candidate, $created, $skipped)) {
                                $reservedProductIds = array_merge($reservedProductIds, $candidate['product_ids']);
                            }
                        }
                    }

                    unset($brandProducts);
                    gc_collect_cycles();
                }

                foreach ($this->feedGroupCandidates(
                    $currentCategory,
                    $this->productsInCategory($currentCategory, $includeInactive)
                        ->whereNotNull('variant_id')
                        ->where('variant_id', 'like', '%-group-%')
                        ->get($this->discoveryProductColumns()),
                ) as $candidate) {
                    $this->applyCandidate($candidate, $created, $skipped);
                }

                $progress?->__invoke($currentCategory->name, $created, $skipped, $categoriesScanned);
            }

            ProductVariantGroup::query()
                ->whereIn('status', ['auto_suggested', 'needs_review', 'approved'])
                ->doesntHave('products')
                ->delete();

            $this->syncUngroupedCatalogVisibility($categoryScope);
        } finally {
            ProductVariantGroup::$muteCatalogSideEffects = $wasMuted;
        }

        app(CatalogCache::class)->invalidate();

        return [
            'created' => $created,
            'skipped' => $skipped,
            'categories_scanned' => $categoriesScanned,
        ];
    }

    /**
     * @return list<int>
     */
    private function categoryIdsWithGroupableProducts(bool $includeInactive): array
    {
        return Product::query()
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->whereNotNull('category_id')
            ->where(function ($query): void {
                $query
                    ->whereNull('variant_group_id')
                    ->orWhereHas('variantGroup', fn ($query) => $query->whereIn('status', ['auto_suggested', 'needs_review', 'approved']));
            })
            ->distinct()
            ->orderBy('category_id')
            ->pluck('category_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<string>
     */
    private function discoveryProductColumns(): array
    {
        return [
            'id',
            'category_id',
            'brand',
            'model',
            'name',
            'price',
            'stock',
            'specifications',
            'variant_id',
            'variant_group_id',
            'source',
            'external_id',
            'is_active',
        ];
    }

    /**
     * @return list<int>|null
     */
    private function resolveCategoryScope(?Category $category): ?array
    {
        if (! $category) {
            return null;
        }

        $category->loadMissing('childrenRecursiveAll');

        $categoryIds = array_values(array_unique([
            $category->id,
            ...$category->allDescendantIds(),
        ]));

        $targetCategoryIds = Category::query()
            ->whereIn('id', $categoryIds)
            ->whereNotNull('target_category_id')
            ->pluck('target_category_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $productCategoryIds = Product::query()
            ->whereIn('source_feed_category_id', $categoryIds)
            ->whereNotNull('category_id')
            ->distinct()
            ->pluck('category_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_values(array_unique([
            ...$categoryIds,
            ...$targetCategoryIds,
            ...$productCategoryIds,
        ]));
    }

    private function productsInCategory(Category $category, bool $includeInactive): \Illuminate\Database\Eloquent\Builder
    {
        return Product::query()
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->whereBelongsTo($category);
    }

    public function syncCatalogVisibility(): void
    {
        ProductVariantGroup::query()
            ->orderBy('id')
            ->each(fn (ProductVariantGroup $group) => $group->syncProductsCatalogVisibility());

        $this->syncUngroupedCatalogVisibility();
    }

    /**
     * @param  list<int>|null  $categoryScope
     */
    private function syncUngroupedCatalogVisibility(?array $categoryScope = null): void
    {
        Product::query()
            ->whereNull('variant_group_id')
            ->where('is_active', true)
            ->where('is_visible_in_catalog', false)
            ->when($categoryScope !== null, fn ($query) => $query->whereIn('category_id', $categoryScope))
            ->select('id')
            ->orderBy('id')
            ->chunkById(1000, function (Collection $products): void {
                Product::query()
                    ->whereKey($products->pluck('id'))
                    ->update(['is_visible_in_catalog' => true]);
            });
    }

    private function applyCandidate(array $candidate, int &$created, int &$skipped): bool
    {
        $isFeedGroup = ($candidate['grouping_level'] ?? '') === 'feed_group_id';
        $isSafe = $isFeedGroup
            ? $this->isSafeFeedGroupCandidate($candidate)
            : $this->isSafeCandidate($candidate);

        if (! $isSafe) {
            $skipped++;

            return false;
        }

        $group = ProductVariantGroup::query()->where('group_key', $candidate['group_key'])->first();

        if ($group && in_array($group->status, ['rejected', 'published'], true)) {
            $skipped++;

            return false;
        }

        DB::transaction(function () use ($candidate, &$created): void {
            ProductVariantGroup::query()->updateOrCreate(
                ['group_key' => $candidate['group_key']],
                [
                    'category_id' => $candidate['category_id'],
                    'primary_product_id' => $candidate['primary_product_id'],
                    'brand' => $candidate['brand'],
                    'title' => $candidate['title'],
                    'grouping_level' => $candidate['grouping_level'],
                    'confidence' => $candidate['confidence'],
                    'variant_option_keys' => $candidate['variant_option_keys'],
                    'secondary_spec_keys' => $candidate['secondary_spec_keys'],
                    'candidate_summary' => $candidate['candidate_summary'],
                    'status' => 'approved',
                ],
            );

            $group = ProductVariantGroup::query()
                ->where('group_key', $candidate['group_key'])
                ->firstOrFail();

            Product::query()
                ->whereIn('id', $candidate['product_ids'])
                ->update([
                    'variant_group_id' => $group->id,
                    'is_primary_variant' => false,
                    'is_visible_in_catalog' => false,
                ]);

            foreach ($candidate['products'] as $product) {
                $product->refresh();
                $product->forceFill([
                    'variant_group_id' => $group->id,
                    'variant_id' => $product->variant_id ?: ($product->source ?: 'product').'-'.($product->external_id ?: $product->id),
                    'variant_options' => $this->variantOptions($product, $candidate['variant_option_keys']),
                    'variant_secondary_specs' => $this->variantOptions($product, $candidate['secondary_spec_keys']),
                    'is_primary_variant' => $product->id === $candidate['primary_product_id'],
                ])->saveQuietly();
            }

            $group->syncProductsCatalogVisibility();

            $created++;
        });

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function feedGroupCandidates(Category $category, Collection $products): array
    {
        return $products
            ->filter(fn (Product $product): bool => $this->isFeedGroupVariantId($product->variant_id))
            ->groupBy('variant_id')
            ->filter(fn (Collection $groupProducts): bool => $groupProducts->count() >= 2)
            ->map(function (Collection $groupProducts, string $variantId) use ($category): array {
                $groupProducts = $groupProducts->values();
                $candidate = $this->candidate($category, 'feed_group_id', $variantId, $groupProducts);

                return $candidate
                    ? $this->finalizeFeedGroupCandidate($candidate, $variantId, $groupProducts)
                    : $this->feedGroupFallbackCandidate($category, $variantId, $groupProducts);
            })
            ->values()
            ->all();
    }

    private function isFeedGroupVariantId(?string $variantId): bool
    {
        return filled($variantId) && str_contains($variantId, '-group-');
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function finalizeFeedGroupCandidate(array $candidate, string $variantId, Collection $products): array
    {
        $candidate['group_key'] = sha1($products->first()->category_id.'|feed_group|'.$variantId);
        $candidate['grouping_level'] = 'feed_group_id';
        $candidate['confidence'] = max((int) ($candidate['confidence'] ?? 0), 92);
        $candidate['title'] = $this->feedGroupTitle($products);

        return $candidate;
    }

    private function feedGroupFallbackCandidate(Category $category, string $variantId, Collection $products): array
    {
        $primary = $this->primaryProduct($products);
        $variantOptionKeys = $this->variantOptionKeys($products);

        return [
            'category_id' => $category->id,
            'brand' => (string) ($products->first()->brand ?? ''),
            'title' => $this->feedGroupTitle($products),
            'group_key' => sha1($category->id.'|feed_group|'.$variantId),
            'grouping_level' => 'feed_group_id',
            'confidence' => 92,
            'variant_option_keys' => $variantOptionKeys,
            'secondary_spec_keys' => $this->secondarySpecKeys($products, $variantOptionKeys),
            'primary_product_id' => $primary->id,
            'product_ids' => $products->pluck('id')->sort()->values()->all(),
            'products' => $products,
            'candidate_summary' => [
                'products_count' => $products->count(),
                'price_min' => (float) $products->min('price'),
                'price_max' => (float) $products->max('price'),
                'examples' => $products->take(5)->pluck('name')->all(),
            ],
        ];
    }

    private function feedGroupTitle(Collection $products): string
    {
        $names = $products->pluck('name')->map(fn ($name): string => trim((string) $name))->filter()->values();

        if ($names->isEmpty()) {
            return 'Група товарів';
        }

        $prefix = $names->first();

        foreach ($names->slice(1) as $name) {
            $length = min(mb_strlen($prefix), mb_strlen($name));

            for ($index = $length; $index > 0; $index--) {
                if (mb_substr($prefix, 0, $index) === mb_substr($name, 0, $index)) {
                    $prefix = mb_substr($prefix, 0, $index);

                    continue 2;
                }
            }

            $prefix = '';

            break;
        }

        $title = trim($prefix) ?: $names->first();
        $brand = trim((string) $products->first()->brand);
        $model = trim((string) $products->first()->model);

        if ($brand !== '' && $model !== '' && ! str_contains(mb_strtolower($title), mb_strtolower($brand))) {
            return Str::limit(trim($brand.' '.$model), 120);
        }

        if ($brand !== '' && ! str_contains(mb_strtolower($title), mb_strtolower($brand))) {
            return Str::limit(trim($brand.' '.$title), 120);
        }

        return Str::limit($title, 120);
    }

    private function isSafeFeedGroupCandidate(array $candidate): bool
    {
        return ($candidate['grouping_level'] ?? '') === 'feed_group_id'
            && count($candidate['product_ids']) >= 2;
    }

    private function candidates(Category $category, Collection $products): array
    {
        $candidates = [];

        foreach (['exact_model' => 0, 'model_family' => 2, 'series' => 1] as $level => $tokens) {
            $groups = $products->groupBy(fn (Product $product): string => $this->groupKey($product, $tokens));

            foreach ($groups as $key => $groupProducts) {
                if ($key === '' || $groupProducts->count() < 2) {
                    continue;
                }

                $candidate = $this->candidate($category, $level, $key, $groupProducts->values());

                if ($candidate && $this->isSafeCandidate($candidate)) {
                    $candidates[] = $candidate;

                    continue;
                }

                foreach ($this->splitDuplicateOptionCandidates($category, $level, $key, $groupProducts->values()) as $splitCandidate) {
                    $candidates[] = $splitCandidate;
                }

                foreach ($this->sameSpecificationColorCandidates($category, $level, $key, $groupProducts->values()) as $colorCandidate) {
                    $candidates[] = $colorCandidate;
                }
            }
        }

        $reservedProductIds = [];

        return collect($candidates)
            ->sortByDesc('confidence')
            ->unique(fn (array $candidate): string => implode('-', $candidate['product_ids']))
            ->filter(function (array $candidate) use (&$reservedProductIds): bool {
                if (array_intersect($candidate['product_ids'], $reservedProductIds) !== []) {
                    return false;
                }

                $reservedProductIds = array_merge($reservedProductIds, $candidate['product_ids']);

                return true;
            })
            ->values()
            ->all();
    }

    private function splitDuplicateOptionCandidates(Category $category, string $level, string $key, Collection $products): array
    {
        foreach (self::SPLIT_DUPLICATE_OPTION_KEYS as $splitKey) {
            $groups = $products
                ->groupBy(fn (Product $product): string => trim((string) data_get($product->specifications ?? [], $splitKey)))
                ->filter(fn (Collection $items, string $value): bool => $value !== '' && $items->count() >= 2);

            if ($groups->count() < 2) {
                continue;
            }

            $candidates = $groups
                ->map(function (Collection $items, string $value) use ($category, $level, $key, $splitKey): ?array {
                    $candidate = $this->candidate($category, $level, $key.'|'.$splitKey.':'.$value, $items->values(), $key);

                    return $candidate && $this->isSafeCandidate($candidate) ? $candidate : null;
                })
                ->filter()
                ->values()
                ->all();

            if (count($candidates) === $groups->count()) {
                return $candidates;
            }
        }

        return [];
    }

    private function candidate(Category $category, string $level, string $key, Collection $products, ?string $titleKey = null): ?array
    {
        $variantOptionKeys = $this->variantOptionKeys($products);

        return $this->candidateWithOptionKeys($category, $level, $key, $products, $variantOptionKeys, $titleKey);
    }

    private function candidateWithOptionKeys(Category $category, string $level, string $key, Collection $products, array $variantOptionKeys, ?string $titleKey = null): ?array
    {

        if ($variantOptionKeys === []) {
            return null;
        }

        $secondaryKeys = $this->secondarySpecKeys($products, $variantOptionKeys);
        $primary = $this->primaryProduct($products);
        $confidence = $this->confidence($products, $variantOptionKeys, $level);

        return [
            'category_id' => $category->id,
            'brand' => (string) $products->first()->brand,
            'title' => $this->groupTitle($products, $titleKey ?: $key),
            'group_key' => sha1($category->id.'|'.$products->first()->brand.'|'.$level.'|'.$key),
            'grouping_level' => $level,
            'confidence' => $confidence,
            'variant_option_keys' => $variantOptionKeys,
            'secondary_spec_keys' => $secondaryKeys,
            'primary_product_id' => $primary->id,
            'product_ids' => $products->pluck('id')->sort()->values()->all(),
            'products' => $products,
            'candidate_summary' => [
                'products_count' => $products->count(),
                'price_min' => (float) $products->min('price'),
                'price_max' => (float) $products->max('price'),
                'examples' => $products->take(5)->pluck('name')->all(),
            ],
        ];
    }

    private function sameSpecificationColorCandidates(Category $category, string $level, string $key, Collection $products): array
    {
        $baseOptionKeys = $this->variantOptionKeys($products);

        if ($baseOptionKeys === []) {
            $colorKey = collect($this->colorOptionKeys($products))
                ->first(fn (string $colorKey): bool => $this->differentValuesForKey($products, $colorKey)->count() > 1);

            if (! $colorKey) {
                return [];
            }

            $items = $products
                ->filter(fn (Product $product): bool => filled($this->optionValue($product, $colorKey)))
                ->groupBy(fn (Product $product): string => (string) $this->optionValue($product, $colorKey))
                ->map(fn (Collection $sameColorProducts): Product => $this->primaryProduct($sameColorProducts))
                ->values();

            $candidate = $this->candidateWithOptionKeys(
                $category,
                $level,
                $key.'|color-only',
                $items,
                [$colorKey],
                $key
            );

            return $candidate && $this->isSafeCandidate($candidate) ? [$candidate] : [];
        }

        $groups = $products
            ->groupBy(fn (Product $product): string => json_encode($this->variantOptions($product, $baseOptionKeys), JSON_UNESCAPED_UNICODE))
            ->filter(fn (Collection $items, string $value): bool => $value !== '' && $value !== '[]' && $items->count() >= 2);

        if ($groups->count() < 2) {
            return [];
        }

        return $groups
            ->map(function (Collection $items, string $value) use ($category, $level, $key, $baseOptionKeys): ?array {
                $colorKey = collect($this->colorOptionKeys($items))
                    ->first(fn (string $colorKey): bool => $this->differentValuesForKey($items, $colorKey)->count() > 1);

                if (! $colorKey) {
                    return null;
                }

                $items = $items
                    ->filter(fn (Product $product): bool => filled($this->optionValue($product, $colorKey)))
                    ->groupBy(fn (Product $product): string => (string) $this->optionValue($product, $colorKey))
                    ->map(fn (Collection $sameColorProducts): Product => $this->primaryProduct($sameColorProducts))
                    ->values();

                if ($items->count() < 2) {
                    return null;
                }

                $label = collect(json_decode($value, true) ?: [])
                    ->map(fn ($optionValue, string $optionKey): string => $optionKey.':'.$optionValue)
                    ->implode('|');

                $candidate = $this->candidateWithOptionKeys(
                    $category,
                    $level,
                    $key.'|spec:'.$label,
                    $items->values(),
                    [$colorKey],
                    $key
                );

                if (! $candidate || ! $this->isSafeCandidate($candidate)) {
                    return null;
                }

                $candidate['secondary_spec_keys'] = $baseOptionKeys;

                return $candidate;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function groupKey(Product $product, int $tokensToKeep): string
    {
        $base = trim((string) $product->model) ?: $this->nameWithoutBrand($product);
        $base = $this->appendNameModifiers($product, $base);
        $base = $this->nameWithoutTrailingShoeSize($base);
        $base = preg_replace('/\b(\d{2,4}(?:[.,]\d+)?\s?(см|mm|мм|m|м|g|г|kg|кг|л|ml|мл)|#[\w-]+)\b/iu', ' ', $base) ?? $base;
        $words = collect(preg_split('/\s+/u', Str::ascii($base)) ?: [])
            ->map(fn (string $word): string => mb_strtolower(trim($word)))
            ->filter(fn (string $word): bool => mb_strlen($word) > 1 || preg_match('/^[a-z\d]$/', $word) === 1)
            ->values();

        if ($tokensToKeep > 0) {
            $words = $words->take($tokensToKeep);
        }

        return $words->implode(' ');
    }

    private function appendNameModifiers(Product $product, string $base): string
    {
        $name = (string) $product->name;

        foreach (self::NAME_MODIFIER_TOKENS as $modifier) {
            if (
                (
                    preg_match('/\b'.preg_quote($modifier, '/').'\b/iu', $name) === 1
                    || ($modifier === 'Wide' && preg_match('/\d(?:[.,]\d+)?w$/iu', trim((string) data_get($product->specifications ?? [], 'Розмір'))) === 1)
                )
                && preg_match('/\b'.preg_quote($modifier, '/').'\b/iu', $base) !== 1
            ) {
                $base .= ' '.$modifier;
            }
        }

        return $base;
    }

    private function nameWithoutBrand(Product $product): string
    {
        return trim(preg_replace('/^'.preg_quote((string) $product->brand, '/').'\s+/iu', '', $product->name) ?? $product->name);
    }

    private function variantOptionKeys(Collection $products): array
    {
        $keys = $this->differentSpecKeys($products)
            ->filter(fn (array $values, string $key): bool => $this->isPrimaryOptionKey($key))
            ->keys()
            ->take(5)
            ->values()
            ->all();

        if ($keys === []) {
            $keys = $this->differentSpecKeys($products)->keys()->take(3)->values()->all();
        }

        $keys = $this->preferSpecificOptionKeys($keys);

        if ($keys === [] && $this->differentDerivedShoeSizes($products)->count() > 1) {
            $keys = [self::DERIVED_SHOE_SIZE_KEY];
        }

        return $keys;
    }

    private function preferSpecificOptionKeys(array $keys): array
    {
        if (in_array('Розмір взуття', $keys, true)) {
            $keys = array_values(array_diff($keys, ['Розмір']));
        }

        foreach (['Міжнародний розмір', 'Международный размер'] as $internationalSizeKey) {
            if (in_array($internationalSizeKey, $keys, true)) {
                $keys = array_values(array_diff($keys, ['Розмір']));

                break;
            }
        }

        return $keys;
    }

    private function secondarySpecKeys(Collection $products, array $primaryKeys): array
    {
        return $this->differentSpecKeys($products)
            ->keys()
            ->reject(fn (string $key): bool => in_array($key, $primaryKeys, true))
            ->take(6)
            ->values()
            ->all();
    }

    private function differentSpecKeys(Collection $products): Collection
    {
        $values = [];

        foreach ($products as $product) {
            foreach (($product->specifications ?? []) as $key => $value) {
                $key = trim((string) $key);
                $value = trim(strip_tags((string) $value));

                if ($key === '' || $value === '' || in_array($key, self::NOISE_SPEC_KEYS, true)) {
                    continue;
                }

                $values[$key][$value] = true;
            }
        }

        return collect($values)
            ->map(fn (array $items): array => array_keys($items))
            ->filter(fn (array $items): bool => count($items) > 1);
    }

    private function differentValuesForKey(Collection $products, string $key): Collection
    {
        return $products
            ->map(fn (Product $product) => trim((string) $this->optionValue($product, $key)))
            ->filter()
            ->unique()
            ->values();
    }

    private function isPrimaryOptionKey(string $key): bool
    {
        return collect(self::PRIMARY_OPTION_HINTS)->contains(fn (string $hint): bool => str_contains(mb_strtolower($key), mb_strtolower($hint)));
    }

    private function primaryProduct(Collection $products): Product
    {
        return $products
            ->sortByDesc(fn (Product $product): int => ($product->stock > 0 ? 100000 : 0) + $product->stock)
            ->first();
    }

    private function confidence(Collection $products, array $variantOptionKeys, string $level): int
    {
        $score = match ($level) {
            'exact_model' => 78,
            'model_family' => 68,
            default => 58,
        };

        $score += min(12, $products->count() * 2);
        $score += min(10, count($variantOptionKeys) * 3);

        $prices = $products->pluck('price')->map(fn ($price): float => (float) $price)->filter(fn (float $price): bool => $price > 0);

        if ($prices->isNotEmpty() && $prices->max() > 0 && ($prices->max() / max(1, $prices->min())) > 2.8) {
            $score -= 18;
        }

        return max(0, min(100, $score));
    }

    private function isSafeCandidate(array $candidate): bool
    {
        if (count($candidate['product_ids']) < 2 || count($candidate['variant_option_keys']) > 5) {
            return false;
        }

        $combinations = collect($candidate['products'])
            ->map(fn (Product $product): string => json_encode($this->variantOptions($product, $candidate['variant_option_keys']), JSON_UNESCAPED_UNICODE))
            ->filter();

        return $combinations->unique()->count() === $combinations->count()
            && mb_strlen($candidate['title']) >= 4
            && $candidate['confidence'] >= 65;
    }

    private function variantOptions(Product $product, array $keys): array
    {
        return collect($keys)
            ->mapWithKeys(fn (string $key): array => [$key => $this->optionValue($product, $key)])
            ->filter(fn ($value): bool => filled($value))
            ->all();
    }

    private function optionValue(Product $product, string $key): ?string
    {
        $value = data_get($product->specifications ?? [], $key);

        if (filled($value)) {
            return (string) $value;
        }

        if ($key === self::DERIVED_COLOR_KEY) {
            return $this->colorFromName($product);
        }

        if ($key === self::DERIVED_SHOE_SIZE_KEY) {
            return $this->shoeSizeFromNameOrSku($product);
        }

        return null;
    }

    private function differentDerivedShoeSizes(Collection $products): Collection
    {
        return $products
            ->map(fn (Product $product): ?string => $this->shoeSizeFromNameOrSku($product))
            ->filter()
            ->unique()
            ->values();
    }

    private function shoeSizeFromNameOrSku(Product $product): ?string
    {
        foreach ([(string) $product->name, (string) $product->sku] as $value) {
            if (preg_match('/(?:,\s*|\()(\d{2}(?:[.,]\d)?w?)\)?\s*$/iu', trim($value), $matches) === 1) {
                return str_replace(',', '.', mb_strtoupper($matches[1]));
            }
        }

        return null;
    }

    private function nameWithoutTrailingShoeSize(string $name): string
    {
        return trim(preg_replace('/(?:,\s*|\()\d{2}(?:[.,]\d)?w?\)?\s*$/iu', '', $name) ?? $name);
    }

    private function colorOptionKeys(Collection $products): array
    {
        $keys = collect(self::SPLIT_DUPLICATE_OPTION_KEYS)
            ->filter(fn (string $colorKey): bool => $this->differentValuesForKey($products, $colorKey)->count() > 1)
            ->values()
            ->all();

        if ($keys !== []) {
            return $keys;
        }

        return $products
            ->map(fn (Product $product): ?string => $this->colorFromName($product))
            ->filter()
            ->unique()
            ->count() > 1
                ? [self::DERIVED_COLOR_KEY]
                : [];
    }

    private function colorFromName(Product $product): ?string
    {
        $name = preg_replace('/\([^)]*\)/u', ' ', (string) $product->name) ?? (string) $product->name;
        $model = trim((string) $product->model);

        if ($model !== '') {
            $name = preg_replace('/^.*?\b'.preg_quote($model, '/').'\b/iu', '', $name) ?? $name;
        }

        $name = preg_replace('/\b\d+(?:[.,]\d+)?\s?(?:mm|мм|cm|см|m|м|g|г|kg|кг|л|ml|мл)?\b/iu', ' ', $name) ?? $name;
        $name = preg_replace('/\b(?:шт|уп|pcs|pc)\b/iu', ' ', $name) ?? $name;
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        $name = trim(preg_replace('/^[^\pL\pN]+|[^\pL\pN]+$/u', '', $name) ?? $name);

        return $name !== '' ? $name : null;
    }

    private function groupTitle(Collection $products, string $key): string
    {
        if ($products->pluck('name')->contains(fn ($name): bool => preg_match('/[\x{0400}-\x{04FF}]/u', (string) $name) === 1)) {
            return $this->feedGroupTitle($products);
        }

        return trim($products->first()->brand.' '.Str::title($key));
    }
}
