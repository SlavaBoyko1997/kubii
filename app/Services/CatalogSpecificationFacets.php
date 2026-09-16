<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Support\CatalogCache;
use App\Support\Locale;
use Illuminate\Database\Eloquent\Builder;

class CatalogSpecificationFacets
{
    private const EXCLUDED_KEYS = [
        'Артикул',
        'Код',
        'Код товару',
        'Код товара',
        'Виробник',
        'Опис',
        'Опис товару',
        'Фото',
        'Зображення',
        'Посилання',
        'URL',
        'Url',
        'Ссылка',
        'Відео',
        'Видео',
    ];

    private const EXCLUDED_KEY_PATTERNS = [
        '/^відео(?:\s+review)?(?:\s+shorts?)?$/iu',
        '/^video(?:\s+review)?(?:\s+shorts?)?$/iu',
    ];

    public function counts(iterable $products, array $specFilters = [], array $allowedKeys = []): array
    {
        $facets = [];

        foreach ($products as $product) {
            $specifications = $this->filterableAttributes($product);

            foreach ($specifications as $key => $value) {
                $key = (string) $key;

                if (($allowedKeys !== [] && ! in_array($key, $allowedKeys, true)) || ! $this->isFilterableKey($key)) {
                    continue;
                }

                if (! $this->matchesFilters($specifications, $specFilters, $key)) {
                    continue;
                }

                foreach ($this->values($value) as $filterValue) {
                    $facets[$key][$filterValue] = ($facets[$key][$filterValue] ?? 0) + 1;
                }
            }
        }

        foreach ($specFilters as $key => $selectedValues) {
            if ($allowedKeys !== [] && ! in_array($key, $allowedKeys, true)) {
                continue;
            }

            foreach ($selectedValues as $value) {
                $facets[$key][$value] ??= 0;
            }
        }

        foreach ($facets as $key => $values) {
            arsort($values);
            $facets[$key] = collect($values)
                ->take(100)
                ->sortKeys()
                ->all();
        }

        ksort($facets);

        return $facets;
    }

    public function matchingProductIds(Builder $query, array $specFilters): array
    {
        if ($specFilters === []) {
            return [];
        }

        $this->constrainProductsWithSpecFilters($query);

        return $query
            ->select($this->specFilterProductColumns())
            ->lazyById(1000)
            ->filter(fn (Product $product): bool => $this->matchesFilters($this->filterableAttributes($product), $specFilters))
            ->pluck('id')
            ->all();
    }

    public function applyFilters(Builder $query, array $specFilters): Builder
    {
        if ($specFilters === []) {
            return $query;
        }

        $matchingIds = $this->matchingProductIds(clone $query, $specFilters);

        return $query->whereKey($matchingIds !== [] ? $matchingIds : [-1]);
    }

    public function warmBaseFacets(?Category $category, CatalogCache $cache): array
    {
        $scope = $category ? 'category-'.$category->id : 'all';

        return $cache->remember('spec-facets:v6:'.$scope, function () use ($category): array {
            return $this->specFacetCounts($category);
        });

        $cache->remember('base-facets:v2:'.$scope, fn (): array => $this->baseFacetCounts($category));

        return [];
    }

    /**
     * @return list<string>
     */
    public function specFilterProductColumns(): array
    {
        return [
            'id',
            'specifications',
            'specifications_ru',
            'variant_options',
            'variant_options_ru',
            'variant_secondary_specs',
            'variant_secondary_specs_ru',
        ];
    }

