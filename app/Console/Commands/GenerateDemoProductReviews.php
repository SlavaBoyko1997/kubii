<?php

namespace App\Console\Commands;

use App\Jobs\GenerateDemoProductReviewsJob;
use App\Models\DemoReviewGenerationBatch;
use App\Models\Product;
use App\Services\OpenAiDemoReviewGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class GenerateDemoProductReviews extends Command
{
    protected $signature = 'demo-reviews:generate
        {--products= : Comma-separated product SKU/external_id/variant_id/id values}
        {--count=5 : Reviews count per product, 1-20}
        {--date-from=2026-06-27 : Minimum random review date}
        {--style=mixed : mixed, short, normal, detailed, conversational}
        {--hidden : Save generated reviews as disabled}';

    protected $description = 'Generate AI demo product reviews through the queue.';

    public function handle(): int
    {
        $codes = collect(explode(',', (string) $this->option('products')))
            ->map(fn (string $code): string => trim($code))
            ->filter()
            ->unique(fn (string $code): string => mb_strtolower($code))
            ->values();

        $count = (int) $this->option('count');
        $dateFrom = (string) $this->option('date-from');
        $style = (string) $this->option('style');

        if ($codes->isEmpty()) {
            $this->error('Pass --products=CODE-001,CODE-002');

            return self::FAILURE;
        }

        if ($count < 1 || $count > 20 || $codes->count() * $count > 200) {
            $this->error('Count must be 1-20 and total reviews must be <= 200.');

            return self::FAILURE;
        }

        if (Carbon::parse($dateFrom)->lt(Carbon::parse('2026-06-27')) || Carbon::parse($dateFrom)->isFuture()) {
            $this->error('date-from must be between 2026-06-27 and today.');

            return self::FAILURE;
        }

        if (! array_key_exists($style, OpenAiDemoReviewGenerator::STYLES)) {
            $this->error('Invalid style.');

            return self::FAILURE;
        }

        $products = Product::query()
            ->where(function ($query) use ($codes): void {
                $query->whereIn('sku', $codes)
                    ->orWhereIn('external_id', $codes)
                    ->orWhereIn('variant_id', $codes);

                $ids = $codes->filter(fn (string $code): bool => ctype_digit($code))->map(fn (string $code): int => (int) $code)->all();
                if ($ids !== []) {
                    $query->orWhereIn('id', $ids);
                }
            })
            ->get();

        if ($products->isEmpty()) {
            $this->error('No products found.');

            return self::FAILURE;
        }

        $batchId = (string) Str::uuid();
        $settings = [
            'count' => $count,
            'date_from' => $dateFrom,
            'date_to' => now()->toDateString(),
            'min_rating' => 4,
            'max_rating' => 5,
            'style' => $style,
            'visible' => ! (bool) $this->option('hidden'),
            'auto_save' => true,
        ];

        DemoReviewGenerationBatch::query()->create([
            'id' => $batchId,
            'status' => 'queued',
            'products_count' => $products->count(),
            'requested_reviews_count' => $products->count() * $count,
            'jobs_total' => $products->count(),
            'model' => (string) config('services.openai.model'),
            'settings' => $settings,
            'started_at' => now(),
        ]);

        foreach ($products as $product) {
            GenerateDemoProductReviewsJob::dispatch((int) $product->id, $batchId, $settings)->onQueue('reviews');
        }

        $this->info('Batch queued: '.$batchId);
        $this->info('Products: '.$products->count().', requested reviews: '.($products->count() * $count));

        return self::SUCCESS;
    }
}
