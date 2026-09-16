<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Product;
use App\Support\CatalogCache;
use App\Support\CategorySpecFilterSynchronizer;
use App\Support\CounterpartyFeedImportProgress;
use App\Support\PlainText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use Throwable;
use XMLReader;

class VoltmarketFeedImporter
{
    private const BATCH_SIZE = 200;

    private const FEED_ROOTS = [
        'istochniki-bespereboinogo-pitaniya',
        'invertory',
        'zaryadnye-ustroistva',
    ];

    private Counterparty $counterparty;

    /** @var array<string, int> */
    private array $categoryMap = [];

    /** @var array<int, int> */
    private array $categoryTargets = [];

    /** @var array<string, string> */
    private array $existingProductSlugs = [];

    private ?int $trackProgressForCounterpartyId = null;

    private ?string $cacheClearTrigger = null;

    public function __construct(
        private readonly CatalogCache $cache,
        private readonly ProductVariantGrouper $variantGrouper,
    ) {}

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

        $file = tempnam(sys_get_temp_dir(), 'voltmarket-feed-');

        if ($file === false) {
            throw new RuntimeException('Не вдалося створити тимчасовий файл для фіду Voltmarket.');
        }

        try {
            $this->reportProgress('Завантаження фіду Voltmarket');

            Http::timeout(1200)
                ->connectTimeout(30)
                ->sink($file)
                ->get($counterparty->feed_url)
                ->throw();

            $result = $this->importFile($counterparty, $file, $progress);

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

        $this->categoryMap = [];
        $this->loadExistingCategories();
        $this->loadCategoryTargets();
        $this->loadExistingProductSlugs();

        $total = $this->countMatchingEntries($file);
        $this->reportProgress('Імпорт товарів Voltmarket', 0, $total);

        $reader = new XMLReader;

        if (! $reader->open($file, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new RuntimeException("Unable to open Voltmarket feed: {$file}");
        }

        $batch = [];
        $imported = 0;
        $categoryIds = [];

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'entry') {
                continue;
            }

            $product = $this->mapEntry($reader->readOuterXml());

            if ($product === null) {
                continue;
            }

            $categoryIds[] = (int) $product['source_feed_category_id'];
            $batch[] = $product;

            if (count($batch) >= self::BATCH_SIZE) {
                $this->upsertProducts($batch);
                $imported += count($batch);
                $batch = [];
                $this->reportProgress('Імпорт товарів Voltmarket', $imported, $total, ['products_imported' => $imported]);
                $progress?->__invoke($imported);
            }
        }

        if ($batch !== []) {
            $this->upsertProducts($batch);
            $imported += count($batch);
            $this->reportProgress('Імпорт товарів Voltmarket', $imported, $total, ['products_imported' => $imported]);
            $progress?->__invoke($imported);
        }

        $reader->close();

        $result = $this->finishImport($categoryIds, $imported);

        $counterparty->update([
            'last_synced_at' => now(),
            'last_sync_products_count' => $result['products'],
            'last_sync_error' => null,
        ]);

        $this->invalidateCatalogCache();

