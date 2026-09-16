<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Product;
use App\Support\CatalogCache;
use App\Support\CounterpartyFeedImportProgress;
use App\Support\PlainText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use XMLReader;

class IbisFeedImporter
{
    private const DEFAULT_SOURCE = 'ibis-gear';

    private const BATCH_SIZE = 500;

    private ?Counterparty $counterparty = null;

    private ?int $trackProgressForCounterpartyId = null;

    private ?string $cacheClearTrigger = null;

    private array $categories = [];

    private array $existingProductSlugs = [];

    private array $usedProductSlugs = [];

    public function __construct(private readonly CatalogCache $cache) {}

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

    public function importCounterparty(Counterparty $counterparty, ?callable $progress = null): array
    {
        if (blank($counterparty->feed_url)) {
            throw new RuntimeException('URL фіду не вказано.');
        }

        $file = tempnam(sys_get_temp_dir(), 'ibis-counterparty-feed-');

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

            return $this->importCounterpartyFile($counterparty, $file, $progress);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function importCounterpartyFile(Counterparty $counterparty, string $file, ?callable $progress = null): array
    {
        $this->counterparty = $counterparty;

        try {
            $result = $this->import($file, false, $progress);

            $counterparty->update([
                'last_synced_at' => now(),
                'last_sync_products_count' => $result['products'],
                'last_sync_error' => null,
            ]);

            return $result;
        } catch (Throwable $exception) {
            $counterparty->update([
                'last_sync_error' => Str::limit($exception->getMessage(), 1000),
            ]);

            throw $exception;
        } finally {
            $this->counterparty = null;
            $this->trackProgressForCounterpartyId = null;
        }
    }

    public function import(string $file, bool $replace = false, ?callable $progress = null): array
    {
        if (! is_file($file)) {
            throw new RuntimeException("Feed file does not exist: {$file}");
        }

        $this->categories = [];

        if ($replace) {
            Product::query()->delete();
            Category::withTrashed()->forceDelete();
        } else {
            Product::query()
                ->where('source', $this->source())
                ->where('is_processed', true)
                ->update(['is_active' => false]);
        }

        $this->existingProductSlugs = Product::query()
            ->where('source', $this->source())
            ->whereNotNull('external_id')
            ->pluck('slug', 'external_id')
            ->all();
        $this->usedProductSlugs = Product::query()
            ->pluck('slug')
            ->filter()
            ->flip()
            ->map(fn (): bool => true)
            ->all();

        $reader = new XMLReader;

        if (! $reader->open($file, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new RuntimeException("Unable to open XML feed: {$file}");
        }

        $batch = [];
        $imported = 0;
        $total = $this->countItems($file);
        $this->reportProgress('Імпорт товарів', 0, $total);

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'item') {
                continue;
            }

            $batch[] = $this->mapItem($reader->readOuterXml());

            if (count($batch) >= self::BATCH_SIZE) {
                $this->upsertProducts($batch);
                $imported += count($batch);
                $batch = [];
                $this->reportProgress('Імпорт товарів', $imported, $total, ['products_imported' => $imported]);
                $progress?->__invoke($imported);
            }
        }

        if ($batch !== []) {
            $this->upsertProducts($batch);
            $imported += count($batch);
            $this->reportProgress('Імпорт товарів', $imported, $total, ['products_imported' => $imported]);
            $progress?->__invoke($imported);
        }

        $reader->close();
        $this->invalidateCatalogCache();

        return [
            'products' => $imported,
            'categories' => count($this->categories),
        ];
    }

    private function source(): string
    {
        return $this->counterparty?->slug ?: self::DEFAULT_SOURCE;
    }

    private function countItems(string $file): int
    {
        $reader = new XMLReader;

        if (! $reader->open($file, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            return 0;
        }

        $count = 0;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'item') {
                $count++;
            }
        }

        $reader->close();

        return $count;
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

    private function invalidateCatalogCache(): void
    {
        $reason = $this->counterparty
            ? 'Імпорт фіду: '.$this->counterparty->name
            : 'Імпорт фіду IBIS';

        if ($this->cacheClearTrigger !== null) {
            $this->cache->invalidateAndLog($this->cacheClearTrigger, $reason);

            return;
        }

        $this->cache->invalidateAndLog(
            \App\Models\CatalogCacheLog::TRIGGER_CLI,
            $reason,
        );
    }

