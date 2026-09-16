<?php

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryCascadeActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabling_parent_category_activates_all_descendants(): void
    {
        $root = Category::create(['name' => 'Туризм', 'slug' => 'tourism', 'is_active' => false]);
        $child = Category::create(['name' => 'Намети', 'slug' => 'tents', 'parent_id' => $root->id, 'is_active' => false]);
        $grandchild = Category::create([
            'name' => '2-місні',
            'slug' => 'two-person-tents',
            'parent_id' => $child->id,
            'is_active' => false,
        ]);

        $root->update(['is_active' => true]);

        $this->assertTrue($root->fresh()->is_active);
        $this->assertTrue($child->fresh()->is_active);
        $this->assertTrue($grandchild->fresh()->is_active);
    }

    public function test_enabling_child_category_does_not_change_parent_state(): void
    {
        $root = Category::create(['name' => 'Туризм', 'slug' => 'tourism-child-only', 'is_active' => false]);
        $child = Category::create(['name' => 'Намети', 'slug' => 'tents-child-only', 'parent_id' => $root->id, 'is_active' => false]);

        $child->update(['is_active' => true]);

        $this->assertFalse($root->fresh()->is_active);
        $this->assertTrue($child->fresh()->is_active);
    }
}
