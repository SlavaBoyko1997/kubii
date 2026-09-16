<?php

namespace App\Support;

use App\Models\Category;
use App\Support\CatalogCache;

class CatalogMergedCategoryPruner
{
    /**
     * @return list<int> Deleted category ids (newest batch last).
     */
    public function prune(): array
    {
        $deleted = [];

        while (true) {
            $ids = Category::query()
                ->mergeSource()
                ->whereDoesntHave('products')
                ->whereDoesntHave('children')
                ->orderBy('id')
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            Category::query()->whereIn('id', $ids)->delete();
            $deleted = [...$deleted, ...$ids->all()];
        }

        app(CatalogCache::class)->invalidate();

        return $deleted;
    }

    /**
     * @return array{deleted: int, remaining_merge_sources: int}
     */
    public function status(): array
    {
        return [
            'deleted' => 0,
            'remaining_merge_sources' => Category::query()->mergeSource()->count(),
        ];
    }

    /**
     * @return list<int>
     */
    public function preview(): array
    {
        return Category::query()
            ->mergeSource()
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }
}
