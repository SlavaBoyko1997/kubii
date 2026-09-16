<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class HomeCatalogSelections
{
    public function resolve(): array
    {
        $products = fn (): Builder => Product::query()
            ->where('is_active', true)
            ->visibleInCatalog();

        $inStockProducts = fn (): Builder => $products()->where('stock', '>', 0);

        $popularCandidates = $inStockProducts()
            ->where('monthly_views', '>', 0)
            ->orderByDesc('monthly_views')
            ->orderByDesc('is_featured')
            ->limit(1000)
            ->get(['products.id', 'products.brand', 'products.is_featured', 'products.monthly_views']);

        $popularProductIds = $popularCandidates->take(10)->pluck('id');

        if ($popularProductIds->count() < 10) {
            $popularProductIds = $popularProductIds
                ->concat(
                    $inStockProducts()
                        ->whereKeyNot($popularProductIds->all())
                        ->orderByDesc('is_featured')
                        ->latest('id')
                        ->take(10 - $popularProductIds->count())
                        ->pluck('products.id')
                )
                ->values();
        }

        $reviewScores = DB::table('reviews')
            ->selectRaw('product_id, AVG(rating) as average_rating')
            ->whereNull('parent_id')
            ->where('is_visible', true)
            ->groupBy('product_id')
            ->pluck('average_rating', 'product_id');

        $topRatedProductIds = $reviewScores->isEmpty()
            ? []
            : $products()
                ->whereKey($reviewScores->keys())
                ->get(['products.id'])
                ->sortByDesc(fn (Product $product): float => (float) $reviewScores->get($product->id, 0))
                ->take(10)
                ->pluck('id')
                ->values()
                ->all();

        $bestBrandProductIds = $popularCandidates
            ->filter(fn (Product $product): bool => filled($product->brand))
            ->unique(fn (Product $product): string => mb_strtolower($product->brand))
            ->take(10)
            ->pluck('id')
            ->values()
            ->all();

        return [
            'saleProductIds' => $inStockProducts()
                ->where('discount_percent', '>', 0)
                ->orderByDesc('discount_percent')
                ->take(10)
                ->pluck('products.id')
                ->all(),
            'topRatedProductIds' => $topRatedProductIds,
            'bestBrandProductIds' => $bestBrandProductIds,
            'popularProductIds' => $popularProductIds->all(),
        ];
    }
}
