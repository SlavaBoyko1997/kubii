<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FeedCategoryAssignment
{
    /**
     * @param  list<int>  $feedCategoryIds
     */
    public function rememberMappings(array $feedCategoryIds, int $targetCategoryId): void
    {
        $feedCategoryIds = collect($feedCategoryIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($feedCategoryIds === []) {
            return;
        }

        Category::query()
            ->whereIn('id', $feedCategoryIds)
            ->whereNotNull('counterparty_id')
            ->update([
                'target_category_id' => $targetCategoryId,
                'updated_at' => now(),
            ]);
    }

    public function defaultCategoryId(?int $feedCategoryId, ?Category $feedCategory = null): ?int
    {
        if ($feedCategoryId === null) {
            return null;
        }

        $currentId = $feedCategoryId;
        $feedCategoryForId = $feedCategory;
        $visited = [];
        $guard = 0;

        while ($currentId !== null && $guard < 50) {
            $guard++;

            if (isset($visited[$currentId])) {
                break;
            }

            $visited[$currentId] = true;

            $feedCategoryForId ??= Category::query()->find($currentId);

            if ($feedCategoryForId?->target_category_id) {
                return (int) $feedCategoryForId->target_category_id;
            }

            $parentId = $feedCategoryForId?->parent_id;
            $currentId = $parentId !== null ? (int) $parentId : null;
            $feedCategoryForId = null;
        }

        return $feedCategoryId;
    }

    public function shouldPreserveCategory(object $existing, ?int $feedCategoryId, ?Category $feedCategory = null): bool
    {
        if ((bool) ($existing->is_processed ?? false)) {
            return true;
        }

        if ($existing->category_id === null) {
            return false;
        }

        $assignedCategoryId = (int) $existing->category_id;
        $defaultCategoryId = (int) ($this->defaultCategoryId($feedCategoryId, $feedCategory) ?? 0);

        if ($defaultCategoryId === 0) {
            return false;
        }

        return $assignedCategoryId !== $feedCategoryId
            && $assignedCategoryId !== $defaultCategoryId;
    }

    /**
     * @return array<int, string>
     */
    public function feedCategoryFilterOptions(): array
    {
        $categoryIds = Product::query()
            ->whereNotNull('source_feed_category_id')
            ->distinct()
            ->pluck('source_feed_category_id')
            ->map(fn ($id): int => (int) $id);

        if ($categoryIds->isEmpty()) {
            return [];
        }

        $builder = app(CategoryTreeBuilder::class);

        return collect($builder->options())
            ->only($categoryIds->all())
            ->all();
    }

    /**
     * @return list<int>
     */
    public function feedCategoryIdsFromQuery(Builder $query): array
    {
        $fromSource = (clone $query)
            ->reorder()
            ->whereNotNull('source_feed_category_id')
            ->distinct()
            ->pluck('source_feed_category_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($fromSource !== []) {
            return $fromSource;
        }

        return (clone $query)
            ->reorder()
            ->whereNotNull('category_id')
            ->distinct()
            ->pluck('category_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(function (int $categoryId): bool {
                $category = Category::query()->find($categoryId);

                return $category !== null && $category->isFeedCategory();
            })
            ->unique()
            ->values()
            ->all();
    }

    public function inferFeedCategoryIdsFromProducts(Collection $products): array
    {
        $ids = $products
            ->pluck('source_feed_category_id')
            ->map(fn ($id): ?int => $id ? (int) $id : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids !== []) {
            return $ids;
        }

        return $products
            ->pluck('category_id')
            ->map(fn ($id): ?int => $id ? (int) $id : null)
            ->filter()
            ->unique()
            ->filter(function (int $categoryId): bool {
                $category = Category::query()->find($categoryId);

                return $category !== null && $category->counterparty_id !== null;
            })
            ->values()
            ->all();
    }
}
