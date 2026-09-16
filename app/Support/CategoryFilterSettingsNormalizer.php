<?php

namespace App\Support;

use App\Models\Category;

class CategoryFilterSettingsNormalizer
{
    public function normalizeStoredSettings(): int
    {
        $updated = 0;

        Category::query()
            ->select(['id', 'visible_filters'])
            ->orderBy('id')
            ->each(function (Category $category) use (&$updated): void {
                $changes = [];

                if ($category->visible_filters === []) {
                    $changes['visible_filters'] = null;
                }

                if ($changes === []) {
                    return;
                }

                $category->updateQuietly($changes);
                $updated++;
            });

        return $updated;
    }
}
