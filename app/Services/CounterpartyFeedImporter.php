<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Product;
use App\Support\CatalogCache;
use App\Support\CategorySpecFilterSynchronizer;
use App\Support\CounterpartyFeedImportProgress;
use App\Support\FeedCategoryAssignment;
use App\Support\PlainText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use RuntimeException;
use SimpleXMLElement;
use Throwable;
use XMLReader;

class CounterpartyFeedImporter
{
    private const BATCH_SIZE = 500;

    private const UPSERT_CHUNK_SIZE = 100;

    private const DEADLOCK_RETRY_ATTEMPTS = 5;

    private Counterparty $counterparty;

    /** @var array<string, array{name: string, parent_id: ?string}> */
    private array $feedCategories = [];

    /** @var array<string, int> */
    private array $categoryMap = [];

    /** @var array<string, string> */
    private array $existingProductSlugs = [];

    /** @var array<string, string> */
    private array $existingProductSlugsBySku = [];

    /** @var array<string, string> */
    private array $existingProductSlugsByNormalizedName = [];

    /** @var Collection<string, object> */
    private Collection $existingProductsByExternalId;

    /** @var Collection<string, object> */
    private Collection $existingProductsBySku;

    /** @var Collection<string, object> */
    private Collection $existingProductsByNormalizedName;

    /** @var array<int, true> */
    private array $autoEnableCategoryIds = [];

    /** @var array<int, int> */
    private array $categoryParents = [];

    /** @var array<int, string> */
    private array $feedCategoryNamesById = [];

    /** @var array<string, int> */
    private array $catalogCategoryIdsByNormalizedName = [];

    private array $usedProductSlugs = [];

    private string $feedProfile = 'standard';

    /** @var array<int, int> */
    private array $feedCategoryTargets = [];

    private ?int $trackProgressForCounterpartyId = null;

    private ?string $cacheClearTrigger = null;

    public function __construct(
        private readonly CatalogCache $cache,
        private readonly ProductVariantGrouper $variantGrouper,
        private readonly FeedCategoryAssignment $feedCategoryAssignment,
    ) {
        $this->existingProductsByExternalId = collect();
        $this->existingProductsBySku = collect();
        $this->existingProductsByNormalizedName = collect();
    }

    public function trackProgressFor(int $counterpartyId): self
    {
        $this->trackProgressForCounterpartyId = $counterpartyId;

        return $this;
    }

    public function withCacheClearTrigger(string $trigger): self
    {
        $this->cacheClearTrigger = $trigger;

        return $this;
    }

    public function import(Counterparty $counterparty, ?callable $progress = null): array
    {
        $this->counterparty = $counterparty;

        if (blank($counterparty->feed_url)) {
            throw new RuntimeException('URL фіду не вказано.');
        }

        $file = tempnam(sys_get_temp_dir(), 'counterparty-feed-');

        if ($file === false) {
            throw new RuntimeException('Не вдалося створити тимчасовий файл для фіду.');
        }

        try {
            $this->reportProgress('Завантаження фіду');

            Http::timeout(1200)
                ->connectTimeout(30)
                ->sink($file)
                ->get($counterparty->feed_url)
                ->throw();

            $result = match ($counterparty->feed_format) {
                'yml', 'xml' => $this->importYml($file, $progress),
                default => throw new RuntimeException("Невідомий формат фіду: {$counterparty->feed_format}"),
            };

            $counterparty->update([
                'last_synced_at' => now(),
                'last_sync_products_count' => $result['products'],
                'last_sync_error' => null,
            ]);

            $this->invalidateCatalogCache();

            return $result;
        } catch (Throwable $exception) {
            $counterparty->update([
                'last_sync_error' => Str::limit($exception->getMessage(), 1000),
            ]);

            throw $exception;
        } finally {
            $this->trackProgressForCounterpartyId = null;

            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function importFile(Counterparty $counterparty, string $file, ?callable $progress = null): array
    {
        $this->counterparty = $counterparty;

        if (! is_file($file)) {
            throw new RuntimeException("Feed file does not exist: {$file}");
        }

        try {
            $result = match ($counterparty->feed_format) {
                'yml', 'xml' => $this->importYml($file, $progress),
                default => throw new RuntimeException("Невідомий формат фіду: {$counterparty->feed_format}"),
            };

            $counterparty->update([
                'last_synced_at' => now(),
                'last_sync_products_count' => $result['products'],
                'last_sync_error' => null,
            ]);

            $this->invalidateCatalogCache();

            return $result;
        } finally {
            $this->trackProgressForCounterpartyId = null;
        }
    }

    private function importYml(string $file, ?callable $progress = null): array
    {
        $this->feedProfile = $this->counterparty->feed_profile ?: 'standard';
        $this->feedCategories = [];
        $this->categoryMap = [];
        $this->loadFeedCategories($file);
        $this->reportProgress('Синхронізація категорій');
        $syncedCategories = $this->syncFeedCategories();
        $this->reportProgress('Синхронізація категорій', extra: ['categories_count' => $syncedCategories]);
        $this->loadFeedCategoryTargets();
        $this->loadCategoryParents();
        $this->loadFeedCategoryNames();
        $this->loadCatalogCategoriesByName();

        $offerTotal = $this->countOffers($file);
        $this->reportProgress('Імпорт товарів', 0, $offerTotal);

        $this->loadAutoEnableCategoryIds();
        $this->loadExistingProductIdentityMaps();
        $this->usedProductSlugs = [];

        $reader = new XMLReader;

        if (! $reader->open($file, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new RuntimeException("Unable to open YML feed: {$file}");
        }

        $batch = [];
        $imported = 0;

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'offer') {
                continue;
            }

            $batch[] = $this->mapOffer($reader->readOuterXml());

            if (count($batch) >= self::BATCH_SIZE) {
                $this->upsertProducts($batch);
                $imported += count($batch);
                $batch = [];
                $this->reportProgress('Імпорт товарів', $imported, $offerTotal, ['products_imported' => $imported]);
                $progress?->__invoke($imported);
            }
        }

        if ($batch !== []) {
            $this->upsertProducts($batch);
            $imported += count($batch);
            $this->reportProgress('Імпорт товарів', $imported, $offerTotal, ['products_imported' => $imported]);
            $progress?->__invoke($imported);
        }

        $reader->close();

        return $this->finishImport([
            'products' => $imported,
            'categories' => $syncedCategories,
        ]);
    }

    private function finishImport(array $result): array
    {
        @set_time_limit(0);

        $this->reportProgress('Групування варіантів');

        $grouping = $this->variantGrouper->discoverScoped(
            $this->variantGroupingCategoryScope(),
            includeInactive: true,
        );

        $this->reportProgress('Оновлення фільтрів', extra: [
            'variant_groups_created' => $grouping['created'],
        ]);

        $this->syncCategorySpecFilters();

        return [
            ...$result,
            'variant_groups_created' => $grouping['created'],
            'variant_groups_skipped' => $grouping['skipped'],
        ];
    }

    private function countOffers(string $file): int
    {
        $reader = new XMLReader;

        if (! $reader->open($file, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            return 0;
        }

        $count = 0;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'offer') {
                $count++;
            }
        }

        $reader->close();

        return $count;
    }

