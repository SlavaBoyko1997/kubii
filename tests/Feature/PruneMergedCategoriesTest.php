<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Support\CatalogMergedCategoryPruner;
use App\Support\CategoryTreeBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneMergedCategoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_pruner_deletes_merge_source_without_products(): void
    {
        $target = Category::create(['name' => 'Target', 'slug' => 'target', 'is_active' => true]);
        $source = Category::create([
            'name' => 'Old duplicate',
            'slug' => 'old-duplicate',
            'is_active' => false,
            'target_category_id' => $target->id,
        ]);

        $deleted = app(CatalogMergedCategoryPruner::class)->prune();

        $this->assertContains($source->id, $deleted);
        $this->assertNull(Category::find($source->id));
        $this->assertNotNull(Category::find($target->id));
    }

    public function test_admin_tree_hides_merge_sources(): void
    {
        $target = Category::create(['name' => 'Target', 'slug' => 'target-tree', 'is_active' => true]);
        Category::create([
            'name' => 'Old duplicate',
            'slug' => 'old-duplicate-tree',
            'is_active' => false,
            'target_category_id' => $target->id,
        ]);
        Category::create(['name' => 'Promo', 'slug' => 'promo-tree', 'is_active' => false]);

        $roots = collect(app(CategoryTreeBuilder::class)->nested())
            ->map(fn (array $node): int => $node['category']->id);

        $this->assertTrue($roots->contains($target->id));
        $this->assertTrue($roots->contains(Category::query()->where('slug', 'promo-tree')->value('id')));
        $this->assertFalse($roots->contains(Category::query()->where('slug', 'old-duplicate-tree')->value('id')));
    }

    public function test_pruner_does_not_delete_category_with_products(): void
    {
        $target = Category::create(['name' => 'Target', 'slug' => 'target-products', 'is_active' => true]);
        $source = Category::create([
            'name' => 'Still has products',
            'slug' => 'still-has-products',
            'is_active' => false,
            'target_category_id' => $target->id,
        ]);

        Product::create([
            'category_id' => $source->id,
            'name' => 'Item',
            'slug' => 'item',
            'price' => 10,
            'stock' => 1,
            'is_active' => true,
        ]);

        app(CatalogMergedCategoryPruner::class)->prune();

        $this->assertNotNull(Category::find($source->id));
    }
}
