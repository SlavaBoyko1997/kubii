<?php

namespace App\Services;

use App\Jobs\GenerateDemoProductReviewsJob;
use App\Models\Category;
use App\Models\DemoReviewGenerationBatch;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DailyDemoReviewScheduler
{
    public const MAX_AI_REVIEWED_PRODUCTS_PERCENT = 40;

    /**
     * @return array{batch_id: ?string, products_count: int, leaf_categories_count: int, products: Collection<int, Product>}
     */
    public function run(
        string $dateFrom = '2026-06-27',
        int $minProducts = 3,
        int $maxProducts = 5,
        int $maxTotal = 200,
        bool $visible = true,
        bool $dryRun = false,
        ?int $adminUserId = null,
        ?int $categoryId = null,
    ): array {
        $leafCategories = $this->leafCategories($categoryId);
        $selected = $this->selectProducts($leafCategories, $minProducts, $maxProducts, $maxTotal);

        if ($dryRun || $selected->isEmpty()) {
            return [
                'batch_id' => null,
                'products_count' => $selected->count(),
                'leaf_categories_count' => $leafCategories->count(),
                'products' => $selected,
            ];
        }

        $batchId = (string) Str::uuid();
        $settings = [
            'count' => 1,
            'date_from' => $dateFrom,
            'date_to' => now()->toDateString(),
            'min_rating' => 3,
            'max_rating' => 5,
            'style' => 'mixed',
            'visible' => $visible,
            'auto_save' => true,
            'trigger' => 'daily',
            'leaf_categories_count' => $leafCategories->count(),
            'max_total' => $maxTotal,
            'category_id' => $categoryId,
            'max_ai_reviews_per_product' => DemoReviewPersistence::MAX_AI_REVIEWS_PER_PRODUCT,
            'max_ai_reviewed_products_percent_per_leaf_category' => self::MAX_AI_REVIEWED_PRODUCTS_PERCENT,
        ];

        DemoReviewGenerationBatch::query()->create([
            'id' => $batchId,
            'admin_user_id' => $adminUserId,
            'status' => 'queued',
            'products_count' => $selected->count(),
            'requested_reviews_count' => $selected->count(),
            'jobs_total' => $selected->count(),
            'model' => (string) config('services.openai.model'),
            'settings' => $settings,
            'started_at' => now(),
        ]);

        $selected->each(fn (Product $product) => GenerateDemoProductReviewsJob::dispatch((int) $product->id, $batchId, $settings)->onQueue('reviews'));

        return [
            'batch_id' => $batchId,
            'products_count' => $selected->count(),
            'leaf_categories_count' => $leafCategories->count(),
            'products' => $selected,
        ];
    }

    /**
     * @return Collection<int, Category>
     */
    private function leafCategories(?int $categoryId = null): Collection
    {
        $query = Category::query()
            ->where('is_active', true)
            ->whereDoesntHave('activeChildren');

        if ($categoryId) {
            $category = Category::query()
                ->where('is_active', true)
                ->with('childrenRecursive')
                ->find($categoryId);

            if (! $category) {
                return collect();
            }

            $categoryIds = [$category->id, ...$category->descendantIds()];
            $query->whereIn('id', $categoryIds);
        }

        return $query
            ->orderBy('id')
            ->get(['id', 'name']);
    }

    /**
     * @param  Collection<int, Category>  $leafCategories
     * @return Collection<int, Product>
     */
    private function selectProducts(Collection $leafCategories, int $minProducts, int $maxProducts, int $maxTotal): Collection
    {
        $selected = collect();

        foreach ($leafCategories as $category) {
            if ($selected->count() >= $maxTotal) {
                break;
            }

            $limit = random_int(max(1, $minProducts), max($minProducts, $maxProducts));
            $remaining = $maxTotal - $selected->count();
            $eligibleProductsQuery = $this->eligibleProductsQuery($category);
            $eligibleProductsCount = (clone $eligibleProductsQuery)->count();

            if ($eligibleProductsCount <= 0) {
                continue;
            }

            $aiReviewedProductsCount = (clone $eligibleProductsQuery)
                ->whereHas('reviews', fn (Builder $query): Builder => $this->aiDemoReviewsQuery($query))
                ->count();
            $maxAiReviewedProductsCount = (int) floor($eligibleProductsCount * (self::MAX_AI_REVIEWED_PRODUCTS_PERCENT / 100));
            $newAiReviewedProductSlots = $maxAiReviewedProductsCount - $aiReviewedProductsCount;

            if ($newAiReviewedProductSlots <= 0) {
                continue;
            }

            $products = $eligibleProductsQuery
                ->withCount(['reviews as ai_demo_reviews_count' => fn (Builder $query): Builder => $this->aiDemoReviewsQuery($query)])
                ->having('ai_demo_reviews_count', '<', DemoReviewPersistence::MAX_AI_REVIEWS_PER_PRODUCT)
                ->inRandomOrder()
                ->limit(max(min($limit, $remaining) * 4, 20))
                ->get(['id', 'sku', 'external_id', 'name', 'category_id'])
                ->filter(function (Product $product) use (&$newAiReviewedProductSlots): bool {
                    $hasAiReviews = (int) ($product->ai_demo_reviews_count ?? 0) > 0;

                    if ($hasAiReviews) {
                        return true;
                    }

                    if ($newAiReviewedProductSlots <= 0) {
                        return false;
                    }

                    $newAiReviewedProductSlots--;

                    return true;
                })
                ->take(min($limit, $remaining));

            $products->each(fn (Product $product) => $selected->push($product));
        }

        return $selected;
    }

    private function eligibleProductsQuery(Category $category): Builder
    {
        return Product::query()
            ->where('category_id', $category->id)
            ->where('is_active', true)
            ->where('is_visible_in_catalog', true)
            ->where('stock', '>', 0)
            ->whereRaw('COALESCE(sale_price, ROUND(price * (100 - COALESCE(discount_percent, 0)) / 100, 2)) > 0');
    }

    private function aiDemoReviewsQuery(Builder $query): Builder
    {
        return $query
            ->whereNull('parent_id')
            ->where('is_demo', true)
            ->where('is_ai_generated', true);
    }
}
