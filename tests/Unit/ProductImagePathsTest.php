<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductImagePathsTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploaded_image_path_has_priority_over_external_url(): void
    {
        $category = Category::create(['name' => 'Намети', 'slug' => 'tents']);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Намет',
            'slug' => 'tent',
            'price' => 1000,
            'stock' => 2,
            'image_path' => 'products/tent-main.webp',
            'image_url' => 'https://example.com/old.jpg',
        ]);

        $this->assertStringContainsString('/storage/products/tent-main.webp', $product->imageUrl());
    }

    public function test_gallery_includes_uploaded_and_external_images(): void
    {
        $category = Category::create(['name' => 'Спальники', 'slug' => 'sleeping-bags']);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Спальник',
            'slug' => 'sleeping-bag',
            'price' => 800,
            'stock' => 1,
            'image_path' => 'products/bag-main.webp',
            'gallery_paths' => ['products/gallery/bag-2.webp'],
            'gallery_images' => ['http://65.108.104.155/pic/example.jpg'],
        ]);

        $gallery = $product->gallery();

        $this->assertCount(3, $gallery);
        $this->assertStringContainsString('/storage/products/bag-main.webp', $gallery[0]);
        $this->assertStringContainsString('/storage/products/gallery/bag-2.webp', $gallery[1]);
        $this->assertSame('https://file.pobedov.com/pic/example.jpg', $gallery[2]);
    }

    public function test_feed_ip_image_url_is_normalized_for_product_card(): void
    {
        $category = Category::create(['name' => 'Одяг', 'slug' => 'clothes']);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Футболка',
            'slug' => 'tshirt',
            'price' => 650,
            'stock' => 5,
            'image_url' => 'http://65.108.104.155/pic/cd5f36d7-badb-11ee-a00b-f02f749621c0.jpg',
        ]);

        $this->assertSame(
            'https://file.pobedov.com/pic/cd5f36d7-badb-11ee-a00b-f02f749621c0.jpg',
            $product->imageUrl(),
        );
    }

    public function test_partial_product_select_includes_uploaded_image_path(): void
    {
        $category = Category::create(['name' => 'Намети', 'slug' => 'tents']);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Намет',
            'slug' => 'tent-partial-select',
            'price' => 1000,
            'stock' => 2,
            'image_path' => 'products/01KVTG338ABE40NQFN00B58J7K.png',
            'image_url' => null,
        ]);

        $loaded = Product::query()
            ->whereKey($product->id)
            ->select([
                'id',
                'category_id',
                'variant_group_id',
                'name',
                'name_ru',
                'slug',
                'sku',
                'brand',
                'price',
                'sale_price',
                'discount_percent',
                'image_url',
                'image_path',
                'stock',
                'is_active',
            ])
            ->first();

        $this->assertStringContainsString(
            '/storage/products/01KVTG338ABE40NQFN00B58J7K.png',
            $loaded->imageUrl(),
        );
    }
}
