<?php

namespace App\Filament\Pages;

use App\Jobs\GenerateCategorySeoJob;
use App\Models\Category;
use App\Models\CategorySeoGeneration;
use App\Models\Product;
use App\Services\CategorySeoPublisher;
use App\Support\CategoryTreeBuilder;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use RuntimeException;

class CategorySeoGenerator extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'Генератор категорій';

    protected static string|\UnitEnum|null $navigationGroup = 'SEO';

    protected static ?int $navigationSort = 10;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.category-seo-generator';

    public string $selectionMode = 'single';

    public ?int $singleCategoryId = null;

    /** @var list<int> */
    public array $categoryIds = [];

    public ?int $branchCategoryId = null;

    public bool $activeOnly = true;

    public string $localeMode = 'uk';

    public int $wordCount = 500;

    /** @var list<string> */
    public array $fields = ['seo_title', 'meta_description', 'h1', 'intro', 'seo_text', 'faq', 'faq_schema'];

    public string $overwriteMode = CategorySeoPublisher::MODE_MISSING;

    public int $maxJobsPerRun = 20;

    public float $maxBudgetUsd = 1.0;

    public bool $dryRun = false;

    public string $historyStatus = 'actionable';

    public function getTitle(): string
    {
        return 'AI генерація SEO для категорій';
    }

    public function generate(): void
    {
        $this->wordCount = max(200, min(1500, $this->wordCount));
        $this->maxJobsPerRun = max(1, min(500, $this->maxJobsPerRun));
        $this->maxBudgetUsd = max(0.01, min(100.0, $this->maxBudgetUsd));
        $categoryIds = $this->selectedCategoryIds();
        $locales = $this->selectedLocales();
        $estimate = $this->estimateRun($categoryIds, $locales);

        if ($categoryIds === []) {
            Notification::make()->warning()->title('Немає категорій для генерації')->send();

            return;
        }

        if ($this->fields === []) {
            Notification::make()->warning()->title('Оберіть хоча б одне поле')->send();

            return;
        }

        if ($estimate['jobs'] > $this->maxJobsPerRun) {
            Notification::make()
                ->danger()
                ->title('Забагато задач для одного запуску')
                ->body('Вибрано '.$estimate['jobs'].' задач, ліміт — '.$this->maxJobsPerRun.'. Зменшіть вибір або підніміть ліміт вручну.')
                ->send();

            return;
        }

        if ($estimate['estimated_cost'] > $this->maxBudgetUsd) {
            Notification::make()
                ->danger()
                ->title('Бюджет запуску перевищено')
                ->body('Оцінка $'.number_format($estimate['estimated_cost'], 4).' більша за ліміт $'.number_format($this->maxBudgetUsd, 2).'.')
                ->send();

            return;
        }

        if ($this->dryRun) {
            Notification::make()
                ->success()
                ->title('Dry-run готовий')
                ->body('Категорій: '.$estimate['categories'].', задач: '.$estimate['jobs'].', орієнтовно слів: '.number_format($estimate['estimated_words']).', вартість: ~$'.number_format($estimate['estimated_cost'], 4).'.')
                ->send();

            return;
        }

        $created = 0;

        foreach ($categoryIds as $categoryId) {
            foreach ($locales as $locale) {
                $generation = CategorySeoGeneration::query()->create([
                    'category_id' => $categoryId,
                    'locale' => $locale,
                    'status' => 'pending',
                    'requested_word_count' => $this->wordCount,
                    'requested_fields' => $this->normalizedFields(),
                    'overwrite_mode' => $this->overwriteMode,
                    'selection_mode' => $this->selectionMode,
                    'settings' => [
                        'active_only' => $this->activeOnly,
                        'branch_category_id' => $this->branchCategoryId,
                        'estimated_cost_before_run' => $estimate['estimated_cost'],
                        'max_budget_usd' => $this->maxBudgetUsd,
                    ],
                    'model' => (string) config('services.openai.model'),
                    'generated_by' => auth()->id(),
                ]);

                GenerateCategorySeoJob::dispatch($generation->id)->onQueue('default');
                $created++;
            }
        }

        Notification::make()
            ->success()
            ->title('Генерацію запущено')
            ->body('Створено задач: '.number_format($created).'. Результати зʼявляться в preview.')
            ->send();
    }

    /**
     * @param  list<int>  $categoryIds
     * @param  list<string>  $locales
     * @return array{categories: int, locales: int, jobs: int, estimated_words: int, estimated_input_tokens: int, estimated_output_tokens: int, estimated_cost: float}
     */
    public function estimateRun(?array $categoryIds = null, ?array $locales = null): array
    {
        $categoryIds ??= $this->selectedCategoryIds();
        $locales ??= $this->selectedLocales();
        $jobs = count($categoryIds) * count($locales);
        $words = $jobs * max(200, min(1500, $this->wordCount));
        $inputTokens = $jobs * 1800;
        $outputTokens = (int) ceil($words * 1.45);

        return [
            'categories' => count($categoryIds),
            'locales' => count($locales),
            'jobs' => $jobs,
            'estimated_words' => $words,
            'estimated_input_tokens' => $inputTokens,
            'estimated_output_tokens' => $outputTokens,
            'estimated_cost' => round((($inputTokens / 1_000_000) * 0.15) + (($outputTokens / 1_000_000) * 0.60), 6),
        ];
    }

    public function publish(int $generationId): void
    {
        $generation = CategorySeoGeneration::query()->with('category')->find($generationId);

        if (! $generation) {
            return;
        }

        try {
            $updated = app(CategorySeoPublisher::class)->publish($generation);
        } catch (RuntimeException $exception) {
            Notification::make()
                ->danger()
                ->title('Не можна опублікувати')
                ->body($exception->getMessage())
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title($updated === [] ? 'Нічого не змінено' : 'SEO опубліковано')
            ->body($updated === [] ? 'Режим не дозволив перезаписати існуючі поля.' : 'Оновлено поля: '.implode(', ', $updated))
            ->send();
    }

    public function reject(int $generationId): void
    {
        CategorySeoGeneration::query()->whereKey($generationId)->update(['status' => 'rejected']);
        Notification::make()->success()->title('AI-варіант відхилено')->send();
    }

    public function cancel(int $generationId): void
    {
        $updated = CategorySeoGeneration::query()
            ->whereKey($generationId)
            ->whereIn('status', ['pending', 'generating'])
            ->update([
                'status' => 'rejected',
                'errors' => [['message' => 'Скасовано адміністратором.', 'at' => now()->toDateTimeString()]],
            ]);

        Notification::make()
            ->success()
            ->title($updated > 0 ? 'Генерацію скасовано' : 'Немає що скасовувати')
            ->send();
    }

    public function deleteGeneration(int $generationId): void
    {
        CategorySeoGeneration::query()->whereKey($generationId)->delete();
        Notification::make()->success()->title('Генерацію видалено')->send();
    }

    public function publishGenerated(): void
    {
        $generations = CategorySeoGeneration::query()
            ->with('category')
            ->whereIn('status', ['generated', 'generated_with_warnings', 'approved'])
            ->latest()
            ->limit(50)
            ->get();

        $published = 0;
        $blocked = 0;

        foreach ($generations as $generation) {
            try {
                $updated = app(CategorySeoPublisher::class)->publish($generation);
                $published += $updated === [] ? 0 : 1;
            } catch (RuntimeException) {
                $blocked++;
            }
        }

        Notification::make()
            ->success()
            ->title('Масова публікація завершена')
            ->body('Опубліковано: '.$published.'. Заблоковано перевірками: '.$blocked.'.')
            ->send();
    }

    public function rejectPendingAndFailed(): void
    {
        $updated = CategorySeoGeneration::query()
            ->whereIn('status', ['pending', 'failed'])
            ->update(['status' => 'rejected']);

        Notification::make()
            ->success()
            ->title('Чернетки очищено')
            ->body('Відхилено: '.number_format($updated).'.')
            ->send();
    }

    public function deleteRejected(): void
    {
        $deleted = CategorySeoGeneration::query()
            ->where('status', 'rejected')
            ->delete();

        Notification::make()
            ->success()
            ->title('Rejected видалено')
            ->body('Видалено: '.number_format($deleted).'.')
            ->send();
    }

    public function restore(int $generationId): void
    {
        $generation = CategorySeoGeneration::query()->with('category')->find($generationId);

        if (! $generation) {
            return;
        }

        $generation->overwrite_mode = CategorySeoPublisher::MODE_ALL;
        $generation->requested_fields = ['seo_title', 'meta_description', 'h1', 'intro', 'seo_text', 'faq'];
        app(CategorySeoPublisher::class)->publish($generation);

        Notification::make()->success()->title('Версію відновлено')->send();
    }

    public function regenerate(int $generationId): void
    {
        $generation = CategorySeoGeneration::query()->find($generationId);

        if (! $generation) {
            return;
        }

        $estimate = $this->estimateRun([$generation->category_id], [$generation->locale]);

        if ($estimate['estimated_cost'] > $this->maxBudgetUsd) {
            Notification::make()
                ->danger()
                ->title('Бюджет перегенерації перевищено')
                ->body('Оцінка $'.number_format($estimate['estimated_cost'], 4).' більша за ліміт $'.number_format($this->maxBudgetUsd, 2).'.')
                ->send();

            return;
        }

        $new = $generation->replicate(['status', 'seo_title', 'meta_description', 'h1', 'intro', 'seo_text', 'faq', 'internal_links', 'validation_errors', 'errors', 'response_id', 'input_tokens', 'output_tokens', 'total_tokens', 'estimated_cost', 'generated_at', 'approved_at', 'published_at']);
        $new->status = 'pending';
        $new->generated_by = auth()->id();
        $new->save();

        GenerateCategorySeoJob::dispatch($new->id)->onQueue('default');
        Notification::make()->success()->title('Перегенерацію запущено')->send();
    }

    /**
     * @return array<string, int>
     */
    public function audit(): array
    {
        $base = Category::query()->whereNull('target_category_id');
        $total = (clone $base)->count();

        return [
            'total' => $total,
            'without_title' => (clone $base)->where(fn ($q) => $q->whereNull('seo_title')->orWhere('seo_title', ''))->count(),
            'without_description' => (clone $base)->where(fn ($q) => $q->whereNull('meta_description')->orWhere('meta_description', ''))->count(),
            'without_h1' => (clone $base)->where(fn ($q) => $q->whereNull('h1')->orWhere('h1', ''))->count(),
            'without_seo_text' => (clone $base)->where(fn ($q) => $q->whereNull('description')->orWhere('description', ''))->count(),
            'without_faq' => (clone $base)->whereNull('seo_faq')->count(),
            'complete' => (clone $base)
                ->whereNotNull('seo_title')->where('seo_title', '!=', '')
                ->whereNotNull('meta_description')->where('meta_description', '!=', '')
                ->whereNotNull('h1')->where('h1', '!=', '')
                ->whereNotNull('description')->where('description', '!=', '')
                ->whereNotNull('seo_faq')
                ->count(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function getCategoryOptionsProperty(): array
    {
        return app(CategoryTreeBuilder::class)->options();
    }

    /**
     * @return EloquentCollection<int, CategorySeoGeneration>
     */
    public function getRecentGenerationsProperty(): EloquentCollection
    {
        $query = CategorySeoGeneration::query()
            ->with('category')
            ->when($this->historyStatus === 'actionable', fn ($query) => $query->whereIn('status', ['generated', 'generated_with_warnings', 'approved']))
            ->when($this->historyStatus !== 'all' && $this->historyStatus !== 'actionable', fn ($query) => $query->where('status', $this->historyStatus));

        return $query
            ->orderByRaw("FIELD(status, 'generated', 'generated_with_warnings', 'approved', 'failed', 'pending', 'generating', 'published', 'rejected')")
            ->latest()
            ->limit(30)
            ->get();
    }

    /**
     * @return array<string, int>
     */
    public function generationStats(): array
    {
        $counts = CategorySeoGeneration::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        return [
            'actionable' => ($counts['generated'] ?? 0) + ($counts['generated_with_warnings'] ?? 0) + ($counts['approved'] ?? 0),
            'pending' => $counts['pending'] ?? 0,
            'generating' => $counts['generating'] ?? 0,
            'failed' => $counts['failed'] ?? 0,
            'published' => $counts['published'] ?? 0,
            'rejected' => $counts['rejected'] ?? 0,
            'all' => array_sum($counts),
        ];
    }

    /**
     * @return list<int>
     */
    private function selectedCategoryIds(): array
    {
        $query = Category::query()->whereNull('target_category_id');

        if ($this->activeOnly) {
            $query->where('is_active', true);
        }

        match ($this->selectionMode) {
            'single' => $query->whereKey($this->singleCategoryId ?: 0),
            'multiple' => $query->whereIn('id', array_map('intval', $this->categoryIds)),
            'missing_seo' => $query->where(function ($q): void {
                $q->whereNull('seo_title')->orWhere('seo_title', '')
                    ->orWhereNull('meta_description')->orWhere('meta_description', '')
                    ->orWhereNull('h1')->orWhere('h1', '')
                    ->orWhereNull('description')->orWhere('description', '');
            }),
            'missing_title' => $query->where(fn ($q) => $q->whereNull('seo_title')->orWhere('seo_title', '')),
            'missing_description' => $query->where(fn ($q) => $q->whereNull('meta_description')->orWhere('meta_description', '')),
            'branch' => $query->whereIn('id', $this->branchCategoryIds()),
            default => null,
        };

        return $query
            ->limit(500)
            ->get(['id'])
            ->filter(fn (Category $category): bool => $this->categoryHasAssortment($category))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function categoryHasAssortment(Category $category): bool
    {
        $category->loadMissing('childrenRecursive');
        $categoryIds = [$category->id, ...$category->descendantIds()];

        return Product::query()
            ->whereIn('category_id', $categoryIds)
            ->where('is_active', true)
            ->where('is_visible_in_catalog', true)
            ->where('stock', '>', 0)
            ->exists();
    }

    /**
     * @return list<int>
     */
    private function branchCategoryIds(): array
    {
        if (! $this->branchCategoryId) {
            return [];
        }

        $category = Category::query()->with('childrenRecursive')->find($this->branchCategoryId);

        return $category ? [$category->id, ...$category->descendantIds()] : [];
    }

    /**
     * @return list<string>
     */
    private function selectedLocales(): array
    {
        return match ($this->localeMode) {
            'ru' => ['ru'],
            'both' => ['uk', 'ru'],
            default => ['uk'],
        };
    }

    /**
     * @return list<string>
     */
    private function normalizedFields(): array
    {
        $fields = array_values(array_intersect($this->fields, ['seo_title', 'meta_description', 'h1', 'intro', 'seo_text', 'faq', 'faq_schema']));

        return in_array('faq_schema', $fields, true) && ! in_array('faq', $fields, true)
            ? [...$fields, 'faq']
            : $fields;
    }
}
