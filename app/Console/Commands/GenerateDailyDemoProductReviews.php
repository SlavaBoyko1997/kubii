<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\DailyDemoReviewScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateDailyDemoProductReviews extends Command
{
    protected $signature = 'demo-reviews:daily
        {--date-from=2026-06-27 : Minimum random review date}
        {--min-products=3 : Minimum products per leaf category}
        {--max-products=5 : Maximum products per leaf category}
        {--max-total=200 : Safety cap for generated reviews per run}
        {--hidden : Save generated reviews as disabled}
        {--dry-run : Show selected products without dispatching jobs}';

    protected $description = 'Daily AI demo reviews for 3-5 in-stock products in each leaf category.';

    public function handle(DailyDemoReviewScheduler $scheduler): int
    {
        $dateFrom = (string) $this->option('date-from');
        $minProducts = max(1, (int) $this->option('min-products'));
        $maxProducts = max($minProducts, (int) $this->option('max-products'));
        $maxTotal = max(1, (int) $this->option('max-total'));

        if (Carbon::parse($dateFrom)->lt(Carbon::parse('2026-06-27')) || Carbon::parse($dateFrom)->isFuture()) {
            $this->error('date-from must be between 2026-06-27 and today.');

            return self::FAILURE;
        }

        $result = $scheduler->run(
            dateFrom: $dateFrom,
            minProducts: $minProducts,
            maxProducts: $maxProducts,
            maxTotal: $maxTotal,
            visible: ! (bool) $this->option('hidden'),
            dryRun: (bool) $this->option('dry-run'),
        );
        $selected = $result['products'];

        if ($selected->isEmpty()) {
            $this->warn('No in-stock products found in leaf categories.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['ID', 'SKU', 'Category', 'AI reviews', 'Name'],
                $selected->map(fn (Product $product): array => [
                    $product->id,
                    $product->sku ?: $product->external_id,
                    $product->category_id,
                    $product->ai_demo_reviews_count ?? '?',
                    $product->name,
                ])->all()
            );

            $this->info('Selected products: '.$selected->count());
            $this->info('Leaf categories: '.$result['leaf_categories_count']);

            return self::SUCCESS;
        }

        $this->info('Daily demo review batch queued: '.$result['batch_id']);
        $this->info('Products/reviews: '.$selected->count());

        return self::SUCCESS;
    }
}