    /**
     * @return list<int>
     */
    private function variantGroupingCategoryScope(): array
    {
        $feedCategoryIds = array_values(array_unique(array_map(intval(...), array_values($this->categoryMap))));

        $targetCategoryIds = Category::query()
            ->whereIn('id', $feedCategoryIds)
            ->whereNotNull('target_category_id')
            ->pluck('target_category_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $productCategoryIds = Product::query()
            ->where('counterparty_id', $this->counterparty->id)
            ->whereNotNull('category_id')
            ->distinct()
            ->pluck('category_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return collect([...$feedCategoryIds, ...$targetCategoryIds, ...$productCategoryIds])
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function invalidateCatalogCache(): void
    {
        $reason = 'Імпорт фіду: '.$this->counterparty->name;

        if ($this->cacheClearTrigger !== null) {
            $this->cache->invalidateAndLog($this->cacheClearTrigger, $reason);

            return;
        }

        $this->cache->invalidate();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function reportProgress(string $stage, int $current = 0, int $total = 0, array $extra = []): void
    {
        if ($this->trackProgressForCounterpartyId === null) {
            return;
        }

        app(CounterpartyFeedImportProgress::class)->update(
            $this->trackProgressForCounterpartyId,
            $stage,
            $current,
            $total,
            $extra,
        );
    }

    private function syncCategorySpecFilters(): void
    {
        $feedCategoryIds = array_values(array_unique(array_map(intval(...), array_values($this->categoryMap))));

        $catalogCategoryIds = Category::query()
            ->whereIn('id', $feedCategoryIds)
            ->whereNotNull('target_category_id')
            ->pluck('target_category_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $productCategoryIds = Product::query()
            ->where('counterparty_id', $this->counterparty->id)
            ->whereNotNull('category_id')
            ->distinct()
            ->pluck('category_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        app(CategorySpecFilterSynchronizer::class)->syncByCategoryIds([
            ...$feedCategoryIds,
            ...$catalogCategoryIds,
            ...$productCategoryIds,
        ], includeInactive: true);
    }

    private function loadFeedCategories(string $file): void
    {
        $reader = new XMLReader;

        if (! $reader->open($file, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new RuntimeException("Unable to open YML feed: {$file}");
        }

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'category') {
                continue;
            }

            $this->storeFeedCategory($reader->readOuterXml());
        }

        $reader->close();
    }

    private function syncFeedCategories(): int
    {
        $this->categoryMap = Category::query()
            ->where('counterparty_id', $this->counterparty->id)
            ->whereNotNull('external_id')
            ->pluck('id', 'external_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $pending = $this->feedCategories;
        $guard = 0;

        while ($pending !== [] && $guard < 50) {
            $guard++;
            $progress = false;

            foreach ($pending as $feedId => $data) {
                $parentFeedId = $data['parent_id'];

                if ($parentFeedId && ! isset($this->categoryMap[$parentFeedId])) {
                    continue;
                }

                $parentDbId = $parentFeedId ? $this->categoryMap[$parentFeedId] : null;
                $category = $this->resolveCategory((string) $feedId, $data, $parentDbId);

                $this->categoryMap[(string) $feedId] = $category->id;
                unset($pending[$feedId]);
                $progress = true;
            }

            if (! $progress) {
                break;
            }
        }

        return count($this->categoryMap);
    }

    /**
     * @param  array{name: string, parent_id: ?string}  $data
     */
    private function resolveCategory(string $feedId, array $data, ?int $parentDbId): Category
    {
        $mapped = Category::withTrashed()
            ->where('counterparty_id', $this->counterparty->id)
            ->where('external_id', $feedId)
            ->first();

        if ($mapped) {
            $mapped->update([
                'name' => $data['name'],
                'name_ru' => $data['name'],
            ]);

            return $mapped;
        }

        // Never hijack a catalog category by name — only reuse this counterparty's
        // own feed categories. Catalog assignment happens later via target/name maps.
        $existing = Category::withTrashed()
            ->where('counterparty_id', $this->counterparty->id)
            ->where('name', $data['name'])
            ->when(
                $parentDbId === null,
                fn ($query) => $query->whereNull('parent_id'),
                fn ($query) => $query->where('parent_id', $parentDbId),
            )
            ->first();

        if ($existing) {
            if (blank($existing->external_id)) {
                $existing->update([
                    'external_id' => $feedId,
                    'name' => $data['name'],
                    'name_ru' => $data['name'],
                    'parent_id' => $parentDbId,
                ]);
            } else {
                $existing->update([
                    'name' => $data['name'],
                    'name_ru' => $data['name'],
                ]);
            }

            return $existing;
        }

        return Category::query()->create([
            'counterparty_id' => $this->counterparty->id,
            'external_id' => $feedId,
            'name' => $data['name'],
            'name_ru' => $data['name'],
            'slug' => $this->categorySlug($data['name']),
            'parent_id' => $parentDbId,
            'is_active' => false,
        ]);
    }

    private function storeFeedCategory(string $xml): void
    {
        $category = simplexml_load_string($xml);

        if ($category === false) {
            return;
        }

        $id = trim((string) ($category['id'] ?? ''));

        if ($id === '') {
            return;
        }

        $this->feedCategories[$id] = [
            'name' => trim((string) $category),
            'parent_id' => trim((string) ($category['parentId'] ?? '')) ?: null,
        ];
    }

    private function mapOffer(string $xml): array
    {
        $offer = simplexml_load_string($xml);

        if ($offer === false) {
            throw new RuntimeException('Unable to parse an offer from the feed.');
        }

        $this->enrichOfferForProfile($offer);

        $externalId = $this->resolveOfferExternalId($offer);

        if ($externalId === '') {
            throw new RuntimeException('Offer is missing an id.');
        }

        $nameRu = trim((string) ($offer->name ?? ''));
        $nameUa = trim((string) ($offer->{'name_ua'} ?? ''));
        [$nameUa, $nameRu] = $this->localizedNames($nameUa, $nameRu);
        $feedCategoryId = trim((string) ($offer->categoryId ?? ''));
        $images = collect();

        foreach ($offer->picture as $picture) {
            $images->push(trim((string) $picture));
        }

        $images = ProductImageMirror::sortPicturesForImport($images->filter()->unique())
            ->reject(fn (string $url): bool => $this->isPlaceholderImage($url))
            ->values();
        $specifications = $this->buildSpecifications($offer, 'uk');
        $specificationsRu = $this->buildSpecifications($offer, 'ru');
        $price = $this->price((string) ($offer->price ?? '0'));
        $oldPrice = $this->price((string) ($offer->oldprice ?? '')) ?: null;
        $salePrice = $oldPrice && $oldPrice > $price ? $price : null;
        $listPrice = $oldPrice && $oldPrice > $price ? $oldPrice : $price;
        [$description, $descriptionRu] = $this->localizedDescriptions(
            $this->description((string) ($offer->{'description_ua'} ?? '')),
            $this->description((string) ($offer->description ?? '')),
        );
        $groupId = trim((string) ($offer['group_id'] ?? $offer['groupId'] ?? ''));
        $externalUrl = trim((string) ($offer->url ?? ''));
        $brand = $this->profileUsesVendorBrand() ? $this->resolveBrand($offer) : null;
        $sku = $this->resolveOfferSku($offer);
        $now = now();

        $variantId = $groupId !== ''
            ? $this->counterparty->slug.'-group-'.$groupId
            : $this->counterparty->slug.'-'.$externalId;

        $slug = $this->existingProductSlugs[$externalId]
            ?? ($sku !== null ? ($this->existingProductSlugsBySku[$sku] ?? null) : null)
            ?? ($this->existingProductSlugsByNormalizedName[$this->normalizeProductIdentityName($nameUa)] ?? null)
            ?? $this->productSlug($nameUa, $externalId);

        $feedCategoryDbId = $this->categoryMap[$feedCategoryId] ?? null;
        $categoryId = $this->defaultCategoryForFeedCategory($feedCategoryDbId);
        $autoEnable = $this->shouldAutoEnableForCategory($categoryId);

        return [
            'category_id' => $categoryId,
            'source_feed_category_id' => $feedCategoryDbId,
            'counterparty_id' => $this->counterparty->id,
            'source' => $this->counterparty->slug,
            'external_id' => $externalId,
            'variant_id' => $variantId,
            'external_url' => $externalUrl !== '' ? $externalUrl : null,
            'name' => $nameUa,
            'name_ru' => $nameRu,
            'slug' => $slug,
            'slug_ru' => $slug,
            'sku' => $sku,
            'description' => $description,
            'description_ru' => $descriptionRu,
            'content' => $description,
            'content_ru' => $descriptionRu,
            'seo_title' => $nameUa.' купити в Kubii',
            'seo_title_ru' => $nameRu.' купить в Kubii',
            'meta_description' => Str::limit($description ?: $nameUa.' - туристичне та рибальське спорядження Kubii.', 155),
            'meta_description_ru' => Str::limit($descriptionRu ?: $nameRu.' - туристическое и рыболовное снаряжение Kubii.', 155),
            'h1' => $nameUa,
            'h1_ru' => $nameRu,
            'is_indexable' => true,
            'canonical_type' => 'self',
            'brand' => $brand,
            'model' => null,
            'season' => $specifications['Сезон'] ?? $this->translateSeasonToUkrainian($specificationsRu['Сезон'] ?? null),
            'season_ru' => $specificationsRu['Сезон'] ?? null,
            'usage_type' => null,
            'usage_type_ru' => null,
            'material' => null,
            'material_ru' => null,
            'weight_grams' => null,
            'specifications' => json_encode($specifications, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'specifications_ru' => json_encode($specificationsRu, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'price' => $listPrice,
            'sale_price' => $salePrice,
            'discount_percent' => $salePrice && $listPrice > $salePrice ? round((1 - $salePrice / $listPrice) * 100) : 0,
            'image_url' => ProductImageMirror::normalizeSourceUrl($images->first()),
            'gallery_images' => json_encode(
                ProductImageMirror::normalizeGalleryUrls($images->slice(1)->values()->all()),
                JSON_UNESCAPED_SLASHES,
            ),
            'content_images' => json_encode($images->take(3)->values()->all(), JSON_UNESCAPED_SLASHES),
            'stock' => $this->resolveStock($offer),
            'is_featured' => false,
            'is_active' => $autoEnable,
            'is_processed' => $autoEnable,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function loadFeedCategoryTargets(): void
    {
        $this->feedCategoryTargets = Category::query()
            ->where('counterparty_id', $this->counterparty->id)
            ->whereNotNull('target_category_id')
            ->pluck('target_category_id', 'id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function loadAutoEnableCategoryIds(): void
    {
        $this->autoEnableCategoryIds = Category::query()
            ->where('is_active', true)
            ->pluck('id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
    }

    private function loadCategoryParents(): void
    {
        $this->categoryParents = Category::withTrashed()
            ->whereNotNull('parent_id')
            ->pluck('parent_id', 'id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function loadFeedCategoryNames(): void
    {
        $this->feedCategoryNamesById = [];

        $rows = DB::table('categories')
            ->where('counterparty_id', $this->counterparty->id)
            ->whereNotNull('external_id')
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'name_ru']);

        foreach ($rows as $row) {
            $name = trim((string) ($row->name ?: $row->name_ru));

            if ($name !== '') {
                $this->feedCategoryNamesById[(int) $row->id] = $name;
            }
        }
    }

    private function loadCatalogCategoriesByName(): void
    {
        $this->catalogCategoryIdsByNormalizedName = [];

        $rows = DB::table('categories')
            ->where('is_active', true)
            ->whereNull('counterparty_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'name', 'name_ru']);

        foreach ($rows as $row) {
            foreach ([(string) $row->name, (string) $row->name_ru] as $name) {
                $normalized = $this->normalizeCategoryName($name);

                if ($normalized === '') {
                    continue;
                }

                $this->catalogCategoryIdsByNormalizedName[$normalized] ??= (int) $row->id;
            }
        }
    }

    private function normalizeCategoryName(string $name): string
    {
        $name = html_entity_decode(trim($name), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($name === '') {
            return '';
        }

        $name = mb_strtolower($name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($name);
    }

    private function shouldAutoEnableForCategory(?int $categoryId): bool
    {
        if ($categoryId === null) {
            return false;
        }

        // Product may sit in an inactive feed leaf while its parent (or mapped
        // catalog ancestor) is already enabled in admin.
        foreach ($this->categoryIdWithAncestors($categoryId) as $id) {
            if (isset($this->autoEnableCategoryIds[$id])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private function categoryIdWithAncestors(int $categoryId): array
    {
        $ids = [];
        $currentId = $categoryId;
        $guard = 0;

        while ($currentId > 0 && $guard < 50) {
            $guard++;

            if (isset($ids[$currentId])) {
                break;
            }

            $ids[$currentId] = true;
            $currentId = $this->categoryParents[$currentId] ?? 0;
        }

        return array_map(intval(...), array_keys($ids));
    }

    private function loadExistingProductIdentityMaps(): void
    {
        // Keep this map lean: gallery JSON for thousands of offers can OOM the worker/server.
        $rows = DB::table('products')
            ->where('source', $this->counterparty->slug)
            ->whereNotNull('external_id')
            ->get([
                'external_id',
                'category_id',
                'source_feed_category_id',
                'slug',
                'sku',
                'name',
                'brand',
                'model',
                'is_processed',
                'is_active',
                'created_at',
                'image_url',
            ]);

        $this->existingProductsByExternalId = $rows->keyBy(fn (object $row): string => (string) $row->external_id);
        $this->existingProductsBySku = collect();
        $this->existingProductsByNormalizedName = collect();
        $this->existingProductSlugs = [];
        $this->existingProductSlugsBySku = [];
        $this->existingProductSlugsByNormalizedName = [];

        foreach ($rows as $row) {
            $this->existingProductSlugs[(string) $row->external_id] = $row->slug;

            if (filled($row->sku)) {
                $this->existingProductsBySku->put((string) $row->sku, $row);
                $this->existingProductSlugsBySku[(string) $row->sku] = $row->slug;
            }

            $normalizedName = $this->normalizeProductIdentityName((string) ($row->name ?? ''));

            if ($normalizedName === '') {
                continue;
            }

            $current = $this->existingProductsByNormalizedName->get($normalizedName);
            $this->existingProductsByNormalizedName->put(
                $normalizedName,
                $current ? $this->preferExistingProduct($current, $row) : $row,
            );
            $this->existingProductSlugsByNormalizedName[$normalizedName] ??= $row->slug;
        }
    }

    private function rememberProductIdentity(object $row): void
    {
        $this->existingProductsByExternalId->put((string) $row->external_id, $row);
        $this->existingProductSlugs[(string) $row->external_id] = $row->slug;

        if (filled($row->sku ?? null)) {
            $this->existingProductsBySku->put((string) $row->sku, $row);
            $this->existingProductSlugsBySku[(string) $row->sku] = $row->slug;
        }

        $normalizedName = $this->normalizeProductIdentityName((string) ($row->name ?? ''));

        if ($normalizedName === '') {
            return;
        }

        $current = $this->existingProductsByNormalizedName->get($normalizedName);
        $this->existingProductsByNormalizedName->put(
            $normalizedName,
            $current ? $this->preferExistingProduct($current, $row) : $row,
        );
        $this->existingProductSlugsByNormalizedName[$normalizedName] ??= $row->slug;
    }

    private function forgetProductIdentity(object $row): void
    {
        $this->existingProductsByExternalId->forget((string) $row->external_id);
        unset($this->existingProductSlugs[(string) $row->external_id]);

        if (filled($row->sku ?? null)
            && (string) ($this->existingProductsBySku->get((string) $row->sku)?->external_id ?? '') === (string) $row->external_id) {
            $this->existingProductsBySku->forget((string) $row->sku);
            unset($this->existingProductSlugsBySku[(string) $row->sku]);
        }

        $normalizedName = $this->normalizeProductIdentityName((string) ($row->name ?? ''));

        if ($normalizedName !== ''
            && (string) ($this->existingProductsByNormalizedName->get($normalizedName)?->external_id ?? '') === (string) $row->external_id) {
            $this->existingProductsByNormalizedName->forget($normalizedName);
            unset($this->existingProductSlugsByNormalizedName[$normalizedName]);
        }
    }

    private function defaultCategoryForFeedCategory(?int $feedCategoryId): ?int
    {
        if ($feedCategoryId === null) {
            return null;
        }

        // 1) Explicit target_category_id on the feed category or its parents.
        foreach ($this->categoryIdWithAncestors($feedCategoryId) as $id) {
            if (isset($this->feedCategoryTargets[$id])) {
                return $this->feedCategoryTargets[$id];
            }
        }

        // 2) Active catalog category with the same name (leaf first, then parents).
        foreach ($this->categoryIdWithAncestors($feedCategoryId) as $id) {
            $name = $this->feedCategoryNamesById[$id] ?? null;

            if ($name === null) {
                continue;
            }

            $normalized = $this->normalizeCategoryName($name);

            if ($normalized !== '' && isset($this->catalogCategoryIdsByNormalizedName[$normalized])) {
                return $this->catalogCategoryIdsByNormalizedName[$normalized];
            }
        }

        return $feedCategoryId;
    }

    private function resolveOfferSku(SimpleXMLElement $offer): ?string
    {
        // Prefer supplier article (vendorCode) as the stable product code,
        // then barcode. Offer @id alone is not enough when feeds rotate ids.
        foreach (['vendorCode', 'barcode'] as $field) {
            $value = trim((string) ($offer->{$field} ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        if ($this->feedProfile === 'travelextreme') {
            $externalId = $this->resolveOfferExternalId($offer);

            return $externalId !== '' ? $externalId : null;
        }

        return null;
    }

    private function resolveOfferExternalId(SimpleXMLElement $offer): string
    {
        $externalId = trim((string) ($offer['id'] ?? ''));

        if ($externalId !== '' || $this->feedProfile !== 'travelextreme') {
            return $externalId;
        }

        $url = trim((string) ($offer->url ?? ''));
        $query = parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_QUERY);

        if (is_string($query)) {
            parse_str($query, $parameters);
            $productId = trim((string) ($parameters['product_id'] ?? ''));

            if ($productId !== '') {
                return 'product-'.$productId;
            }
        }

        $name = trim((string) ($offer->name ?? ''));

        return $name !== '' ? 'name-'.Str::slug($name) : '';
    }

    private function isPlaceholderImage(string $url): bool
    {
        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

        return str_ends_with($path, '/image/no_image.png')
            || str_ends_with($path, '/no_image.png');
    }

    /**
     * Camotec historically put the article in the title as "(8480)".
     * Newer feeds may drop it — treat both as the same product identity.
     */
    private function normalizeProductIdentityName(string $name): string
    {
        $name = html_entity_decode(trim($name), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($name === '') {
            return '';
        }

        $name = preg_replace('/\s*\(\s*[\dA-Za-zА-Яа-яЁёІіЇїЄєҐґ\-]+\s*\)/u', '', $name) ?? $name;
        $name = mb_strtolower($name);
        $name = preg_replace('/\s*,\s*/u', ', ', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($name);
    }

    private function preferExistingProduct(object $first, object $second): object
    {
        $firstProcessed = (bool) ($first->is_processed ?? false);
        $secondProcessed = (bool) ($second->is_processed ?? false);

        if ($firstProcessed !== $secondProcessed) {
            return $firstProcessed ? $first : $second;
        }

        return strcmp((string) ($first->created_at ?? ''), (string) ($second->created_at ?? '')) <= 0
            ? $first
            : $second;
    }

    private function claimExistingProductIdentity(object $current, string $externalId): object
    {
        if ((string) $current->external_id === $externalId) {
            $this->rememberProductIdentity($current);

            return $current;
        }

        $conflict = $this->existingProductsByExternalId->get((string) $externalId);

        if ($conflict && (string) $conflict->external_id !== (string) $current->external_id) {
            $preferred = $this->preferExistingProduct($current, $conflict);
            $discard = $preferred === $current ? $conflict : $current;

            $this->withDeadlockRetry(function () use ($discard): void {
                DB::table('products')
                    ->where('source', $this->counterparty->slug)
                    ->where('external_id', $discard->external_id)
                    ->delete();
            });

            $this->forgetProductIdentity($discard);
            $current = $preferred;
        }

        if ((string) $current->external_id !== $externalId) {
            $previousExternalId = (string) $current->external_id;

            $this->withDeadlockRetry(function () use ($previousExternalId, $externalId): void {
                DB::table('products')
                    ->where('source', $this->counterparty->slug)
                    ->where('external_id', $previousExternalId)
                    ->update([
                        'external_id' => $externalId,
                        'updated_at' => now(),
                    ]);
            });

            $this->forgetProductIdentity($current);
            $current->external_id = $externalId;
        }

        $this->rememberProductIdentity($current);

        return $current;
    }

    private function resolveBrand(SimpleXMLElement $offer): ?string
    {
        $vendor = trim((string) ($offer->vendor ?? ''));

        if ($vendor === '' || in_array(mb_strtolower($vendor), ['без марки', 'без бренду', 'no brand'], true)) {
            return null;
        }

        return $vendor;
    }

    private function profileUsesVendorBrand(): bool
    {
        return in_array($this->feedProfile, ['camotec', 'atlantmarket', 'ranger', 'trampopt', 'travelextreme', 'salmo'], true);
    }

    private function profileUsesAvailabilityStock(): bool
    {
        return in_array($this->feedProfile, ['camotec', 'atlantmarket', 'ranger', 'salmo'], true);
    }

    private function enrichOfferForProfile(SimpleXMLElement $offer): void
    {
        if ($this->feedProfile !== 'camotec') {
            return;
        }

        $this->ensureOfferParam($offer, 'Розмір', trim((string) ($offer->sizeTitle ?? '')));
        $this->ensureOfferParam($offer, 'Колір', trim((string) ($offer->colorTitle ?? '')));
    }

    private function ensureOfferParam(SimpleXMLElement $offer, string $name, string $value): void
    {
        if ($value === '') {
            return;
        }

        foreach ($offer->param as $param) {
            if (trim((string) ($param['name'] ?? '')) === $name) {
                return;
            }
        }

        $param = $offer->addChild('param', htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8'));
        $param->addAttribute('name', $name);
    }

    private function resolveStock(SimpleXMLElement $offer): int
    {
        if (in_array($this->feedProfile, ['ranger', 'travelextreme'], true)) {
            $quantity = trim((string) ($offer->stock_quantity ?? ''));

            if ($quantity !== '') {
                return max(0, (int) floor((float) str_replace(',', '.', $quantity)));
            }
        }

        if ($this->profileUsesAvailabilityStock()) {
            if ($this->feedProfile === 'camotec' && isset($offer->quantityStatus) && trim((string) $offer->quantityStatus) !== '') {
                return max(0, (int) $offer->quantityStatus);
            }

            foreach (['in_stock', 'available'] as $attribute) {
                $value = strtolower(trim((string) ($offer[$attribute] ?? '')));

                if ($value === '') {
                    continue;
                }

                return in_array($value, ['true', '1', 'yes'], true) ? 1 : 0;
            }

            return 0;
        }

        return max(0, (int) ($offer->quantity_in_stock ?? 0));
    }

    private function upsertProducts(array $products): void
    {
        $clearedImageExternalIds = [];
        $clearedGalleryExternalIds = [];
        $externalIds = array_column($products, 'external_id');

        foreach ($products as &$product) {
            $candidates = [];
            $byExternalId = $this->existingProductsByExternalId->get((string) $product['external_id']);

            if ($byExternalId) {
                $candidates[] = $byExternalId;
            }

            if (filled($product['sku'] ?? null)) {
                $bySku = $this->existingProductsBySku->get((string) $product['sku']);

                if ($bySku) {
                    $candidates[] = $bySku;
                }
            }

            $normalizedName = $this->normalizeProductIdentityName((string) ($product['name'] ?? ''));

            if ($normalizedName !== '') {
                $byName = $this->existingProductsByNormalizedName->get($normalizedName);

                if ($byName) {
                    $candidates[] = $byName;
                }
            }

            $current = null;

            foreach ($candidates as $candidate) {
                $current = $current
                    ? $this->preferExistingProduct($current, $candidate)
                    : $candidate;
            }

            if ($current) {
                foreach ($candidates as $candidate) {
                    if ((string) $candidate->external_id === (string) $current->external_id) {
                        continue;
                    }

                    $this->withDeadlockRetry(function () use ($candidate): void {
                        DB::table('products')
                            ->where('source', $this->counterparty->slug)
                            ->where('external_id', $candidate->external_id)
                            ->delete();
                    });

                    $this->forgetProductIdentity($candidate);
                }

                $current = $this->claimExistingProductIdentity($current, (string) $product['external_id']);
            }

            if (! $current) {
                $this->rememberProductIdentity((object) [
                    'external_id' => $product['external_id'],
                    'category_id' => $product['category_id'] ?? null,
                    'source_feed_category_id' => $product['source_feed_category_id'] ?? null,
                    'slug' => $product['slug'],
                    'sku' => $product['sku'] ?? null,
                    'name' => $product['name'] ?? null,
                    'brand' => $product['brand'] ?? null,
                    'model' => $product['model'] ?? null,
                    'is_processed' => $product['is_processed'] ?? false,
                    'is_active' => $product['is_active'] ?? false,
                    'created_at' => $product['created_at'] ?? null,
                    'image_url' => $product['image_url'] ?? null,
                ]);

                continue;
            }

            $feedCategoryId = $product['source_feed_category_id'] ?? null;

            $product['slug'] = $current->slug;
            $product['slug_ru'] = $current->slug;

            if (! filled($product['sku'] ?? null) && filled($current->sku)) {
                $product['sku'] = $current->sku;
            }

            if ($this->feedCategoryAssignment->shouldPreserveCategory(
                $current,
                $feedCategoryId !== null ? (int) $feedCategoryId : null,
            )) {
                $product['category_id'] = $current->category_id;
            }

            $autoEnable = $this->shouldAutoEnableForCategory(
                isset($product['category_id']) ? (int) $product['category_id'] : null,
            );

            if ((bool) $current->is_processed) {
                $product['is_processed'] = true;
                $product['is_active'] = (bool) $current->is_active;
            } elseif ($autoEnable) {
                $product['is_processed'] = true;
                $product['is_active'] = true;
            } else {
                $product['is_processed'] = false;
                $product['is_active'] = (bool) $current->is_active;
            }

            $product['created_at'] = $current->created_at;
            $product['source_feed_category_id'] = $feedCategoryId;

            if (filled($current->brand)) {
                $product['brand'] = $current->brand;
            }

            if (filled($current->model)) {
                $product['model'] = $current->model;
            }

            $incomingImageUrl = ProductImageMirror::normalizeSourceUrl($product['image_url'] ?? null);
            $currentImageUrl = ProductImageMirror::normalizeSourceUrl($current->image_url ?? null);

            if ($incomingImageUrl !== $currentImageUrl) {
                $clearedImageExternalIds[] = $product['external_id'];
            }

            $product['image_url'] = $incomingImageUrl;

            $this->rememberProductIdentity((object) [
                'external_id' => $product['external_id'],
                'category_id' => $product['category_id'] ?? null,
                'source_feed_category_id' => $product['source_feed_category_id'] ?? null,
                'slug' => $product['slug'],
                'sku' => $product['sku'] ?? null,
                'name' => $product['name'] ?? null,
                'brand' => $product['brand'] ?? null,
                'model' => $product['model'] ?? null,
                'is_processed' => $product['is_processed'] ?? false,
                'is_active' => $product['is_active'] ?? false,
                'created_at' => $product['created_at'] ?? null,
                'image_url' => $product['image_url'] ?? null,
            ]);
        }
        unset($product);

        $currentGalleries = DB::table('products')
            ->where('source', $this->counterparty->slug)
            ->whereIn('external_id', $externalIds)
            ->pluck('gallery_images', 'external_id');

        foreach ($products as &$product) {
            $incomingGallery = ProductImageMirror::normalizeGalleryUrls(
                json_decode((string) ($product['gallery_images'] ?? '[]'), true),
            );
            $currentGallery = ProductImageMirror::normalizeGalleryUrls(
                json_decode((string) ($currentGalleries[(string) $product['external_id']] ?? '[]'), true),
            );

            if ($incomingGallery !== $currentGallery) {
                $clearedGalleryExternalIds[] = $product['external_id'];
            }

            $product['gallery_images'] = json_encode($incomingGallery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        unset($product);

        $this->persistProductBatch(
            $products,
            $clearedImageExternalIds,
            $clearedGalleryExternalIds,
            $externalIds,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @param  list<string|int>  $clearedImageExternalIds
     * @param  list<string|int>  $clearedGalleryExternalIds
     * @param  list<string|int>  $externalIds
     */
    private function persistProductBatch(
        array $products,
        array $clearedImageExternalIds,
        array $clearedGalleryExternalIds,
        array $externalIds,
    ): void {
        $this->withDeadlockRetry(function () use (
            $products,
            $clearedImageExternalIds,
            $clearedGalleryExternalIds,
            $externalIds,
        ): void {
            usort(
                $products,
                fn (array $left, array $right): int => strcmp(
                    (string) $left['external_id'],
                    (string) $right['external_id'],
                ),
            );

            foreach (array_chunk($products, self::UPSERT_CHUNK_SIZE) as $chunk) {
                $this->releaseConflictingSkus($chunk);

                // Also drop duplicate SKUs inside the same chunk before upsert.
                $seenSkus = [];
                foreach ($chunk as &$product) {
                    $sku = filled($product['sku'] ?? null) ? (string) $product['sku'] : null;

                    if ($sku === null) {
                        continue;
                    }

                    if (isset($seenSkus[$sku])) {
                        $product['sku'] = null;

                        continue;
                    }

                    $seenSkus[$sku] = true;
                }
                unset($product);

                DB::table('products')->upsert(
                    $chunk,
                    ['source', 'external_id'],
                    array_keys($chunk[0]),
                );
            }

            if ($clearedImageExternalIds !== []) {
                DB::table('products')
                    ->where('source', $this->counterparty->slug)
                    ->whereIn('external_id', $clearedImageExternalIds)
                    ->update([
                        'image_path' => null,
                        'mirrored_image_url' => null,
                        'updated_at' => now(),
                    ]);
            }

            if ($clearedGalleryExternalIds !== []) {
                DB::table('products')
                    ->where('source', $this->counterparty->slug)
                    ->whereIn('external_id', $clearedGalleryExternalIds)
                    ->update([
                        'gallery_paths' => null,
                        'mirrored_gallery_urls' => null,
                        'updated_at' => now(),
                    ]);
            }

            $missingCodes = DB::table('products')
                ->where('source', $this->counterparty->slug)
                ->whereIn('external_id', $externalIds)
                ->whereNull('sku')
                ->orderBy('id')
                ->get(['id']);

            foreach ($missingCodes as $product) {
                DB::table('products')->where('id', $product->id)->update([
                    'sku' => Product::siteSkuForId((int) $product->id),
                ]);
            }
        });
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withDeadlockRetry(callable $callback, ?int $attempts = null): mixed
    {
        $attempts ??= self::DEADLOCK_RETRY_ATTEMPTS;
        $attempt = 0;

        starting:
        try {
            return $callback();
        } catch (QueryException $exception) {
            $attempt++;

            if ($attempt >= $attempts || ! $this->isDeadlockException($exception)) {
                throw $exception;
            }

            usleep((50_000 * $attempt) + random_int(0, 50_000));

            goto starting;
        }
    }

    private function isDeadlockException(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        return $sqlState === '40001'
            || $driverCode === 1213
            || str_contains($message, 'deadlock')
            || str_contains($message, 'try restarting transaction');
    }

    /**
     * @param  list<array<string, mixed>>  $products
     */
    private function releaseConflictingSkus(array &$products): void
    {
        $skus = array_values(array_unique(array_filter(
            array_map(fn (array $product): ?string => filled($product['sku'] ?? null) ? (string) $product['sku'] : null, $products),
        )));

        if ($skus === []) {
            return;
        }

        $owners = DB::table('products')
            ->whereIn('sku', $skus)
            ->orderBy('id')
            ->get(['sku', 'source', 'external_id'])
            ->groupBy('sku');

        foreach ($products as &$product) {
            $sku = filled($product['sku'] ?? null) ? (string) $product['sku'] : null;

            if ($sku === null) {
                continue;
            }

            $conflict = ($owners->get($sku) ?? collect())->first(
                fn (object $owner): bool => ! (
                    (string) $owner->source === (string) $this->counterparty->slug
                    && (string) $owner->external_id === (string) $product['external_id']
                ),
            );

            if ($conflict) {
                // Avoid UNIQUE(sku) failures that abort the whole import batch.
                $product['sku'] = null;
            }
        }
        unset($product);
    }

    private function categorySlug(string $name): string
    {
        $base = Str::slug($name, '-', 'uk') ?: Str::slug($name) ?: 'category';
        $base = Str::limit($base, 220, '');
        $slug = $base;
        $suffix = 2;

        while (Category::withTrashed()->where('slug', $slug)->exists()) {
            $slug = Str::limit($base, 220 - strlen((string) $suffix), '').'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function localizedNames(string $nameUaTag, string $nameTag): array
    {
        return [
            $nameUaTag !== '' ? $nameUaTag : $nameTag,
            $nameTag !== '' ? $nameTag : $nameUaTag,
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function localizedDescriptions(?string $descriptionUaTag, ?string $descriptionTag): array
    {
        $descriptionUaTag = trim((string) $descriptionUaTag);
        $descriptionTag = trim((string) $descriptionTag);

        $uk = $descriptionUaTag !== '' ? $descriptionUaTag : $descriptionTag;
        $ru = $descriptionTag !== '' ? $descriptionTag : $descriptionUaTag;

        return [
            $uk !== '' ? $uk : null,
            $ru !== '' ? $ru : null,
        ];
    }

    private function looksUkrainian(string $text): bool
    {
        return (bool) preg_match('/[іїєґ]/u', $text);
    }

    private function buildSpecifications(SimpleXMLElement $offer, string $locale): array
    {
        $neutral = [];
        $explicitUk = [];
        $explicitRu = [];

        foreach ($offer->param as $param) {
            $name = trim((string) ($param['name'] ?? ''));
            $value = trim(strip_tags((string) $param));

            if ($name === '' || $value === '') {
                continue;
            }

            $lower = mb_strtolower($name);
            $cleanName = $this->cleanParamName($name);

            if (str_contains($lower, '(укр)')) {
                $explicitUk[$cleanName] = $this->ukrainianParamValue($cleanName, $value);

                continue;
            }

            if (str_contains($lower, '(рос)')) {
                $explicitRu[$cleanName] = $value;

                continue;
            }

            if ($this->looksUkrainian($name)) {
                $explicitUk[$cleanName] = $this->ukrainianParamValue($cleanName, $value);
                $explicitRu[$cleanName] = $value;

                continue;
            }

            $neutral[$cleanName] = $locale === 'uk'
                ? $this->ukrainianParamValue($cleanName, $value)
                : $value;
        }

        if ($locale === 'uk') {
            $specs = [...$neutral, ...$explicitUk];
        } else {
            $specs = [...$neutral, ...$explicitRu];
        }

        if ($this->feedProfile === 'ranger') {
            $descriptionHtml = $locale === 'uk'
                ? (string) ($offer->{'description_ua'} ?? '')
                : (string) ($offer->description ?? '');

            return $this->withManufacturerArticle($offer, [
                ...$specs,
                ...$this->parseRangerDescriptionSpecifications($descriptionHtml),
            ]);
        }

        return $this->withManufacturerArticle($offer, $specs);
    }

    /**
     * @param  array<string, string>  $specs
     * @return array<string, string>
     */
    private function withManufacturerArticle(SimpleXMLElement $offer, array $specs): array
    {
        if (isset($specs['Артикул виробника'])) {
            return $specs;
        }

        $barcode = trim((string) ($offer->barcode ?? ''));

        if ($barcode !== '') {
            $specs['Артикул виробника'] = $barcode;
        }

        return $specs;
    }

    /**
     * @return array<string, string>
     */
    private function parseRangerDescriptionSpecifications(string $html): array
    {
        $html = trim($html);

        if ($html === '') {
            return [];
        }

        $sectionSpecs = [];

        if (preg_match('/Характеристик(?:и|ики)\s*:.*?(<\/strong>)?\s*(.*)$/uis', $html, $match)) {
            $section = preg_replace('/\[(?:embed|video)[^\]]*\].*$/uis', '', $match[2]) ?? $match[2];
            $sectionSpecs = $this->parseRangerSpecificationSection(trim($section));
        }

        if ($sectionSpecs !== []) {
            return $sectionSpecs;
        }

        return $this->parseRangerInlineStrongSpecs($html);
    }

    /**
     * @return array<string, string>
     */
    private function parseRangerSpecificationSection(string $section): array
    {
        if ($section === '') {
            return [];
        }

        $specs = [];

        foreach (preg_split('/;/u', $section) ?: [] as $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                continue;
            }

            if (preg_match('/^(?:(?:—|–|-)\s*)?<strong>\s*([^<]+?)\s*<\/strong>\s*:?\s*(.*)$/us', $segment, $item)) {
                $this->addRangerDescriptionSpec($specs, $item[1], $item[2]);

                continue;
            }

            if (preg_match('/^(?:—|–|-)\s*([^:]+):\s*(.+)$/u', $segment, $item)) {
                $this->addRangerDescriptionSpec($specs, $item[1], $item[2]);
            }
        }

        return $specs;
    }

    /**
     * @return array<string, string>
     */
    private function parseRangerInlineStrongSpecs(string $html): array
    {
        $specs = [];

        if (! preg_match_all(
            '/(?:—|–|-)\s*<strong>\s*([^<]+?)\s*<\/strong>\s*:?\s*(.*?)(?=\s*(?:—|–|-)\s*<strong>|\s*<strong>RANGER\b|$)/uis',
            $html,
            $matches,
            PREG_SET_ORDER,
        )) {
            return [];
        }

        foreach ($matches as $item) {
            $this->addRangerDescriptionSpec($specs, $item[1], $item[2]);
        }

        return $specs;
    }

    /**
     * @param  array<string, string>  $specs
     */
    private function addRangerDescriptionSpec(array &$specs, string $rawName, string $rawValue): void
    {
        $name = $this->normalizeRangerSpecName($rawName);
        $value = $this->normalizeRangerSpecValue($rawValue);

        if ($name === '' || $value === '') {
            return;
        }

        $specs[$name] = $value;
    }

    private function normalizeRangerSpecName(string $name): string
    {
        $name = trim(strip_tags($name));
        $name = preg_replace('/^(?:\s|—|–|-)+/u', '', $name) ?? $name;
        $name = rtrim($name, ':');
        $name = trim($name);

        if ($name === '') {
            return '';
        }

        return mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);
    }

    private function normalizeRangerSpecValue(string $value): string
    {
        $value = trim(strip_tags($value));
        $value = rtrim($value, ';.');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function cleanParamName(string $name): string
    {
        return trim((string) preg_replace('/\s*\((укр|рос)\)\s*/ui', '', $name));
    }

    private function ukrainianParamValue(string $name, string $value): string
    {
        if ($name === 'Сезон') {
            return $this->translateSeasonToUkrainian($value) ?? $value;
        }

        if ($name === 'Цвет' || $name === 'Колір') {
            return $this->translateColorToUkrainian($value) ?? $value;
        }

        if ($name === 'Состояние') {
            return match (mb_strtolower(trim($value))) {
                'новое' => 'Нове',
                default => $value,
            };
        }

        return $value;
    }

    private function translateSeasonToUkrainian(?string $season): ?string
    {
        if ($season === null || $season === '') {
            return null;
        }

        return match (mb_strtolower(trim($season))) {
            'лето', 'літо' => 'Літо',
            'зима' => 'Зима',
            'весна' => 'Весна',
            'осень', 'осінь' => 'Осінь',
            default => $season,
        };
    }

    private function translateColorToUkrainian(?string $color): ?string
    {
        if ($color === null || $color === '') {
            return null;
        }

        return match (mb_strtolower(trim($color))) {
            'темно-синий', 'темно-синяя', 'темно-синее' => 'Темно-синій',
            'красный', 'красная', 'красное' => 'Червоний',
            'черный', 'черная', 'черное' => 'Чорний',
            'белый', 'белая', 'белое' => 'Білий',
            default => $color,
        };
    }

    private function productSlug(string $name, string $externalId): string
    {
        $nameWithoutExternalId = preg_replace(
            '/(?<!\d)'.preg_quote($externalId, '/').'(?!\d)/u',
            ' ',
            $name,
        ) ?: $name;
        $base = Str::limit(Str::slug($nameWithoutExternalId) ?: 'tovar', 220, '');
        $slug = $base;
        $suffix = 2;

        while (isset($this->usedProductSlugs[$slug]) || Product::query()->where('slug', $slug)->exists()) {
            $slug = Str::limit($base, 220 - strlen((string) $suffix), '').'-'.$suffix;
            $suffix++;
        }

        $this->usedProductSlugs[$slug] = true;

        return $slug;
    }

    private function description(string $description): ?string
    {
        return PlainText::fromHtml($description);
    }

    private function price(string $price): float
    {
        return (float) str_replace(',', '.', preg_replace('/[^\d,.]/', '', $price) ?? '');
    }
}
