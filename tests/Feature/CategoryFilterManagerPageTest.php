<?php

namespace Tests\Feature;

use App\Filament\Pages\CategoryFilterManager;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CategoryFilterManagerPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_configure_filters_for_category_and_children(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $parent = Category::create([
            'name' => 'Одяг',
            'slug' => 'admin-filter-clothes',
            'is_active' => true,
        ]);
        $child = Category::create([
            'name' => 'Черевики',
            'slug' => 'admin-filter-boots',
            'parent_id' => $parent->id,
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $child->id,
            'name' => 'Черевики Scout MID Койот, 42',
            'slug' => 'admin-filter-scout-mid-42',
            'brand' => 'camotec',
            'model' => 'Scout MID',
            'price' => 3200,
            'stock' => 4,
            'is_active' => true,
            'specifications' => [
                'Колір' => 'Койот',
                'Розмір' => '42',
            ],
        ]);

        Livewire::actingAs($admin)
            ->test(CategoryFilterManager::class)
            ->set('categoryId', $parent->id)
            ->assertSee('Розмір')
            ->assertSee('Колір')
            ->set('baseFilters', ['brand', 'price'])
            ->set('specFilters', ['Розмір'])
            ->set('filterLabelInputs.'.sha1('Розмір').'.uk', 'Розмір взуття')
            ->set('filterLabelInputs.'.sha1('Розмір').'.ru', 'Размер обуви')
            ->call('save')
            ->assertHasNoErrors();

        $parent->refresh();

        $this->assertSame(['brand', 'price'], $parent->visible_filters);
        $this->assertSame(['Розмір'], $parent->visible_spec_filters);
        $this->assertSame(['Розмір'], $parent->visible_spec_filters_ru);
        $this->assertSame(['Розмір' => 'Розмір взуття'], $parent->filter_labels);
        $this->assertSame(['Розмір' => 'Размер обуви'], $parent->filter_labels_ru);

        $this->get($parent->catalogUrl())
            ->assertOk()
            ->assertSee('<legend>Розмір взуття</legend>', false);
    }

    public function test_category_filter_manager_lists_storefront_visible_categories(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $visible = Category::create([
            'name' => 'Видима категорія',
            'slug' => 'storefront-visible-filter-category',
            'is_active' => true,
        ]);
        $empty = Category::create([
            'name' => 'Порожня категорія',
            'slug' => 'storefront-empty-filter-category',
            'is_active' => true,
        ]);
        $inactive = Category::create([
            'name' => 'Вимкнена категорія',
            'slug' => 'storefront-inactive-filter-category',
            'is_active' => false,
        ]);
        $hiddenByName = Category::create([
            'name' => 'ІБІС Зброя',
            'slug' => 'ibis-hidden-filter-category',
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $visible->id,
            'name' => 'Видимий товар',
            'slug' => 'storefront-visible-filter-product',
            'price' => 1000,
            'stock' => 1,
            'is_active' => true,
            'is_visible_in_catalog' => true,
        ]);
        Product::create([
            'category_id' => $inactive->id,
            'name' => 'Товар вимкненої категорії',
            'slug' => 'storefront-inactive-filter-product',
            'price' => 1000,
            'stock' => 1,
            'is_active' => true,
            'is_visible_in_catalog' => true,
        ]);
        Product::create([
            'category_id' => $hiddenByName->id,
            'name' => 'Прихований товар',
            'slug' => 'ibis-hidden-filter-product',
            'price' => 1000,
            'stock' => 1,
            'is_active' => true,
            'is_visible_in_catalog' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(CategoryFilterManager::class)
            ->assertSet('categoryOptions', [$visible->id => 'Видима категорія'])
            ->assertSet('categoryId', $visible->id)
            ->assertDontSee('Порожня категорія')
            ->assertDontSee('Вимкнена категорія')
            ->assertDontSee('ІБІС Зброя')
            ->assertHasNoErrors();

        $this->assertTrue($empty->exists);
    }

    public function test_admin_can_auto_disable_filters_below_product_threshold(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $category = Category::create([
            'name' => 'Фільтри з порогом',
            'slug' => 'threshold-filters',
            'is_active' => true,
            'visible_filters' => ['brand', 'price', 'material'],
            'visible_spec_filters' => ['Розмір', 'Колір'],
        ]);

        foreach ([1, 2, 3] as $index) {
            Product::create([
                'category_id' => $category->id,
                'name' => 'Черевики '.$index,
                'slug' => 'threshold-boots-'.$index,
                'brand' => 'camotec',
                'material' => $index === 1 ? 'Шкіра' : null,
                'price' => 3200,
                'stock' => 4,
                'is_active' => true,
                'specifications' => array_filter([
                    'Розмір' => (string) (40 + $index),
                    'Колір' => $index === 1 ? 'Койот' : null,
                ]),
            ]);
        }

        Livewire::actingAs($admin)
            ->test(CategoryFilterManager::class)
            ->set('categoryId', $category->id)
            ->set('minimumFilterProducts', 2)
            ->call('autoDisableSparseFilters')
            ->assertHasNoErrors();

        $category->refresh();

        $this->assertSame(['brand', 'price'], $category->visible_filters);
        $this->assertSame(['Розмір'], $category->visible_spec_filters);
        $this->assertSame(['Розмір'], $category->visible_spec_filters_ru);
    }

    public function test_admin_can_auto_disable_spec_filters_with_latin_keys(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $category = Category::create([
            'name' => 'Латинські фільтри',
            'slug' => 'latin-key-filters',
            'is_active' => true,
            'visible_filters' => ['brand', 'price'],
            'visible_spec_filters' => ['Розмір', 'dljaKogo', 'kolr'],
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Черевики Scout',
            'slug' => 'latin-key-scout',
            'brand' => 'camotec',
            'price' => 3200,
            'stock' => 4,
            'is_active' => true,
            'specifications' => [
                'Розмір' => '42',
                'dljaKogo' => 'Для чоловіків',
                'kolr' => 'Койот',
            ],
        ]);

        Livewire::actingAs($admin)
            ->test(CategoryFilterManager::class)
            ->set('categoryId', $category->id)
            ->call('autoDisableLatinNamedSpecFilters')
            ->assertHasNoErrors();

        $category->refresh();

        $this->assertSame(['Розмір'], $category->visible_spec_filters);
        $this->assertSame(['Розмір'], $category->visible_spec_filters_ru);
    }

    public function test_child_category_manager_shows_inherited_spec_filter_selection(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $parent = Category::create([
            'name' => 'Одяг',
            'slug' => 'inherited-spec-parent',
            'is_active' => true,
            'visible_spec_filters' => ['Колір'],
            'visible_spec_filters_ru' => ['Колір'],
        ]);
        $child = Category::create([
            'name' => 'Футболки',
            'slug' => 'inherited-spec-child',
            'parent_id' => $parent->id,
            'is_active' => true,
            'visible_spec_filters' => null,
        ]);

        Product::create([
            'category_id' => $child->id,
            'name' => 'Футболка Birch S',
            'slug' => 'inherited-spec-shirt-s',
            'brand' => 'Bavovna',
            'price' => 960,
            'stock' => 4,
            'is_active' => true,
            'specifications' => [
                'Колір' => 'Birch',
                'rozmrOdjagu' => 'S',
            ],
        ]);

        Livewire::actingAs($admin)
            ->test(CategoryFilterManager::class)
            ->set('categoryId', $child->id)
            ->assertSet('specFilters', ['Колір'])
            ->assertSee('rozmrOdjagu')
            ->assertHasNoErrors();
    }

    public function test_saved_inherited_spec_filter_key_is_visible_but_unchecked_without_current_products(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $parent = Category::create([
            'name' => 'Одяг',
            'slug' => 'saved-inherited-spec-parent',
            'is_active' => true,
            'visible_spec_filters' => ['rozmrOdjagu'],
            'visible_spec_filters_ru' => ['rozmrOdjagu'],
        ]);
        $child = Category::create([
            'name' => 'Футболки',
            'slug' => 'saved-inherited-spec-child',
            'parent_id' => $parent->id,
            'is_active' => true,
            'visible_spec_filters' => null,
        ]);

        Livewire::actingAs($admin)
            ->test(CategoryFilterManager::class)
            ->set('categoryId', $child->id)
            ->assertSet('specFilters', [])
            ->assertSee('rozmrOdjagu')
            ->assertSee('збережено')
            ->assertHasNoErrors();
    }

    public function test_admin_disabling_spec_filters_hides_old_russian_spec_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $category = Category::create([
            'name' => 'Фільтри характеристик',
            'slug' => 'spec-filters-disabled',
            'is_active' => true,
            'visible_filters' => ['brand'],
            'visible_spec_filters' => ['Колір'],
            'visible_spec_filters_ru' => ['Колір'],
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Ніж синій',
            'slug' => 'spec-filter-blue-knife',
            'brand' => 'Ganzo',
            'price' => 1200,
            'stock' => 3,
            'is_active' => true,
            'specifications' => ['Колір' => 'Синій'],
        ]);

        Livewire::actingAs($admin)
            ->test(CategoryFilterManager::class)
            ->set('categoryId', $category->id)
            ->set('specFilters', [])
            ->call('save')
            ->assertHasNoErrors();

        $category->refresh();

        $this->assertSame([], $category->visible_spec_filters);
        $this->assertSame([], $category->visible_spec_filters_ru);

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertDontSee('<legend>Колір</legend>', false)
            ->assertDontSee('Синій');

        $this->get($category->catalogUrl(locale: 'ru'))
            ->assertOk()
            ->assertDontSee('<legend>Колір</legend>', false)
            ->assertDontSee('Синій');
    }

    public function test_admin_can_save_category_filters_and_apply_them_to_children_with_checkbox(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $parent = Category::create([
            'name' => 'Одяг',
            'slug' => 'save-with-children-parent',
            'is_active' => true,
            'visible_filters' => ['brand', 'price', 'model'],
            'visible_spec_filters' => ['Розмір', 'Колір'],
        ]);
        $child = Category::create([
            'name' => 'Черевики',
            'slug' => 'save-with-children-child',
            'parent_id' => $parent->id,
            'is_active' => true,
            'visible_filters' => ['model'],
            'visible_spec_filters' => ['Колір'],
        ]);

        Product::create([
            'category_id' => $child->id,
            'name' => 'Черевики Scout',
            'slug' => 'save-with-children-scout',
            'brand' => 'camotec',
            'model' => 'Scout',
            'price' => 3200,
            'stock' => 4,
            'is_active' => true,
            'specifications' => [
                'Розмір' => '42',
                'Колір' => 'Койот',
            ],
        ]);

        Livewire::actingAs($admin)
            ->test(CategoryFilterManager::class)
            ->set('categoryId', $parent->id)
            ->set('baseFilters', ['brand', 'price'])
            ->set('specFilters', ['Розмір'])
            ->set('filterLabelInputs.'.sha1('Розмір').'.uk', 'Розмір взуття')
            ->set('applyToChildrenOnSave', true)
            ->call('save')
            ->assertHasNoErrors();

        $parent->refresh();
        $child->refresh();

        $this->assertSame(['brand', 'price'], $parent->visible_filters);
        $this->assertSame(['brand', 'price'], $child->visible_filters);
        $this->assertSame(['Розмір'], $parent->visible_spec_filters);
        $this->assertSame(['Розмір'], $child->visible_spec_filters);
        $this->assertSame(['Розмір'], $child->visible_spec_filters_ru);
        $this->assertSame(['Розмір' => 'Розмір взуття'], $child->filter_labels);
    }

    public function test_admin_can_apply_current_filter_settings_to_child_categories(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $parent = Category::create([
            'name' => 'Одяг',
            'slug' => 'apply-parent-clothes',
            'is_active' => true,
            'visible_filters' => ['brand', 'price'],
            'visible_spec_filters' => ['Розмір'],
            'filter_labels' => ['Розмір' => 'Розмір взуття'],
            'filter_labels_ru' => ['Розмір' => 'Размер обуви'],
        ]);
        $child = Category::create([
            'name' => 'Черевики',
            'slug' => 'apply-child-boots',
            'parent_id' => $parent->id,
            'is_active' => true,
            'visible_filters' => ['model'],
            'visible_spec_filters' => ['Колір'],
            'filter_labels' => ['Колір' => 'Колір товару'],
        ]);

        Product::create([
            'category_id' => $child->id,
            'name' => 'Черевики Scout',
            'slug' => 'apply-child-scout',
            'brand' => 'camotec',
            'price' => 3200,
            'stock' => 4,
            'is_active' => true,
            'specifications' => [
                'Розмір' => '42',
                'Колір' => 'Койот',
            ],
        ]);

        Livewire::actingAs($admin)
            ->test(CategoryFilterManager::class)
            ->set('categoryId', $parent->id)
            ->call('applyToChildren')
            ->assertHasNoErrors();

        $child->refresh();

        $this->assertSame(['brand', 'price'], $child->visible_filters);
        $this->assertSame(['Розмір'], $child->visible_spec_filters);
        $this->assertSame(['Розмір'], $child->visible_spec_filters_ru);
        $this->assertSame(['Розмір' => 'Розмір взуття'], $child->filter_labels);
        $this->assertSame(['Розмір' => 'Размер обуви'], $child->filter_labels_ru);
    }
}
