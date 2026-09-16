<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CategoryFilterVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_child_category_inherits_parent_base_filters_when_not_configured(): void
    {
        $parent = Category::create([
            'name' => 'Одяг',
            'slug' => 'clothes-filters-parent',
            'is_active' => true,
            'visible_filters' => ['brand', 'price', 'season'],
        ]);
        $child = Category::create([
            'name' => 'Футболки',
            'slug' => 'shirts-filters-child',
            'parent_id' => $parent->id,
            'is_active' => true,
            'visible_filters' => [],
        ]);

        Product::create([
            'category_id' => $child->id,
            'name' => 'Футболка Favorite',
            'slug' => 'shirt-favorite-filter',
            'brand' => 'Favorite',
            'season' => 'Літо',
            'price' => 650,
            'stock' => 2,
            'is_active' => true,
        ]);
        Product::create([
            'category_id' => $child->id,
            'name' => 'Футболка Pobedov',
            'slug' => 'shirt-pobedov-filter',
            'brand' => 'Pobedov',
            'season' => 'Літо',
            'price' => 700,
            'stock' => 2,
            'is_active' => true,
        ]);

        $this->get($child->catalogUrl())
            ->assertOk()
            ->assertSee('<legend>Бренд</legend>', false)
            ->assertSee('<legend>Ціна', false)
            ->assertSee('<legend>Сезон</legend>', false)
            ->assertDontSee('<legend>Модель</legend>', false);
    }

    public function test_price_filter_is_shown_even_when_all_products_have_same_price(): void
    {
        $category = Category::create([
            'name' => 'Аксесуари',
            'slug' => 'same-price-filters',
            'is_active' => true,
        ]);

        foreach (['first', 'second'] as $suffix) {
            Product::create([
                'category_id' => $category->id,
                'name' => 'Аксесуар '.$suffix,
                'slug' => 'same-price-'.$suffix,
                'price' => 500,
                'stock' => 2,
                'is_active' => true,
            ]);
        }

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertSee('<legend>Ціна', false);
    }

    public function test_spec_filters_from_parent_apply_to_child_category(): void
    {
        $parent = Category::create([
            'name' => 'Спорядження',
            'slug' => 'gear-spec-filters-parent',
            'is_active' => true,
            'visible_spec_filters' => ['Международный размер'],
        ]);
        $child = Category::create([
            'name' => 'Футболки',
            'slug' => 'gear-spec-filters-child',
            'parent_id' => $parent->id,
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $child->id,
            'name' => 'Футболка L',
            'slug' => 'spec-filter-shirt-l',
            'price' => 650,
            'stock' => 2,
            'is_active' => true,
            'specifications' => ['Международный размер' => 'L'],
        ]);
        Product::create([
            'category_id' => $child->id,
            'name' => 'Футболка XL',
            'slug' => 'spec-filter-shirt-xl',
            'price' => 650,
            'stock' => 2,
            'is_active' => true,
            'specifications' => ['Международный размер' => 'XL'],
        ]);

        $this->get($child->catalogUrl())
            ->assertOk()
            ->assertSee('<legend>Международный размер</legend>', false)
            ->assertSee('data-filter-option="spec[Международный размер]"', false)
            ->assertSee('value="L"', false)
            ->assertSee('value="XL"', false);
    }

    public function test_admin_can_disable_catalog_filter_for_category_and_children_from_storefront(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $parent = Category::create([
            'name' => 'Одяг',
            'slug' => 'front-admin-filter-parent',
            'is_active' => true,
            'visible_spec_filters' => ['Матеріал', 'Колір'],
        ]);
        $child = Category::create([
            'name' => 'Кросівки',
            'slug' => 'front-admin-filter-child',
            'parent_id' => $parent->id,
            'is_active' => true,
            'visible_spec_filters' => ['Матеріал', 'Колір'],
        ]);

        Product::create([
            'category_id' => $child->id,
            'name' => 'Кросівки зі шкіри',
            'slug' => 'front-admin-filter-sneakers',
            'price' => 2200,
            'stock' => 2,
            'is_active' => true,
            'is_visible_in_catalog' => true,
            'specifications' => [
                'Матеріал' => 'Шкіра',
                'Колір' => 'Чорний',
            ],
        ]);

        $this->actingAs($admin)
            ->postJson(route('catalog.admin.disable-filter'), [
                'category_id' => $parent->id,
                'type' => 'spec',
                'key' => 'Матеріал',
                'include_children' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(['Колір'], $parent->fresh()->visible_spec_filters);
        $this->assertSame(['Колір'], $child->fresh()->visible_spec_filters);

        $this->get($child->catalogUrl())
            ->assertOk()
            ->assertDontSee('<legend>Матеріал</legend>', false)
            ->assertSee('<legend>Колір', false);
    }

    public function test_catalog_filter_admin_controls_are_visible_only_for_admins(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $category = Category::create([
            'name' => 'Адмін-фільтри',
            'slug' => 'front-admin-controls',
            'is_active' => true,
            'visible_spec_filters' => ['Матеріал'],
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Кросівки',
            'slug' => 'front-admin-controls-sneakers',
            'price' => 2200,
            'stock' => 2,
            'is_active' => true,
            'is_visible_in_catalog' => true,
            'specifications' => ['Матеріал' => 'Шкіра'],
        ]);

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertDontSee('data-admin-disable-filter', false);

        $this->actingAs($admin)
            ->get($category->catalogUrl())
            ->assertOk()
            ->assertSee('data-admin-disable-filter', false);
    }

    public function test_non_admin_cannot_disable_catalog_filter_from_storefront(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $category = Category::create([
            'name' => 'Захищені фільтри',
            'slug' => 'front-admin-filter-forbidden',
            'is_active' => true,
            'visible_spec_filters' => ['Матеріал'],
        ]);

        $this->actingAs($user)
            ->postJson(route('catalog.admin.disable-filter'), [
                'category_id' => $category->id,
                'type' => 'spec',
                'key' => 'Матеріал',
            ])
            ->assertForbidden();

        $this->assertSame(['Матеріал'], $category->fresh()->visible_spec_filters);
    }
}