    public function constrainProductsWithSpecFilters(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNotNull('specifications')
                ->orWhereNotNull('specifications_ru')
                ->orWhereNotNull('variant_options')
                ->orWhereNotNull('variant_options_ru')
                ->orWhereNotNull('variant_secondary_specs')
                ->orWhereNotNull('variant_secondary_specs_ru');
        });
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function specFacetCounts(?Category $category): array
    {
        $allowedKeys = $this->configuredKeys($category, includeDiscovered: true);
        $categoryIds = $this->categoryScopeIds($category);
        $query = Product::query()
            ->where('is_active', true)
            ->visibleInCatalog()
            ->when($category, fn (Builder $query) => $query->whereIn('category_id', $categoryIds));

        $this->constrainProductsWithSpecFilters($query);

        return $this->counts(
            $query->select($this->specFilterProductColumns())->lazyById(1000),
            allowedKeys: $allowedKeys,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function baseFacetCounts(?Category $category): array
    {
        if ($category === null) {
            return [];
        }

        $visibleBaseFilters = collect($category->resolvedVisibleFilters())
            ->intersect(['sale', 'brand', 'model', 'price', 'season', 'usage_type', 'material', 'weight', 'stock'])
            ->values()
            ->all();
        $categoryIds = $this->categoryScopeIds($category);
        $seasonColumn = Locale::isRussian() ? 'season_ru' : 'season';
        $usageTypeColumn = Locale::isRussian() ? 'usage_type_ru' : 'usage_type';
        $materialColumn = Locale::isRussian() ? 'material_ru' : 'material';
        $baseQuery = Product::query()
            ->where('is_active', true)
            ->visibleInCatalog()
            ->whereIn('category_id', $categoryIds);
        $priceRange = in_array('price', $visibleBaseFilters, true)
            ? (clone $baseQuery)->selectRaw('MIN(price) as minimum, MAX(price) as maximum')->first()
            : null;

        return [
            'brands' => in_array('brand', $visibleBaseFilters, true) ? $this->columnFacetCounts(clone $baseQuery, 'brand') : [],
            'models' => in_array('model', $visibleBaseFilters, true) ? $this->columnFacetCounts(clone $baseQuery, 'model') : [],
            'seasons' => in_array('season', $visibleBaseFilters, true) ? $this->columnFacetCounts(clone $baseQuery, $seasonColumn) : [],
            'usageTypes' => in_array('usage_type', $visibleBaseFilters, true) ? $this->columnFacetCounts(clone $baseQuery, $usageTypeColumn) : [],
            'materials' => in_array('material', $visibleBaseFilters, true) ? $this->columnFacetCounts(clone $baseQuery, $materialColumn) : [],
            'minimumPrice' => $priceRange?->minimum,
            'maximumPrice' => $priceRange?->maximum,
            'hasSaleProducts' => (clone $baseQuery)->onSale()->exists(),
            'hasWeightProducts' => in_array('weight', $visibleBaseFilters, true)
                && (clone $baseQuery)->whereNotNull('weight_grams')->where('weight_grams', '>', 0)->exists(),
            'hasOutOfStockProducts' => in_array('stock', $visibleBaseFilters, true)
                && (clone $baseQuery)->where('stock', '<=', 0)->exists(),
        ];
    }

    /**
     * @return list<int>
     */
    private function categoryScopeIds(?Category $category): array
    {
        if ($category === null) {
            return [];
        }

        $category->loadMissing('childrenRecursiveAll');

        return [$category->id, ...$category->allDescendantIds()];
    }

    /**
     * @return array<string, int>
     */
    private function columnFacetCounts(Builder $query, string $column): array
    {
        return $query
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->selectRaw($column.' as facet_value, COUNT(*) as aggregate')
            ->groupBy($column)
            ->orderByDesc('aggregate')
            ->pluck('aggregate', 'facet_value')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    public function withSelectedValues(array $facets, array $selected): array
    {
        foreach ($selected as $value) {
            $facets[$value] ??= 0;
        }

        ksort($facets);

        return $facets;
    }

    public function matchesFilters(array $specifications, array $specFilters, ?string $exceptKey = null): bool
    {
        foreach ($specFilters as $key => $selectedValues) {
            if ($key === $exceptKey) {
                continue;
            }

            $values = $this->values($specifications[$key] ?? null);

            if ($values === [] || collect($selectedValues)->intersect($values)->isEmpty()) {
                return false;
            }
        }

        return true;
    }

    public function configuredKeys(?Category $category, bool $includeDiscovered = false): array
    {
        $configured = $category?->resolvedVisibleSpecFilters();

        $keys = $configured === null
            ? []
            : collect($configured)
                ->map(fn ($key): string => trim((string) $key))
                ->filter(fn (string $key): bool => $this->isFilterableKey($key))
                ->unique()
                ->values()
                ->all();

        if (! $includeDiscovered || $category === null || $configured !== null) {
            return $keys;
        }

        $discovered = array_keys($this->availableFilterCounts(
            $category,
            includeInactive: false,
            requireCatalogVisibility: true,
        ));

        return $discovered;
    }

    public function availableFilterCounts(
        Category $category,
        bool $includeInactive = false,
        bool $requireCatalogVisibility = false,
    ): array {
        $category->loadMissing('childrenRecursiveAll');
        $categoryIds = [$category->id, ...$category->allDescendantIds()];
        $counts = [];

        $products = Product::query()
            ->when(! $includeInactive, fn (Builder $query) => $query->where('is_active', true))
            ->when($requireCatalogVisibility, fn (Builder $query) => $query->visibleInCatalog())
            ->whereIn('category_id', $categoryIds);

        $this->constrainProductsWithSpecFilters($products);

        $products->select($this->specFilterProductColumns())
            ->lazyById(1000)
            ->each(function (Product $product) use (&$counts): void {
                foreach (array_keys($this->filterableAttributes($product)) as $key) {
                    $key = trim((string) $key);

                    if ($this->isFilterableKey($key)) {
                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                    }
                }
            });

        arsort($counts);

        return $counts;
    }

    public function availableFilterOptions(Category $category, bool $includeInactive = false): array
    {
        return collect($this->availableFilterCounts($category, $includeInactive))
            ->mapWithKeys(fn (int $count, string $key): array => [
                $key => $key.' ('.__(':count товарів', ['count' => $count]).')',
            ])
            ->all();
    }

    public function isFilterableKey(string $key): bool
    {
        $key = trim($key);

        if (
            $key === ''
            || mb_strlen($key) > 80
            || collect(self::EXCLUDED_KEYS)->contains(fn (string $excluded): bool => mb_strtolower($excluded) === mb_strtolower($key))
        ) {
            return false;
        }

        return ! collect(self::EXCLUDED_KEY_PATTERNS)
            ->contains(fn (string $pattern): bool => preg_match($pattern, $key) === 1);
    }

    public function values(mixed $value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->flatMap(function ($item): array {
                $text = trim(strip_tags((string) $item));

                if ($text === '') {
                    return [];
                }

                if (str_contains($text, ',')) {
                    return collect(preg_split('/\s*,\s*/u', $text) ?: [])
                        ->map(fn (string $part): string => trim($part))
                        ->filter(fn (string $part): bool => $part !== '')
                        ->all();
                }

                return [$text];
            })
            ->filter(fn (string $item): bool => $item !== '' && mb_strlen($item) <= 120)
            ->unique()
            ->values()
            ->all();
    }

    private function filterableAttributes(Product $product): array
    {
        return collect([
            ...$product->displaySpecifications(),
            ...$product->localizedVariantOptions(),
            ...$product->localizedVariantSecondarySpecs(),
        ])->all();
    }
}
