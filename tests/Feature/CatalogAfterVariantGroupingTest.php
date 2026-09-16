<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariantGroup;
use App\Services\ProductVariantGrouper;
use App\Support\CatalogCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CatalogAfterVariantGroupingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_catalog_shows_primary_product_after_variant_regroup_and_cache_clear(): void
    {
        $category = Category::create(['name' => 'Футболки', 'slug' => 'grouped-shirts-catalog', 'is_active' => true]);

        foreach ([
            ['0001', 'Футболка Pobedov XL', 11],
            ['0002', 'Футболка Pobedov L', 5],
        ] as [$externalId, $name, $stock]) {
            Product::create([
                'category_id' => $category->id,
                'source' => 'pobedov',
                'external_id' => $externalId,
                'variant_id' => 'pobedov-group-229771',
                'name' => $name,
                'slug' => 'grouped-catalog-'.$externalId,
                'price' => 650,
                'stock' => $stock,
                'is_active' => true,
                'specifications' => ['Международный размер' => $externalId],
            ]);
        }

        app(ProductVariantGrouper::class)->discover($category);

        $group = ProductVariantGroup::query()->firstOrFail();
        $this->assertSame(1, Product::query()->where('category_id', $category->id)->visibleInCatalog()->count());

        Cache::flush();
        app(CatalogCache::class)->invalidate();

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertSee('Футболка Pobedov XL')
            ->assertSee('1 товар')
            ->assertDontSee('Футболка Pobedov L');
    }

    public function test_sync_catalog_visibility_repairs_invisible_primary_variants(): void
    {
        $category = Category::create(['name' => 'Ремонт', 'slug' => 'repair-visibility', 'is_active' => true]);

        foreach ([
            ['0001', 'Товар головний', 11],
            ['0002', 'Товар варіант', 5],
        ] as [$externalId, $name, $stock]) {
            Product::create([
                'category_id' => $category->id,
                'source' => 'pobedov',
                'external_id' => $externalId,
                'variant_id' => 'pobedov-group-repair',
                'name' => $name,
                'slug' => 'repair-'.$externalId,
                'price' => 650,
                'stock' => $stock,
                'is_active' => true,
            ]);
        }

        app(ProductVariantGrouper::class)->discover($category);

        Product::query()->update([
            'is_visible_in_catalog' => false,
        ]);

        app(ProductVariantGrouper::class)->syncCatalogVisibility();

        $this->assertSame(1, Product::query()->where('category_id', $category->id)->visibleInCatalog()->count());

        Cache::flush();

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertSee('Товар головний');
    }

    public function test_catalog_with_filter_does_not_show_hidden_variant_products(): void
    {
        $category = Category::create(['name' => 'Крісла', 'slug' => 'folding-chairs-filter', 'is_active' => true]);

        foreach ([
            ['0001', 'Крісло Ranger Guard Lite (Арт. RA 2241)', 'Ranger', 11],
            ['0002', 'Крісло Ranger Guard Lite синє', 'Ranger', 5],
        ] as [$externalId, $name, $brand, $stock]) {
            Product::create([
                'category_id' => $category->id,
                'source' => 'pobedov',
                'external_id' => $externalId,
                'variant_id' => 'pobedov-group-chair',
                'name' => $name,
                'slug' => 'chair-filter-'.$externalId,
                'brand' => $brand,
                'price' => 1200,
                'sale_price' => 999,
                'stock' => $stock,
                'is_active' => true,
            ]);
        }

        app(ProductVariantGrouper::class)->discover($category);

        $primary = Product::query()->visibleInCatalog()->firstOrFail();

        Cache::flush();
        app(CatalogCache::class)->invalidate();

        $this->followingRedirects()->get($category->catalogUrl(['on_sale' => 1]))
            ->assertOk()
            ->assertSee($primary->name)
            ->assertDontSee('Крісло Ranger Guard Lite синє');
    }

    public function test_spec_filter_counts_ignore_hidden_variant_products(): void
    {
        $category = Category::create([
            'name' => 'Кросівки',
            'slug' => 'sneakers-hidden-variant-filter',
            'is_active' => true,
            'visible_spec_filters' => ['Матеріал'],
        ]);
        $group = ProductVariantGroup::create([
            'category_id' => $category->id,
            'title' => 'Кросівки Trail',
            'group_key' => 'sneakers-hidden-variant-filter',
            'variant_option_keys' => ['Матеріал'],
            'status' => 'approved',
        ]);
        $primary = Product::create([
            'category_id' => $category->id,
            'variant_group_id' => $group->id,
            'name' => 'Кросівки Trail текстиль',
            'slug' => 'sneakers-trail-textile',
            'price' => 3200,
            'stock' => 2,
            'is_active' => true,
            'is_primary_variant' => true,
            'is_visible_in_catalog' => true,
            'specifications' => ['Матеріал' => 'Текстиль'],
        ]);
        Product::create([
            'category_id' => $category->id,
            'variant_group_id' => $group->id,
            'name' => 'Кросівки Trail шкіра',
            'slug' => 'sneakers-trail-leather',
            'price' => 3300,
            'stock' => 2,
            'is_active' => true,
            'is_primary_variant' => false,
            'is_visible_in_catalog' => false,
            'specifications' => ['Матеріал' => 'Шкіра'],
        ]);
        $group->update(['primary_product_id' => $primary->id]);

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertSee('Текстиль')
            ->assertDontSee('Шкіра');

        $this->get($category->catalogUrl(['filter' => 'shkira']))
            ->assertNotFound();
    }

    public function test_search_finds_primary_product_by_hidden_variant_article(): void
    {
        $category = Category::create(['name' => 'Крісла', 'slug' => 'folding-chairs-search', 'is_active' => true]);

        foreach ([
            ['0001', 'Крісло Ranger Guard Lite (Арт. RA 2241)', 11],
            ['0002', 'Крісло Ranger Guard Lite синє', 5],
        ] as [$externalId, $name, $stock]) {
            Product::create([
                'category_id' => $category->id,
                'source' => 'pobedov',
                'external_id' => $externalId,
                'variant_id' => 'pobedov-group-search-chair',
                'name' => $name,
                'slug' => 'chair-search-'.$externalId,
                'price' => 1200,
                'stock' => $stock,
                'is_active' => true,
            ]);
        }

        app(ProductVariantGrouper::class)->discover($category);

        $primary = Product::query()->visibleInCatalog()->firstOrFail();

        Cache::flush();

        $results = app(\App\Services\SmartSearch::class)->search('2241')['products'];

        $this->assertTrue($results->contains('id', $primary->id));
    }

    public function test_sync_catalog_visibility_repairs_groups_without_primary_flag(): void
    {
        $category = Category::create(['name' => 'Ремонт primary', 'slug' => 'repair-primary-flag', 'is_active' => true]);

        foreach ([
            ['0001', 'Товар A', 11],
            ['0002', 'Товар B', 5],
        ] as [$externalId, $name, $stock]) {
            Product::create([
                'category_id' => $category->id,
                'source' => 'pobedov',
                'external_id' => $externalId,
                'variant_id' => 'pobedov-group-repair-primary',
                'name' => $name,
                'slug' => 'repair-primary-'.$externalId,
                'price' => 650,
                'stock' => $stock,
                'is_active' => true,
            ]);
        }

        app(ProductVariantGrouper::class)->discover($category);

        Product::query()->update([
            'is_primary_variant' => false,
            'is_visible_in_catalog' => false,
        ]);

        ProductVariantGroup::query()->firstOrFail()->syncProductsCatalogVisibility();

        $this->assertSame(1, Product::query()->where('category_id', $category->id)->visibleInCatalog()->count());

        Cache::flush();

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertSee('Товар A');
    }
}
