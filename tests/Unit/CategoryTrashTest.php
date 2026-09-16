<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Product;
use App\Support\CategoryTreeBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTrashTest extends TestCase
{
    use RefreshDatabase;

    public function test_trash_category_moves_subtree_to_trash(): void
    {
        $parent = Category::create(['name' => 'Parent', 'slug' => 'parent-trash']);
        $child = Category::create(['name' => 'Child', 'slug' => 'child-trash', 'parent_id' => $parent->id]);
        $builder = app(CategoryTreeBuilder::class);

        $deleted = $builder->trashCategory($parent->id);

        $this->assertSame(2, $deleted);
        $this->assertSoftDeleted('categories', ['id' => $parent->id]);
        $this->assertSoftDeleted('categories', ['id' => $child->id]);
        $this->assertSame([], $builder->nested());
        $this->assertCount(1, $builder->nestedTrashed());
        $this->assertSame($parent->id, $builder->nestedTrashed()[0]['category']->id);
        $this->assertCount(1, $builder->nestedTrashed()[0]['children']);
    }

    public function test_restore_category_restores_subtree_and_trashed_ancestors(): void
    {
        $root = Category::create(['name' => 'Root', 'slug' => 'root-trash']);
        $child = Category::create(['name' => 'Child', 'slug' => 'child-restore', 'parent_id' => $root->id]);
        $builder = app(CategoryTreeBuilder::class);
        $builder->trashCategory($root->id);

        $restored = $builder->restoreCategory($child->id);

        $this->assertSame(2, $restored);
        $this->assertNotSoftDeleted('categories', ['id' => $root->id]);
        $this->assertNotSoftDeleted('categories', ['id' => $child->id]);
        $this->assertSame([], $builder->nestedTrashed());
        $this->assertCount(1, $builder->nested());
    }

    public function test_trashed_products_keep_category_reference(): void
    {
        $category = Category::create(['name' => 'With products', 'slug' => 'with-products-trash']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Item',
            'slug' => 'item-trash',
            'price' => 100,
            'stock' => 1,
            'is_active' => true,
            'is_processed' => true,
        ]);

        app(CategoryTreeBuilder::class)->trashCategory($category->id);

        $this->assertSoftDeleted('categories', ['id' => $category->id]);
        $this->assertSame($category->id, $product->fresh()->category_id);
    }
}
