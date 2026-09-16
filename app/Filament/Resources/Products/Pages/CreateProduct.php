<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Category;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function fillForm(): void
    {
        parent::fillForm();

        $categoryId = request()->integer('category_id') ?: null;

        $this->form->fill(ProductResource::defaultProductFormData($categoryId));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['stock'] ??= 1;
        $data['discount_percent'] ??= 0;
        $data['is_active'] ??= true;
        $data['is_processed'] ??= true;
        $data['is_featured'] ??= false;
        $data['is_indexable'] ??= true;
        $data['canonical_type'] ??= 'self';

        if (blank($data['slug'] ?? null) && filled($data['name'] ?? null)) {
            $data['slug'] = ProductResource::generateUniqueProductSlug((string) $data['name']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function preserveFormDataWhenCreatingAnother(array $data): array
    {
        return Arr::only($data, ['category_id', 'is_active', 'is_processed', 'is_featured']);
    }

    protected function getRedirectUrl(): string
    {
        return ProductResource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    public function getSubheading(): ?string
    {
        $categoryId = request()->integer('category_id') ?: null;

        if (! $categoryId) {
            return 'Після збереження відкриється повна форма — можна додати фото та опис.';
        }

        $category = Category::query()->with('parentRecursive')->find($categoryId);

        return $category
            ? 'Категорія: '.$category->breadcrumbTrail()->map(fn (Category $item): string => $item->getRawOriginal('name'))->implode(' → ')
            : null;
    }
}
