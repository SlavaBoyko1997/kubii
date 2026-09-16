<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Services\CatalogSpecificationFacets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSpecificationFacetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_filter_counts_include_inactive_products_when_requested(): void
    {
        $category = Category::create([
            'name' => 'Поло',
            'slug' => 'camotec-polo-filters',
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Поло S',
            'slug' => 'camotec-polo-s',
            'price' => 960,
            'stock' => 1,
            'is_active' => false,
            'specifications' => [
                'Розмір' => 'S',
                'Сезон' => 'Літо, Весна',
            ],
        ]);

        $facets = app(CatalogSpecificationFacets::class);

        $this->assertSame([], array_keys($facets->availableFilterCounts($category)));
        $this->assertSame(
            ['Розмір', 'Сезон'],
            array_keys($facets->availableFilterCounts($category, includeInactive: true)),
        );
    }

    public function test_values_split_comma_separated_specifications(): void
    {
        $facets = app(CatalogSpecificationFacets::class);

        $this->assertSame(
            ['Літо', 'Весна'],
            $facets->values('Літо, Весна'),
        );
    }

    public function test_apply_filters_matches_comma_separated_specification_values(): void
    {
        $category = Category::create([
            'name' => 'Куртки',
            'slug' => 'jackets-season-filter',
            'is_active' => true,
        ]);

        $matching = Product::create([
            'category_id' => $category->id,
            'name' => 'Куртка літня',
            'slug' => 'summer-jacket-filter',
            'price' => 4200,
            'stock' => 2,
            'is_active' => true,
            'is_visible_in_catalog' => true,
            'specifications' => [
                'Сезон' => 'Літо, Весна',
            ],
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Куртка зимова',
            'slug' => 'winter-jacket-filter',
            'price' => 5200,
            'stock' => 2,
            'is_active' => true,
            'is_visible_in_catalog' => true,
            'specifications' => [
                'Сезон' => 'Зима',
            ],
        ]);

        $query = Product::query()->where('category_id', $category->id);
        app(CatalogSpecificationFacets::class)->applyFilters($query, ['Сезон' => ['Літо']]);

        $this->assertSame([$matching->id], $query->pluck('id')->all());
    }

    public function test_apply_filters_matches_variant_secondary_specs(): void
    {
        $category = Category::create([
            'name' => 'Черевики',
            'slug' => 'boots-secondary-spec-filter',
            'is_active' => true,
        ]);

        $matching = Product::create([
            'category_id' => $category->id,
            'name' => 'Черевик 42',
            'slug' => 'boot-42-filter',
            'price' => 3200,
            'stock' => 1,
            'is_active' => true,
            'is_visible_in_catalog' => true,
            'variant_secondary_specs' => [
                'Розмір' => '42',
            ],
        ]);

        $query = Product::query()->where('category_id', $category->id);
        app(CatalogSpecificationFacets::class)->applyFilters($query, ['Розмір' => ['42']]);

        $this->assertSame([$matching->id], $query->pluck('id')->all());
    }

    public function test_catalog_spec_filter_finds_products_with_comma_separated_values(): void
    {
        $category = Category::create([
            'name' => 'Спальники',
            'slug' => 'sleeping-bags-season-filter',
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Спальник літній',
            'slug' => 'summer-sleeping-bag',
            'price' => 2800,
            'stock' => 2,
            'is_active' => true,
            'is_visible_in_catalog' => true,
            'specifications' => [
                'Сезон' => 'Літо, Весна',
            ],
        ]);

        $this->followingRedirects()->get($category->catalogUrl(['spec' => ['Сезон' => ['Літо']]]))
            ->assertOk()
            ->assertSee('Спальник літній')
            ->assertSee('data-filter-key="Сезон"', false);
    }
}