    private function mapItem(string $xml): array
    {
        $item = simplexml_load_string($xml);

        if ($item === false) {
            throw new RuntimeException('Unable to parse an item from the feed.');
        }

        $google = $item->children('http://base.google.com/ns/1.0');
        $externalId = trim((string) $google->id);
        $name = trim((string) $google->title);
        $nameRu = trim((string) ($google->title_ru ?? '')) ?: $name;
        $images = collect();

        foreach ($google->image_link as $image) {
            $images->push(trim((string) $image));
        }

        $images = $images->filter()->unique()->values();
        $specifications = $this->specifications($google->prop, 'uk');
        $specificationsRu = $this->specifications($google->prop, 'ru') ?: $specifications;
        $price = $this->price((string) $google->price);
        $salePrice = $this->price((string) $google->sale_price) ?: null;
        $categoryParts = $this->categoryParts((string) $google->google_product_category);
        $categoryPathRu = trim((string) ($google->google_product_category_ru ?? ''));
        $categoryPartsRu = $categoryPathRu === '' ? $categoryParts : $this->categoryParts($categoryPathRu);
        $category = $this->category($categoryParts, $categoryPartsRu, $images->first());
        $description = $this->description((string) $google->description);
        $descriptionRu = $this->description((string) ($google->description_ru ?? '')) ?: $description;
        $now = now();
        $groupId = trim((string) ($item['group_id'] ?? ''));
        $variantId = $groupId !== ''
            ? $this->source().'-group-'.$groupId
            : $this->source().'-'.$externalId;

        return [
            'category_id' => $category->id,
            'source_feed_category_id' => $category->id,
            'counterparty_id' => $this->counterparty?->id,
            'source' => $this->source(),
            'external_id' => $externalId,
            'variant_id' => $variantId,
            'external_url' => trim((string) $google->link) ?: null,
            'name' => $name,
            'name_ru' => $nameRu,
            'slug' => $slug = $this->existingProductSlugs[$externalId] ?? $this->productSlug($name, $externalId),
            'slug_ru' => $slug,
            'sku' => null,
            'description' => $description,
            'description_ru' => $descriptionRu,
            'content' => $description,
            'content_ru' => $descriptionRu,
            'seo_title' => $name.' купити в Kubii',
            'seo_title_ru' => $nameRu.' купить в Kubii',
            'meta_description' => Str::limit($description ?: $name.' - туристичне та рибальське спорядження Kubii.', 155),
            'meta_description_ru' => Str::limit($descriptionRu ?: $nameRu.' - туристическое и рыболовное снаряжение Kubii.', 155),
            'h1' => $name,
            'h1_ru' => $nameRu,
            'is_indexable' => true,
            'canonical_type' => 'self',
            'brand' => trim((string) $google->brand) ?: null,
            'model' => $specifications['Модель'] ?? null,
            'season' => $specifications['Сезон'] ?? null,
            'season_ru' => $specificationsRu['Сезон'] ?? null,
            'usage_type' => $categoryParts->first(),
            'usage_type_ru' => $categoryPartsRu->first() ?: $categoryParts->first(),
            'material' => $specifications['Матеріал'] ?? null,
            'material_ru' => $specificationsRu['Материал'] ?? $specificationsRu['Матеріал'] ?? null,
            'weight_grams' => $this->weight($specifications),
            'specifications' => json_encode($specifications, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'specifications_ru' => json_encode($specificationsRu, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'price' => $price,
            'sale_price' => $salePrice,
            'discount_percent' => $salePrice && $price > $salePrice ? round((1 - $salePrice / $price) * 100) : 0,
            'image_url' => $images->first(),
            'gallery_images' => json_encode($images->slice(1)->values()->all(), JSON_UNESCAPED_SLASHES),
            'content_images' => json_encode($images->take(3)->values()->all(), JSON_UNESCAPED_SLASHES),
            'stock' => (int) $google->availability > 0 ? 10 : 0,
            'is_featured' => false,
            'is_active' => false,
            'is_processed' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function upsertProducts(array $products): void
    {
        $existing = DB::table('products')
            ->where('source', $this->source())
            ->whereIn('external_id', array_column($products, 'external_id'))
            ->get(['external_id', 'category_id', 'slug', 'sku', 'is_processed', 'is_active', 'created_at'])
            ->keyBy('external_id');

        foreach ($products as &$product) {
            $current = $existing->get($product['external_id']);

            if (! $current) {
                continue;
            }

            $product['category_id'] = $current->category_id;
            $product['slug'] = $current->slug;
            $product['slug_ru'] = $current->slug;
            $product['sku'] = $current->sku;
            $product['is_processed'] = (bool) $current->is_processed;
            $product['is_active'] = (bool) $current->is_active;
            $product['created_at'] = $current->created_at;
        }
        unset($product);

        DB::table('products')->upsert(
            $products,
            ['source', 'external_id'],
            array_keys(collect($products)->first()),
        );

        $missingCodes = DB::table('products')
            ->where('source', $this->source())
            ->whereIn('external_id', array_column($products, 'external_id'))
            ->whereNull('sku')
            ->get(['id']);

        if ($missingCodes->isNotEmpty()) {
            foreach ($missingCodes as $product) {
                DB::table('products')->where('id', $product->id)->update([
                    'sku' => Product::siteSkuForId((int) $product->id),
                ]);
            }
        }
    }

    private function category(Collection $parts, Collection $partsRu, ?string $image): Category
    {
        $parentId = null;
        $path = [];
        $category = null;

        foreach ($parts as $index => $part) {
            $path[] = $part;
            $key = implode(' > ', $path);
            $partRu = $partsRu->get($index) ?: $part;

            if (! isset($this->categories[$key])) {
                $slug = $this->categorySlug($this->counterparty ? [$this->source(), ...$path] : $path);
                $externalId = sha1($key);
                $category = $this->counterparty
                    ? Category::withTrashed()->firstOrCreate(
                        [
                            'counterparty_id' => $this->counterparty->id,
                            'external_id' => $externalId,
                        ],
                        [
                            'name' => $part,
                            'name_ru' => $partRu,
                            'slug' => $slug,
                            'slug_ru' => $slug,
                            'parent_id' => $parentId,
                            'is_active' => false,
                        ],
                    )
                    : Category::withTrashed()->firstOrCreate(
                        ['slug' => $slug],
                        [
                            'name' => $part,
                            'name_ru' => $partRu,
                            'slug_ru' => $slug,
                            'parent_id' => $parentId,
                            'is_active' => true,
                        ],
                    );

                if (! $category->getRawOriginal('name_ru')) {
                    $category->update([
                        'name_ru' => $partRu,
                        'slug_ru' => $slug,
                        ...($this->counterparty ? [
                            'name' => $part,
                            'parent_id' => $parentId,
                        ] : []),
                    ]);
                } elseif ($category->getRawOriginal('slug_ru') !== $slug) {
                    $category->update([
                        'slug_ru' => $slug,
                        ...($this->counterparty ? [
                            'name' => $part,
                            'name_ru' => $partRu,
                            'parent_id' => $parentId,
                        ] : []),
                    ]);
                }

                $this->categories[$key] = $category;
            }

            $category = $this->categories[$key];

            if ($image && ! $category->image_path && ! $category->image_url) {
                $category->update(['image_url' => $image]);
            }

            $parentId = $category->id;
        }

        return $category;
    }

    private function categoryParts(string $categoryPath): Collection
    {
        $parts = collect(explode('>', html_entity_decode($categoryPath)))
            ->map(fn (string $part): string => trim($part))
            ->filter()
            ->values();

        if (in_array($parts->first(), ['ІБІС Риболовля', 'ИБИС Рыбалка'], true)) {
            $parts->shift();
        }

        return $parts->isEmpty() ? collect(['Інше']) : $parts;
    }

    private function categorySlug(array $path): string
    {
        $slug = Str::slug(implode('-', $path));

        return Str::limit($slug, 210, '').'-'.substr(sha1(implode('|', $path)), 0, 10);
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

        while (isset($this->usedProductSlugs[$slug])) {
            $slug = Str::limit($base, 220 - strlen((string) $suffix), '').'-'.$suffix;
            $suffix++;
        }

        $this->usedProductSlugs[$slug] = true;

        return $slug;
    }

    private function specifications(iterable $properties, string $locale): array
    {
        $specifications = [];

        foreach ($properties as $property) {
            $attributes = $property->attributes();
            $name = $locale === 'uk'
                ? (trim((string) $attributes['name_ua']) ?: trim((string) $attributes['name']))
                : (trim((string) $attributes['name_ru']) ?: trim((string) $attributes['name']) ?: trim((string) $attributes['name_ua']));
            $value = $locale === 'uk'
                ? (trim((string) $attributes['value_ua']) ?: trim((string) $property))
                : (trim((string) $attributes['value_ru']) ?: trim((string) $property) ?: trim((string) $attributes['value_ua']));

            if (in_array(mb_strtolower($name), ['код товару', 'код товара'], true)) {
                continue;
            }

            if ($name !== '' && $value !== '') {
                $specifications[$name] = $value;
            }
        }

        return $specifications;
    }

    private function description(string $description): ?string
    {
        return PlainText::fromHtml($description);
    }

    private function price(string $price): float
    {
        return (float) str_replace(',', '.', preg_replace('/[^\d,.]/', '', $price) ?? '');
    }

    private function weight(array $specifications): ?int
    {
        foreach (['Маса, г', 'Вага, г'] as $key) {
            if (isset($specifications[$key]) && preg_match('/[\d,.]+/', $specifications[$key], $matches)) {
                return (int) round((float) str_replace(',', '.', $matches[0]));
            }
        }

        if (isset($specifications['Вага в упаковці, кг']) && preg_match('/[\d,.]+/', $specifications['Вага в упаковці, кг'], $matches)) {
            return (int) round((float) str_replace(',', '.', $matches[0]) * 1000);
        }

        return null;
    }
}
