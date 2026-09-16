<?php

namespace App\Filament\Pages;

use App\Jobs\GenerateDemoProductReviewsJob;
use App\Models\Category;
use App\Models\DemoReviewGenerationBatch;
use App\Models\Product;
use App\Models\Review;
use App\Services\DemoReviewPersistence;
use App\Services\DailyDemoReviewScheduler;
use App\Services\OpenAiDemoReviewGenerator;
use App\Support\DemoReviewNameGenerator;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoReviewGenerator extends Page
{
    private const MIN_DATE = '2026-06-27';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static ?string $navigationLabel = 'Генератор відгуків';

    protected static string|\UnitEnum|null $navigationGroup = 'Товари';

    protected static ?int $navigationSort = 40;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.demo-review-generator';

    public string $productCodes = '';

    public int $count = 3;

    public string $dateFrom = self::MIN_DATE;

    public int $minRating = 4;

    public int $maxRating = 5;

    public string $style = 'mixed';

    public bool $autoSave = true;

    public bool $visible = true;

    /** @var list<string> */
    public array $notFoundCodes = [];

    /** @var list<array<string, mixed>> */
    public array $previewRows = [];

    public ?string $lastBatchId = null;

    public int $dailyMaxTotal = 200;

    public bool $dailyDryRun = false;

    public ?int $dailyCategoryId = null;

    /** @var list<array<string, mixed>> */
    public array $dailyPreviewRows = [];

    public function mount(): void
    {
        $this->dateFrom = self::MIN_DATE;
    }

    public function getTitle(): string
    {
        return 'Генератор відгуків';
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $codes = $this->normalizedCodes();
        $total = count($codes) * max(1, min(20, $this->count));
        $estimatedTokens = $total * 220;

        return [
            'products' => count($codes),
            'per_product' => $this->count,
            'total_reviews' => $total,
            'estimated_tokens' => $estimatedTokens,
            'estimated_cost' => round((($estimatedTokens * 0.75) / 1_000_000 * 0.15) + (($estimatedTokens * 0.25) / 1_000_000 * 0.60), 6),
        ];
    }

    public function run(): void
    {
        $products = $this->validatedProducts();

        if ($products->isEmpty()) {
            Notification::make()->danger()->title('Немає знайдених товарів')->send();

            return;
        }

        if ($this->autoSave) {
            $this->dispatchJobs($products);

            return;
        }

        $this->generatePreview($products);
    }

    public function savePreview(): void
    {
        if ($this->previewRows === []) {
            Notification::make()->warning()->title('Немає відгуків для збереження')->send();

            return;
        }

        $batchId = (string) Str::uuid();

        DemoReviewGenerationBatch::query()->create([
            'id' => $batchId,
            'admin_user_id' => auth()->id(),
            'status' => 'completed',
            'products_count' => collect($this->previewRows)->pluck('product_id')->unique()->count(),
            'requested_reviews_count' => count($this->previewRows),
            'successful_reviews_count' => 0,
            'failed_reviews_count' => 0,
            'jobs_total' => 0,
            'jobs_finished' => 0,
            'model' => (string) config('services.openai.model'),
            'settings' => $this->batchSettings(),
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $saved = 0;
        $persistence = app(DemoReviewPersistence::class);
        $generator = app(OpenAiDemoReviewGenerator::class);

        foreach ($this->previewRows as $index => $row) {
            $product = Product::query()->find($row['product_id']);

            if (! $product) {
                continue;
            }

            $hash = hash('sha256', $generator->inputHash($product, 1, (string) $row['style'], $this->dateFrom, now()->toDateString(), $batchId).':preview:'.$index);

            $review = $persistence->save(
                product: $product,
                generated: [
                    'text' => $row['text'],
                    'rating' => $row['rating'],
                    'style' => $row['style'],
                    'mentions_delivery' => $row['mentions_delivery'],
                    'mentions_service' => $row['mentions_service'],
                    'has_intentional_typo' => $row['has_intentional_typo'],
                ],
                batchId: $batchId,
                inputHash: $hash,
                dateFrom: $this->dateFrom,
                dateTo: now()->toDateString(),
                metadata: ['saved_from_preview' => true],
                visible: $this->visible,
                authorName: (string) ($row['author_name'] ?? ''),
            );

            if ($review) {
                $saved++;
            }
        }

        DemoReviewGenerationBatch::query()->whereKey($batchId)->update([
            'successful_reviews_count' => $saved,
            'failed_reviews_count' => max(0, count($this->previewRows) - $saved),
        ]);

        $this->lastBatchId = $batchId;
        $this->previewRows = [];

        Notification::make()->success()->title('Відгуки збережено')->body("Batch: {$batchId}. Збережено: {$saved}.")->send();
    }

    public function savePreviewRow(int $index): void
    {
        if (! isset($this->previewRows[$index])) {
            return;
        }

        $row = $this->previewRows[$index];
        $batchId = $this->lastBatchId ?: (string) Str::uuid();

        if (! $this->lastBatchId) {
            DemoReviewGenerationBatch::query()->create([
                'id' => $batchId,
                'admin_user_id' => auth()->id(),
                'status' => 'completed',
                'products_count' => 1,
                'requested_reviews_count' => 1,
                'model' => (string) config('services.openai.model'),
                'settings' => $this->batchSettings(),
                'started_at' => now(),
                'finished_at' => now(),
            ]);
            $this->lastBatchId = $batchId;
        }

        $product = Product::query()->find($row['product_id']);

        if (! $product) {
            Notification::make()->danger()->title('Товар не знайдено')->send();

            return;
        }

        $hash = hash('sha256', app(OpenAiDemoReviewGenerator::class)->inputHash($product, 1, (string) $row['style'], $this->dateFrom, now()->toDateString(), $batchId).':single:'.$index);
        $review = app(DemoReviewPersistence::class)->save(
            product: $product,
            generated: [
                'text' => $row['text'],
                'rating' => $row['rating'],
                'style' => $row['style'],
                'mentions_delivery' => $row['mentions_delivery'],
                'mentions_service' => $row['mentions_service'],
                'has_intentional_typo' => $row['has_intentional_typo'],
            ],
            batchId: $batchId,
            inputHash: $hash,
            dateFrom: $this->dateFrom,
            dateTo: now()->toDateString(),
            metadata: ['saved_from_preview' => true],
            visible: $this->visible,
            authorName: (string) ($row['author_name'] ?? ''),
        );

        if ($review) {
            DemoReviewGenerationBatch::query()->whereKey($batchId)->increment('successful_reviews_count');
            unset($this->previewRows[$index]);
            $this->previewRows = array_values($this->previewRows);
            Notification::make()->success()->title('Відгук збережено')->send();
        } else {
            Notification::make()->warning()->title('Не збережено')->body('Схожий або дубльований текст уже є.')->send();
        }
    }

    public function regeneratePreviewRow(int $index): void
    {
        if (! isset($this->previewRows[$index])) {
            return;
        }

        $product = Product::query()->find($this->previewRows[$index]['product_id']);

        if (! $product) {
            Notification::make()->danger()->title('Товар не знайдено')->send();

            return;
        }

        $result = app(OpenAiDemoReviewGenerator::class)->generate($product, 1, $this->style, $this->minRating, $this->maxRating);
        $review = $result['reviews'][0];
        $name = app(DemoReviewNameGenerator::class)->make();

        $this->previewRows[$index] = array_merge($this->previewRows[$index], [
            'author_name' => $name['display_name'],
            'text' => $review['text'],
            'rating' => $review['rating'],
            'style' => $review['style'],
            'mentions_delivery' => $review['mentions_delivery'],
            'mentions_service' => $review['mentions_service'],
            'has_intentional_typo' => $review['has_intentional_typo'],
        ]);

        Notification::make()->success()->title('Відгук перегенеровано')->send();
    }

    public function deletePreviewRow(int $index): void
    {
        unset($this->previewRows[$index]);
        $this->previewRows = array_values($this->previewRows);
    }

    public function deleteBatch(string $batchId): void
    {
        DB::transaction(function () use ($batchId): void {
            Review::query()
                ->where('generation_batch_id', $batchId)
                ->where('is_demo', true)
                ->get()
                ->each
                ->delete();

            DemoReviewGenerationBatch::query()->whereKey($batchId)->delete();
        });

        Notification::make()->success()->title('Batch видалено')->body($batchId)->send();
    }

    public function runDaily(): void
    {
        $this->dailyPreviewRows = [];
        $this->dailyMaxTotal = max(1, min(500, $this->dailyMaxTotal));

        $date = Carbon::parse($this->dateFrom);

        if ($date->isFuture() || $date->lt(Carbon::parse(self::MIN_DATE))) {
            Notification::make()->danger()->title('Невірна дата')->body('Дата має бути не раніше 27.06.2026 і не в майбутньому.')->send();

            return;
        }

        $result = app(DailyDemoReviewScheduler::class)->run(
            dateFrom: $this->dateFrom,
            minProducts: 3,
            maxProducts: 5,
            maxTotal: $this->dailyMaxTotal,
            visible: $this->visible,
            dryRun: $this->dailyDryRun,
            adminUserId: auth()->id(),
            categoryId: $this->dailyCategoryId ? (int) $this->dailyCategoryId : null,
        );

        if ($this->dailyDryRun) {
            $this->dailyPreviewRows = $result['products']
                ->take(80)
                ->map(fn (Product $product): array => [
                    'id' => $product->id,
                    'code' => $product->sku ?: $product->external_id ?: $product->id,
                    'category_id' => $product->category_id,
                    'ai_reviews' => $product->ai_demo_reviews_count ?? 0,
                    'name' => $product->name,
                ])
                ->values()
                ->all();

            Notification::make()
                ->success()
                ->title('Daily dry-run готовий')
                ->body('Вибрано товарів: '.$result['products_count'].'. Кінцевих категорій: '.$result['leaf_categories_count'])
                ->send();

            return;
        }

        if (! $result['batch_id']) {
            Notification::make()->warning()->title('Немає товарів для daily-генерації')->send();

            return;
        }

        $this->lastBatchId = $result['batch_id'];

        Notification::make()
            ->success()
            ->title('Daily-генерацію запущено')
            ->body('Batch: '.$result['batch_id'].'. Товарів: '.$result['products_count'])
            ->send();
    }

    private function dispatchJobs(Collection $products): void
    {
        $batchId = (string) Str::uuid();

        DemoReviewGenerationBatch::query()->create([
            'id' => $batchId,
            'admin_user_id' => auth()->id(),
            'status' => 'queued',
            'products_count' => $products->count(),
            'requested_reviews_count' => $products->count() * $this->count,
            'jobs_total' => $products->count(),
            'model' => (string) config('services.openai.model'),
            'settings' => $this->batchSettings(),
            'started_at' => now(),
        ]);

        foreach ($products as $product) {
            GenerateDemoProductReviewsJob::dispatch((int) $product->id, $batchId, $this->batchSettings())->onQueue('reviews');
        }

        $this->lastBatchId = $batchId;

        Notification::make()->success()->title('Генерацію запущено')->body("Batch: {$batchId}")->send();
    }

    private function generatePreview(Collection $products): void
    {
        $generator = app(OpenAiDemoReviewGenerator::class);
        $names = app(DemoReviewNameGenerator::class);
        $rows = [];

        foreach ($products as $product) {
            $result = $generator->generate($product, $this->count, $this->style, $this->minRating, $this->maxRating);

            foreach ($result['reviews'] as $review) {
                $name = $names->make();
                $rows[] = [
                    'product_id' => $product->id,
                    'product_code' => $product->sku ?: $product->external_id ?: $product->id,
                    'product_name' => $product->name,
                    'author_name' => $name['display_name'],
                    'text' => $review['text'],
                    'rating' => $review['rating'],
                    'review_date' => Carbon::parse($this->dateFrom)->addSeconds(random_int(0, max(1, Carbon::parse($this->dateFrom)->diffInSeconds(now()))))->format('d.m.Y'),
                    'style' => $review['style'],
                    'mentions_delivery' => $review['mentions_delivery'],
                    'mentions_service' => $review['mentions_service'],
                    'has_intentional_typo' => $review['has_intentional_typo'],
                ];
            }
        }

        $this->previewRows = $rows;

        Notification::make()->success()->title('Попередній перегляд готовий')->body('Згенеровано: '.count($rows))->send();
    }

    private function validatedProducts(): Collection
    {
        $codes = $this->normalizedCodes();
        $this->notFoundCodes = [];
        $this->previewRows = [];

        if ($codes === []) {
            Notification::make()->danger()->title('Вставте коди товарів')->send();

            return collect();
        }

        if ($this->count < 1 || $this->count > 20) {
            Notification::make()->danger()->title('Кількість має бути 1–20')->send();

            return collect();
        }

        if (count($codes) * $this->count > 200) {
            Notification::make()->danger()->title('Ліміт — не більше 200 відгуків за запуск')->send();

            return collect();
        }

        $date = Carbon::parse($this->dateFrom);

        if ($date->isFuture() || $date->lt(Carbon::parse(self::MIN_DATE))) {
            Notification::make()->danger()->title('Невірна дата')->body('Дата має бути не раніше 27.06.2026 і не в майбутньому.')->send();

            return collect();
        }

        if (! in_array($this->style, array_keys(OpenAiDemoReviewGenerator::STYLES), true)) {
            Notification::make()->danger()->title('Невідомий стиль')->send();

            return collect();
        }

        $products = Product::query()
            ->where(function ($query) use ($codes): void {
                $query->whereIn('sku', $codes)
                    ->orWhereIn('external_id', $codes)
                    ->orWhereIn('variant_id', $codes);

                $numericIds = collect($codes)->filter(fn (string $code): bool => ctype_digit($code))->map(fn (string $code): int => (int) $code)->all();

                if ($numericIds !== []) {
                    $query->orWhereIn('id', $numericIds);
                }
            })
            ->get();

        $foundCodes = $products
            ->flatMap(fn (Product $product): array => array_filter([(string) $product->sku, (string) $product->external_id, (string) $product->variant_id, (string) $product->id]))
            ->map(fn (string $code): string => mb_strtolower(trim($code)))
            ->unique()
            ->all();

        $this->notFoundCodes = collect($codes)
            ->reject(fn (string $code): bool => in_array(mb_strtolower($code), $foundCodes, true))
            ->values()
            ->all();

        return $products;
    }

    /**
     * @return list<string>
     */
    private function normalizedCodes(): array
    {
        return collect(preg_split('/[\r\n,;]+/', $this->productCodes) ?: [])
            ->map(fn (string $code): string => trim($code))
            ->filter()
            ->unique(fn (string $code): string => mb_strtolower($code))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function batchSettings(): array
    {
        return [
            'count' => $this->count,
            'date_from' => $this->dateFrom,
            'date_to' => now()->toDateString(),
            'min_rating' => $this->minRating,
            'max_rating' => $this->maxRating,
            'style' => $this->style,
            'visible' => $this->visible,
            'auto_save' => $this->autoSave,
            'trigger' => 'manual',
            'max_ai_reviews_per_product' => DemoReviewPersistence::MAX_AI_REVIEWS_PER_PRODUCT,
        ];
    }

    /**
     * @return EloquentCollection<int, DemoReviewGenerationBatch>
     */
    public function getRecentBatchesProperty(): EloquentCollection
    {
        return DemoReviewGenerationBatch::query()
            ->with('admin')
            ->latest()
            ->limit(20)
            ->get();
    }

    /**
     * @return array<int, string>
     */
    public function getDailyCategoryOptionsProperty(): array
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->whereNull('target_category_id')
            ->with('parentRecursive')
            ->orderBy('parent_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id'])
            ->sortBy(fn (Category $category): string => $category->breadcrumbTrail()->pluck('name')->implode(' / '));

        return $categories
            ->mapWithKeys(fn (Category $category): array => [
                $category->id => $category->breadcrumbTrail()->pluck('name')->implode(' / '),
            ])
            ->all();
    }
}
