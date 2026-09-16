<?php

namespace App\Jobs;

use App\Models\CategorySeoGeneration;
use App\Services\CategorySeoContextBuilder;
use App\Services\OpenAiCategorySeoGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateCategorySeoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(public readonly int $generationId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(CategorySeoContextBuilder $contextBuilder, OpenAiCategorySeoGenerator $generator): void
    {
        $generation = CategorySeoGeneration::query()->with('category')->find($this->generationId);

        if (! $generation || ! $generation->category) {
            return;
        }

        $generation->update(['status' => 'generating']);

        $context = $contextBuilder->build($generation->category, $generation->locale);
        $result = $generator->generate(
            category: $generation->category,
            context: $context,
            locale: $generation->locale,
            wordCount: (int) $generation->requested_word_count,
            fields: (array) $generation->requested_fields,
        );

        $generation->update([
            'status' => $result['validation_errors'] === [] ? 'generated' : 'generated_with_warnings',
            'context_summary' => [
                'active_products' => data_get($context, 'products.active_count'),
                'brands' => array_slice((array) data_get($context, 'products.brands', []), 0, 10),
                'breadcrumb' => data_get($context, 'hierarchy.breadcrumb'),
            ],
            'seo_title' => $result['content']['seo_title'] ?? null,
            'meta_description' => $result['content']['meta_description'] ?? null,
            'h1' => $result['content']['h1'] ?? null,
            'intro' => $result['content']['intro'] ?? null,
            'seo_text' => $result['content']['seo_text'] ?? null,
            'faq' => $result['content']['faq'] ?? [],
            'internal_links' => $result['content']['internal_links'] ?? [],
            'validation_errors' => $result['validation_errors'],
            'model' => (string) config('services.openai.model'),
            'prompt_version' => OpenAiCategorySeoGenerator::PROMPT_VERSION,
            'response_id' => $result['response_id'],
            'input_tokens' => $result['usage']['input_tokens'],
            'output_tokens' => $result['usage']['output_tokens'],
            'total_tokens' => $result['usage']['total_tokens'],
            'estimated_cost' => $this->estimateCost($result['usage']['input_tokens'], $result['usage']['output_tokens']),
            'generated_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        CategorySeoGeneration::query()
            ->whereKey($this->generationId)
            ->update([
                'status' => 'failed',
                'errors' => [['message' => $exception->getMessage(), 'at' => now()->toDateTimeString()]],
            ]);
    }

    private function estimateCost(int $inputTokens, int $outputTokens): float
    {
        return round((($inputTokens / 1_000_000) * 0.15) + (($outputTokens / 1_000_000) * 0.60), 6);
    }
}
