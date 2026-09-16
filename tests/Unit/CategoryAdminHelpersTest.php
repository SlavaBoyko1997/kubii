<?php

namespace Tests\Unit;

use App\Filament\Resources\Categories\CategoryResource;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryAdminHelpersTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_unique_category_slug_from_name(): void
    {
        Category::create(['name' => 'Намети', 'slug' => 'namety']);

        $this->assertSame('namety-2', CategoryResource::generateUniqueCategorySlug('Намети'));
    }

    public function test_it_calculates_next_sort_order_within_parent(): void
    {
        $parent = Category::create(['name' => 'Туризм', 'slug' => 'tourism', 'sort_order' => 0]);
        Category::create(['name' => 'Намети', 'slug' => 'tents', 'parent_id' => $parent->id, 'sort_order' => 3]);

        $this->assertSame(4, CategoryResource::nextCategorySortOrder($parent->id));
        $this->assertSame(1, CategoryResource::nextCategorySortOrder(null));
    }
}
