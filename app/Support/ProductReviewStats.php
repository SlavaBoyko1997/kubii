<?php

namespace App\Support;

use App\Models\Product;
use App\Models\Review;

class ProductReviewStats
{
    public static function syncForProduct(int $productId): void
    {
        $stats = Review::query()
            ->where('product_id', $productId)
            ->whereNull('parent_id')
            ->where('is_visible', true)
            ->selectRaw('COUNT(*) as aggregate_count, AVG(rating) as aggregate_avg')
            ->first();

        $count = (int) ($stats->aggregate_count ?? 0);

        Product::query()
            ->whereKey($productId)
            ->update([
                'reviews_count' => $count,
                'reviews_avg_rating' => $count > 0
                    ? round((float) $stats->aggregate_avg, 2)
                    : null,
            ]);
    }
}
