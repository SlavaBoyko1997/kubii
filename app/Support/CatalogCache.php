<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use App\Support\ProductBrand;
use Closure;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CatalogCache
{
    private const VERSION_KEY = 'catalog:version';

    private const DIRTY_KEY = 'catalog:dirty';

    private ?string $targetVersion = null;

    private ?string $previousVersion = null;

    public function menu(): array
    {
        return $this->remember('menu:v12', function (): array {
            $activeCategories = Category::query()
                ->where('is_active', true)
                ->where('name', '!=', 'ІБІС Зброя')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'parent_id', 'name', 'name_ru', 'slug', 'slug_ru', 'image_url', 'image_path']);

            $counts = $this->categoryProductCounts();
            $activeChildren = $activeCategories->groupBy(fn (Category $category): string => (string) ($category->parent_id ?? ''));
            $byId = $activeCategories->keyBy('id');

            $locale = Locale::current();
            $publicSlug = fn (Category $category): string => $category->publicSlug($locale);

            $categoryPath = function (Category $category) use (&$categoryPath, $byId, $publicSlug): string {
                $trail = [$category];
                $parentId = $category->parent_id;

                while ($parentId && $parent = $byId->get($parentId)) {
                    array_unshift($trail, $parent);
                    $parentId = $parent->parent_id;
                }

                $fullPrefix = '';
                $segments = collect($trail)->map(function (Category $category) use (&$fullPrefix, $publicSlug): string {
                    $slug = $publicSlug($category);
                    $segment = $fullPrefix !== '' && str_starts_with($slug, $fullPrefix.'-')
                        ? substr($slug, strlen($fullPrefix) + 1)
                        : $slug;
                    $fullPrefix = $slug;

                    return $segment ?: $slug;
                })->all();

                $shortSegments = count($segments) <= 1
                    ? $segments
                    : [$segments[0], $segments[array_key_last($segments)]];

                return Locale::prefixPath('/'.implode('/', $shortSegments).'/', Locale::current());
            };

            $categoryNode = function (Category $category) use (&$categoryNode, $activeChildren, $counts, $categoryPath): ?array {
                $productsCount = $counts[$category->id] ?? 0;

                if ($productsCount < 1) {
                    return null;
                }

                $subcategories = $activeChildren->get((string) $category->id, collect())
                    ->map(fn (Category $child): ?array => $categoryNode($child))
                    ->filter()
                    ->values();

                return [
                    'id' => $category->id,
                    'name' => $category->translated('name'),
                    'slug' => $category->translated('slug'),
                    'url' => $categoryPath($category),
                    'image_url' => $category->image_url,
                    'image_src' => $category->imageUrl(),
                    'products_count' => $productsCount,
                    'children_count' => $subcategories->count(),
                    'children' => $subcategories->take(6)->all(),
                    'all_children' => $subcategories->all(),
                ];
            };

            return $activeChildren->get('', collect())
                ->map(fn (Category $category): ?array => $categoryNode($category))
                ->filter()
                ->values()
                ->all();
        });
    }

    /**
     * @return list<array{name: string, url: string, image_src: ?string}>
     */
    public function homeRootCategories(): array
    {
        return $this->remember('home-root-categories:v2', function (): array {
            $counts = $this->categoryProductCounts();
            $locale = Locale::current();

            return Category::query()
                ->whereNull('parent_id')
                ->where('is_active', true)
                ->where('name', '!=', 'ІБІС Зброя')
                ->orderBy('sort_order')
                ->get(['id', 'name', 'name_ru', 'slug', 'slug_ru', 'image_url', 'image_path'])
                ->filter(fn (Category $category): bool => ($counts[$category->id] ?? 0) > 0)
                ->map(fn (Category $category): array => [
                    'id' => $category->id,
                    'name' => $category->translated('name'),
                    'url' => $category->catalogUrl(absolute: false, locale: $locale),
                    'image_src' => $this->categoryImageSrc($category->id),
                ])
                ->values()
                ->all();
        });
    }

    public function categoryImageSrc(int $categoryId): ?string
    {
        return $this->remember('category-image-src:v1:'.$categoryId, function () use ($categoryId): ?string {
            $category = Category::query()->find($categoryId);

            return $category?->imageUrl();
        });
    }

    /**
     * @return list<array{id: int, name: string, url: string, image_src: ?string}>
     */
    public function categoryShowcase(int $categoryId): array
    {
        return $this->remember('category-showcase:v2:'.$categoryId, function () use ($categoryId): array {
            $category = Category::query()
                ->with(['childrenRecursive', 'parent.activeChildren'])
                ->find($categoryId);

            if (! $category) {
                return [];
            }

            if ($category->childrenRecursive->isEmpty()) {
                return [];
            }

            $counts = $this->categoryProductCounts();
            $locale = Locale::current();
            $showcaseCategories = collect([$category])
                ->concat($this->showcaseCategorySiblings($category))
                ->unique('id')
                ->filter(fn (Category $showcaseCategory): bool => ($counts[$showcaseCategory->id] ?? 0) > 0)
                ->values();

            return $showcaseCategories
                ->map(fn (Category $showcaseCategory): array => [
                    'id' => $showcaseCategory->id,
                    'name' => $showcaseCategory->translated('name'),
                    'url' => $showcaseCategory->catalogUrl(absolute: false, locale: $locale),
                    'image_src' => $this->categoryImageSrc($showcaseCategory->id),
                ])
                ->all();
        });
    }

    /**
     * @return Collection<int, Category>
     */
    private function showcaseCategorySiblings(Category $category): Collection
    {
        if ($category->childrenRecursive->isNotEmpty()) {
            return $category->childrenRecursive;
        }

        if ($category->parent) {
            return $category->parent->activeChildren;
        }

        $counts = $this->categoryProductCounts();

        return Category::query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->where('name', '!=', 'ІБІС Зброя')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'name_ru', 'slug', 'slug_ru', 'image_url', 'image_path'])
            ->filter(fn (Category $rootCategory): bool => ($counts[$rootCategory->id] ?? 0) > 0)
            ->values();
    }

    /**
     * @return array<int, int>
     */
    public function categoryProductCounts(): array
    {
        return $this->remember('category-product-counts:v1', function (): array {
            $directCounts = Product::query()
                ->where('is_active', true)
                ->visibleInCatalog()
                ->selectRaw('category_id, COUNT(*) as aggregate')
                ->groupBy('category_id')
                ->pluck('aggregate', 'category_id')
                ->map(fn ($count): int => (int) $count)
                ->all();

            $allChildren = Category::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'parent_id'])
                ->groupBy(fn (Category $category): string => (string) ($category->parent_id ?? ''));

            $counts = [];

            $countProducts = function (int $categoryId) use (&$countProducts, &$counts, $allChildren, $directCounts): int {
                if (isset($counts[$categoryId])) {
                    return $counts[$categoryId];
                }

                return $counts[$categoryId] = ($directCounts[$categoryId] ?? 0)
                    + $allChildren->get((string) $categoryId, collect())->sum(fn (Category $category): int => $countProducts($category->id));
            };

            foreach ($allChildren->flatten() as $category) {
                $countProducts($category->id);
            }

            return $counts;
        });
    }

    public function categoryHasProducts(int $categoryId): bool
    {
        return $this->categoryProductCount($categoryId) > 0;
    }

    public function categoryProductCount(int $categoryId): int
    {
        return (int) ($this->categoryProductCounts()[$categoryId] ?? 0);
    }

    public function categoryPathIndex(): array
    {
        return $this->remember('category-path-index:v2', function (): array {
            $counts = $this->categoryProductCounts();
            $categories = Category::query()
                ->where('is_active', true)
                ->get(['id', 'parent_id', 'slug'])
                ->keyBy('id');
            $canonical = [];
            $legacy = [];

            foreach ($categories as $category) {
                if (($counts[$category->id] ?? 0) < 1) {
                    continue;
                }

                $trail = [];
                $current = $category;

                while ($current) {
                    array_unshift($trail, $current);
                    $current = $current->parent_id ? $categories->get($current->parent_id) : null;
                }

                $prefix = '';
                $segments = collect($trail)->map(function (Category $item) use (&$prefix): string {
                    $slug = preg_replace('/-[a-f0-9]{10}$/i', '', (string) $item->slug) ?: (string) $item->slug;
                    $segment = $prefix !== '' && str_starts_with($slug, $prefix.'-')
                        ? substr($slug, strlen($prefix) + 1)
                        : $slug;
                    $prefix = $slug;

                    return $segment ?: $slug;
                })->filter()->values();

                if ($segments->isEmpty()) {
                    continue;
                }

                $fullPath = $segments->implode('/');
                $shortPath = $segments->count() <= 1
                    ? $fullPath
                    : $segments->first().'/'.$segments->last();

                $canonical[mb_strtolower($shortPath)] = $category->id;
                $legacy[mb_strtolower($fullPath)] = $category->id;
            }

            return compact('canonical', 'legacy');
        });
    }

    /**
     * @return list<array{name: string, slug: string, products_count: int}>
     */
    public function brands(): array
    {
        return $this->remember('brands:v1', function (): array {
            $rows = Product::query()
                ->where('is_active', true)
                ->visibleInCatalog()
                ->whereNotNull('brand')
                ->where('brand', '!=', '')
                ->selectRaw('brand as name, COUNT(*) as products_count')
                ->groupBy('brand')
                ->orderBy('brand')
                ->get();

            $used = [];

            return $rows->map(function ($row) use (&$used): array {
                $base = ProductBrand::slug((string) $row->name);
                $slug = $base;
                $suffix = 2;

                while (isset($used[$slug])) {
                    $slug = $base.'-'.$suffix;
                    $suffix++;
                }

                $used[$slug] = true;

                return [
                    'name' => (string) $row->name,
                    'slug' => $slug,
                    'products_count' => (int) $row->products_count,
                ];
            })->values()->all();
        });
    }

    /**
     * @return array<string, list<array{name: string, slug: string, products_count: int}>>
     */
    public function brandGroups(): array
    {
        $groups = [];

        foreach ($this->brands() as $brand) {
            if (! is_array($brand) || ! isset($brand['name'], $brand['slug'])) {
                continue;
            }

            $letter = mb_strtoupper(mb_substr((string) $brand['name'], 0, 1));
            $letter = preg_match('/\p{L}/u', $letter) === 1 ? $letter : '#';
            $groups[$letter][] = $brand;
        }

        return $groups;
    }

    /**
     * @return array{name: string, slug: string, products_count: int}|null
     */
    public function brandBySlug(string $slug): ?array
    {
        return $this->brandsBySlug()[$slug] ?? null;
    }

    /**
     * @return array{name: string, slug: string, products_count: int}|null
     */
    public function brandByName(string $name): ?array
    {
        return $this->brandsByName()[mb_strtolower(trim($name))] ?? null;
    }

    /**
     * @return array<string, array{name: string, slug: string, products_count: int}>
     */
    private function brandsBySlug(): array
    {
        return $this->remember('brands-by-slug:v1', function (): array {
            $index = [];

            foreach ($this->brands() as $brand) {
                $index[$brand['slug']] = $brand;
            }

            return $index;
        });
    }

    /**
     * @return array<string, array{name: string, slug: string, products_count: int}>
     */
    private function brandsByName(): array
    {
        return $this->remember('brands-by-name:v1', function (): array {
            $index = [];

            foreach ($this->brands() as $brand) {
                $index[mb_strtolower($brand['name'])] = $brand;
            }

            return $index;
        });
    }

    public function remember(string $key, Closure $resolver): mixed
    {
        $key = Locale::isRussian() ? 'ru:'.$key : $key;
        $version = $this->version();
        $cacheKey = $this->keyForVersion($version, $key);
        $missing = new \stdClass;
        $value = Cache::get($cacheKey, $missing);

        if ($value !== $missing) {
            return $value;
        }

        return Cache::lock($cacheKey.':build-lock', 300)->block(60, function () use ($cacheKey, $missing, $resolver, $version): mixed {
            $value = Cache::get($cacheKey, $missing);

            if ($value !== $missing) {
                return $value;
            }

            $value = $resolver();
            Cache::forever($cacheKey, $value);
            $this->registerKey($version, $cacheKey);

            return $value;
        });
    }

    public function rememberFor(string $key, int $ttlSeconds, Closure $resolver): mixed
    {
        $key = Locale::isRussian() ? 'ru:'.$key : $key;
        $version = $this->version();
        $cacheKey = $this->keyForVersion($version, $key);
        $missing = new \stdClass;
        $value = Cache::get($cacheKey, $missing);

        if ($value !== $missing) {
            return $value;
        }

        return Cache::lock($cacheKey.':build-lock', 300)->block(60, function () use ($cacheKey, $missing, $resolver, $ttlSeconds): mixed {
            $value = Cache::get($cacheKey, $missing);

            if ($value !== $missing) {
                return $value;
            }

            $value = $resolver();
            Cache::put($cacheKey, $value, $ttlSeconds);

            return $value;
        });
    }

    public function put(string $key, mixed $value): void
    {
        $key = Locale::isRussian() ? 'ru:'.$key : $key;
        $version = $this->version();
        $cacheKey = $this->keyForVersion($version, $key);

        Cache::forever($cacheKey, $value);
        $this->registerKey($version, $cacheKey);
    }

    public function invalidate(): void
    {
        if ($this->targetVersion !== null) {
            return;
        }

        Cache::forever(self::DIRTY_KEY, true);

        $currentVersion = $this->version();
        $newVersion = (string) Str::uuid();

        Cache::forever(self::VERSION_KEY, $newVersion);
        $this->forgetVersion($currentVersion);
    }

    public function invalidateAndLog(string $trigger, ?string $reason = null): void
    {
        if ($this->targetVersion !== null) {
            return;
        }

        $this->invalidate();

        app(CatalogCacheLogger::class)->recordClear($trigger, $reason);
    }

    public function markRefreshStale(): void
    {
        Cache::forever(self::DIRTY_KEY, true);
    }

    public function beginRefresh(): string
    {
        $this->previousVersion = $this->version();
        Cache::forever(self::DIRTY_KEY, false);

        return $this->targetVersion = (string) Str::uuid();
    }

    public function publishRefresh(): void
    {
        if ($this->targetVersion === null) {
            return;
        }

        if (Cache::get(self::DIRTY_KEY)) {
            $this->forgetVersion($this->targetVersion);
            Cache::forever(self::VERSION_KEY, (string) Str::uuid());
            Cache::forever(self::DIRTY_KEY, false);
            $this->targetVersion = null;
            $this->previousVersion = null;

            return;
        }

        $newVersion = $this->targetVersion;

        Cache::forever(self::VERSION_KEY, $newVersion);
        Cache::forever(self::DIRTY_KEY, false);
        $this->targetVersion = null;

        if ($this->previousVersion !== null && $this->previousVersion !== $newVersion) {
            $this->forgetVersion($this->previousVersion);
        }

        $this->previousVersion = null;
    }

    private function keyForVersion(string $version, string $key): string
    {
        return 'catalog:'.$version.':'.$key;
    }

    private function version(): string
    {
        if ($this->targetVersion !== null) {
            return $this->targetVersion;
        }

        return (string) Cache::rememberForever(self::VERSION_KEY, fn (): string => (string) Str::uuid());
    }

    private function registerKey(string $version, string $cacheKey): void
    {
        $store = Cache::getStore();

        if ($store instanceof RedisStore) {
            $store->connection()->sadd(
                $store->getPrefix().$this->manifestSetKey($version),
                $cacheKey,
            );

            return;
        }

        $manifestKey = $this->manifestKey($version);

        Cache::lock($manifestKey.':lock', 10)->block(3, function () use ($manifestKey, $cacheKey): void {
            $keys = Cache::get($manifestKey, []);

            if (! in_array($cacheKey, $keys, true)) {
                $keys[] = $cacheKey;
                Cache::forever($manifestKey, $keys);
            }
        });
    }

    private function forgetVersion(string $version): void
    {
        $manifestKey = $this->manifestKey($version);
        $keys = Cache::get($manifestKey, []);
        $store = Cache::getStore();

        if ($store instanceof RedisStore) {
            $connection = $store->connection();
            $manifestSetKey = $store->getPrefix().$this->manifestSetKey($version);
            $keys = array_values(array_unique([
                ...(is_array($keys) ? $keys : []),
                ...((array) $connection->smembers($manifestSetKey)),
            ]));
            $connection->del($manifestSetKey);
        }

        foreach ($keys as $cacheKey) {
            Cache::forget($cacheKey);
        }

        Cache::forget($manifestKey);
    }

    private function manifestKey(string $version): string
    {
        return 'catalog:manifest:'.$version;
    }

    private function manifestSetKey(string $version): string
    {
        return 'catalog:manifest-set:'.$version;
    }
}
