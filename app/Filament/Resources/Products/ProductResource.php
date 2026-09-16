<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Concerns\InteractsWithProductForm;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Models\Category;
use App\Models\Product;
use App\Support\CatalogCache;
use App\Support\CategorySpecFilterSynchronizer;
use App\Support\CategoryTreeBuilder;
use App\Support\FeedCategoryAssignment;
use BackedEnum;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductResource extends Resource
{
    use InteractsWithProductForm;

    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $navigationLabel = 'Товари';

    protected static ?string $modelLabel = 'товар';

    protected static ?string $pluralModelLabel = 'товари';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Product::query()->where('is_processed', false)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function categorySelect(string $field = 'category_id'): Select
    {
        return Select::make($field)
            ->label('Категорія')
            ->searchable()
            ->required(fn (?Product $record): bool => $record === null || (bool) $record->is_processed)
            ->native(false)
            ->preload()
            ->options(fn (): array => app(CategoryTreeBuilder::class)->options())
            ->getSearchResultsUsing(fn (string $search): array => Category::query()
                ->where('is_active', true)
                ->where(function ($query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('name_ru', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                })
                ->with('parentRecursive')
                ->limit(50)
                ->get()
                ->mapWithKeys(fn (Category $category): array => [
                    $category->id => static::categoryLabel($category),
                ])
                ->all())
            ->getOptionLabelUsing(function ($value): ?string {
                $category = Category::query()->with('parentRecursive')->find($value);

                return $category ? static::categoryLabel($category) : null;
            })
            ->helperText('Оберіть найточнішу підкатегорію — від неї залежать фільтри в каталозі.');
    }

    public static function brandField(string $field = 'brand'): TextInput
    {
        return TextInput::make($field)
            ->label('Бренд')
            ->maxLength(120)
            ->datalist(fn (): array => static::existingBrands());
    }

    public static function modelField(string $field = 'model'): TextInput
    {
        return TextInput::make($field)
            ->label('Модель')
            ->maxLength(120)
            ->datalist(fn (): array => static::existingModels());
    }

    /**
     * @return array<int, Component>
     */
    public static function catalogAssignmentSchema(bool $categoryRequired = false): array
    {
        return [
            static::categorySelect()
                ->required($categoryRequired),
            static::brandField(),
            static::modelField(),
        ];
    }

    /**
     * @param  array{category_id?: int|null, brand?: ?string, model?: ?string}  $attributes
     */
    public static function assignCatalogAttributesQuery(Builder $query, array $attributes, bool $allowClear = false): int
    {
        $updates = [];

        if (array_key_exists('category_id', $attributes) && $attributes['category_id'] !== null) {
            $updates['category_id'] = $attributes['category_id'];
            $updates['is_processed'] = true;
        }

        if (array_key_exists('brand', $attributes)) {
            if (filled($attributes['brand'])) {
                $updates['brand'] = trim((string) $attributes['brand']);
            } elseif ($allowClear) {
                $updates['brand'] = null;
            }
        }

        if (array_key_exists('model', $attributes)) {
            if (filled($attributes['model'])) {
                $updates['model'] = trim((string) $attributes['model']);
            } elseif ($allowClear) {
                $updates['model'] = null;
            }
        }

        if ($updates === []) {
            return 0;
        }

        $updates['updated_at'] = now();

        $updated = (clone $query)
            ->reorder()
            ->update($updates);

        if ($updated > 0) {
            app(CatalogCache::class)->invalidate();

            if (array_key_exists('category_id', $attributes) && $attributes['category_id'] !== null) {
                app(CategorySpecFilterSynchronizer::class)->syncByCategoryIds([(int) $attributes['category_id']]);
            }
        }

        return $updated;
    }

    /**
     * @return array<int, string>
     */
    public static function feedCategoryFilterOptions(): array
    {
        return app(FeedCategoryAssignment::class)->feedCategoryFilterOptions();
    }

    public static function moveToCategoryQuery(
        Builder $query,
        int $categoryId,
        bool $rememberFeedCategoryMapping = true,
    ): int {
        $assignment = app(FeedCategoryAssignment::class);
        $feedCategoryIds = $assignment->feedCategoryIdsFromQuery($query);

        $updated = (clone $query)
            ->reorder()
            ->update([
                'category_id' => $categoryId,
                'is_processed' => true,
                'updated_at' => now(),
            ]);

        if ($updated > 0 && $rememberFeedCategoryMapping) {
            $assignment->rememberMappings($feedCategoryIds, $categoryId);
        }

        if ($updated > 0) {
            app(CategorySpecFilterSynchronizer::class)->syncByCategoryIds([$categoryId]);
            app(CatalogCache::class)->invalidate();
        }

        return $updated;
    }

    /**
     * @return array<int, string>
     */
    public static function existingBrands(): array
    {
        return Product::query()
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->distinct()
            ->orderBy('brand')
            ->pluck('brand')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function existingModels(): array
    {
        return Product::query()
            ->whereNotNull('model')
            ->where('model', '!=', '')
            ->distinct()
            ->orderBy('model')
            ->pluck('model')
            ->all();
    }

    private static function categoryLabel(Category $category): string
    {
        return $category->breadcrumbTrail()
            ->map(fn (Category $item): string => $item->getRawOriginal('name'))
            ->implode(' → ');
    }

    /**
     * @return array<int, string>
     */
    public static function productCategoryFilterOptions(?string $tab = 'all'): array
    {
        $query = Product::query()->whereNotNull('category_id');

        match ($tab) {
            'unprocessed' => $query->where('is_processed', false),
            'processed' => $query->where('is_processed', true),
            'catalog_issues' => $query->catalogPlacementIssues(),
            default => null,
        };

        $categoryIds = $query
            ->distinct()
            ->pluck('category_id')
            ->map(fn ($id): int => (int) $id);

        if ($categoryIds->isEmpty()) {
            return [];
        }

        return collect(app(CategoryTreeBuilder::class)->options())
            ->only($categoryIds->all())
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function unprocessedCategoryOptions(): array
    {
        return static::productCategoryFilterOptions('unprocessed');
    }

    public static function activateUnprocessedQuery(Builder $query): int
    {
        return static::activateQuery($query, onlyUnprocessed: true);
    }

    public static function resolveBulkActionQuery(HasTable $livewire, Builder $recordsQuery): Builder
    {
        if ($livewire->isTrackingDeselectedTableRecords) {
            return $recordsQuery;
        }

        $selectedCount = count($livewire->selectedTableRecords);

        if ($selectedCount === 0) {
            return $recordsQuery->whereRaw('1 = 0');
        }

        $allCount = $livewire->getAllSelectableTableRecordsCount();

        if ($selectedCount >= $allCount) {
            return $livewire->getFilteredTableQuery() ?? $recordsQuery;
        }

        return $recordsQuery;
    }

    public static function activateQuery(Builder $query, bool $onlyUnprocessed = false): int
    {
        @set_time_limit(0);

        $scoped = (clone $query)->whereNotNull('category_id');

        if ($onlyUnprocessed) {
            $scoped->where('is_processed', false);
        }

        $categoryIds = collect();

        if ($onlyUnprocessed) {
            $categoryIds = (clone $scoped)
                ->reorder()
                ->distinct()
                ->pluck('category_id');
        }

        $updated = (clone $scoped)
            ->reorder()
            ->update([
                'is_processed' => true,
                'is_active' => true,
                'updated_at' => now(),
            ]);

        if ($onlyUnprocessed && $categoryIds->isNotEmpty()) {
            Category::query()
                ->whereKey($categoryIds)
                ->where('is_active', false)
                ->update(['is_active' => true, 'updated_at' => now()]);
        }

        if ($updated > 0) {
            app(CatalogCache::class)->invalidate();
        }

        return $updated;
    }

    public static function deactivateQuery(Builder $query): int
    {
        @set_time_limit(0);

        $updated = (clone $query)
            ->reorder()
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            app(CatalogCache::class)->invalidate();
        }

        return $updated;
    }

    public static function deactivateUnprocessedQuery(Builder $query): int
    {
        @set_time_limit(0);

        $updated = (clone $query)
            ->reorder()
            ->where('is_processed', false)
            ->update([
                'is_processed' => true,
                'is_active' => false,
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            app(CatalogCache::class)->invalidate();
        }

        return $updated;
    }

    public static function deleteUnprocessedQuery(Builder $query): int
    {
        @set_time_limit(0);

        $deleted = (clone $query)
            ->reorder()
            ->where('is_processed', false)
            ->delete();

        if ($deleted > 0) {
            app(CatalogCache::class)->invalidate();
        }

        return $deleted;
    }

    public static function productsInCategoryQuery(int $categoryId, bool $includeDescendants = true): Builder
    {
        if (! $includeDescendants) {
            return Product::query()->where('category_id', $categoryId);
        }

        $category = Category::query()
            ->with('childrenRecursive')
            ->find($categoryId);

        if (! $category) {
            return Product::query()->whereRaw('1 = 0');
        }

        return Product::query()->whereIn('category_id', [
            $category->id,
            ...$category->descendantIds(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    public static function activeProductCategoryOptions(): array
    {
        $categoryIds = Product::query()
            ->where('is_active', true)
            ->whereNotNull('category_id')
            ->distinct()
            ->pluck('category_id')
            ->map(fn ($id): int => (int) $id);

        if ($categoryIds->isEmpty()) {
            return [];
        }

        return collect(app(CategoryTreeBuilder::class)->options())
            ->only($categoryIds->all())
            ->all();
    }
}
