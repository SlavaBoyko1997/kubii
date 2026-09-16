<?php

namespace App\Services;

use App\Models\DemoReviewGenerationBatch;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Support\DemoReviewDuplicateGuard;
use App\Support\DemoReviewNameGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoReviewPersistence
{
    public const MAX_AI_REVIEWS_PER_PRODUCT = 7;

    public function __construct(
        private readonly DemoReviewNameGenerator $names,
        private readonly DemoReviewDuplicateGuard $duplicates,
    ) {}

    /**
     * @param  array<string, mixed>  $generated
     * @param  array<string, mixed>  $metadata
     */
    public function save(
        Product $product,
        array $generated,
        string $batchId,
        string $inputHash,
        string $dateFrom,
        string $dateTo,
        array $metadata = [],
        bool $visible = true,
        ?string $authorName = null,
    ): ?Review {
        return DB::transaction(function () use ($product, $generated, $batchId, $inputHash, $dateFrom, $dateTo, $metadata, $visible, $authorName): ?Review {
            Product::query()
                ->whereKey($product->id)
                ->lockForUpdate()
                ->value('id');

            $aiReviewsCount = Review::query()
                ->where('product_id', $product->id)
                ->whereNull('parent_id')
                ->where('is_demo', true)
                ->where('is_ai_generated', true)
                ->lockForUpdate()
                ->limit(self::MAX_AI_REVIEWS_PER_PRODUCT)
                ->pluck('id')
                ->count();

            if ($aiReviewsCount >= self::MAX_AI_REVIEWS_PER_PRODUCT) {
                return null;
            }

            if (Review::query()->where('product_id', $product->id)->where('input_hash', $inputHash)->exists()) {
                return null;
            }

            $text = trim((string) ($generated['text'] ?? ''));

            if ($this->duplicates->isDuplicateForProduct((int) $product->id, $text)) {
                return null;
            }

            $name = $authorName ? $this->nameFromDisplay($authorName) : $this->names->make();
            $user = $this->demoUser($name, $batchId);
            $reviewDate = $this->randomDate($dateFrom, $dateTo);

            return Review::query()->create([
                'product_id' => $product->id,
                'product_variant_id' => $product->variant_group_id ? $product->id : null,
                'user_id' => $user->id,
                'parent_id' => null,
                'rating' => (int) $generated['rating'],
                'title' => null,
                'body' => $text,
                'pros' => null,
                'cons' => null,
                'is_verified_purchase' => false,
                'is_visible' => $visible,
                'is_demo' => true,
                'is_ai_generated' => true,
                'source' => 'openai',
                'environment' => app()->environment(),
                'generation_batch_id' => $batchId,
                'model' => (string) config('services.openai.model'),
                'prompt_version' => OpenAiDemoReviewGenerator::PROMPT_VERSION,
                'input_hash' => $inputHash,
                'metadata' => array_merge($metadata, [
                    'style' => $generated['style'] ?? null,
                    'mentions_delivery' => $generated['mentions_delivery'] ?? false,
                    'mentions_service' => $generated['mentions_service'] ?? false,
                    'has_intentional_typo' => $generated['has_intentional_typo'] ?? false,
                    'demo_author_name' => $name['display_name'],
                ]),
                'review_date' => $reviewDate,
                'generated_at' => now(),
                'created_at' => $reviewDate,
                'updated_at' => $reviewDate,
            ]);
        });
    }

    /**
     * @return array{first_name: string, last_name: string, display_name: string}
     */
    private function nameFromDisplay(string $displayName): array
    {
        $displayName = trim(preg_replace('/\s+/u', ' ', $displayName) ?? '');
        $parts = preg_split('/\s+/u', $displayName, 2) ?: [];

        return [
            'first_name' => $parts[0] ?? 'Покупець',
            'last_name' => $parts[1] ?? '',
            'display_name' => $displayName !== '' ? $displayName : 'Покупець',
        ];
    }

    public function markBatchJobFinished(string $batchId, int $successful, int $failed, array $usage = [], ?string $responseId = null, ?string $error = null): void
    {
        $batch = DemoReviewGenerationBatch::query()->find($batchId);

        if (! $batch) {
            return;
        }

        $errors = (array) ($batch->errors ?? []);

        if ($error) {
            $errors[] = [
                'message' => $error,
                'at' => now()->toDateTimeString(),
            ];
        }

        $batch->fill([
            'status' => $error ? 'failed' : 'running',
            'jobs_finished' => $batch->jobs_finished + 1,
            'successful_reviews_count' => $batch->successful_reviews_count + $successful,
            'failed_reviews_count' => $batch->failed_reviews_count + $failed,
            'input_tokens' => $batch->input_tokens + (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => $batch->output_tokens + (int) ($usage['output_tokens'] ?? 0),
            'total_tokens' => $batch->total_tokens + (int) ($usage['total_tokens'] ?? 0),
            'estimated_cost' => $batch->estimated_cost + $this->estimateCost((int) ($usage['input_tokens'] ?? 0), (int) ($usage['output_tokens'] ?? 0)),
            'errors' => $errors,
        ]);

        $settings = (array) ($batch->settings ?? []);
        if ($responseId) {
            $settings['response_ids'][] = $responseId;
            $batch->settings = $settings;
        }

        if ($batch->jobs_finished >= $batch->jobs_total) {
            $batch->status = $errors === [] ? 'completed' : 'completed_with_errors';
            $batch->finished_at = now();
        }

        $batch->save();
    }

    private function demoUser(array $name, string $batchId): User
    {
        $email = 'demo-review-'.Str::lower(Str::random(16)).'@example.invalid';

        return User::query()->create([
            'name' => $name['display_name'],
            'last_name' => $name['last_name'],
            'first_name' => $name['first_name'],
            'email' => $email,
            'password' => Str::password(32),
            'is_demo' => true,
            'is_guest' => false,
            'is_admin' => false,
            'demo_metadata' => [
                'source' => 'demo_review_generator',
                'generation_batch_id' => $batchId,
            ],
        ]);
    }

    private function randomDate(string $dateFrom, string $dateTo): CarbonImmutable
    {
        $from = CarbonImmutable::parse($dateFrom)->startOfDay();
        $to = CarbonImmutable::parse($dateTo)->endOfDay();
        $timestamp = random_int($from->timestamp, max($from->timestamp, $to->timestamp));

        return CarbonImmutable::createFromTimestamp($timestamp);
    }

    private function estimateCost(int $inputTokens, int $outputTokens): float
    {
        // Conservative placeholder until the admin sets real model pricing.
        return round((($inputTokens / 1_000_000) * 0.15) + (($outputTokens / 1_000_000) * 0.60), 6);
    }
}
