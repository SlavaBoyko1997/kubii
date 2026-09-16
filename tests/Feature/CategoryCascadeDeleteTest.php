<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryCascadeDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_parent_category_deletes_all_descendants(): void
    {
        $root = Category::create(['name' => 'Туризм', 'slug' => 'tourism']);
        $child = Category::create(['name' => 'Намети', 'slug' => 'tents', 'parent_id' => $root->id]);
        $grandchild = Category::create(['name' => '2-місні', 'slug' => 'two-person-tents', 'parent_id' => $child->id]);

        Product::create([
            'category_id' => $grandchild->id,
            'name' => 'Намет тестовий',
            'slug' => 'test-tent',
            'price' => 1000,
            'stock' => 1,
        ]);

        $root->delete();

        $this->assertDatabaseMissing('categories', ['id' => $root->id]);
        $this->assertDatabaseMissing('categories', ['id' => $child->id]);
        $this->assertDatabaseMissing('categories', ['id' => $grandchild->id]);
        $this->assertNull(Product::query()->where('slug', 'test-tent')->value('category_id'));
    }
}
