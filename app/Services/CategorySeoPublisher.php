<?php

namespace App\Services;

use App\Models\CategorySeoGeneration;
use App\Support\CatalogCache;
use RuntimeException;

class CategorySeoPublisher
{
    public const MODE_MISSING = 'missing';
    public const MODE_SELECTED = 'selected';
    public const MODE_ALL = 'all';

    /**
     * @return list<string>
     */
    public function publish(CategorySeoGeneration $generation): array
    {
        $category = $generation->category;
        $locale = $generation->locale === 'ru' ? 'ru' : 'uk';
        $mode = (string) ($generation->overwrite_mode ?: self::MODE_MISSING);
        $fields = (array) ($generation->requested_fields ?: []);
        $updates = [];

        $this->put($updates, $category, $locale, 'seo_title', $generation->seo_title, $fields, $mode);
        $this->put($updates, $category, $locale, 'meta_description', $generation->meta_description, $fields, $mode);
        $this->put($updates, $category, $locale, 'h1', $generation->h1, $fields, $mode);
        $this->put($updates, $category, $locale, 'seo_intro', $generation->intro, $fields, $mode, 'intro');
        $this->put($updates, $category, $locale, 'description', $generation->seo_text, $fields, $mode, 'seo_text');
        $this->put($updates, $category, $locale, 'seo_faq', $generation->faq, $fields, $mode, 'faq');

        if ($updates === []) {
            return [];
        }

        $metaColumn = $locale === 'ru' ? 'meta_description_ru' : 'meta_description';
        if (array_key_exists($metaColumn, $updates)) {
            $duplicate = app(CategorySeoUniqueness::class)->duplicateMetaDescription((string) $updates[$metaColumn], $locale, (int) $category->id, (int) $generation->id);

            if ($duplicate) {
                throw new RuntimeException('Meta Description не унікальний: '.$duplicate.'.');
            }
        }

        $descriptionColumn = $locale === 'ru' ? 'description_ru' : 'description';
        if (array_key_exists($descriptionColumn, $updates)) {
            $duplicate = app(CategorySeoUniqueness::class)->duplicateSeoText((string) $updates[$descriptionColumn], $locale, (int) $category->id, (int) $generation->id);

            if ($duplicate) {
                throw new RuntimeException('SEO-текст не унікальний: '.$duplicate.'.');
            }
        }

        $updates['ai_seo_generated_at'] = now();
        $category->update($updates);
        $generation->update([
            'status' => 'published',
            'approved_at' => $generation->approved_at ?: now(),
            'published_at' => now(),
        ]);

        app(CatalogCache::class)->invalidate();

        return array_keys($updates);
    }

    /**
     * @param  array<string, mixed>  $updates
     * @param  list<string>  $requestedFields
     */
    private function put(
        array &$updates,
        mixed $category,
        string $locale,
        string $field,
        mixed $value,
        array $requestedFields,
        string $mode,
        ?string $requestField = null,
    ): void {
        $requestField ??= $field;

        if (! in_array($requestField, $requestedFields, true) || blank($value)) {
            return;
        }

        $column = $locale === 'ru' ? $field.'_ru' : $field;

        if ($mode === self::MODE_MISSING && filled($category->{$column})) {
            return;
        }

        $updates[$column] = $value;
    }
}
