<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Support\Locale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SmartSearch
{
    private const SYNONYMS = [
        'вудка' => ['вудилище', 'вудилища', 'спінінг'],
        'удочка' => ['вудка', 'вудилище'],
        'палатка' => ['намет', 'намети'],
        'катушка' => ['котушка', 'котушки'],
        'крючок' => ['гачок', 'гачки'],
        'леска' => ['волосінь'],
        'рюкзак' => ['наплічник'],
    ];

    private const LATIN_KEYS = "qwertyuiop[]asdfghjkl;'zxcvbnm,.`";

    private const UKRAINIAN_KEYS = "йцукенгшщзхїфівапролджєячсмитьбю'";

    public function search(string $query, int $productLimit = 8, int $categoryLimit = 5): array
    {
        $normalized = $this->normalize($query);

        if (mb_strlen($normalized) < 2) {
            return [
                'query' => $normalized,
                'products' => collect(),
                'categories' => collect(),
                'corrected' => null,
            ];
        }

        $tokenGroups = $this->tokenGroups($normalized);
        $candidates = $this->productCandidates($tokenGroups);
        $learning = $this->learningScores($normalized);

        $products = $candidates
            ->map(fn (Product $product): array => [
                'item' => $product,
                'score' => $this->productScore($product, $normalized, $tokenGroups)
                    + min(35, (int) ($learning['product'][$product->id] ?? 0) * 4),
            ])
            ->filter(fn (array $candidate): bool => $candidate['score'] >= 22)
            ->sortByDesc('score')
            ->take($productLimit)
            ->pluck('item')
            ->values();

        $categories = $this->categoryCandidates()
            ->map(fn (Category $category): array => [
                'item' => $category,
                'score' => $this->textScore(
                    $this->normalize($category->breadcrumbTrail()->pluck('name')->implode(' ')),
                    $normalized,
                    $tokenGroups,
                ) + min(35, (int) ($learning['category'][$category->id] ?? 0) * 4),
            ])
            ->filter(fn (array $candidate): bool => $candidate['score'] >= 22)
            ->sortByDesc('score')
            ->take($categoryLimit)
            ->pluck('item')
            ->values();

        return [
            'query' => $normalized,
            'products' => $products,
            'categories' => $categories,
            'corrected' => $this->correctionLabel($normalized, $tokenGroups),
        ];
    }

    public function normalize(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^\pL\pN]+/u', ' ')
            ->squish()
            ->limit(160, '')
            ->toString();
    }

    public function recordClick(string $query, string $type, int $id): void
    {
        $normalized = $this->normalize($query);

        if (mb_strlen($normalized) < 2 || ! in_array($type, ['product', 'category'], true)) {
            return;
        }

        $hash = $this->interactionHash($normalized);
        $interaction = DB::table('search_interactions')
            ->where('query_hash', $hash)
            ->where('result_type', $type)
            ->where('result_id', $id)
            ->first();

        if ($interaction) {
            DB::table('search_interactions')->where('id', $interaction->id)->update([
                'clicks' => min(1000000, (int) $interaction->clicks + 1),
                'last_clicked_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('search_interactions')->insert([
                'query_hash' => $hash,
                'normalized_query' => $normalized,
                'result_type' => $type,
                'result_id' => $id,
                'clicks' => 1,
                'last_clicked_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

    }

    private function productCandidates(array $tokenGroups): Collection
    {
        $cacheKey = 'smart-search:candidates:v2:'.Locale::current().':'.sha1(
            json_encode($tokenGroups, JSON_UNESCAPED_UNICODE)
        );
        $ids = Cache::remember(
            $cacheKey,
            now()->addMinutes(10),
            fn (): array => $this->uncachedProductCandidates($tokenGroups)->pluck('id')->all(),
        );

        if ($ids === []) {
            return collect();
        }

        return Product::query()
            ->whereKey($ids)
            ->where('is_active', true)
            ->visibleInCatalog()
            ->with(['category.parentRecursive'])
            ->select([
                'id', 'category_id', 'name', 'name_ru', 'slug', 'slug_ru', 'sku', 'brand', 'model',
                'price', 'sale_price', 'discount_percent', 'stock', 'image_url', 'image_path',
                'is_active', 'is_featured', 'variant_group_id', 'is_primary_variant', 'is_visible_in_catalog',
            ])
            ->get()
            ->sortBy(fn (Product $product): int => array_search($product->id, $ids, true))
            ->values();
    }

    private function uncachedProductCandidates(array $tokenGroups): Collection
    {
        $exactCodes = collect($tokenGroups)
            ->flatten()
            ->filter(fn (string $term): bool => ctype_digit($term))
            ->unique()
            ->values()
            ->all();

        if ($exactCodes !== []) {
            $exactProducts = Product::query()
                ->where('is_active', true)
                ->where(function (Builder $query) use ($exactCodes): void {
                    $query->whereIn('sku', $exactCodes);

                    foreach ($exactCodes as $code) {
                        $needle = '%'.addcslashes($code, '%_\\').'%';
                        $query->orWhere('name', 'like', $needle)
                            ->orWhere('name_ru', 'like', $needle);
                    }
                })
                ->with(['category.parentRecursive'])
                ->select([
                    'id', 'category_id', 'name', 'name_ru', 'slug', 'slug_ru', 'sku', 'brand', 'model',
                    'price', 'sale_price', 'discount_percent', 'stock', 'image_url', 'image_path',
                    'is_active', 'is_featured', 'variant_group_id', 'is_primary_variant', 'is_visible_in_catalog',
                ])
                ->get();

            $exactProducts = $this->mapToCatalogRepresentatives($exactProducts);

            if ($exactProducts->isNotEmpty()) {
                return $exactProducts;
            }
        }

        if (! Locale::isRussian() && DB::getDriverName() === 'sqlite' && DB::getSchemaBuilder()->hasTable('product_search')) {
            $ids = collect($this->fullTextQueries($tokenGroups))
                ->flatMap(fn (string $match): Collection => DB::table('product_search')
                    ->whereRaw('product_search MATCH ?', [$match])
                    ->orderByRaw('bm25(product_search, 0, 7, 4, 4, 8)')
                    ->limit(140)
                    ->pluck('product_id'))
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->take(240)
                ->all();

            if ($ids === []) {
                return collect();
            }

            return $this->mapToCatalogRepresentatives(Product::query()
                ->whereKey($ids)
                ->where('is_active', true)
                ->with(['category.parentRecursive'])
                ->select([
                    'id', 'category_id', 'name', 'name_ru', 'slug', 'slug_ru', 'sku', 'brand', 'model',
                    'price', 'sale_price', 'discount_percent', 'stock', 'image_url', 'image_path',
                    'is_active', 'is_featured', 'variant_group_id', 'is_primary_variant', 'is_visible_in_catalog',
                ])
                ->get());
        }

        if (DB::getDriverName() === 'mysql') {
            $columns = Locale::isRussian()
                ? 'name_ru, name, brand, model, sku'
                : 'name, brand, model, sku';
            $ids = collect($this->fullTextQueries($tokenGroups))
                ->flatMap(fn (string $match): Collection => DB::table('products')
                    ->select('id')
                    ->where('is_active', true)
                    ->whereRaw("MATCH({$columns}) AGAINST (? IN BOOLEAN MODE)", [$match])
                    ->orderByRaw("MATCH({$columns}) AGAINST (? IN BOOLEAN MODE) DESC", [$match])
                    ->limit(140)
                    ->pluck('id'))
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->take(240)
                ->all();

            return $this->mapToCatalogRepresentatives(Product::query()
                ->whereKey($ids)
                ->where('is_active', true)
                ->with(['category.parentRecursive'])
                ->select([
                    'id', 'category_id', 'name', 'name_ru', 'slug', 'slug_ru', 'sku', 'brand', 'model',
                    'price', 'sale_price', 'discount_percent', 'stock', 'image_url', 'image_path',
                    'is_active', 'is_featured', 'variant_group_id', 'is_primary_variant', 'is_visible_in_catalog',
                ])
                ->get());
        }

        $query = Product::query()
            ->where('is_active', true)
            ->with(['category.parentRecursive'])
            ->select([
                'id', 'category_id', 'name', 'name_ru', 'slug', 'slug_ru', 'sku', 'brand', 'model',
                'price', 'sale_price', 'discount_percent', 'stock', 'image_url', 'image_path',
                'is_active', 'is_featured', 'variant_group_id', 'is_primary_variant', 'is_visible_in_catalog',
            ]);

        foreach ($tokenGroups as $group) {
            $query->where(function (Builder $query) use ($group): void {
                foreach ($group as $term) {
                    $prefix = mb_substr($term, 0, mb_strlen($term) >= 6 ? 4 : 3);
                    $needle = '%'.addcslashes($term, '%_\\').'%';
                    $prefixNeedle = '%'.addcslashes($prefix, '%_\\').'%';

                    $query->orWhere(function (Builder $query) use ($needle, $prefixNeedle): void {
                        $query
                            ->where(Locale::isRussian() ? 'name_ru' : 'name', 'like', $needle)
                            ->orWhere('name', 'like', $needle)
                            ->orWhere('brand', 'like', $needle)
                            ->orWhere('model', 'like', $needle)
                            ->orWhere('sku', 'like', $needle)
                            ->orWhere(Locale::isRussian() ? 'name_ru' : 'name', 'like', $prefixNeedle)
                            ->orWhere('name', 'like', $prefixNeedle);
                    });
                }
            });
        }

        return $this->mapToCatalogRepresentatives($query
            ->orderByDesc('is_featured')
            ->orderByRaw('CASE WHEN stock > 0 THEN 0 ELSE 1 END')
            ->limit(180)
            ->get());
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Product>
     */
    private function mapToCatalogRepresentatives(Collection $products): Collection
    {
        if ($products->isEmpty()) {
            return $products;
        }

        $hiddenGroupIds = $products
            ->filter(fn (Product $product): bool => ! $product->is_visible_in_catalog && $product->variant_group_id !== null)
            ->pluck('variant_group_id')
            ->unique()
            ->values();

        $replacements = collect();

        if ($hiddenGroupIds->isNotEmpty()) {
            $replacements = Product::query()
                ->whereIn('variant_group_id', $hiddenGroupIds)
                ->where('is_visible_in_catalog', true)
                ->where('is_active', true)
                ->get()
                ->keyBy('variant_group_id');
        }

        return $products
            ->map(function (Product $product) use ($replacements): ?Product {
                if ($product->is_visible_in_catalog) {
                    return $product;
                }

                if ($product->variant_group_id && $replacements->has($product->variant_group_id)) {
                    return $replacements->get($product->variant_group_id);
                }

                return null;
            })
            ->filter()
            ->unique('id')
            ->values();
    }

    private function categoryCandidates(): Collection
    {
        $ids = Cache::remember(
            'smart-search:categories:v1:'.Locale::current(),
            now()->addMinutes(10),
            fn (): array => Category::query()
                ->where('is_active', true)
                ->where('name', '!=', 'ІБІС Зброя')
                ->pluck('id')
                ->all(),
        );

        return Category::query()
            ->whereKey($ids)
            ->with('parentRecursive')
            ->get();
    }

    private function productScore(Product $product, string $query, array $tokenGroups): float
    {
        $name = $this->normalize($product->name);
        $brand = $this->normalize((string) $product->brand);
        $model = $this->normalize((string) $product->model);
        $sku = $this->normalize((string) $product->sku);
        $category = $this->normalize((string) $product->category?->name);

        $score = $this->textScore($name, $query, $tokenGroups);
        $score += $this->textScore(trim("{$brand} {$model}"), $query, $tokenGroups) * .72;
        $score += $this->textScore($category, $query, $tokenGroups) * .35;
        $nameWords = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokenGroups as $group) {
            if (array_intersect($group, $nameWords) !== []) {
                $score += 55;
            }
        }

        if ($sku !== '' && $sku === $query) {
            $score += 130;
        }

        if ($product->stock > 0) {
            $score += 4;
        }

        if ($product->is_featured) {
            $score += 2;
        }

        return $score;
    }

    private function textScore(string $text, string $query, array $tokenGroups): float
    {
        if ($text === '') {
            return 0;
        }

        $score = 0;

        if ($text === $query) {
            $score += 150;
        } elseif (str_starts_with($text, $query)) {
            $score += 95;
        } elseif (str_contains($text, $query)) {
            $score += 70;
        }

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokenGroups as $group) {
            $best = 0;

            foreach ($group as $term) {
                foreach ($words as $word) {
                    if ($word === $term) {
                        $best = max($best, 42);
                    } elseif (
                        str_starts_with($word, $term)
                        || (mb_strlen($word) >= 4 && abs(mb_strlen($word) - mb_strlen($term)) <= 2 && str_starts_with($term, $word))
                    ) {
                        $best = max($best, 30);
                    } elseif (mb_strlen($term) >= 4 && str_contains($word, $term)) {
                        $best = max($best, 26);
                    } elseif (mb_strlen($term) >= 4 && abs(mb_strlen($word) - mb_strlen($term)) <= 2) {
                        $distance = levenshtein($this->asciiForDistance($term), $this->asciiForDistance($word));
                        $allowed = mb_strlen($term) >= 8 ? 2 : 1;

                        if ($distance <= $allowed) {
                            $best = max($best, 38 - ($distance * 6));
                        }
                    }
                }
            }

            $score += $best;
        }

        return $score;
    }

    private function tokenGroups(string $query): array
    {
        $groups = collect(preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY))
            ->map(function (string $token): array {
                $alternatives = [$token];
                $switched = $this->switchKeyboardLayout($token);

                if ($switched !== $token) {
                    $alternatives[] = $switched;
                }

                foreach (self::SYNONYMS[$token] ?? [] as $synonym) {
                    $alternatives[] = $synonym;
                }

                foreach (self::SYNONYMS[$switched] ?? [] as $synonym) {
                    $alternatives[] = $synonym;
                }

                return array_values(array_unique(array_filter($alternatives, fn (string $value): bool => mb_strlen($value) >= 2)));
            })
            ->filter()
            ->values();

        $merged = [];

        foreach ($groups as $group) {
            $existing = collect($merged)->search(fn (array $candidate): bool => array_intersect($candidate, $group) !== []);

            if ($existing !== false) {
                $merged[$existing] = array_values(array_unique([...$merged[$existing], ...$group]));
            } else {
                $merged[] = $group;
            }
        }

        return array_slice($merged, 0, 5);
    }

    private function switchKeyboardLayout(string $token): string
    {
        $latin = preg_split('//u', self::LATIN_KEYS, -1, PREG_SPLIT_NO_EMPTY);
        $ukrainian = preg_split('//u', self::UKRAINIAN_KEYS, -1, PREG_SPLIT_NO_EMPTY);
        $latinToUkrainian = array_combine($latin, $ukrainian);
        $ukrainianToLatin = array_combine($ukrainian, $latin);
        $characters = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY);
        $hasLatin = preg_match('/[a-z]/u', $token) === 1;
        $hasUkrainian = preg_match('/[а-яіїєґ]/u', $token) === 1;

        if ($hasLatin && ! $hasUkrainian) {
            return collect($characters)->map(fn (string $character): string => $latinToUkrainian[$character] ?? $character)->implode('');
        }

        if ($hasUkrainian && ! $hasLatin) {
            return collect($characters)->map(fn (string $character): string => $ukrainianToLatin[$character] ?? $character)->implode('');
        }

        return $token;
    }

    private function correctionLabel(string $query, array $tokenGroups): ?string
    {
        if (preg_match('/^[a-z0-9\s]+$/u', $query) !== 1) {
            return null;
        }

        $corrected = collect($tokenGroups)
            ->map(function (array $group): string {
                $ukrainian = collect($group)->first(fn (string $term): bool => preg_match('/[а-яіїєґ]/u', $term) === 1);

                return $ukrainian ?? $group[0];
            })
            ->implode(' ');

        return $corrected !== $query ? $corrected : null;
    }

    private function fullTextQueries(array $tokenGroups): array
    {
        $choices = [
            collect($tokenGroups)->map(fn (array $group): string => $group[0])->all(),
            collect($tokenGroups)->map(function (array $group): string {
                return collect($group)->first(fn (string $term): bool => preg_match('/[а-яіїєґ]/u', $term) === 1) ?? $group[0];
            })->all(),
        ];

        if (count($tokenGroups) === 1) {
            foreach ($tokenGroups[0] as $alternative) {
                $choices[] = [$alternative];
            }
        }

        return collect($choices)
            ->map(function (array $terms): string {
                return collect($terms)
                    ->unique()
                    ->map(function (string $term): string {
                        $prefixLength = mb_strlen($term) >= 6 ? 4 : min(3, mb_strlen($term));
                        $prefix = preg_replace('/[^\pL\pN]+/u', '', mb_substr($term, 0, $prefixLength));

                        if (DB::getDriverName() === 'sqlite') {
                            return '"'.str_replace('"', '""', $prefix).'"*';
                        }

                        return '+'.$prefix.'*';
                    })
                    ->filter()
                    ->implode(' ');
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function learningScores(string $query): array
    {
        if (! DB::getSchemaBuilder()->hasTable('search_interactions')) {
            return ['product' => [], 'category' => []];
        }

        return DB::table('search_interactions')
            ->where('query_hash', $this->interactionHash($query))
            ->orderByDesc('clicks')
            ->limit(30)
            ->get()
            ->groupBy('result_type')
            ->map(fn (Collection $rows): array => $rows->pluck('clicks', 'result_id')->all())
            ->all() + ['product' => [], 'category' => []];
    }

    private function interactionHash(string $query): string
    {
        return sha1(Locale::isRussian() ? 'ru|'.$query : $query);
    }

    private function asciiForDistance(string $value): string
    {
        return Str::ascii($value, 'uk');
    }
}
