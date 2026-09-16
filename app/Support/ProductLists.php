<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductVariantGroup;
use Illuminate\Support\Collection;

class ProductLists
{
    private const FAVORITES_KEY = 'favorites';

    private const COMPARISON_KEY = 'comparison';

    public function toggleFavorite(Product $product): bool
    {
        return $this->toggle(self::FAVORITES_KEY, $product);
    }

    public function toggleComparison(Product $product): bool
    {
        return $this->toggle(self::COMPARISON_KEY, $product);
    }

    public function favoriteIds(): array
    {
        return $this->ids(self::FAVORITES_KEY);
    }

    public function comparisonIds(): array
    {
        return $this->ids(self::COMPARISON_KEY);
    }

    public function favoriteProducts(): Collection
    {
        return $this->products($this->favoriteIds());
    }

    public function comparisonProducts(): Collection
    {
        return $this->products($this->comparisonIds());
    }

    private function toggle(string $key, Product $product): bool
    {
        $ids = $this->ids($key);

        if (in_array($product->id, $ids, true)) {
            session()->put($key, array_values(array_diff($ids, [$product->id])));

            return false;
        }

        $ids[] = $product->id;
        session()->put($key, array_values(array_unique($ids)));

        return true;
    }

    private function ids(string $key): array
    {
        return array_map('intval', session()->get($key, []));
    }

    private function products(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $positions = array_flip($ids);

        return Product::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->with([
                'category',
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
            ])
            ->get()
            ->sortBy(fn (Product $product): int => $positions[$product->id] ?? PHP_INT_MAX)
            ->values();
    }
}
