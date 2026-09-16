<?php

namespace App\Services;

use App\Models\Category;
use App\Models\CategorySeoGeneration;

class CategorySeoUniqueness
{
    public function duplicateMetaDescription(string $value, string $locale, int $categoryId, ?int $generationId = null): ?string
    {
        return $this->duplicateText(
            value: $value,
            locale: $locale,
            categoryId: $categoryId,
            generationId: $generationId,
            categoryField: 'meta_description',
            generationField: 'meta_description',
            statuses: ['generated', 'generated_with_warnings', 'approved', 'published'],
        );
    }

    public function duplicateSeoText(string $value, string $locale, int $categoryId, ?int $generationId = null): ?string
    {
        return $this->duplicateText(
            value: $value,
            locale: $locale,
            categoryId: $categoryId,
            generationId: $generationId,
            categoryField: 'description',
            generationField: 'seo_text',
            statuses: ['generated', 'generated_with_warnings', 'approved', 'published'],
        );
    }

    /**
     * @param  list<string>  $statuses
     */
    private function duplicateText(
        string $value,
        string $locale,
        int $categoryId,
        ?int $generationId,
        string $categoryField,
        string $generationField,
        array $statuses,
    ): ?string {
        $normalized = $this->normalize($value);

        if ($normalized === '') {
            return null;
        }

        $categoryColumn = $locale === 'ru' ? $categoryField.'_ru' : $categoryField;
        $categoryDuplicate = Category::query()
            ->whereKeyNot($categoryId)
            ->whereNotNull($categoryColumn)
            ->get(['id', 'name', $categoryColumn])
            ->first(fn (Category $category): bool => $this->normalize((string) $category->{$categoryColumn}) === $normalized);

        if ($categoryDuplicate) {
            return 'Дубль з категорією ID '.$categoryDuplicate->id.' — '.$categoryDuplicate->getRawOriginal('name');
        }

        $generationDuplicate = CategorySeoGeneration::query()
            ->where('locale', $locale)
            ->where('category_id', '!=', $categoryId)
            ->when($generationId, fn ($query) => $query->whereKeyNot($generationId))
            ->whereIn('status', $statuses)
            ->whereNotNull($generationField)
            ->latest()
            ->limit(500)
            ->get(['id', 'category_id', $generationField])
            ->first(fn (CategorySeoGeneration $generation): bool => $this->normalize((string) $generation->{$generationField}) === $normalized);

        if ($generationDuplicate) {
            return 'Дубль з AI-генерацією ID '.$generationDuplicate->id.' для категорії ID '.$generationDuplicate->category_id;
        }

        return null;
    }

    private function normalize(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = mb_strtolower($value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }
}
