<?php

namespace Tests\Feature;

use App\Jobs\MirrorProductImagesJob;
use App\Jobs\MirrorOneProductGalleryJob;
use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Product;
use App\Services\ProductImageMirror;
use App\Support\CounterpartyImageMirrorProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CounterpartyImageMirrorProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_mirror_job_updates_counterparty_image_progress(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('WebP support is not available in the current PHP build.');
        }

        $counterparty = Counterparty::create([
            'name' => 'Pobedov',
            'slug' => 'pobedov',
            'feed_url' => 'https://example.test/feed.yml',
            'feed_format' => 'yml',
        ]);

        $category = Category::create(['name' => 'Спінінги', 'slug' => 'spinning-progress']);
        $product = Product::create([
            'category_id' => $category->id,
            'counterparty_id' => $counterparty->id,
            'source' => 'pobedov',
            'external_id' => '00000006297',
            'name' => 'Спінінг',
            'slug' => 'spinning-progress-product',
            'price' => 1200,
            'stock' => 2,
            'image_url' => 'https://example.test/pobedov-1.jpg',
        ]);

        $png = $this->samplePngBytes();

        Http::fake([
            'https://example.test/pobedov-1.jpg' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        Queue::fake();

        (new MirrorProductImagesJob($counterparty->id))->handle(
            app(\App\Services\ProductImageMirror::class),
            app(CounterpartyImageMirrorProgress::class),
        );

        Queue::assertPushed(\App\Jobs\MirrorOneProductImageJob::class);

        (new \App\Jobs\MirrorOneProductImageJob($counterparty->id, $product->id))->handle(
            app(\App\Services\ProductImageMirror::class),
            app(CounterpartyImageMirrorProgress::class),
        );

        $status = app(CounterpartyImageMirrorProgress::class)->status($counterparty->id);

        $this->assertSame('completed', $status['state'] ?? null);
        $this->assertSame(1, $status['mirrored'] ?? null);
        $this->assertSame(0, $status['failed'] ?? null);

        $product->refresh();
        $this->assertNotNull($product->image_path);
    }

    public function test_gallery_job_continues_when_first_batch_leaves_product_incomplete(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('WebP support is not available in the current PHP build.');
        }

        config(['product-images.mirror_gallery_images_per_job' => 1]);

        $counterparty = Counterparty::create([
            'name' => 'Ranger Gallery',
            'slug' => 'ranger-gallery-progress',
            'feed_url' => 'https://example.test/feed.xml',
            'feed_format' => 'xml',
        ]);

        $category = Category::create(['name' => 'Галерея', 'slug' => 'gallery-progress']);
        Product::create([
            'category_id' => $category->id,
            'counterparty_id' => $counterparty->id,
            'source' => 'ranger',
            'external_id' => 'gallery-progress',
            'name' => 'Товар',
            'slug' => 'gallery-progress-product',
            'price' => 1200,
            'stock' => 2,
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

        Queue::fake();

        (new MirrorProductImagesJob($counterparty->id, phase: \App\Services\ProductImageMirror::PHASE_GALLERY))->handle(
            app(\App\Services\ProductImageMirror::class),
            app(CounterpartyImageMirrorProgress::class),
        );

        Queue::assertPushed(\App\Jobs\MirrorOneProductGalleryJob::class);

        $status = app(CounterpartyImageMirrorProgress::class)->status($counterparty->id);
        $this->assertSame('running', $status['state'] ?? null);
        $this->assertSame(2, $status['total'] ?? null);
    }

    public function test_broken_gallery_image_is_counted_and_does_not_stall_the_run(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Broken Gallery',
            'slug' => 'broken-gallery',
            'feed_url' => 'https://example.test/feed.xml',
            'feed_format' => 'xml',
        ]);

        $category = Category::create(['name' => 'Галерея', 'slug' => 'broken-gallery-category']);
        $product = Product::create([
            'category_id' => $category->id,
            'counterparty_id' => $counterparty->id,
            'source' => 'ranger',
            'external_id' => 'broken-gallery-product',
            'name' => 'Товар із недоступним фото',
            'slug' => 'broken-gallery-product',
            'price' => 1200,
            'stock' => 2,
            'gallery_images' => ['https://example.test/missing-gallery.jpg'],
        ]);

        Http::fake([
            'https://example.test/missing-gallery.jpg' => Http::response('', 500),
        ]);

        Queue::fake();

        $progress = app(CounterpartyImageMirrorProgress::class);
        $progress->startPhase($counterparty->id, ProductImageMirror::PHASE_GALLERY, 1);

        (new MirrorOneProductGalleryJob($counterparty->id, $product->id))->handle(
            app(ProductImageMirror::class),
            $progress,
        );

        $status = $progress->status($counterparty->id);

        $this->assertSame('completed', $status['state'] ?? null);
        $this->assertSame(1, $status['current'] ?? null);
        $this->assertSame(1, $status['failed'] ?? null);
        $this->assertSame(1, $status['failure_reasons']['http:500'] ?? null);
        $this->assertFalse(app(ProductImageMirror::class)->hasPendingGalleryFast($counterparty->id));
        $this->assertSame([], $product->fresh()->gallery_images);
    }

    public function test_gallery_product_job_does_not_use_a_unique_lock_that_blocks_its_follow_up_job(): void
    {
        $job = new MirrorOneProductGalleryJob(1, 1);

        $this->assertNotInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class, $job);
    }

    public function test_gallery_phase_has_separate_counters_and_keeps_main_photo_summary(): void
    {
        Queue::fake();

        $counterparty = Counterparty::create([
            'name' => 'Separate Phase Counters',
            'slug' => 'separate-phase-counters',
            'feed_url' => 'https://example.test/feed.xml',
            'feed_format' => 'xml',
        ]);

        $progress = app(CounterpartyImageMirrorProgress::class);
        $progress->start($counterparty->id, 21);
        $progress->update($counterparty->id, 21, 17, 4, ProductImageMirror::PHASE_MAIN);

        $gallery = $progress->kickoffGalleryPhase($counterparty->id);

        $this->assertSame(ProductImageMirror::PHASE_GALLERY, $gallery['phase'] ?? null);
        $this->assertSame(21, $gallery['main_total'] ?? null);
        $this->assertSame(17, $gallery['main_mirrored'] ?? null);
        $this->assertSame(4, $gallery['main_failed'] ?? null);
        $this->assertSame(0, $gallery['total'] ?? null);
        $this->assertSame(0, $gallery['mirrored'] ?? null);
        $this->assertFalse($gallery['phase_counters_initialized'] ?? true);

        $gallery = $progress->startPhase($counterparty->id, ProductImageMirror::PHASE_GALLERY, 22714);

        $this->assertSame(22714, $gallery['total'] ?? null);
        $this->assertSame(0, $gallery['current'] ?? null);
        $this->assertSame(0, $gallery['mirrored'] ?? null);
        $this->assertSame(0, $gallery['failed'] ?? null);
        $this->assertTrue($gallery['phase_counters_initialized'] ?? false);
    }

    public function test_cancelled_image_import_stays_cancelled_and_queued_orchestrator_becomes_a_noop(): void
    {
        Queue::fake();

        $counterparty = Counterparty::create([
            'name' => 'Cancelled Images',
            'slug' => 'cancelled-images',
            'feed_url' => 'https://example.test/feed.xml',
            'feed_format' => 'xml',
        ]);

        $progress = app(CounterpartyImageMirrorProgress::class);
        $progress->start($counterparty->id, 10);
        $progress->update($counterparty->id, 4, 4, 0, ProductImageMirror::PHASE_MAIN);
        $cancelled = $progress->cancel($counterparty->id);

        $this->assertSame('cancelled', $cancelled['state'] ?? null);
        $this->assertSame(4, $cancelled['current'] ?? null);
        $this->assertTrue($progress->isCancelled($counterparty->id));

        $progress->tickProduct($counterparty->id, 999, mirrored: true, failed: false);
        $progress->complete($counterparty->id, 10, 0, 10);

        $this->assertSame('cancelled', $progress->status($counterparty->id)['state'] ?? null);
        $this->assertSame(4, $progress->status($counterparty->id)['current'] ?? null);

        $mirror = \Mockery::mock(ProductImageMirror::class);
        $mirror->shouldNotReceive('pendingMainProductIds');

        (new MirrorProductImagesJob($counterparty->id))->handle($mirror, $progress);

        Queue::assertNothingPushed();
    }

    public function test_job_recovers_from_unexpected_exception_without_failing(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Broken Mirror',
            'slug' => 'broken-mirror',
            'feed_url' => 'https://example.test/feed.yml',
            'feed_format' => 'yml',
        ]);

        Queue::fake();

        $mirror = \Mockery::mock(\App\Services\ProductImageMirror::class);
        $mirror->shouldReceive('pendingMainProductIds')->andReturn([999]);
        $mirror->shouldReceive('hasPendingGalleryFast')->andReturn(false);

        (new MirrorProductImagesJob($counterparty->id))->handle(
            $mirror,
            app(CounterpartyImageMirrorProgress::class),
        );

        Queue::assertPushed(\App\Jobs\MirrorOneProductImageJob::class);

        $status = app(CounterpartyImageMirrorProgress::class)->status($counterparty->id);
        $this->assertSame('running', $status['state'] ?? null);
        $this->assertSame(1, $status['total'] ?? null);
    }

    public function test_recover_stuck_gallery_processes_products(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('WebP support is not available in the current PHP build.');
        }

        Queue::fake();

        $counterparty = Counterparty::create([
            'name' => 'Stuck Gallery',
            'slug' => 'stuck-gallery',
            'feed_url' => 'https://example.test/feed.yml',
            'feed_format' => 'yml',
        ]);

        $category = Category::create(['name' => 'Галерея', 'slug' => 'stuck-gallery-cat']);
        Product::create([
            'category_id' => $category->id,
            'counterparty_id' => $counterparty->id,
            'source' => 'ranger',
            'external_id' => 'stuck-gallery',
            'name' => 'Товар',
            'slug' => 'stuck-gallery-product',
            'price' => 900,
            'stock' => 1,
            'gallery_images' => ['https://example.test/gallery-stuck.jpg'],
        ]);

        $png = $this->samplePngBytes();
        Http::fake([
            'https://example.test/gallery-stuck.jpg' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $progress = app(CounterpartyImageMirrorProgress::class);
        $progress->startPhase($counterparty->id, ProductImageMirror::PHASE_GALLERY, 1);

        $status = $progress->status($counterparty->id);
        $status['last_activity_at'] = now()->subMinutes(2)->toIso8601String();
        \Illuminate\Support\Facades\Cache::put($progress->key($counterparty->id), $status, now()->addHour());

        $progress->recoverIfStuck($counterparty->id, idleSeconds: 60);

        Queue::assertPushed(\App\Jobs\MirrorOneProductGalleryJob::class);
        Queue::assertPushed(MirrorProductImagesJob::class, fn (MirrorProductImagesJob $job): bool => $job->phase === ProductImageMirror::PHASE_GALLERY);
    }

    public function test_fail_stale_starts_gallery_when_main_batch_is_complete(): void
    {
        Queue::fake();

        $counterparty = Counterparty::create([
            'name' => 'Main Done',
            'slug' => 'main-done',
            'feed_url' => 'https://example.test/feed.yml',
            'feed_format' => 'yml',
        ]);

        $category = Category::create(['name' => 'Галерея', 'slug' => 'main-done-gallery']);
        Product::create([
            'category_id' => $category->id,
            'counterparty_id' => $counterparty->id,
            'source' => 'ranger',
            'external_id' => 'main-done-gallery',
            'name' => 'Товар',
            'slug' => 'main-done-gallery-product',
            'price' => 900,
            'stock' => 1,
            'gallery_images' => ['https://example.test/gallery-1.jpg'],
        ]);

        $progress = app(CounterpartyImageMirrorProgress::class);
        $progress->start($counterparty->id, 133);
        $progress->update($counterparty->id, 133, 127, 6, ProductImageMirror::PHASE_MAIN);

        $result = $progress->failStale($counterparty->id);

        $this->assertSame('running', $result['state'] ?? null);
        Queue::assertPushed(MirrorProductImagesJob::class, fn (MirrorProductImagesJob $job): bool => $job->phase === ProductImageMirror::PHASE_GALLERY);
    }

    public function test_recover_if_main_finished_after_failed_stale(): void
    {
        Queue::fake();

        $counterparty = Counterparty::create([
            'name' => 'Recover Main',
            'slug' => 'recover-main',
            'feed_url' => 'https://example.test/feed.yml',
            'feed_format' => 'yml',
        ]);

        $category = Category::create(['name' => 'Галерея', 'slug' => 'recover-main-gallery']);
        Product::create([
            'category_id' => $category->id,
            'counterparty_id' => $counterparty->id,
            'source' => 'ranger',
            'external_id' => 'recover-main-gallery',
            'name' => 'Товар',
            'slug' => 'recover-main-gallery-product',
            'price' => 900,
            'stock' => 1,
            'gallery_images' => ['https://example.test/gallery-1.jpg'],
        ]);

        $progress = app(CounterpartyImageMirrorProgress::class);
        $progress->start($counterparty->id, 133);
        $progress->update($counterparty->id, 133, 127, 6, ProductImageMirror::PHASE_MAIN);
        $progress->fail($counterparty->id, 'stale');

        $result = $progress->recoverIfMainFinished($counterparty->id);

        $this->assertSame('running', $result['state'] ?? null);
        Queue::assertPushed(MirrorProductImagesJob::class, fn (MirrorProductImagesJob $job): bool => $job->phase === ProductImageMirror::PHASE_GALLERY);
    }

    public function test_recover_stuck_main_processes_remaining_products(): void
    {
        Queue::fake();

        $counterparty = Counterparty::create([
            'name' => 'Stuck Main',
            'slug' => 'stuck-main',
            'feed_url' => 'https://example.test/feed.yml',
            'feed_format' => 'yml',
        ]);

        $category = Category::create(['name' => 'Test', 'slug' => 'stuck-main-cat']);
        $products = [];
        for ($i = 1; $i <= 3; $i++) {
            $products[] = Product::create([
                'category_id' => $category->id,
                'counterparty_id' => $counterparty->id,
                'source' => 'pobedov',
                'external_id' => 'stuck-'.$i,
                'name' => 'Товар '.$i,
                'slug' => 'stuck-main-product-'.$i,
                'price' => 100,
                'stock' => 1,
                'image_url' => 'https://example.test/stuck-'.$i.'.jpg',
            ]);
        }

        $progress = app(CounterpartyImageMirrorProgress::class);
        $progress->start($counterparty->id, 3);
        $progress->tickProduct($counterparty->id, $products[0]->id, mirrored: true, failed: false);
        $progress->tickProduct($counterparty->id, $products[1]->id, mirrored: true, failed: false);

        $status = $progress->status($counterparty->id);
        $status['last_activity_at'] = now()->subMinutes(2)->toIso8601String();
        \Illuminate\Support\Facades\Cache::put($progress->key($counterparty->id), $status, now()->addHour());

        $progress->recoverIfStuck($counterparty->id, idleSeconds: 60);

        Queue::assertPushed(\App\Jobs\MirrorOneProductImageJob::class, 3);
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
