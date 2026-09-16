<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Models\Category;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    protected function fillForm(): void
    {
        parent::fillForm();

        $parentId = request()->integer('parent_id') ?: null;

        $this->form->fill(CategoryResource::defaultCategoryFormData($parentId));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['sort_order'] ??= CategoryResource::nextCategorySortOrder($data['parent_id'] ?? null);
        $data['is_active'] ??= true;
        $data['visible_filters'] ??= ['brand', 'model', 'price'];

        if (blank($data['slug'] ?? null) && filled($data['name'] ?? null)) {
            $data['slug'] = CategoryResource::generateUniqueCategorySlug((string) $data['name']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function preserveFormDataWhenCreatingAnother(array $data): array
    {
        return Arr::only($data, ['parent_id', 'is_active', 'visible_filters']);
    }

    protected function getRedirectUrl(): string
    {
        return CategoryResource::getUrl('tree');
    }

    public function getSubheading(): ?string
    {
        $parentId = request()->integer('parent_id') ?: null;

        if (! $parentId) {
            return 'Створюється коренева категорія верхнього рівня.';
        }

        $parent = Category::query()->find($parentId);

        return $parent
            ? 'Підкатегорія для: '.$parent->getRawOriginal('name')
            : null;
    }
}
