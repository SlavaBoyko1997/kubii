<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CategorySeoContextBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(Category $category, string $locale = 'uk'): array
    {
        $category->loadMissing(['parentRecursive', 'childrenRecursive']);
        $categoryIds = [$category->id, ...$category->descendantIds()];
        $productsQuery = $this->activeProductsQuery($categoryIds);
        $sampleProducts = (clone $productsQuery)
            ->inRandomOrder()
            ->limit(30)
            ->get(['id', 'name', 'name_ru', 'brand', 'model', 'price', 'sale_price', 'discount_percent', 'season', 'season_ru', 'usage_type', 'usage_type_ru', 'material', 'material_ru', 'weight_grams', 'specifications', 'specifications_ru']);

        $priceStats = (clone $productsQuery)
            ->selectRaw('MIN(COALESCE(sale_price, ROUND(price * (100 - COALESCE(discount_percent, 0)) / 100, 2))) as min_price')
            ->selectRaw('MAX(COALESCE(sale_price, ROUND(price * (100 - COALESCE(discount_percent, 0)) / 100, 2))) as max_price')
            ->first();

        $brands = (clone $productsQuery)
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->select('brand')
            ->selectRaw('COUNT(*) as products_count')
            ->groupBy('brand')
            ->orderByDesc('products_count')
            ->limit(20)
            ->pluck('brand')
            ->values()
            ->all();

        $children = $category->childrenRecursive
            ->map(fn (Category $child): array => [
                'name' => (string) $child->translated('name', $locale),
                'url' => $child->catalogUrl(absolute: false, locale: $locale),
            ])
            ->values()
            ->all();

        return [
            'category' => [
                'id' => $category->id,
                'name' => (string) $category->translated('name', $locale),
                'slug' => $category->getRawOriginal('slug'),
                'url' => $category->catalogUrl(absolute: false, locale: $locale),
                'h1' => (string) $category->translated('h1', $locale),
                'seo_title' => (string) $category->translated('seo_title', $locale),
                'meta_description' => (string) $category->translated('meta_description', $locale),
                'description' => Str::limit(strip_tags((string) $category->translated('description', $locale)), 1600, ''),
            ],
            'hierarchy' => [
                'breadcrumb' => $category->breadcrumbTrail()->map(fn (Category $item): string => (string) $item->translated('name', $locale))->values()->all(),
                'parent' => $category->parentRecursive ? (string) $category->parentRecursive->translated('name', $locale) : null,
                'children' => $children,
            ],
            'products' => [
                'active_count' => (clone $productsQuery)->count(),
                'sample_names' => $sampleProducts->map(fn (Product $product): string => (string) $product->translated('name', $locale))->filter()->values()->all(),
                'brands' => $brands,
                'price_range' => [
                    'min' => $priceStats?->min_price ? (float) $priceStats->min_price : null,
                    'max' => $priceStats?->max_price ? (float) $priceStats->max_price : null,
                    'currency' => 'UAH',
                ],
                'attributes' => $this->attributeSummary($sampleProducts, $locale),
                'visible_filters' => $this->visibleFilters($category, $locale),
            ],
            'internal_link_whitelist' => $this->internalLinks($category, $locale),
            'search_intent' => ['commercial', 'transactional', 'informational'],
            'language' => $locale === 'ru' ? 'ru' : 'uk',
        ];
    }

    /**
     * @param  list<int>  $categoryIds
     */
    private function activeProductsQuery(array $categoryIds): Builder
    {
        return Product::query()
            ->whereIn('category_id', $categoryIds)
            ->where('is_active', true)
            ->where('is_visible_in_catalog', true)
            ->where('stock', '>', 0)
            ->whereRaw('COALESCE(sale_price, ROUND(price * (100 - COALESCE(discount_percent, 0)) / 100, 2)) > 0');
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array<string, list<string>>
     */
    private function attributeSummary(Collection $products, string $locale): array
    {
        $attributes = [];

        foreach ($products as $product) {
            foreach (['season', 'usage_type', 'material'] as $field) {
                $value = $product->translated($field, $locale);

                if (filled($value)) {
                    $attributes[$field][] = trim((string) $value);
                }
            }

            if ($product->weight_grams) {
                $attributes['weight'][] = ((int) $product->weight_grams).' г';
            }

            foreach ((array) $product->translated('specifications', $locale) as $key => $value) {
                if (filled($key) && filled($value)) {
                    $attributes[(string) $key][] = is_array($value) ? implode(', ', array_slice($value, 0, 4)) : (string) $value;
                }
            }
        }

        return collect($attributes)
            ->map(fn (array $values): array => collect($values)->map(fn (string $value): string => trim(strip_tags($value)))->filter()->countBy()->sortDesc()->keys()->take(8)->values()->all())
            ->filter()
            ->sortByDesc(fn (array $values): int => count($values))
            ->take(18)
            ->all();
    }

    /**
     * @return list<string>
     */
    private function visibleFilters(Category $category, string $locale): array
    {
        $base = collect($category->resolvedVisibleFilters())
            ->map(fn (string $filter): string => match ($filter) {
                'sale' => $locale === 'ru' ? 'Акционные товары' : 'Акційні товари',
                'brand' => $locale === 'ru' ? 'Бренд' : 'Бренд',
                'model' => $locale === 'ru' ? 'Модель' : 'Модель',
                'price' => $locale === 'ru' ? 'Цена' : 'Ціна',
                'season' => $locale === 'ru' ? 'Сезон' : 'Сезон',
                'usage_type' => $locale === 'ru' ? 'Тип использования' : 'Тип використання',
                'material' => $locale === 'ru' ? 'Материал' : 'Матеріал',
                'weight' => $locale === 'ru' ? 'Вес' : 'Вага',
                'stock' => $locale === 'ru' ? 'Наличие' : 'Наявність',
                default => $filter,
            });

        return $base
            ->merge((array) $category->resolvedVisibleSpecFilters())
            ->filter()
            ->unique()
            ->take(25)
            ->values()
            ->all();
    }

    /**
     * @return list<array{name: string, url: string}>
     */
    private function internalLinks(Category $category, string $locale): array
    {
        $category->loadMissing('parentRecursive.children');
        $related = collect();

        if ($category->parentRecursive) {
            $category->parentRecursive->loadMissing('children');
            $related = $related->merge($category->parentRecursive->children);
        }

        $related = $related->merge($category->childrenRecursive);

        return $related
            ->filter(fn (Category $item): bool => $item->id !== $category->id && $item->is_active)
            ->unique('id')
            ->take(8)
            ->map(fn (Category $item): array => [
                'name' => (string) $item->translated('name', $locale),
                'url' => $item->catalogUrl(absolute: false, locale: $locale),
            ])
            ->values()
            ->all();
    }
}
