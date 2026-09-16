<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Services\GoogleMerchantFeedGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use XMLWriter;

class GoogleMerchantFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.name', 'TripFish');
        config()->set('app.url', 'https://tripfish.com.ua');
        config()->set('filesystems.disks.public.url', 'https://tripfish.com.ua/storage');
        config()->set('google_merchant.chunk_size', 1);
        Storage::fake('public');
    }

    public function test_feed_contains_only_valid_products_with_merchant_fields(): void
    {
        $root = Category::create([
            'name' => 'Рибальство',
            'slug' => 'rybalstvo',
            'is_active' => true,
        ]);
        $child = Category::create([
            'name' => 'Вудилища',
            'slug' => 'rybalstvo-vudylyshcha',
            'parent_id' => $root->id,
            'is_active' => true,
        ]);
        $leaf = Category::create([
            'name' => 'Спінінгові',
            'slug' => 'rybalstvo-vudylyshcha-spininhovi',
            'parent_id' => $child->id,
            'is_active' => true,
        ]);

        $valid = $this->product($leaf, [
            'name' => 'Спінінг <strong>Test</strong>',
            'slug' => 'spininh-test',
            'description' => '<p>Надійний спінінг &amp; чохол.</p><script>alert(1)</script>',
            'brand' => 'Test Brand',
            'sku' => 'SKU-100',
            'price' => 1299,
            'sale_price' => 999,
            'image_path' => null,
            'image_url' => 'https://tripfish.com.ua/storage/products/spinning.webp',
            'gallery_paths' => ['https://tripfish.com.ua/storage/products/spinning-side.webp'],
        ]);
        $this->product($leaf, ['slug' => 'disabled', 'is_active' => false]);
        $this->product($leaf, ['slug' => 'without-image', 'image_path' => null, 'image_url' => null]);
        $this->product($leaf, ['slug' => 'without-price', 'price' => 0, 'sale_price' => null]);

        $result = app(GoogleMerchantFeedGenerator::class)->generate();

        $this->assertSame(1, $result['products'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertSame(3, $result['skipped']);

        $xml = Storage::disk('public')->get('feeds/google-merchant-feed.xml');
        $document = simplexml_load_string($xml);

        $this->assertNotFalse($document);
        $this->assertCount(1, $document->channel->item);

        $merchant = $document->channel->item->children('g', true);
        $this->assertSame((string) $valid->id, (string) $merchant->id);
        $this->assertSame('Спінінг Test', (string) $merchant->title);
        $this->assertStringNotContainsString('script', (string) $merchant->description);
        $this->assertSame('https://tripfish.com.ua/rybalstvo/products/spininh-test', (string) $merchant->link);
        $this->assertSame('https://tripfish.com.ua/storage/products/spinning.webp', (string) $merchant->image_link);
        $this->assertSame('1299.00 UAH', (string) $merchant->price);
        $this->assertSame('999.00 UAH', (string) $merchant->sale_price);
        $this->assertSame('in_stock', (string) $merchant->availability);
        $this->assertSame('new', (string) $merchant->condition);
        $this->assertSame('Test Brand', (string) $merchant->brand);
        $this->assertSame('SKU-100', (string) $merchant->mpn);
        $this->assertSame('Рибальство > Вудилища > Спінінгові', (string) $merchant->product_type);
        $this->assertSame('Sporting Goods > Outdoor Recreation > Fishing', (string) $merchant->google_product_category);
        $this->assertSame('sale', (string) $merchant->custom_label_3);
        $this->assertSame('https://tripfish.com.ua/storage/products/spinning-side.webp', (string) $merchant->additional_image_link);

        $this->get('/google-merchant-feed.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function test_regular_product_has_no_sale_price(): void
    {
        $category = Category::create([
            'name' => 'Туризм',
            'slug' => 'turyzm-ta-kempinh',
            'is_active' => true,
        ]);
        $this->product($category, [
            'slug' => 'regular-product',
            'price' => 500,
            'sale_price' => null,
        ]);

        $this->artisan('merchant:feed:generate')->assertSuccessful();

        $document = simplexml_load_string(Storage::disk('public')->get('feeds/google-merchant-feed.xml'));
        $merchant = $document->channel->item->children('g', true);

        $this->assertSame('500.00 UAH', (string) $merchant->price);
        $this->assertFalse(isset($merchant->sale_price));
        $this->assertSame('regular', (string) $merchant->custom_label_3);
    }

    public function test_failed_generation_keeps_previous_feed(): void
    {
        $category = Category::create([
            'name' => 'Туризм',
            'slug' => 'turyzm-ta-kempinh',
            'is_active' => true,
        ]);
        $this->product($category);
        Storage::disk('public')->put('feeds/google-merchant-feed.xml', '<old-feed/>');

        $generator = new class extends GoogleMerchantFeedGenerator
        {
            protected function writeItem(XMLWriter $writer, array $item): void
            {
                throw new RuntimeException('Synthetic feed failure');
            }
        };

        try {
            $generator->generate();
            $this->fail('Expected feed generation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic feed failure', $exception->getMessage());
        }

        $this->assertSame('<old-feed/>', Storage::disk('public')->get('feeds/google-merchant-feed.xml'));
        $this->assertSame(
            ['feeds/google-merchant-feed.xml'],
            Storage::disk('public')->files('feeds', true),
        );
    }

    /** @param array<string, mixed> $attributes */
    private function product(Category $category, array $attributes = []): Product
    {
        static $sequence = 0;
        $sequence++;

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Тестовий товар '.$sequence,
            'slug' => 'merchant-product-'.$sequence,
            'sku' => 'MERCHANT-'.$sequence,
            'description' => 'Опис тестового товару',
            'price' => 1000,
            'sale_price' => null,
            'discount_percent' => 0,
            'image_url' => 'https://tripfish.com.ua/storage/products/merchant-'.$sequence.'.webp',
            'stock' => 5,
            'is_active' => true,
            'is_visible_in_catalog' => true,
            ...$attributes,
        ]);
    }
}
