<?php

namespace App\Support;

use App\Models\Category;
use Illuminate\Support\Collection;

class CategoryTreeBuilder
{
    public function nested(bool $includeInactive = true, bool $adminTreeOnly = true): array
    {
        $categories = Category::query()
            ->when($adminTreeOnly, fn ($query) => $query->visibleInAdminTree())
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $ids = $categories->pluck('id')->flip();

        // If a parent is filtered out (e.g. mapped feed category), promote orphans to root
        // so they still appear in selects.
        $children = $categories->groupBy(function (Category $category) use ($ids): string {
            $parentId = $category->parent_id;

            if ($parentId !== null && ! $ids->has($parentId)) {
                return '';
            }

            return (string) ($parentId ?? '');
        });

        $build = function (?int $parentId = null) use (&$build, $children): array {
            return $this->sortCategoriesForTree($children->get((string) ($parentId ?? ''), collect()))
                ->map(fn (Category $category): array => [
                    'category' => $category,
                    'children' => $build($category->id),
                ])
                ->all();
        };

        return $build();
    }

    public function nestedTrashed(): array
    {
        $categories = Category::onlyTrashed()
            ->visibleInAdminTree()
            ->withCount('products')
            ->orderByDesc('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $trashedIds = $categories->pluck('id')->flip();

        $children = $categories->groupBy(function (Category $category) use ($trashedIds): string {
            $parentId = $category->parent_id;

            if ($parentId !== null && $trashedIds->has($parentId)) {
                return (string) $parentId;
            }

            return '';
        });

        $build = function (?int $parentId = null) use (&$build, $children): array {
            return $this->sortCategoriesForTree($children->get((string) ($parentId ?? ''), collect()))
                ->map(fn (Category $category): array => [
                    'category' => $category,
                    'children' => $build($category->id),
                ])
                ->all();
        };

        return $build();
    }

    public function trashedCount(): int
    {
        return Category::onlyTrashed()->visibleInAdminTree()->count();
    }

    public function trashCategory(int $categoryId): int
    {
        Category::query()->findOrFail($categoryId);

        $ids = [$categoryId, ...$this->allDescendantIds($categoryId)];

        return Category::query()->whereIn('id', $ids)->delete();
    }

    public function restoreCategory(int $categoryId): int
    {
        $category = Category::onlyTrashed()->findOrFail($categoryId);
        $ids = collect([$categoryId, ...$this->allTrashedDescendantIds($categoryId)]);

        $current = $category;

        while ($current->parent_id !== null) {
            $parent = Category::withTrashed()->find($current->parent_id);

            if ($parent === null) {
                break;
            }

            if ($parent->trashed()) {
                $ids->prepend($parent->id);
            }

            $current = $parent;
        }

        return Category::onlyTrashed()
            ->whereIn('id', $ids->unique()->values()->all())
            ->restore();
    }

    public function options(bool $adminTreeOnly = true): array
    {
        $options = [];

        $walk = function (array $nodes, int $depth = 0) use (&$walk, &$options): void {
            foreach ($nodes as $node) {
                /** @var Category $category */
                $category = $node['category'];
                $prefix = $depth > 0 ? str_repeat(' ', $depth).'↳ ' : '';
                $suffix = $category->is_active ? '' : ' (неактивна)';
                $options[$category->id] = $prefix.$category->getRawOriginal('name').$suffix;

                if ($node['children'] !== []) {
                    $walk($node['children'], $depth + 1);
                }
            }
        };

        $walk($this->nested(includeInactive: true, adminTreeOnly: $adminTreeOnly));

        return $options;
    }

    /**
     * Categories that can appear in the storefront catalog: active and with
     * at least one public product in the category subtree.
     *
     * @return array<int, string>
     */
    public function storefrontOptions(): array
    {
        $counts = app(CatalogCache::class)->categoryProductCounts();
        $categories = Category::query()
            ->where('is_active', true)
            ->where('name', '!=', 'ІБІС Зброя')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->filter(fn (Category $category): bool => ($counts[$category->id] ?? 0) > 0)
            ->values();

        $ids = $categories->pluck('id')->flip();
        $children = $categories->groupBy(function (Category $category) use ($ids): string {
            $parentId = $category->parent_id;

            if ($parentId !== null && ! $ids->has($parentId)) {
                return '';
            }

            return (string) ($parentId ?? '');
        });
        $options = [];

        $walk = function (?int $parentId = null, int $depth = 0) use (&$walk, &$options, $children): void {
            foreach ($this->sortCategoriesForTree($children->get((string) ($parentId ?? ''), collect())) as $category) {
                $prefix = $depth > 0 ? str_repeat(' ', $depth).'↳ ' : '';
                $options[$category->id] = $prefix.$category->getRawOriginal('name');

                $walk($category->id, $depth + 1);
            }
        };

        $walk();

        return $options;
    }

    /**
     * Full category list for variant grouping (includes feed / mapped categories).
     *
     * @return array<int, string>
     */
    public function optionsForGrouping(): array
    {
        $options = [];

        $walk = function (array $nodes, int $depth = 0) use (&$walk, &$options): void {
            foreach ($nodes as $node) {
                /** @var Category $category */
                $category = $node['category'];
                $prefix = $depth > 0 ? str_repeat(' ', $depth).'↳ ' : '';
                $options[$category->id] = $prefix.$this->groupingOptionLabel($category);

                if ($node['children'] !== []) {
                    $walk($node['children'], $depth + 1);
                }
            }
        };

        $walk($this->nested(includeInactive: true, adminTreeOnly: false));

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public function searchOptionsForGrouping(string $search, int $limit = 80): array
    {
        $search = trim($search);

        if ($search === '') {
            return [];
        }

        return Category::query()
            ->with(['parentRecursive'])
            ->withCount('products')
            ->where(function ($query) use ($search): void {
                $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('name_ru', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('external_id', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn (Category $category): array => [
                $category->id => $this->groupingOptionLabel($category, includePath: true),
            ])
            ->all();
    }

    public function groupingOptionLabel(Category $category, bool $includePath = false): string
    {
        $name = $includePath
            ? $category->breadcrumbTrail()->map(fn (Category $item): string => $item->getRawOriginal('name'))->implode(' / ')
            : $category->getRawOriginal('name');
        $marks = [];

        if (! $category->is_active) {
            $marks[] = 'неактивна';
        }

        if ($category->counterparty_id !== null) {
            $marks[] = 'фід';
        }

        if (($category->products_count ?? 0) > 0) {
            $marks[] = number_format($category->products_count).' тов.';
        }

        return $name.($marks !== [] ? ' ('.implode(', ', $marks).')' : '');
    }

    /**
     * @return array<int, string>
     */
    public function optionsForParentSelect(?Category $record = null): array
    {
        $options = $this->options();

        if (! $record) {
            return $options;
        }

        $exclude = collect([$record->id, ...$record->descendantIds()]);

        return collect($options)
            ->reject(fn (string $label, int|string $id): bool => $exclude->contains((int) $id))
            ->all();
    }

    public function allIds(): array
    {
        return Category::query()->orderBy('sort_order')->orderBy('name')->pluck('id')->all();
    }

    public function moveCategory(int $categoryId, ?int $newParentId): void
    {
        $category = Category::query()->findOrFail($categoryId);

        if ($newParentId === $categoryId) {
            throw new \InvalidArgumentException('Категорію не можна перемістити в саму себе.');
        }

        if ($newParentId !== null) {
            Category::query()->findOrFail($newParentId);

            $invalidParents = [$categoryId, ...$this->allDescendantIds($categoryId)];

            if (in_array($newParentId, $invalidParents, true)) {
                throw new \InvalidArgumentException('Категорію не можна перемістити в її підкатегорію.');
            }
        }

        if ($category->parent_id === $newParentId) {
            return;
        }

        $nextSortOrder = ((int) Category::query()
            ->where('parent_id', $newParentId)
            ->whereKeyNot($categoryId)
            ->max('sort_order')) + 1;

        $category->update([
            'parent_id' => $newParentId,
            'sort_order' => $nextSortOrder,
        ]);
    }

    public function reorderCategory(int $categoryId, int $anchorCategoryId, string $position): void
    {
        if (! in_array($position, ['before', 'after'], true)) {
            throw new \InvalidArgumentException('Невідома позиція сортування.');
        }

        if ($categoryId === $anchorCategoryId) {
            return;
        }

        $category = Category::query()->findOrFail($categoryId);
        $anchor = Category::query()->findOrFail($anchorCategoryId);
        $invalidAnchors = [$categoryId, ...$this->allDescendantIds($categoryId)];

        if (in_array($anchorCategoryId, $invalidAnchors, true)) {
            throw new \InvalidArgumentException('Категорію не можна перемістити в її підкатегорію.');
        }

        $newParentId = $anchor->parent_id;
        $siblings = Category::query()
            ->where('parent_id', $newParentId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->reject(fn (Category $sibling): bool => $sibling->is($category))
            ->values();

        $anchorIndex = $siblings->search(fn (Category $sibling): bool => $sibling->is($anchor));

        if ($anchorIndex === false) {
            throw new \InvalidArgumentException('Не вдалося знайти категорію для сортування.');
        }

        $insertAt = $position === 'after' ? $anchorIndex + 1 : $anchorIndex;
        $siblings->splice($insertAt, 0, [$category]);
        $category->update(['parent_id' => $newParentId]);

        $siblings->values()->each(function (Category $sibling, int $index): void {
            $sortOrder = $index + 1;

            if ((int) $sibling->sort_order === $sortOrder) {
                return;
            }

            $sibling->updateQuietly(['sort_order' => $sortOrder]);
        });
    }

    public function shiftCategory(int $categoryId, string $direction): void
    {
        if (! in_array($direction, ['up', 'down'], true)) {
            throw new \InvalidArgumentException('Невідомий напрямок сортування.');
        }

        $category = Category::query()->findOrFail($categoryId);
        $siblings = Category::query()
            ->where('parent_id', $category->parent_id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->values();

        $index = $siblings->search(fn (Category $sibling): bool => $sibling->is($category));

        if ($index === false) {
            throw new \InvalidArgumentException('Не вдалося знайти категорію для сортування.');
        }

        if ($direction === 'up') {
            if ($index === 0) {
                return;
            }

            $this->reorderCategory($categoryId, $siblings[$index - 1]->id, 'before');

            return;
        }

        if ($index >= $siblings->count() - 1) {
            return;
        }

        $this->reorderCategory($categoryId, $siblings[$index + 1]->id, 'after');
    }

    public function indentCategory(int $categoryId): void
    {
        $category = Category::query()->findOrFail($categoryId);
        $siblings = Category::query()
            ->where('parent_id', $category->parent_id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->values();

        $index = $siblings->search(fn (Category $sibling): bool => $sibling->is($category));

        if ($index === false || $index === 0) {
            return;
        }

        $this->moveCategory($categoryId, $siblings[$index - 1]->id);
    }

    public function outdentCategory(int $categoryId): void
    {
        $category = Category::query()->findOrFail($categoryId);

        if ($category->parent_id === null) {
            return;
        }

        $parent = Category::query()->findOrFail($category->parent_id);
        $this->reorderCategory($categoryId, $parent->id, 'after');
    }

    /**
     * @return array<int>
     */
    public function allDescendantIds(int $categoryId): array
    {
        $children = Category::query()
            ->where('parent_id', $categoryId)
            ->pluck('id');

        return $children
            ->flatMap(fn (int $childId): array => [$childId, ...$this->allDescendantIds($childId)])
            ->all();
    }

    /**
     * @return array<int>
     */
    public function allTrashedDescendantIds(int $categoryId): array
    {
        $children = Category::onlyTrashed()
            ->where('parent_id', $categoryId)
            ->pluck('id');

        return $children
            ->flatMap(fn (int $childId): array => [$childId, ...$this->allTrashedDescendantIds($childId)])
            ->all();
    }

    public function flatten(Collection $categories): Collection
    {
        $children = $categories->groupBy(fn (Category $category): string => (string) ($category->parent_id ?? ''));

        $walk = function (?int $parentId = null) use (&$walk, $children): Collection {
            return $children
                ->get((string) ($parentId ?? ''), collect())
                ->flatMap(fn (Category $category): Collection => collect([$category])->merge($walk($category->id)));
        };

        return $walk();
    }

    private function sortCategoriesForTree(Collection $categories): Collection
    {
        return $categories
            ->sort(function (Category $left, Category $right): int {
                $leftEmpty = (int) ($left->products_count ?? 0) === 0;
                $rightEmpty = (int) ($right->products_count ?? 0) === 0;

                if ($leftEmpty !== $rightEmpty) {
                    return $leftEmpty <=> $rightEmpty;
                }

                $sortOrder = (int) $left->sort_order <=> (int) $right->sort_order;

                if ($sortOrder !== 0) {
                    return $sortOrder;
                }

                return strnatcasecmp((string) $left->getRawOriginal('name'), (string) $right->getRawOriginal('name'));
            })
            ->values();
    }
}
