<?php

namespace Tests\Feature;

use App\Jobs\MirrorProductImagesJob;
use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Product;
use App\Services\CounterpartyFeedImporter;
use App\Services\ProductImageMirror;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageMirrorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_mirror_downloads_image_and_stores_webp_path(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('WebP support is not available in the current PHP build.');
        }

        $category = Category::create(['name' => 'Спінінги', 'slug' => 'spinning-mirror']);
        $product = Product::create([
            'category_id' => $category->id,
            'source' => 'pobedov',
            'external_id' => '00000006297',
            'name' => 'Спінінг',
            'slug' => 'spinning-mirror-product',
            'price' => 1200,
            'stock' => 2,
            'image_url' => 'https://example.test/pobedov-1.jpg',
        ]);

        $png = $this->samplePngBytes();

        Http::fake([
            'https://example.test/pobedov-1.jpg' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $mirrored = app(ProductImageMirror::class)->mirror($product->fresh());

        $this->assertTrue($mirrored);

        $product->refresh();

        $this->assertNotNull($product->image_path);
        $this->assertSame('https://example.test/pobedov-1.jpg', $product->mirrored_image_url);
        $this->assertStringContainsString('/storage/', $product->imageUrl());
        Storage::disk('public')->assertExists($product->image_path);
        $this->assertStringEndsWith('.webp', $product->image_path);
    }

    public function test_feed_import_does_not_queue_image_mirror_job(): void
    {
        Queue::fake();

        $counterparty = Counterparty::create([
            'name' => 'Pobedov',
            'slug' => 'pobedov',
            'feed_url' => 'https://example.test/feed.yml',
            'feed_format' => 'yml',
        ]);

        app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/pobedov-feed.yml'),
        );

        Queue::assertNotPushed(MirrorProductImagesJob::class);
    }

    public function test_feed_import_normalizes_image_url(): void
    {
        Queue::fake();

        $counterparty = Counterparty::create([
            'name' => 'Pobedov',
            'slug' => 'pobedov',
            'feed_url' => 'https://example.test/feed.yml',
            'feed_format' => 'yml',
        ]);

        app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/pobedov-feed.yml'),
        );

        $product = Product::query()->where('external_id', '00000006297')->firstOrFail();

        $this->assertSame('https://example.test/pobedov-1.jpg', $product->image_url);
    }

    public function test_changed_feed_image_url_clears_local_mirror_metadata(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Pobedov',
            'slug' => 'pobedov',
            'feed_url' => 'https://example.test/feed.yml',
            'feed_format' => 'yml',
        ]);

        $category = Category::create([
            'name' => 'Feed category',
            'slug' => 'feed-category',
            'counterparty_id' => $counterparty->id,
            'external_id' => '26',
        ]);

        Product::create([
            'category_id' => $category->id,
            'source' => 'pobedov',
            'external_id' => '00000006297',
            'name' => 'Футболка',
            'slug' => 'pobedov-shirt',
            'price' => 650,
            'stock' => 11,
            'image_url' => 'https://example.test/old-image.jpg',
            'image_path' => 'products/pobedov/00000006297/main.webp',
            'mirrored_image_url' => 'https://example.test/old-image.jpg',
        ]);

        Queue::fake();

        app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/pobedov-feed.yml'),
        );

        $product = Product::query()->where('external_id', '00000006297')->firstOrFail();

        $this->assertSame('https://example.test/pobedov-1.jpg', $product->image_url);
        $this->assertNull($product->image_path);
        $this->assertNull($product->mirrored_image_url);
    }

    public function test_mirror_downloads_gallery_images_after_main_phase(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('WebP support is not available in the current PHP build.');
        }

        $category = Category::create(['name' => 'Галерея', 'slug' => 'gallery-mirror']);
        $product = Product::create([
            'category_id' => $category->id,
            'source' => 'pobedov',
            'external_id' => 'gallery-product',
            'name' => 'Товар з галереєю',
            'slug' => 'gallery-mirror-product',
            'price' => 1200,
            'stock' => 2,
            'image_url' => 'https://example.test/main.jpg',
            'image_path' => 'products/pobedov/gallery-product/main.webp',
            'mirrored_image_url' => 'https://example.test/main.jpg',
            'gallery_images' => [
                'https://example.test/gallery-1.jpg',
                'https://example.test/gallery-2.jpg',
            ],
        ]);

        $png = $this->samplePngBytes();

        Http::fake([
            'https://example.test/gallery-1.jpg' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            'https://example.test/gallery-2.jpg' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $result = app(ProductImageMirror::class)->mirrorGallery($product->fresh());

        $this->assertSame(2, $result['mirrored']);
        $this->assertSame(0, $result['failed']);

        $product->refresh();

        $this->assertCount(2, $product->gallery_paths ?? []);
        $this->assertSame([], $product->gallery_images ?? []);
        $this->assertCount(2, $product->mirrored_gallery_urls ?? []);
        Storage::disk('public')->assertExists($product->gallery_paths[0]);
    }

    public function test_job_runs_gallery_phase_after_main_phase(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('WebP support is not available in the current PHP build.');
        }

        Queue::fake();

        $counterparty = Counterparty::create([
            'name' => 'Pobedov',
            'slug' => 'pobedov',
            'feed_url' => 'https://example.test/feed.yml',
            'feed_format' => 'yml',
        ]);

        $category = Category::create(['name' => 'Галерея', 'slug' => 'gallery-job']);
        Product::create([
            'category_id' => $category->id,
            'counterparty_id' => $counterparty->id,
            'source' => 'pobedov',
            'external_id' => 'gallery-only',
            'name' => 'Товар',
            'slug' => 'gallery-only-product',
            'price' => 900,
            'stock' => 1,
            'image_path' => 'products/pobedov/gallery-only/main.webp',
            'mirrored_image_url' => 'https://example.test/main.jpg',
            'image_url' => 'https://example.test/main.jpg',
            'gallery_images' => ['https://example.test/gallery-1.jpg'],
        ]);

        (new MirrorProductImagesJob($counterparty->id))->handle(
            app(ProductImageMirror::class),
            app(\App\Support\CounterpartyImageMirrorProgress::class),
        );

        Queue::assertPushed(MirrorProductImagesJob::class, fn (MirrorProductImagesJob $job): bool => $job->phase === ProductImageMirror::PHASE_GALLERY);
    }

    public function test_mirror_gallery_step_processes_images_incrementally(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('WebP support is not available in the current PHP build.');
        }

        $category = Category::create(['name' => 'Галерея step', 'slug' => 'gallery-step']);
        $product = Product::create([
            'category_id' => $category->id,
            'source' => 'pobedov',
            'external_id' => 'gallery-step-product',
            'name' => 'Товар з галереєю step',
            'slug' => 'gallery-step-product',
            'price' => 1200,
            'stock' => 2,
            'gallery_images' => [
                'https://example.test/gallery-1.jpg',
                'https://example.test/gallery-2.jpg',
                'https://example.test/gallery-3.jpg',
            ],
        ]);

        $png = $this->samplePngBytes();

        Http::fake([
            'https://example.test/gallery-1.jpg' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            'https://example.test/gallery-2.jpg' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            'https://example.test/gallery-3.jpg' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $mirror = app(ProductImageMirror::class);
        $first = $mirror->mirrorGalleryStep($product->fresh(), 2);

        $this->assertSame(2, $first['mirrored']);
        $this->assertFalse($first['completed']);

        $product->refresh();
        $this->assertCount(2, $product->gallery_paths ?? []);

        $second = $mirror->mirrorGalleryStep($product->fresh(), 2);

        $this->assertSame(1, $second['mirrored']);
        $this->assertTrue($second['completed']);
        $this->assertCount(3, $product->fresh()->gallery_paths ?? []);
    }

    public function test_mirror_batch_reconciles_already_mirrored_urls_without_redownloading(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('WebP support is not available in the current PHP build.');
        }

        $category = Category::create(['name' => 'Реконсил', 'slug' => 'mirror-reconcile']);
        Product::create([
            'category_id' => $category->id,
            'source' => 'pobedov',
            'external_id' => 'reconcile-product',
            'name' => 'Товар',
            'slug' => 'mirror-reconcile-product',
            'price' => 900,
            'stock' => 1,
            'image_url' => 'http://file.pobedov.com/example.jpg',
            'image_path' => 'products/pobedov/reconcile-product/main.webp',
            'mirrored_image_url' => 'https://file.pobedov.com/example.jpg',
        ]);

        Http::fake();

        $result = app(ProductImageMirror::class)->mirrorBatch(phase: ProductImageMirror::PHASE_MAIN);

        $this->assertSame(0, $result['mirrored']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, app(ProductImageMirror::class)->pendingCount());
        Http::assertNothingSent();
    }

    public function test_mirror_batch_counts_failed_product_without_aborting_batch(): void
    {
        $category = Category::create(['name' => 'Помилка', 'slug' => 'mirror-failure']);
        Product::create([
            'category_id' => $category->id,
            'source' => 'pobedov',
            'external_id' => 'broken-product',
            'name' => 'Товар з помилкою',
            'slug' => 'mirror-broken-product',
            'price' => 900,
            'stock' => 1,
            'image_url' => 'https://example.test/broken.jpg',
        ]);

        Http::fake([
            'https://example.test/broken.jpg' => Http::response('not-an-image', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $result = app(ProductImageMirror::class)->mirrorBatch(phase: ProductImageMirror::PHASE_MAIN);

        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['mirrored']);
        $this->assertSame(1, $result['failed']);
    }

    public function test_sort_pictures_prefers_hash_paths_over_cyrillic_legacy_names(): void
    {
        $sorted = ProductImageMirror::sortPicturesForImport([
            'https://atlantmarket.com.ua/upload/iblock/eac/Точильний верстат Ganzo Touch Pro GTP 800Х800 (1).jpg',
            'https://atlantmarket.com.ua/upload/iblock/9ac/xfmlaokmqte133swoshqmfdlqfwopvw2/GTP_1.jpg',
            'https://atlantmarket.com.ua/upload/iblock/455/27dq147iv6wsr58t0oqiwlf19du2ih8m/GTP_2.jpg',
        ]);

        $this->assertStringContainsString('GTP_1.jpg', $sorted->first());
    }

    public function test_mirror_promotes_ascii_gallery_url_when_legacy_cyrillic_main_fails(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('WebP support is not available in the current PHP build.');
        }

        $category = Category::create(['name' => 'Atlant', 'slug' => 'atlant-fallback']);
        $legacy = 'https://atlantmarket.com.ua/upload/iblock/eac/Точильний верстат Ganzo Touch Pro GTP 800Х800 (1).jpg';
        $ascii = 'https://atlantmarket.com.ua/upload/iblock/9ac/xfmlaokmqte133swoshqmfdlqfwopvw2/GTP_1.jpg';
        $product = Product::create([
            'category_id' => $category->id,
            'source' => 'atlantmarket',
            'external_id' => '44828',
            'external_url' => 'https://atlantmarket.com.ua/tochilniy-verstat-ganzo-touch-pro-gtp/',
            'name' => 'Точильний верстат',
            'slug' => 'atlant-fallback-product',
            'price' => 1250,
            'stock' => 2,
            'image_url' => $legacy,
            'gallery_images' => [$ascii, 'https://atlantmarket.com.ua/upload/iblock/455/27dq147iv6wsr58t0oqiwlf19du2ih8m/GTP_2.jpg'],
        ]);

        $png = $this->samplePngBytes();

        Http::fake([
            ProductImageMirror::encodeUrlForRequest($legacy) => Http::response('missing', 404, ['Content-Type' => 'text/html']),
            ProductImageMirror::encodeUrlForRequest($ascii) => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $mirrored = app(ProductImageMirror::class)->mirror($product->fresh());

        $this->assertTrue($mirrored);
        $product->refresh();
        $this->assertSame($ascii, $product->image_url);
        $this->assertNotNull($product->image_path);
    }

    public function test_encode_url_for_request_encodes_cyrillic_and_spaces_in_path(): void
    {
        $url = 'https://atlantmarket.com.ua/upload/iblock/eac/Точильний верстат Ganzo (1).jpg';
        $encoded = ProductImageMirror::encodeUrlForRequest($url);

        $this->assertStringNotContainsString('Точильний', $encoded);
        $this->assertStringContainsString('%D0%A2%D0%BE%D1%87', $encoded);
        $this->assertStringContainsString('%20', $encoded);
    }

    public function test_mirror_downloads_image_with_cyrillic_url_path(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('WebP support is not available in the current PHP build.');
        }

        $category = Category::create(['name' => 'Кирилиця', 'slug' => 'cyrillic-mirror']);
        $sourceUrl = 'https://atlantmarket.com.ua/upload/iblock/eac/Точильний верстат (1).jpg';
        $product = Product::create([
            'category_id' => $category->id,
            'source' => 'atlantmarket',
            'external_id' => 'cyrillic-image',
            'name' => 'Точильний верстат',
            'slug' => 'cyrillic-mirror-product',
            'price' => 1200,
            'stock' => 2,
            'image_url' => $sourceUrl,
        ]);

        $png = $this->samplePngBytes();
        $requestUrl = ProductImageMirror::encodeUrlForRequest($sourceUrl);

        Http::fake([
            $requestUrl => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $mirrored = app(ProductImageMirror::class)->mirror($product->fresh());

        $this->assertTrue($mirrored);
        Http::assertSent(fn ($request): bool => $request->url() === $requestUrl);
    }

    public function test_mirror_batch_records_failure_reason_for_invalid_response(): void
    {
        $category = Category::create(['name' => 'Помилка', 'slug' => 'mirror-failure-reason']);
        Product::create([
            'category_id' => $category->id,
            'source' => 'pobedov',
            'external_id' => 'broken-product-reason',
            'name' => 'Товар з помилкою',
            'slug' => 'mirror-broken-product-reason',
            'price' => 900,
            'stock' => 1,
            'image_url' => 'https://example.test/broken.jpg',
        ]);

        Http::fake([
            'https://example.test/broken.jpg' => Http::response('not-an-image', 200, ['Content-Type' => 'text/html']),
        ]);

        $result = app(ProductImageMirror::class)->mirrorBatch(phase: ProductImageMirror::PHASE_MAIN);

        $this->assertSame(1, $result['failed']);
        $this->assertSame('not_image', $result['failures'][0]['reason'] ?? null);
    }

    public function test_pending_main_count_ignores_already_mirrored_products_with_url_mismatch(): void
    {
        $category = Category::create(['name' => 'Підрахунок', 'slug' => 'mirror-main-count']);
        Product::create([
            'category_id' => $category->id,
            'source' => 'pobedov',
            'external_id' => 'count-product',
            'name' => 'Товар',
            'slug' => 'mirror-main-count-product',
            'price' => 900,
            'stock' => 1,
            'image_url' => 'http://file.pobedov.com/example.jpg',
            'image_path' => 'products/pobedov/count-product/main.webp',
            'mirrored_image_url' => 'https://file.pobedov.com/example.jpg',
        ]);

        $mirror = app(ProductImageMirror::class);

        $this->assertSame(0, $mirror->pendingCount());
        $this->assertSame(1, $mirror->reconcilePendingMainMetadata());
        $this->assertSame(0, $mirror->pendingCount());
    }

    private function samplePngBytes(): string
    {
        $image = imagecreatetruecolor(120, 120);
        $background = imagecolorallocate($image, 24, 96, 64);
        imagefill($image, 0, 0, $background);
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
