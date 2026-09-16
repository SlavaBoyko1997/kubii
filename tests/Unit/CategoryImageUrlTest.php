<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Product;
use App\Support\StoredAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryImageUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_local_public_image_paths(): void
    {
        $category = Category::create([
            'name' => 'Туризм',
            'slug' => 'tourism',
            'image_url' => '/images/categories/tourism-white.webp',
        ]);

        $this->assertStringContainsString('/images/categories/tourism-white.webp', $category->imageUrl());
    }

    public function test_uploaded_image_path_has_priority_over_external_url(): void
    {
        $category = Category::create([
            'name' => 'Намети',
            'slug' => 'tents',
            'image_path' => 'categories/tents.webp',
            'image_url' => '/images/categories/tents-white.webp',
        ]);

        $this->assertStringContainsString('/storage/categories/tents.webp', $category->imageUrl());
    }

    public function test_it_uses_product_image_when_category_has_no_photo(): void
    {
        $category = Category::create([
            'name' => 'Гамаки',
            'slug' => 'hammocks',
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Гамак Naturehike',
            'slug' => 'naturehike-hammock',
            'price' => 1800,
            'stock' => 3,
            'is_active' => true,
            'is_processed' => true,
            'image_path' => 'products/hammock-main.webp',
        ]);

        $this->assertStringContainsString('/storage/products/hammock-main.webp', $category->fresh()->imageUrl());
    }

    public function test_category_image_has_priority_over_product_fallback(): void
    {
        $category = Category::create([
            'name' => 'Рюкзаки',
            'slug' => 'backpacks',
            'image_path' => 'categories/backpacks.webp',
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Рюкзак Osprey',
            'slug' => 'osprey-backpack',
            'price' => 4200,
            'stock' => 2,
            'is_active' => true,
            'is_processed' => true,
            'image_path' => 'products/osprey-main.webp',
        ]);

        $this->assertStringContainsString('/storage/categories/backpacks.webp', $category->imageUrl());
        $this->assertStringNotContainsString('/storage/products/osprey-main.webp', $category->imageUrl());
    }

    public function test_it_normalizes_storage_prefixed_paths(): void
    {
        $this->assertStringContainsString(
            '/storage/categories/tents.webp',
            StoredAsset::url('/storage/categories/tents.webp') ?? '',
        );

        $this->assertStringContainsString(
            '/storage/categories/tents.webp',
            StoredAsset::url('storage/categories/tents.webp') ?? '',
        );
    }
}
