<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Support\PlainText;
use App\Support\StoredAsset;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use XMLWriter;

class GoogleMerchantFeedGenerator
{
    private const GOOGLE_NAMESPACE = 'http://base.google.com/ns/1.0';

    /**
     * @return array{started_at: string, finished_at: string, products: int, skipped: int, skipped_reasons: array<string, int>, path: string, bytes: int}
     */
    public function generate(): array
    {
        $startedAt = now();
        $disk = Storage::disk('public');
        $relativePath = (string) config('google_merchant.path', 'feeds/google-merchant-feed.xml');
        $directory = trim(dirname($relativePath), '.');
        $temporaryPath = $relativePath.'.tmp-'.Str::uuid();

        if ($directory !== '') {
            $disk->makeDirectory($directory);
        }

        $absoluteTemporaryPath = $disk->path($temporaryPath);
        $absoluteTargetPath = $disk->path($relativePath);
        $writer = new XMLWriter;

        if (! $writer->openURI($absoluteTemporaryPath)) {
            throw new RuntimeException('Unable to open temporary Google Merchant feed file.');
        }

        $products = 0;
        $skippedReasons = [];
        $categories = $this->categoryIndex();

        try {
            $this->startDocument($writer);

            Product::query()
                ->select([
                    'id',
                    'category_id',
                    'name',
                    'slug',
                    'sku',
                    'description',
                    'content',
                    'brand',
                    'price',
                    'sale_price',
                    'discount_percent',
                    'image_url',
                    'image_path',
                    'gallery_images',
                    'gallery_paths',
                    'stock',
                    'is_featured',
                    'is_active',
                    'is_visible_in_catalog',
                ])
                ->chunkById((int) config('google_merchant.chunk_size', 500), function ($chunk) use ($categories, $writer, &$products, &$skippedReasons): void {
                    foreach ($chunk as $product) {
                        $item = $this->item($product, $categories);

                        if (isset($item['skip'])) {
                            $reason = $item['skip'];
                            $skippedReasons[$reason] = ($skippedReasons[$reason] ?? 0) + 1;

                            continue;
                        }

                        $this->writeItem($writer, $item);
                        $products++;
                    }
                });

            $writer->endElement();
            $writer->endElement();
            $writer->endDocument();
            $writer->flush();

            if (! @rename($absoluteTemporaryPath, $absoluteTargetPath)) {
                throw new RuntimeException('Unable to atomically publish the Google Merchant feed.');
            }

            clearstatcache(true, $absoluteTargetPath);
            $result = [
                'started_at' => $startedAt->toIso8601String(),
                'finished_at' => now()->toIso8601String(),
                'products' => $products,
                'skipped' => array_sum($skippedReasons),
                'skipped_reasons' => $skippedReasons,
                'path' => $absoluteTargetPath,
                'bytes' => (int) (filesize($absoluteTargetPath) ?: 0),
            ];

            Log::info('Google Merchant feed generated.', $result);

            return $result;
        } catch (Throwable $exception) {
            $writer->flush();
            @unlink($absoluteTemporaryPath);

            Log::error('Google Merchant feed generation failed.', [
                'started_at' => $startedAt->toIso8601String(),
                'path' => $absoluteTargetPath,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function startDocument(XMLWriter $writer): void
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        $writer->startDocument('1.0', 'UTF-8');
        $writer->setIndent(true);
        $writer->startElement('rss');
        $writer->writeAttribute('version', '2.0');
        $writer->writeAttribute('xmlns:g', self::GOOGLE_NAMESPACE);
        $writer->startElement('channel');
        $writer->writeElement('title', (string) config('app.name', 'Kubii').' product feed');
        $writer->writeElement('link', $baseUrl);
        $writer->writeElement('description', 'Google Merchant Center product feed');
    }

    /**
     * @param  array<int, array{id: int, parent_id: ?int, name: string, slug: string, is_active: bool}>  $categories
     * @return array<string, mixed>
     */
    private function item(Product $product, array $categories): array
    {
        if (! $product->is_active || ! $product->is_visible_in_catalog) {
            return ['skip' => 'disabled'];
        }

        if ((float) $product->price <= 0 || $product->salePrice() <= 0) {
            return ['skip' => 'no_price'];
        }

        if ((int) $product->stock <= 0) {
            return ['skip' => 'not_purchasable'];
        }

        if (blank($product->slug)) {
            return ['skip' => 'no_url'];
        }

        $trail = $this->categoryTrail((int) $product->category_id, $categories);

        if ($trail === [] || collect($trail)->contains(fn (array $category): bool => ! $category['is_active'])) {
            return ['skip' => 'no_category'];
        }

        if (blank($product->image_path) && blank($product->image_url)) {
            return ['skip' => 'no_image'];
        }

        $image = StoredAsset::url($product->image_path) ?: StoredAsset::url($product->image_url);
        $link = $this->productUrl($product, $trail[0]);

        if (! $this->absoluteHttpUrl($image)) {
            return ['skip' => 'no_image'];
        }

        if (! $this->absoluteHttpUrl($link)) {
            return ['skip' => 'no_url'];
        }

        $title = $this->clean((string) $product->name, 150);

        if ($title === '') {
            return ['skip' => 'no_title'];
        }

        $productType = collect($trail)->pluck('name')->filter()->implode(' > ');
        $description = $this->description($product, $productType);
        $basePrice = (float) $product->price;
        $effectivePrice = $product->salePrice();
        $onSale = $effectivePrice < $basePrice;
        $rootSlug = $trail[0]['slug'];

        return [
            'id' => (string) $product->id,
            'title' => $title,
            'description' => $description,
            'link' => $link,
            'image_link' => $image,
            'additional_image_links' => $this->additionalImages($product, $image),
            'availability' => 'in_stock',
            'price' => $this->price($onSale ? $basePrice : $effectivePrice),
            'sale_price' => $onSale ? $this->price($effectivePrice) : null,
            'condition' => 'new',
            'brand' => $this->clean((string) $product->brand, 70),
            'mpn' => $this->clean((string) $product->sku, 70),
            'product_type' => $productType,
            'google_product_category' => config('google_merchant.google_product_categories.'.$rootSlug)
                ?: config('google_merchant.default_google_product_category'),
            'custom_label_0' => $trail[0]['name'],
            'custom_label_1' => $this->clean((string) $product->brand, 100),
            'custom_label_2' => $product->is_featured ? 'priority_high' : ((int) $product->stock <= 2 ? 'priority_low' : 'priority_normal'),
            'custom_label_3' => $onSale ? 'sale' : 'regular',
            'custom_label_4' => 'in_stock',
        ];
    }

    /** @param array<string, mixed> $item */
    protected function writeItem(XMLWriter $writer, array $item): void
    {
        $writer->startElement('item');

        foreach (['id', 'title', 'description', 'link', 'image_link', 'availability', 'price', 'sale_price', 'condition', 'brand', 'mpn', 'product_type', 'google_product_category', 'custom_label_0', 'custom_label_1', 'custom_label_2', 'custom_label_3', 'custom_label_4'] as $field) {
            if (filled($item[$field] ?? null)) {
                $writer->writeElementNs('g', $field, self::GOOGLE_NAMESPACE, (string) $item[$field]);
            }
        }

        foreach ($item['additional_image_links'] as $image) {
            $writer->writeElementNs('g', 'additional_image_link', self::GOOGLE_NAMESPACE, $image);
        }

        $writer->endElement();
    }

    /**
     * @return array<int, array{id: int, parent_id: ?int, name: string, slug: string, is_active: bool}>
     */
    private function categoryIndex(): array
    {
        return Category::query()
            ->get(['id', 'parent_id', 'name', 'slug', 'is_active'])
            ->mapWithKeys(fn (Category $category): array => [
                $category->id => [
                    'id' => (int) $category->id,
                    'parent_id' => $category->parent_id ? (int) $category->parent_id : null,
                    'name' => $this->clean((string) $category->getRawOriginal('name'), 750),
                    'slug' => $category->publicSlug('uk'),
                    'is_active' => (bool) $category->is_active,
                ],
            ])
            ->all();
    }

    /**
     * @param  array<int, array{id: int, parent_id: ?int, name: string, slug: string, is_active: bool}>  $categories
     * @return list<array{id: int, parent_id: ?int, name: string, slug: string, is_active: bool}>
     */
    private function categoryTrail(int $categoryId, array $categories): array
    {
        $trail = [];
        $seen = [];

        while (isset($categories[$categoryId]) && ! isset($seen[$categoryId])) {
            $seen[$categoryId] = true;
            array_unshift($trail, $categories[$categoryId]);
            $categoryId = (int) ($categories[$categoryId]['parent_id'] ?? 0);
        }

        return $trail;
    }

    /** @param array{id: int, parent_id: ?int, name: string, slug: string, is_active: bool} $rootCategory */
    private function productUrl(Product $product, array $rootCategory): string
    {
        return rtrim((string) config('app.url'), '/')
            .'/'.rawurlencode($rootCategory['slug'])
            .'/products/'.rawurlencode((string) $product->getRawOriginal('slug'));
    }

    private function description(Product $product, string $productType): string
    {
        $description = PlainText::fromHtml($product->description)
            ?: PlainText::fromHtml($product->content);

        if (blank($description)) {
            $description = collect([$product->name, $product->brand, $productType])
                ->filter()
                ->unique()
                ->implode('. ');
        }

        return $this->clean((string) $description, 5000);
    }

    /** @return list<string> */
    private function additionalImages(Product $product, string $mainImage): array
    {
        return collect([
            ...StoredAsset::urls($product->gallery_paths ?? []),
            ...StoredAsset::urls($product->gallery_images ?? []),
        ])
            ->filter(fn (string $url): bool => $url !== $mainImage && $this->absoluteHttpUrl($url))
            ->unique()
            ->take((int) config('google_merchant.additional_images_limit', 10))
            ->values()
            ->all();
    }

    private function price(float $price): string
    {
        return number_format($price, 2, '.', '').' UAH';
    }

    private function clean(string $value, int $limit): string
    {
        $value = PlainText::fromHtml($value) ?? '';
        $value = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim(mb_substr($value, 0, $limit));
    }

    private function absoluteHttpUrl(?string $url): bool
    {
        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
    }
}