        return $result;
    }

    private function finishImport(array $categoryIds, int $imported): array
    {
        @set_time_limit(0);

        $categoryIds = array_values(array_unique(array_map(intval(...), $categoryIds)));

        $this->reportProgress('Групування варіантів Voltmarket');

        $grouping = $this->variantGrouper->discoverScoped($categoryIds, includeInactive: true);

        $this->reportProgress('Оновлення фільтрів Voltmarket', extra: [
            'variant_groups_created' => $grouping['created'],
        ]);

        app(CategorySpecFilterSynchronizer::class)->syncByCategoryIds($categoryIds, includeInactive: true);

        return [
            'products' => $imported,
            'categories' => count($this->categoryMap),
            'variant_groups_created' => $grouping['created'],
            'variant_groups_skipped' => $grouping['skipped'],
        ];
    }

    private function countMatchingEntries(string $file): int
    {
        $reader = new XMLReader;

        if (! $reader->open($file, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            return 0;
        }

        $count = 0;

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'entry') {
                continue;
            }

            if ($this->mapEntry($reader->readOuterXml(), dryRun: true) !== null) {
                $count++;
            }
        }

        $reader->close();

        return $count;
    }

    private function mapEntry(string $xml, bool $dryRun = false): ?array
    {
        $entry = simplexml_load_string($xml);

        if (! $entry instanceof SimpleXMLElement) {
            return null;
        }

        $g = $entry->children('http://base.google.com/ns/1.0');
        $externalId = trim((string) ($g->id ?? ''));
        $link = trim((string) ($g->link ?? ''));
        $productType = trim((string) ($g->product_type ?? ''));
        $categoryPath = $this->categoryPathFor($link, $productType);

        if ($externalId === '' || $link === '' || $categoryPath === []) {
            return null;
        }

        if ($dryRun) {
            return [];
        }

        $feedCategoryId = $this->categoryForPath($categoryPath);
        $categoryId = $this->categoryTargets[$feedCategoryId] ?? $feedCategoryId;
        $name = trim((string) ($g->title ?? ''));
        $description = $this->description((string) ($g->description ?? ''));
        $price = $this->price((string) ($g->price ?? '0'));
        $salePrice = $this->price((string) ($g->sale_price ?? '')) ?: null;
        $salePrice = $salePrice !== null && $salePrice > 0 && $salePrice < $price ? $salePrice : null;
        $imageUrl = ProductImageMirror::normalizeSourceUrl(trim((string) ($g->image_link ?? '')) ?: null);
        $specifications = $this->specifications($g);
        $sku = 'VM-'.$externalId;
        $now = now();

        return [
            'category_id' => $categoryId,
            'source_feed_category_id' => $feedCategoryId,
            'counterparty_id' => $this->counterparty->id,
            'source' => $this->counterparty->slug,
            'external_id' => $externalId,
            'variant_id' => $this->counterparty->slug.'-'.$externalId,
            'external_url' => $link,
            'name' => $name,
            'name_ru' => $name,
            'slug' => $this->existingProductSlugs[$externalId] ?? $this->productSlug($name, $externalId),
            'slug_ru' => $this->existingProductSlugs[$externalId] ?? $this->productSlug($name, $externalId),
            'sku' => $sku,
            'description' => $description,
            'description_ru' => $description,
            'content' => $description,
            'content_ru' => $description,
            'seo_title' => $name.' купити в Kubii',
            'seo_title_ru' => $name.' купить в Kubii',
            'meta_description' => Str::limit($description ?: $name.' - обладнання Voltmarket у Kubii.', 155),
            'meta_description_ru' => Str::limit($description ?: $name.' - оборудование Voltmarket в Kubii.', 155),
            'h1' => $name,
            'h1_ru' => $name,
            'is_indexable' => true,
            'canonical_type' => 'self',
            'brand' => trim((string) ($g->brand ?? '')) ?: null,
            'model' => null,
            'season' => null,
            'season_ru' => null,
            'usage_type' => null,
            'usage_type_ru' => null,
            'material' => null,
            'material_ru' => null,
            'weight_grams' => null,
            'specifications' => json_encode($specifications, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'specifications_ru' => json_encode($specifications, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'price' => $price,
            'sale_price' => $salePrice,
            'discount_percent' => $salePrice !== null && $price > 0 ? round((1 - $salePrice / $price) * 100) : 0,
            'image_url' => $imageUrl,
            'gallery_images' => json_encode([], JSON_UNESCAPED_SLASHES),
            'content_images' => json_encode(array_values(array_filter([$imageUrl])), JSON_UNESCAPED_SLASHES),
            'stock' => mb_strtolower(trim((string) ($g->availability ?? ''))) === 'in stock' ? 1 : 0,
            'is_featured' => false,
            'is_active' => false,
            'is_processed' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @return list<string>
     */
    private function categoryPathFor(string $link, string $productType): array
    {
        if (str_starts_with($productType, 'Паливні генератори')) {
            return $this->splitProductType($productType);
        }

        if (str_starts_with($productType, 'Акумуляторні батареї')) {
            return $this->splitProductType($productType);
        }

        if (str_contains($productType, 'Сонячні інвертори')) {
            return ['Сонячні інвертори'];
        }

        $path = trim((string) parse_url($link, PHP_URL_PATH), '/');

        foreach (self::FEED_ROOTS as $root) {
            if ($path === $root || str_starts_with($path, $root.'/')) {
                return match ($root) {
                    'istochniki-bespereboinogo-pitaniya' => ['Джерела безперебійного живлення'],
                    'invertory' => ['Інвертори'],
                    'zaryadnye-ustroistva' => ['Зарядні пристрої'],
                };
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function splitProductType(string $productType): array
    {
        return collect(explode('>', $productType))
            ->map(fn (string $part): string => trim($part))
            ->filter()
            ->values()
            ->all();
    }

    private function categoryForPath(array $path): int
    {
        $parentId = null;
        $externalPath = [];

        foreach ($path as $name) {
            $externalPath[] = Str::slug($name, '-', 'uk') ?: sha1($name);
            $externalId = implode('/', $externalPath);

            if (isset($this->categoryMap[$externalId])) {
                $parentId = $this->categoryMap[$externalId];

                continue;
            }

            $category = Category::withTrashed()
                ->where('counterparty_id', $this->counterparty->id)
                ->where('external_id', $externalId)
                ->first();

            if ($category) {
                $category->update([
                    'name' => $name,
                    'name_ru' => $name,
                    'parent_id' => $parentId,
                ]);
            } else {
                $category = Category::query()->create([
                    'counterparty_id' => $this->counterparty->id,
                    'external_id' => $externalId,
                    'name' => $name,
                    'name_ru' => $name,
                    'slug' => $this->categorySlug($name),
                    'parent_id' => $parentId,
                    'is_active' => false,
                ]);
            }

            $this->categoryMap[$externalId] = $category->id;
            $parentId = $category->id;
        }

        return (int) $parentId;
    }

    private function upsertProducts(array $products): void
    {
        $existing = DB::table('products')
            ->where('source', $this->counterparty->slug)
            ->whereIn('external_id', array_column($products, 'external_id'))
            ->get(['external_id', 'slug', 'sku', 'is_processed', 'is_active', 'created_at', 'image_url'])
            ->keyBy('external_id');

        $clearedImageExternalIds = [];

        foreach ($products as &$product) {
            $current = $existing->get((string) $product['external_id']);

            if (! $current) {
                continue;
            }

            $product['slug'] = $current->slug;
            $product['slug_ru'] = $current->slug;
            $product['created_at'] = $current->created_at;
            $product['is_processed'] = (bool) $current->is_processed;
            $product['is_active'] = (bool) $current->is_active;

            if (filled($current->sku)) {
                $product['sku'] = $current->sku;
            }

            if (ProductImageMirror::normalizeSourceUrl($current->image_url ?? null) !== ProductImageMirror::normalizeSourceUrl($product['image_url'] ?? null)) {
                $clearedImageExternalIds[] = $product['external_id'];
            }
        }
        unset($product);

        DB::table('products')->upsert(
            $products,
            ['source', 'external_id'],
            array_keys($products[0]),
        );

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
    }

    private function loadExistingCategories(): void
    {
        $this->categoryMap = Category::query()
            ->where('counterparty_id', $this->counterparty->id)
            ->whereNotNull('external_id')
            ->pluck('id', 'external_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function loadCategoryTargets(): void
    {
        $this->categoryTargets = Category::query()
            ->where('counterparty_id', $this->counterparty->id)
            ->whereNotNull('target_category_id')
            ->pluck('target_category_id', 'id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function loadExistingProductSlugs(): void
    {
        $this->existingProductSlugs = DB::table('products')
            ->where('source', $this->counterparty->slug)
            ->whereNotNull('external_id')
            ->pluck('slug', 'external_id')
            ->all();
    }

    private function invalidateCatalogCache(): void
    {
        $reason = 'Імпорт Voltmarket: '.$this->counterparty->name;

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

    private function description(string $html): ?string
    {
        return PlainText::fromHtml($html) ?: null;
    }

    private function price(string $value): float
    {
        $normalized = preg_replace('/[^\d.,]/', '', $value) ?? '';
        $normalized = str_replace(',', '.', $normalized);

        return (float) $normalized;
    }

    /**
     * @return array<string, string>
     */
    private function specifications(SimpleXMLElement $g): array
    {
        $specifications = [];

        foreach ($g->product_detail as $detail) {
            $name = $this->normalizeSpecificationName((string) ($detail->attribute_name ?? ''));
            $value = $this->normalizeSpecificationValue($name, (string) ($detail->attribute_value ?? ''));

            if ($name !== '' && $value !== '') {
                $specifications[$name] = $value;
            }
        }

        return $specifications;
    }

    private function normalizeSpecificationName(string $name): string
    {
        $name = trim($name);

        return match ($name) {
            'Кіл-ть фаз' => 'Кількість фаз',
            default => $name,
        };
    }

    private function normalizeSpecificationValue(string $name, string $value): string
    {
        $value = trim(strip_tags($value));

        if ($value === '') {
            return '';
        }

        if ($name === 'Тип монтажу') {
            return match (mb_strtolower($value)) {
                'настенный' => 'настінний',
                'напольный' => 'підлоговий',
                default => $value,
            };
        }

        if ($name === 'Призначення') {
            return collect(preg_split('/[;|]/u', $value) ?: [])
                ->map(fn (string $item): string => trim($item))
                ->filter()
                ->map(fn (string $item): string => match (mb_strtolower($item)) {
                    'автономного электроснабжения' => 'автономного електроживлення',
                    'для дома' => 'для дому',
                    'для дачи' => 'для дачі',
                    'для предприятия' => 'для підприємства',
                    default => $item,
                })
                ->unique()
                ->sort()
                ->values()
                ->implode(';');
        }

        return $value;
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

    private function productSlug(string $name, string $externalId): string
    {
        $base = Str::slug($name, '-', 'uk') ?: Str::slug($name) ?: 'voltmarket-product';
        $base = Str::limit($base, 200, '');
        $slug = $base;
        $suffix = 2;

        while (Product::query()->where('slug', $slug)->exists()) {
            $slug = Str::limit($base, 200 - strlen((string) $suffix), '').'-'.$suffix;
            $suffix++;
        }

        return $slug ?: 'voltmarket-'.$externalId;
    }
}
