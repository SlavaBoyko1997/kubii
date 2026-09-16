<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\Review;
use App\Services\DemoReviewPersistence;
use App\Services\OpenAiDemoReviewGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateDemoProductReviewsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public readonly int $productId,
        public readonly string $batchId,
        public readonly array $settings,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(OpenAiDemoReviewGenerator $generator, DemoReviewPersistence $persistence): void
    {
        $product = Product::query()->find($this->productId);

        if (! $product) {
            $persistence->markBatchJobFinished($this->batchId, 0, (int) ($this->settings['count'] ?? 0), error: 'Товар ID '.$this->productId.' не знайдено.');

            return;
        }

        $requestedCount = max(0, (int) $this->settings['count']);
        $existingAiReviewsCount = Review::query()
            ->where('product_id', $product->id)
            ->whereNull('parent_id')
            ->where('is_demo', true)
            ->where('is_ai_generated', true)
            ->count();
        $count = min($requestedCount, max(0, DemoReviewPersistence::MAX_AI_REVIEWS_PER_PRODUCT - $existingAiReviewsCount));

        if ($count <= 0) {
            $persistence->markBatchJobFinished($this->batchId, 0, $requestedCount, error: 'У товару ID '.$this->productId.' вже є 7 AI-відгуків.');

            return;
        }

        $dateFrom = (string) $this->settings['date_from'];
        $dateTo = (string) $this->settings['date_to'];
        $style = (string) $this->settings['style'];
        $visible = (bool) ($this->settings['visible'] ?? true);
        $minRating = (int) ($this->settings['min_rating'] ?? 4);
        $maxRating = (int) ($this->settings['max_rating'] ?? 5);
        $inputHash = $generator->inputHash($product, $count, $style, $dateFrom, $dateTo, $this->batchId);

        $saved = 0;
        $failed = 0;
        $slot = 0;
        $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0];
        $responseIds = [];

        for ($attempt = 1; $attempt <= 3 && $saved < $count; $attempt++) {
            $remaining = $count - $saved;
            $result = $generator->generate($product, $remaining, $style, $minRating, $maxRating);
            $responseIds[] = $result['response_id'];
            $usage['input_tokens'] += $result['usage']['input_tokens'];
            $usage['output_tokens'] += $result['usage']['output_tokens'];
            $usage['total_tokens'] += $result['usage']['total_tokens'];

            foreach ($result['reviews'] as $index => $review) {
                $reviewHash = hash('sha256', $inputHash.':'.$slot);
                $slot++;

                $created = $persistence->save(
                    product: $product,
                    generated: $review,
                    batchId: $this->batchId,
                    inputHash: $reviewHash,
                    dateFrom: $dateFrom,
                    dateTo: $dateTo,
                    metadata: [
                        'batch_input_hash' => $inputHash,
                        'response_id' => $result['response_id'],
                        'generation_attempt' => $attempt,
                    ],
                    visible: $visible,
                );

                if ($created) {
                    $saved++;
                } else {
                    $failed++;
                }
            }
        }

        $persistence->markBatchJobFinished(
            batchId: $this->batchId,
            successful: $saved,
            failed: max(0, $requestedCount - $saved),
            usage: $usage,
            responseId: collect($responseIds)->filter()->implode(','),
        );
    }

    public function failed(Throwable $exception): void
    {
        app(DemoReviewPersistence::class)->markBatchJobFinished(
            batchId: $this->batchId,
            successful: 0,
            failed: (int) ($this->settings['count'] ?? 0),
            error: $exception->getMessage(),
        );
    }
}
