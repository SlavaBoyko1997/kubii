<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Services\CatalogSpecificationFacets;
use App\Support\CategorySpecFilterSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategorySpecFilterSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_keys_respect_explicit_spec_filter_selection(): void
    {
        $category = Category::create([
            'name' => 'Поло',
            'slug' => 'catalog-polo-filters',
            'is_active' => true,
            'visible_spec_filters' => ['Розмір'],
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Поло S',
            'slug' => 'polo-filter-s',
            'price' => 960,
            'stock' => 1,
            'is_active' => true,
            'is_processed' => true,
            'is_visible_in_catalog' => true,
            'specifications' => [
                'Розмір' => 'S',
                'Технології' => 'Швидке висихання',
            ],
        ]);

        $keys = app(CatalogSpecificationFacets::class)->configuredKeys($category, includeDiscovered: true);

        $this->assertContains('Розмір', $keys);
        $this->assertNotContains('Технології', $keys);
    }

    public function test_synchronizer_does_not_reenable_filters_removed_from_explicit_selection(): void
    {
        $category = Category::create([
            'name' => 'Поло',
            'slug' => 'sync-polo-filters',
            'is_active' => true,
            'visible_spec_filters' => ['Розмір'],
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Поло M',
            'slug' => 'sync-polo-m',
            'price' => 960,
            'stock' => 1,
            'is_active' => true,
            'specifications' => [
                'Розмір' => 'M',
                'Стать' => 'Для чоловіків',
            ],
        ]);

        $updated = app(CategorySpecFilterSynchronizer::class)->syncCategory($category);

        $this->assertFalse($updated);
        $this->assertSame(['Розмір'], $category->fresh()->visible_spec_filters ?? []);
    }

    public function test_synchronizer_keeps_explicitly_empty_spec_filter_selection(): void
    {
        $category = Category::create([
            'name' => 'Поло',
            'slug' => 'sync-empty-polo-filters',
            'is_active' => true,
            'visible_spec_filters' => [],
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Поло без фільтрів',
            'slug' => 'sync-empty-polo',
            'price' => 960,
            'stock' => 1,
            'is_active' => true,
            'specifications' => [
                'Розмір' => 'M',
            ],
        ]);

        $updated = app(CategorySpecFilterSynchronizer::class)->syncCategory($category);

        $this->assertFalse($updated);
        $this->assertSame([], $category->fresh()->visible_spec_filters);
    }

    public function test_child_category_inherits_parent_spec_filter_selection(): void
    {
        $parent = Category::create([
            'name' => 'Одяг',
            'slug' => 'parent-clothes-filters',
            'is_active' => true,
            'visible_spec_filters' => ['Розмір'],
        ]);
        $child = Category::create([
            'name' => 'Поло',
            'slug' => 'child-polo-filters',
            'parent_id' => $parent->id,
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $child->id,
            'name' => 'Поло L',
            'slug' => 'child-polo-l',
            'price' => 960,
            'stock' => 1,
            'is_active' => true,
            'is_visible_in_catalog' => true,
            'specifications' => [
                'Розмір' => 'L',
                'Колір' => 'Олива',
            ],
        ]);

        $keys = app(CatalogSpecificationFacets::class)->configuredKeys($child, includeDiscovered: true);

        $this->assertContains('Розмір', $keys);
        $this->assertNotContains('Колір', $keys);
    }

    public function test_configured_keys_include_variant_secondary_specs(): void
    {
        $category = Category::create([
            'name' => 'Куртки',
            'slug' => 'jackets-secondary-spec-filters',
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Куртка M',
            'slug' => 'jacket-secondary-spec-m',
            'price' => 3200,
            'stock' => 1,
            'is_active' => true,
            'is_visible_in_catalog' => true,
            'variant_secondary_specs' => [
                'Утеплювач' => 'Synthetic',
            ],
        ]);

        $facets = app(CatalogSpecificationFacets::class);
        $keys = $facets->configuredKeys($category, includeDiscovered: true);
        $counts = $facets->counts(
            $facets->constrainProductsWithSpecFilters(
                Product::query()->where('category_id', $category->id),
            )
                ->select($facets->specFilterProductColumns())
                ->lazyById(100),
            allowedKeys: $keys,
        );

        $this->assertContains('Утеплювач', $keys);
        $this->assertArrayHasKey('Утеплювач', $counts);
        $this->assertArrayHasKey('Synthetic', $counts['Утеплювач']);
    }
}
