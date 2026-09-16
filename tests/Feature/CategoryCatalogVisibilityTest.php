<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Support\CatalogCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CategoryCatalogVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_empty_categories_are_hidden_from_menu_and_home(): void
    {
        Category::create(['name' => 'Порожня', 'slug' => 'empty-category', 'is_active' => true]);
        $filled = Category::create(['name' => 'З товарами', 'slug' => 'filled-category', 'is_active' => true]);

        Product::create([
            'category_id' => $filled->id,
            'name' => 'Товар у категорії',
            'slug' => 'product-in-category',
            'price' => 1000,
            'stock' => 2,
            'is_active' => true,
        ]);

        $menu = app(CatalogCache::class)->menu();

        $this->assertCount(1, $menu);
        $this->assertSame('З товарами', $menu[0]['name']);

        $this->get('/')
            ->assertOk()
            ->assertSee('З товарами')
            ->assertDontSee('Порожня');
    }

    public function test_empty_category_page_returns_not_found(): void
    {
        $category = Category::create(['name' => 'Порожня', 'slug' => 'empty-page-category', 'is_active' => true]);

        $this->get($category->catalogUrl())->assertNotFound();
    }

    public function test_parent_category_stays_visible_when_products_are_in_child_category(): void
    {
        $parent = Category::create(['name' => 'Туризм', 'slug' => 'tourism-visible-parent', 'is_active' => true]);
        $child = Category::create([
            'name' => 'Намети',
            'slug' => 'tents-visible-child',
            'parent_id' => $parent->id,
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $child->id,
            'name' => 'Намет видимий',
            'slug' => 'visible-tent',
            'price' => 1500,
            'stock' => 2,
            'is_active' => true,
        ]);

        $menu = collect(app(CatalogCache::class)->menu());

        $this->assertTrue($menu->contains(fn (array $item): bool => $item['name'] === 'Туризм'));
        $this->get($parent->catalogUrl())
            ->assertOk()
            ->assertSee('Намет видимий');
    }

    public function test_leaf_category_does_not_showcase_sibling_categories(): void
    {
        $root = Category::create(['name' => 'Каталог', 'slug' => 'catalog-root', 'is_active' => true]);
        $parent = Category::create([
            'name' => 'Одяг та взуття',
            'slug' => 'clothes-parent',
            'parent_id' => $root->id,
            'is_active' => true,
        ]);
        $leaf = Category::create([
            'name' => 'Кофти та толстовки',
            'slug' => 'hoodies-leaf',
            'parent_id' => $parent->id,
            'is_active' => true,
        ]);
        $sibling = Category::create([
            'name' => 'Штани',
            'slug' => 'pants-sibling',
            'parent_id' => $parent->id,
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $leaf->id,
            'name' => 'Флісова кофта',
            'slug' => 'fleece-hoodie-leaf',
            'price' => 1200,
            'stock' => 3,
            'is_active' => true,
        ]);
        Product::create([
            'category_id' => $sibling->id,
            'name' => 'Трекінгові штани',
            'slug' => 'trekking-pants-sibling',
            'price' => 1600,
            'stock' => 2,
            'is_active' => true,
        ]);

        $cache = app(CatalogCache::class);

        $this->assertSame([], $cache->categoryShowcase($leaf->id));
        $this->assertTrue(collect($cache->categoryShowcase($parent->id))->contains('id', $leaf->id));
        $this->assertTrue(collect($cache->categoryShowcase($parent->id))->contains('id', $sibling->id));

        $this->get($root->catalogUrl())
            ->assertOk()
            ->assertDontSee('catalog-parent-link', false);

        $this->get($parent->catalogUrl())
            ->assertOk()
            ->assertSee('catalog-parent-link', false)
            ->assertSee($root->name)
            ->assertSee('showcase-category-card', false);

        $this->get($leaf->catalogUrl())
            ->assertOk()
            ->assertSee('catalog-parent-link', false)
            ->assertSee($parent->name)
            ->assertDontSee('showcase-category-card', false)
            ->assertDontSee($sibling->name);
    }

    public function test_menu_follows_admin_sort_order_not_product_count(): void
    {
        $first = Category::create([
            'name' => 'Перша',
            'slug' => 'menu-order-first',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $second = Category::create([
            'name' => 'Друга',
            'slug' => 'menu-order-second',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $first->id,
            'name' => 'Один товар',
            'slug' => 'menu-order-one-product',
            'price' => 100,
            'stock' => 1,
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $second->id,
            'name' => 'Багато товарів A',
            'slug' => 'menu-order-many-a',
            'price' => 100,
            'stock' => 1,
            'is_active' => true,
        ]);
        Product::create([
            'category_id' => $second->id,
            'name' => 'Багато товарів B',
            'slug' => 'menu-order-many-b',
            'price' => 100,
            'stock' => 1,
            'is_active' => true,
        ]);
        Product::create([
            'category_id' => $second->id,
            'name' => 'Багато товарів C',
            'slug' => 'menu-order-many-c',
            'price' => 100,
            'stock' => 1,
            'is_active' => true,
        ]);

        $menu = app(CatalogCache::class)->menu();

        $this->assertSame(['Перша', 'Друга'], collect($menu)->pluck('name')->all());
    }
}
