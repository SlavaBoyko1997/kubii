<?php

namespace App\Filament\Resources\Categories\Concerns;

use App\Models\Category;
use App\Support\CategoryTreeBuilder;
use Filament\Forms\Components\Select;
use Illuminate\Support\Str;

trait InteractsWithCategoryForm
{
    /**
     * @return array<string, mixed>
     */
    public static function defaultCategoryFormData(?int $parentId = null): array
    {
        return [
            'parent_id' => $parentId,
            'is_active' => true,
            'sort_order' => static::nextCategorySortOrder($parentId),
            'visible_filters' => ['brand', 'model', 'price'],
        ];
    }

    public static function nextCategorySortOrder(?int $parentId = null): int
    {
        $max = Category::query()
            ->where('parent_id', $parentId)
            ->max('sort_order');

        return ((int) $max) + 1;
    }

    public static function generateUniqueCategorySlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name, '-', 'uk') ?: Str::slug($name) ?: 'category';
        $base = Str::limit($base, 220, '');
        $slug = $base;
        $suffix = 2;

        while (Category::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = Str::limit($base, 220 - strlen((string) $suffix), '').'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    public static function parentCategorySelect(): Select
    {
        return Select::make('parent_id')
            ->label('Батьківська категорія')
            ->placeholder('Коренева категорія (верхній рівень)')
            ->options(fn (?Category $record): array => app(CategoryTreeBuilder::class)->optionsForParentSelect($record))
            ->searchable()
            ->preload()
            ->native(false)
            ->helperText('Залиште порожнім, якщо це головний напрямок каталогу.');
    }
}
