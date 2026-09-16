<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BrandCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_index_lists_active_brands_and_brand_page_filters_products(): void
    {
        $backpacks = Category::create([
            'name' => 'Рюкзаки',
            'slug' => 'brand-ryukzaky',
            'is_active' => true,
        ]);
        $tents = Category::create([
            'name' => 'Намети',
            'slug' => 'brand-namety',
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $backpacks->id,
            'name' => 'Osprey рюкзак',
            'slug' => 'osprey-ryukzak',
            'brand' => 'Osprey',
            'model' => 'Atmos',
            'price' => 6250,
            'stock' => 3,
            'is_active' => true,
            'is_visible_in_catalog' => true,
        ]);
        Product::create([
            'category_id' => $tents->id,
            'name' => 'Osprey намет',
            'slug' => 'osprey-namet',
            'brand' => 'Osprey',
            'model' => 'Farpoint',
            'price' => 8900,
            'stock' => 2,
            'is_active' => true,
            'is_visible_in_catalog' => true,
        ]);
        Product::create([
            'category_id' => $backpacks->id,
            'name' => 'Deuter рюкзак',
            'slug' => 'deuter-ryukzak',
            'brand' => 'Deuter',
            'price' => 4100,
            'stock' => 4,
            'is_active' => true,
            'is_visible_in_catalog' => true,
        ]);

        $this->get('/brand')
            ->assertOk()
            ->assertSee('Osprey')
            ->assertSee('Deuter')
            ->assertSee('/brand/osprey', false);

        $this->get('/ru/brand')
            ->assertOk()
            ->assertSee('Бренды');

        $this->get('/brand/osprey')
            ->assertOk()
            ->assertSee('Osprey рюкзак')
            ->assertSee('Osprey намет')
            ->assertDontSee('Deuter рюкзак')
            ->assertSee('data-deferred-filters', false);

        $filters = $this->getJson('/brand/osprey?filters_only=1')
            ->assertOk()
            ->json('filters');

        $this->assertIsString($filters);
        $this->assertStringContainsString('catalog_category[]', $filters);
        $this->assertStringContainsString('Рюкзаки', $filters);
        $this->assertStringContainsString('Намети', $filters);

        $this->get('/brand/osprey?catalog_category[]=Рюкзаки')
            ->assertOk()
            ->assertSee('Osprey рюкзак')
            ->assertDontSee('Osprey намет');

        $this->get('/brand/missing-brand')->assertNotFound();
    }

    public function test_brand_index_renders_when_a_letter_has_a_single_brand(): void
    {
        $category = Category::create([
            'name' => 'Рюкзаки',
            'slug' => 'brand-single-letter',
            'is_active' => true,
        ]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Osprey Quasar',
            'slug' => 'osprey-single-letter',
            'brand' => 'Osprey',
            'price' => 6250,
            'stock' => 3,
            'is_active' => true,
            'is_visible_in_catalog' => true,
        ]);

        $this->get('/brand')
            ->assertOk()
            ->assertSee('Osprey')
            ->assertSee('/brand/osprey', false)
            ->assertDontSee('Cannot access offset', false);
    }

    public function test_repeat_brand_page_request_does_not_rebuild_product_listing(): void
    {
        $category = Category::create([
            'name' => 'Рюкзаки',
            'slug' => 'brand-cache-ryukzaky',
            'is_active' => true,
        ]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Osprey Quasar',
            'slug' => 'osprey-quasar-cache',
            'brand' => 'Osprey',
            'price' => 6250,
            'stock' => 3,
            'is_active' => true,
            'is_visible_in_catalog' => true,
        ]);

        $this->get('/brand/osprey')->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/brand/osprey')->assertOk();

        $productQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => str_contains(mb_strtolower($query), 'from "products"') || str_contains(mb_strtolower($query), 'from `products`'))
            ->values();

        $this->assertSame([], $productQueries->all());
    }

    public function test_product_page_links_to_brand_catalog(): void
    {
        $category = Category::create([
            'name' => 'Рюкзаки',
            'slug' => 'brand-link-ryukzaky',
            'is_active' => true,
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Osprey Quasar',
            'slug' => 'osprey-quasar',
            'brand' => 'Osprey',
            'price' => 6250,
            'stock' => 3,
            'is_active' => true,
            'is_visible_in_catalog' => true,
        ]);

        $this->get($product->url())
            ->assertOk()
            ->assertSee('/brand/osprey', false);
    }
}
