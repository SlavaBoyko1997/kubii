<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Product;
use App\Support\CategoryTreeBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CategoryTreeBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_move_category_changes_parent(): void
    {
        $root = Category::create(['name' => 'Root', 'slug' => 'root']);
        $child = Category::create(['name' => 'Child', 'slug' => 'child', 'parent_id' => $root->id]);
        $target = Category::create(['name' => 'Target', 'slug' => 'target']);

        app(CategoryTreeBuilder::class)->moveCategory($child->id, $target->id);

        $this->assertSame($target->id, $child->fresh()->parent_id);
    }

    public function test_move_category_to_root_clears_parent(): void
    {
        $root = Category::create(['name' => 'Root', 'slug' => 'root']);
        $child = Category::create(['name' => 'Child', 'slug' => 'child', 'parent_id' => $root->id]);

        app(CategoryTreeBuilder::class)->moveCategory($child->id, null);

        $this->assertNull($child->fresh()->parent_id);
    }

    public function test_move_category_prevents_cycles(): void
    {
        $root = Category::create(['name' => 'Root', 'slug' => 'root']);
        $child = Category::create(['name' => 'Child', 'slug' => 'child', 'parent_id' => $root->id]);
        $grandchild = Category::create(['name' => 'Grandchild', 'slug' => 'grandchild', 'parent_id' => $child->id]);

        $this->expectException(InvalidArgumentException::class);

        app(CategoryTreeBuilder::class)->moveCategory($root->id, $grandchild->id);
    }

    public function test_reorder_category_moves_item_after_sibling(): void
    {
        $first = Category::create(['name' => 'First', 'slug' => 'first', 'sort_order' => 1]);
        $second = Category::create(['name' => 'Second', 'slug' => 'second', 'sort_order' => 2]);
        $third = Category::create(['name' => 'Third', 'slug' => 'third', 'sort_order' => 3]);

        app(CategoryTreeBuilder::class)->reorderCategory($first->id, $second->id, 'after');

        $this->assertSame(2, $first->fresh()->sort_order);
        $this->assertSame(1, $second->fresh()->sort_order);
        $this->assertSame(3, $third->fresh()->sort_order);
    }

    public function test_reorder_category_can_move_between_parents_on_same_level(): void
    {
        $parent = Category::create(['name' => 'Parent', 'slug' => 'parent']);
        $first = Category::create(['name' => 'First', 'slug' => 'first-child', 'parent_id' => $parent->id, 'sort_order' => 1]);
        $second = Category::create(['name' => 'Second', 'slug' => 'second-child', 'parent_id' => $parent->id, 'sort_order' => 2]);
        $otherRoot = Category::create(['name' => 'Other root', 'slug' => 'other-root', 'sort_order' => 1]);

        app(CategoryTreeBuilder::class)->reorderCategory($otherRoot->id, $first->id, 'before');

        $this->assertSame($parent->id, $otherRoot->fresh()->parent_id);
        $this->assertSame(1, $otherRoot->fresh()->sort_order);
        $this->assertSame(2, $first->fresh()->sort_order);
        $this->assertSame(3, $second->fresh()->sort_order);
    }

    public function test_shift_category_moves_item_up(): void
    {
        $first = Category::create(['name' => 'First', 'slug' => 'shift-first', 'sort_order' => 1]);
        $second = Category::create(['name' => 'Second', 'slug' => 'shift-second', 'sort_order' => 2]);

        app(CategoryTreeBuilder::class)->shiftCategory($second->id, 'up');

        $this->assertSame(1, $second->fresh()->sort_order);
        $this->assertSame(2, $first->fresh()->sort_order);
    }

    public function test_indent_and_outdent_category_change_nesting(): void
    {
        $first = Category::create(['name' => 'First', 'slug' => 'indent-first', 'sort_order' => 1]);
        $second = Category::create(['name' => 'Second', 'slug' => 'indent-second', 'sort_order' => 2]);

        app(CategoryTreeBuilder::class)->indentCategory($second->id);

        $this->assertSame($first->id, $second->fresh()->parent_id);

        app(CategoryTreeBuilder::class)->outdentCategory($second->id);

        $this->assertNull($second->fresh()->parent_id);
        $this->assertGreaterThan($first->fresh()->sort_order, $second->fresh()->sort_order);
    }

    public function test_nested_excludes_inactive_categories_by_default(): void
    {
        $active = Category::create(['name' => 'Active', 'slug' => 'active', 'is_active' => true]);
        Category::create(['name' => 'Inactive', 'slug' => 'inactive', 'is_active' => false]);
        Category::create([
            'name' => 'Inactive child',
            'slug' => 'inactive-child',
            'parent_id' => $active->id,
            'is_active' => false,
        ]);

        $tree = app(CategoryTreeBuilder::class)->nested(includeInactive: false);

        $this->assertCount(1, $tree);
        $this->assertSame('Active', $tree[0]['category']->name);
        $this->assertSame([], $tree[0]['children']);
    }

    public function test_nested_places_categories_without_products_last_at_each_level(): void
    {
        $root = Category::create(['name' => 'Root', 'slug' => 'root', 'sort_order' => 1]);
        $emptyEarly = Category::create([
            'name' => 'Empty early',
            'slug' => 'empty-early',
            'parent_id' => $root->id,
            'sort_order' => 1,
        ]);
        $withProducts = Category::create([
            'name' => 'With products',
            'slug' => 'with-products',
            'parent_id' => $root->id,
            'sort_order' => 2,
        ]);
        $emptyLate = Category::create([
            'name' => 'Empty late',
            'slug' => 'empty-late',
            'parent_id' => $root->id,
            'sort_order' => 3,
        ]);

        Product::create([
            'category_id' => $withProducts->id,
            'name' => 'Test product',
            'slug' => 'test-product',
            'price' => 100,
            'stock' => 1,
            'is_active' => true,
            'is_processed' => true,
        ]);

        $rootNode = collect(app(CategoryTreeBuilder::class)->nested())
            ->firstWhere(fn (array $node): bool => $node['category']->is($root));

        $this->assertNotNull($rootNode);
        $this->assertSame(
            [$withProducts->id, $emptyEarly->id, $emptyLate->id],
            collect($rootNode['children'])->map(fn (array $node): int => $node['category']->id)->all(),
        );
    }

    public function test_options_for_grouping_includes_mapped_feed_categories(): void
    {
        $catalog = Category::create([
            'name' => 'Взуття каталог',
            'slug' => 'catalog-shoes-group',
            'is_active' => true,
        ]);
        $feedParent = Category::create([
            'name' => 'Взуття фід',
            'slug' => 'feed-shoes-parent',
            'counterparty_id' => null,
            'external_id' => null,
            'target_category_id' => $catalog->id,
            'is_active' => false,
        ]);
        // Ensure it looks like a feed category in labels; counterparty optional for this test.
        $feedLeaf = Category::create([
            'name' => 'Черевики фід',
            'slug' => 'feed-boots-leaf',
            'parent_id' => $feedParent->id,
            'target_category_id' => $catalog->id,
            'is_active' => false,
        ]);

        $adminOptions = app(CategoryTreeBuilder::class)->options();
        $groupingOptions = app(CategoryTreeBuilder::class)->optionsForGrouping();

        $this->assertArrayNotHasKey($feedParent->id, $adminOptions);
        $this->assertArrayNotHasKey($feedLeaf->id, $adminOptions);
        $this->assertArrayHasKey($catalog->id, $groupingOptions);
        $this->assertArrayHasKey($feedParent->id, $groupingOptions);
        $this->assertArrayHasKey($feedLeaf->id, $groupingOptions);
        $this->assertStringContainsString('неактивна', $groupingOptions[$feedLeaf->id]);
    }

    public function test_grouping_category_search_includes_feed_and_inactive_categories(): void
    {
        $parent = Category::create([
            'name' => 'Одяг та взуття',
            'slug' => 'clothes-search-grouping',
            'is_active' => true,
        ]);
        $counterparty = Counterparty::create(['name' => 'Camotec', 'slug' => 'camotec-search-grouping']);
        $feedCategory = Category::create([
            'name' => 'Черевики Scout фід',
            'slug' => 'scout-feed-search-grouping',
            'parent_id' => $parent->id,
            'counterparty_id' => $counterparty->id,
            'external_id' => 'feed-scout',
            'is_active' => false,
        ]);

        Product::create([
            'category_id' => $feedCategory->id,
            'name' => 'Черевики Scout MID Койот',
            'slug' => 'search-grouping-scout-boot',
            'price' => 4595,
            'stock' => 1,
        ]);

        $options = app(CategoryTreeBuilder::class)->searchOptionsForGrouping('Scout');

        $this->assertArrayHasKey($feedCategory->id, $options);
        $this->assertStringContainsString('Одяг та взуття / Черевики Scout фід', $options[$feedCategory->id]);
        $this->assertStringContainsString('неактивна', $options[$feedCategory->id]);
        $this->assertStringContainsString('фід', $options[$feedCategory->id]);
    }
}
