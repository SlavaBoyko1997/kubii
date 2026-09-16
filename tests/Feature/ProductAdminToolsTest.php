<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ProductAdminToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_tools_are_hidden_for_guests(): void
    {
        $product = $this->createProduct();

        $this->get($product->url())
            ->assertOk()
            ->assertDontSee('data-copy-product-specs', false)
            ->assertDontSee(__('Скачати фото'));
    }

    public function test_admin_tools_are_visible_for_store_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $product = $this->createProduct([
            'specifications' => ['Вага' => '217 г'],
            'brand' => 'Ranger',
        ]);

        $this->actingAs($admin)
            ->get($product->url())
            ->assertOk()
            ->assertSee('data-copy-product-specs', false)
            ->assertSee(__('Скачати фото'))
            ->assertSee(__('Скопіювати характеристики'));
    }

    public function test_non_admin_cannot_download_product_images(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $product = $this->createProduct();

        $this->actingAs($user)
            ->get(route('products.admin.download-images', $product))
            ->assertForbidden();
    }

    public function test_admin_can_download_product_images_as_zip_folder_named_by_sku(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is not available in the current PHP build.');
        }

        Storage::fake('public');
        Storage::disk('public')->put('products/test/main.webp', 'main-image');
        Storage::disk('public')->put('products/test/gallery-01.webp', 'gallery-image');

        $admin = User::factory()->create(['is_admin' => true]);
        $product = $this->createProduct([
            'sku' => 'GL8221',
            'image_path' => 'products/test/main.webp',
            'gallery_paths' => ['products/test/gallery-01.webp'],
        ]);

        $response = $this->actingAs($admin)
            ->get(route('products.admin.download-images', $product));

        $response->assertOk();
        $response->assertDownload('GL8221.zip');

        $zipPath = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath));
        $this->assertSame(2, $zip->numFiles);
        $this->assertStringStartsWith('GL8221/', $zip->getNameIndex(0));
        $this->assertStringStartsWith('GL8221/', $zip->getNameIndex(1));
        $zip->close();
    }

    public function test_copyable_details_text_includes_product_name_and_specifications(): void
    {
        $product = $this->createProduct([
            'name' => 'Мультитул Ranger',
            'brand' => 'Ranger',
            'model' => 'X1',
            'specifications' => [
                'Вага' => '217 г',
                'Колір' => 'чорний',
            ],
        ]);

        $text = $product->copyableDetailsText();

        $this->assertStringContainsString('Мультитул Ranger', $text);
        $this->assertStringContainsString('Виробник: Ranger', $text);
        $this->assertStringContainsString('Модель: X1', $text);
        $this->assertStringContainsString('Вага: 217 г', $text);
        $this->assertStringContainsString('Колір: чорний', $text);
    }

    public function test_ordered_display_specifications_format_array_values(): void
    {
        $product = $this->createProduct([
            'specifications' => [
                'Колір' => ['чорний', 'сірий'],
            ],
        ]);

        $this->assertSame(
            ['Колір' => 'чорний, сірий'],
            $product->orderedDisplaySpecifications(),
        );
    }

    public function test_admin_download_fetches_remote_images_when_local_paths_are_missing(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is not available in the current PHP build.');
        }

        Http::fake([
            'https://example.test/product.jpg' => Http::response('remote-image', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        $product = $this->createProduct([
            'sku' => 'REMOTE1',
            'image_url' => 'https://example.test/product.jpg',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('products.admin.download-images', $product));

        $response->assertOk();
        $response->assertDownload('REMOTE1.zip');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createProduct(array $overrides = []): Product
    {
        $category = Category::create(['name' => 'Тест', 'slug' => 'admin-tools-'.uniqid()]);

        return Product::create(array_merge([
            'category_id' => $category->id,
            'name' => 'Тестовий товар',
            'slug' => 'admin-tools-product-'.uniqid(),
            'price' => 1000,
            'stock' => 3,
            'image_url' => 'https://example.test/main.jpg',
        ], $overrides));
    }
}
