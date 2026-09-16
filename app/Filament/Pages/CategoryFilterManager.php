<?php

namespace App\Filament\Pages;

use App\Models\Category;
use App\Models\Product;
use App\Services\CatalogSpecificationFacets;
use App\Support\CatalogCache as CatalogCacheStore;
use App\Support\CategoryTreeBuilder;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class CategoryFilterManager extends Page
{
    private const BASE_FILTERS = [
        'sale' => 'Акційні товари',
        'brand' => 'Бренд',
        'model' => 'Модель',
        'price' => 'Ціна',
        'season' => 'Сезон',
        'usage_type' => 'Тип використання',
        'material' => 'Матеріал',
        'weight' => 'Максимальна вага',
        'stock' => 'Тільки в наявності',
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Фільтри категорій';

    protected static string|\UnitEnum|null $navigationGroup = 'Товари';

    protected static ?int $navigationSort = 35;

    protected string $view = 'filament.pages.category-filter-manager';

    public ?int $categoryId = null;

    /** @var array<int, string> */
    public array $categoryOptions = [];

    /** @var list<string> */
    public array $baseFilters = [];

    /** @var list<string> */
    public array $specFilters = [];

    /** @var array<string, array{label: string, count: int|null, label_input_id: string}> */
    public array $availableBaseFilters = [];

    /** @var array<string, array{label: string, count: int|null, label_input_id: string}> */
    public array $availableSpecFilters = [];

    /** @var array<string, array{key: string, uk: string, ru: string, placeholder_uk: string, placeholder_ru: string}> */
    public array $filterLabelInputs = [];

    public int $minimumFilterProducts = 5;

    public bool $applyToChildrenOnSave = false;

    public ?string $categoryName = null;

    public ?string $settingsMode = null;

    public function mount(CategoryTreeBuilder $tree): void
    {
        $this->categoryOptions = $tree->storefrontOptions();

        if ($this->categoryId === null && $this->categoryOptions !== []) {
            $this->categoryId = (int) array_key_first($this->categoryOptions);
        }

        $this->loadCategoryFilters();
    }

    public function updatedCategoryId(): void
    {
        $this->loadCategoryFilters();
    }

    public function save(): void
    {
        $category = $this->selectedCategory();

        if (! $category) {
            Notification::make()->danger()->title('Оберіть категорію')->send();

            return;
        }

        $baseFilters = $this->normalizedSelection($this->baseFilters, array_keys(self::BASE_FILTERS));

        if ($baseFilters === []) {
            Notification::make()
                ->warning()
                ->title('Залиште хоча б один основний фільтр')
                ->body('Порожній список у цій моделі означає успадкування фільтрів від батьківської категорії.')
                ->send();

            return;
        }

        $settings = $this->currentFilterSettings($category, $baseFilters);

        $category->forceFill($settings)->save();

        $updatedChildren = $this->applyToChildrenOnSave
            ? $this->updateDescendants($category, $settings)
            : 0;

        app(CatalogCacheStore::class)->invalidate();

        Notification::make()
            ->success()
            ->title('Фільтри категорії збережено')
            ->body($updatedChildren > 0
                ? 'Оновлено дочірніх категорій: '.$updatedChildren.'. Кеш каталогу скинуто.'
                : 'Кеш каталогу скинуто, зміни підхопляться на сайті.')
            ->send();

        $this->loadCategoryFilters();
    }

    public function autoDisableSparseFilters(): void
    {
        $threshold = max(1, $this->minimumFilterProducts);
        $baseFilters = collect($this->availableBaseFilters)
            ->filter(fn (array $filter): bool => (int) ($filter['count'] ?? 0) >= $threshold)
            ->keys()
            ->all();

        if ($baseFilters === []) {
            Notification::make()
                ->warning()
                ->title('Основні фільтри не змінено')
                ->body('Жоден основний фільтр не має достатньо товарів. Залишаю поточний набір, щоб категорія не успадкувала дефолтні фільтри.')
                ->send();

            return;
        }

        $this->baseFilters = $baseFilters;
        $this->specFilters = collect($this->availableSpecFilters)
            ->filter(fn (array $filter): bool => (int) ($filter['count'] ?? 0) >= $threshold)
            ->keys()
            ->all();

        $this->save();
    }

    public function autoDisableLatinNamedSpecFilters(): void
    {
        $disabled = collect($this->specFilters)
            ->filter(fn (string $key): bool => $this->isLatinOnlyFilterKey($key))
            ->values()
            ->all();

        $this->specFilters = collect($this->specFilters)
            ->reject(fn (string $key): bool => $this->isLatinOnlyFilterKey($key))
            ->values()
            ->all();

        $this->save();

        if ($disabled !== []) {
            Notification::make()
                ->success()
                ->title('Фільтри з латинськими ключами вимкнено')
                ->body('Вимкнено: '.implode(', ', $disabled))
                ->send();
        }
    }

    public function applyToChildren(): void
    {
        $category = $this->selectedCategory();

        if (! $category) {
            Notification::make()->danger()->title('Оберіть категорію')->send();

            return;
        }

        $baseFilters = $this->normalizedSelection($this->baseFilters, array_keys(self::BASE_FILTERS));

        if ($baseFilters === []) {
            Notification::make()
                ->warning()
                ->title('Залиште хоча б один основний фільтр')
                ->body('Не можу застосувати до дочірніх категорій порожній список основних фільтрів.')
                ->send();

            return;
        }

        $settings = $this->currentFilterSettings($category, $baseFilters);
        $updatedChildren = $this->updateDescendants($category, $settings);

        if ($updatedChildren === 0) {
            Notification::make()
                ->warning()
                ->title('Немає дочірніх категорій')
                ->send();

            return;
        }

        app(CatalogCacheStore::class)->invalidate();

        Notification::make()
            ->success()
            ->title('Фільтри застосовано до дочірніх категорій')
            ->body('Оновлено категорій: '.$updatedChildren.'. Кеш каталогу скинуто.')
            ->send();
    }

    public function getTitle(): string
    {
        return 'Фільтри категорій';
    }

    private function loadCategoryFilters(): void
    {
        $category = $this->selectedCategory();

        if (! $category) {
            $this->categoryName = null;
            $this->settingsMode = null;
            $this->baseFilters = [];
            $this->specFilters = [];
            $this->availableBaseFilters = [];
            $this->availableSpecFilters = [];
            $this->filterLabelInputs = [];

            return;
        }

        $this->categoryName = $category->breadcrumbTrail()
            ->map(fn (Category $item): string => (string) $item->getRawOriginal('name'))
            ->implode(' / ');
        $this->settingsMode = $this->settingsModeLabel($category);
        $this->availableBaseFilters = $this->baseFilterAvailability($category);
        $this->availableSpecFilters = $this->specFilterAvailability($category);
        $this->baseFilters = $this->selectedBaseFilters($category);
        $this->specFilters = $this->selectedSpecFilters($category);
        $this->filterLabelInputs = $this->filterLabelInputs($category);
    }

    private function selectedCategory(): ?Category
    {
        if ($this->categoryId === null) {
            return null;
        }

        return Category::query()
            ->with(['childrenRecursiveAll', 'parentRecursive'])
            ->find($this->categoryId);
    }

    /**
     * @param  list<string>  $baseFilters
     * @return array<string, mixed>
     */
    private function currentFilterSettings(Category $category, array $baseFilters): array
    {
        $knownSpecKeys = array_unique([
            ...array_keys($this->availableSpecFilters),
            ...(array) $category->visible_spec_filters,
        ]);
        $specFilters = $this->normalizedSelection($this->specFilters, $knownSpecKeys);

        return [
            'visible_filters' => $baseFilters,
            'visible_spec_filters' => $specFilters,
            'visible_spec_filters_ru' => $specFilters,
            'filter_labels' => $this->normalizedFilterLabels('uk'),
            'filter_labels_ru' => $this->normalizedFilterLabels('ru'),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function updateDescendants(Category $category, array $settings): int
    {
        $category->loadMissing('childrenRecursiveAll');
        $descendantIds = $category->allDescendantIds();

        if ($descendantIds === []) {
            return 0;
        }

        Category::query()
            ->whereIn('id', $descendantIds)
            ->update([...$settings, 'updated_at' => now()]);

        return count($descendantIds);
    }

    /**
     * @return list<string>
     */
    private function selectedBaseFilters(Category $category): array
    {
        $filters = $category->visible_filters;

        if ($filters === null || $filters === []) {
            $filters = $category->resolvedVisibleFilters();
        }

        return $this->normalizedSelection((array) $filters, array_keys(self::BASE_FILTERS));
    }

    /**
     * @return list<string>
     */
    private function selectedSpecFilters(Category $category): array
    {
        $resolvedFilters = $category->resolvedVisibleSpecFilters();

        if ($resolvedFilters !== null) {
            $countedSpecFilters = collect($this->availableSpecFilters)
                ->filter(fn (array $filter): bool => $filter['count'] !== null)
                ->keys()
                ->all();

            return $this->normalizedSelection((array) $resolvedFilters, $countedSpecFilters);
        }

        return array_keys($this->availableSpecFilters);
    }

    private function settingsModeLabel(Category $category): string
    {
        $parts = [];
        $parts[] = ($category->visible_filters === null || $category->visible_filters === [])
            ? 'основні успадковані'
            : 'основні налаштовані';
        $parts[] = $category->visible_spec_filters === null
            ? 'характеристики авто/успадковані'
            : 'характеристики налаштовані';

        return implode(', ', $parts);
    }

    /**
     * @return array<string, array{label: string, count: int|null, label_input_id: string}>
     */
    private function baseFilterAvailability(Category $category): array
    {
        $query = Product::query()
            ->whereIn('category_id', $this->categoryScopeIds($category));

        $availability = [
            'sale' => (clone $query)->onSale()->count(),
            'brand' => $this->filledProductCount(clone $query, 'brand'),
            'model' => $this->filledProductCount(clone $query, 'model'),
            'price' => (clone $query)->where('price', '>', 0)->count(),
            'season' => $this->filledProductCount(clone $query, 'season'),
            'usage_type' => $this->filledProductCount(clone $query, 'usage_type'),
            'material' => $this->filledProductCount(clone $query, 'material'),
            'weight' => (clone $query)->whereNotNull('weight_grams')->where('weight_grams', '>', 0)->count(),
            'stock' => (clone $query)->where('stock', '<=', 0)->count(),
        ];

        return collect(self::BASE_FILTERS)
            ->map(fn (string $label, string $key): array => [
                'label' => $label,
                'count' => (int) ($availability[$key] ?? 0),
                'label_input_id' => $this->filterLabelInputId($key),
            ])
            ->all();
    }

    /**
     * @return array<string, array{label: string, count: int|null, label_input_id: string}>
     */
    private function specFilterAvailability(Category $category): array
    {
        $facets = app(CatalogSpecificationFacets::class);
        $counts = $facets->availableFilterCounts($category, includeInactive: true);

        foreach ((array) $category->resolvedVisibleSpecFilters() as $key) {
            $key = trim((string) $key);

            if ($key !== '' && $facets->isFilterableKey($key)) {
                $counts[$key] ??= null;
            }
        }

        ksort($counts, SORT_NATURAL | SORT_FLAG_CASE);

        return collect($counts)
            ->map(fn (?int $count, string $key): array => [
                'label' => $key,
                'count' => $count,
                'label_input_id' => $this->filterLabelInputId($key),
            ])
            ->all();
    }

    /**
     * @return array<string, array{key: string, uk: string, ru: string, placeholder_uk: string, placeholder_ru: string}>
     */
    private function filterLabelInputs(Category $category): array
    {
        $keys = array_values(array_unique([
            ...array_keys($this->availableBaseFilters),
            ...array_keys($this->availableSpecFilters),
        ]));
        $storedUk = (array) $category->filter_labels;
        $storedRu = (array) $category->filter_labels_ru;

        return collect($keys)
            ->mapWithKeys(fn (string $key): array => [
                $this->filterLabelInputId($key) => [
                    'key' => $key,
                    'uk' => (string) ($storedUk[$key] ?? ''),
                    'ru' => (string) ($storedRu[$key] ?? ''),
                    'placeholder_uk' => $category->filterLabel($key, $this->defaultFilterLabel($key), false),
                    'placeholder_ru' => $category->filterLabel($key, $this->defaultFilterLabel($key), true),
                ],
            ])
            ->all();
    }

    private function filterLabelInputId(string $key): string
    {
        return sha1($key);
    }

    private function defaultFilterLabel(string $key): string
    {
        return self::BASE_FILTERS[$key] ?? $key;
    }

    private function isLatinOnlyFilterKey(string $key): bool
    {
        return preg_match('/[A-Za-z]/', $key) === 1
            && preg_match('/\p{Cyrillic}/u', $key) !== 1;
    }

    /**
     * @return array<string, string>|null
     */
    private function normalizedFilterLabels(string $locale): ?array
    {
        $labels = collect($this->filterLabelInputs)
            ->mapWithKeys(function (array $input) use ($locale): array {
                $key = trim((string) ($input['key'] ?? ''));
                $label = trim((string) ($input[$locale] ?? ''));

                return $key !== '' && $label !== '' ? [$key => $label] : [];
            })
            ->all();

        return $labels === [] ? null : $labels;
    }

    /**
     * @return list<int>
     */
    private function categoryScopeIds(Category $category): array
    {
        $category->loadMissing('childrenRecursiveAll');

        return [$category->id, ...$category->allDescendantIds()];
    }

    private function filledProductCount(Builder $query, string $column): int
    {
        return $query
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->count();
    }

    /**
     * @param  list<string>  $selected
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function normalizedSelection(array $selected, array $allowed): array
    {
        return collect($selected)
            ->map(fn ($key): string => trim((string) $key))
            ->filter(fn (string $key): bool => $key !== '' && in_array($key, $allowed, true))
            ->unique()
            ->values()
            ->all();
    }
}
