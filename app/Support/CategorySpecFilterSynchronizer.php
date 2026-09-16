<?php

namespace App\Support;

use App\Models\Category;
use App\Services\CatalogSpecificationFacets;
use Illuminate\Support\Collection;

class CategorySpecFilterSynchronizer
{
    public function __construct(
        private readonly CatalogSpecificationFacets $facets,
        private readonly CatalogCache $cache,
    ) {}

    public function syncCategory(Category $category, bool $includeInactive = true): bool
    {
        $availableKeys = array_keys($this->facets->availableFilterCounts(
            $category,
            includeInactive: $includeInactive,
            requireCatalogVisibility: ! $includeInactive,
        ));

        if ($availableKeys === []) {
            return false;
        }

        $current = $category->visible_spec_filters;

        if ($current === []) {
            return false;
        }

        if ($current === null) {
            $category->update(['visible_spec_filters' => $availableKeys]);

            return true;
        }

        return false;
    }

    /**
     * @param  iterable<Category|int>  $categories
     */
    public function syncCategories(iterable $categories, bool $includeInactive = true): int
    {
        $updated = 0;

        foreach ($this->resolveCategories($categories) as $category) {
            if ($this->syncCategory($category, $includeInactive)) {
                $updated++;
            }
        }

        if ($updated > 0) {
            $this->cache->invalidate();
        }

        return $updated;
    }

    /**
     * @param  list<int>  $categoryIds
     */
    public function syncByCategoryIds(array $categoryIds, bool $includeInactive = true): int
    {
        $categoryIds = collect($categoryIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($categoryIds === []) {
            return 0;
        }

        return $this->syncCategories(
            Category::query()->whereIn('id', $categoryIds)->get(),
            $includeInactive,
        );
    }

    public function syncAll(bool $includeInactive = false): int
    {
        $updated = 0;

        Category::query()
            ->orderBy('id')
            ->chunkById(100, function (Collection $categories) use (&$updated, $includeInactive): void {
                foreach ($categories as $category) {
                    if ($this->syncCategory($category, $includeInactive)) {
                        $updated++;
                    }
                }
            });

        if ($updated > 0) {
            $this->cache->invalidate();
        }

        return $updated;
    }

    /**
     * @param  iterable<Category|int>  $categories
     * @return Collection<int, Category>
     */
    private function resolveCategories(iterable $categories): Collection
    {
        $models = collect($categories)
            ->map(function (Category|int $category): ?Category {
                if ($category instanceof Category) {
                    return $category;
                }

                return Category::query()->find((int) $category);
            })
            ->filter()
            ->unique(fn (Category $category): int => $category->id)
            ->values();

        if ($models->isEmpty()) {
            return collect();
        }

        return Category::query()
            ->whereIn('id', $models->pluck('id'))
            ->get()
            ->keyBy('id')
            ->pipe(fn (Collection $indexed): Collection => $models
                ->map(fn (Category $category): ?Category => $indexed->get($category->id))
                ->filter()
                ->values());
    }
}
