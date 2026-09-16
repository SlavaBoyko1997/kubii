<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Product;
use App\Models\ProductVariantGroup;
use App\Services\CounterpartyFeedImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CounterpartyFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_yml_feed_creates_inactive_category_tree_and_links_unapproved_products(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Pobedov',
            'slug' => 'pobedov',
            'feed_url' => 'https://example.test/feed.yml',
            'feed_format' => 'yml',
        ]);

        $result = app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/pobedov-feed.yml'),
        );

        $this->assertSame(2, $result['products']);
        $this->assertSame(2, $result['categories']);

        $rootCategory = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '9')
            ->first();

        $leafCategory = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '26')
            ->first();

        $this->assertNotNull($rootCategory);
        $this->assertNotNull($leafCategory);
        $this->assertFalse($rootCategory->is_active);
        $this->assertFalse($leafCategory->is_active);
        $this->assertNull($rootCategory->parent_id);
        $this->assertSame($rootCategory->id, $leafCategory->parent_id);

        $product = Product::query()->where('external_id', '00000006297')->firstOrFail();
        $this->assertSame($leafCategory->id, $product->category_id);
        $this->assertFalse($product->is_processed);
        $this->assertFalse($product->is_active);
        $this->assertSame('pobedov', $product->source);
        $this->assertSame('pobedov-group-229771', $product->variant_id);
        $this->assertNull($product->brand);
        $this->assertNull($product->model);
        $this->assertSame('Футболка чоловіча однотонна темно-синя Pobedov Peremoga Військова', $product->name);
        $this->assertSame('Футболка мужская однотонная темно-синяя Pobedov Peremoga Військова', $product->name_ru);
        $this->assertSame('Чудова базова футболка на кожен день.', $product->description);
        $this->assertSame('Великолепная базовая футболка на каждый день.', $product->description_ru);
        $this->assertSame('95% бавовна, 5% еластан', $product->specifications['Склад тканини'] ?? null);
        $this->assertSame('95% хлопок, 5% эластан', $product->specifications_ru['Склад тканини'] ?? null);
        $this->assertSame('Нове', $product->specifications['Состояние'] ?? null);
        $this->assertSame('Casual', $product->specifications['Стиль'] ?? null);
        $this->assertSame('Приталенный', $product->specifications['Силуэт'] ?? null);
        $this->assertSame('Темно-синій', $product->specifications['Цвет'] ?? null);
        $this->assertSame('XXXL', $product->specifications['Международный размер'] ?? null);
        $this->assertSame('Літо', $product->season);
        $this->assertSame('Лето', $product->season_ru);
        $this->assertSame(650.0, (float) $product->price);
        $this->assertSame(11, $product->stock);
        $this->assertArrayNotHasKey('Відео', $product->displaySpecifications());
        $this->assertArrayHasKey('Відео', $product->specifications ?? []);
        $this->assertSame('Новое', $product->specifications_ru['Состояние'] ?? null);

        $importedCategory = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '26')
            ->firstOrFail();
        $this->assertSame('cholovichi-futbolky-ta-mayky', $importedCategory->slug);
        $this->assertStringNotContainsString('pobedov-cat-', $importedCategory->slug);
    }

    public function test_reimport_preserves_processed_product_category_and_brand(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Pobedov',
            'slug' => 'pobedov',
            'feed_format' => 'yml',
        ]);

        $importer = app(CounterpartyFeedImporter::class);
        $importer->importFile($counterparty, base_path('tests/Fixtures/pobedov-feed.yml'));

        $manualCategory = Category::create(['name' => 'Футболки', 'slug' => 'counterparty-tshirts', 'is_active' => true]);
        $product = Product::query()->where('external_id', '00000006297')->firstOrFail();
        $product->update([
            'category_id' => $manualCategory->id,
            'is_processed' => true,
            'is_active' => true,
            'brand' => 'Pobedov Brand',
            'model' => 'Peremoga',
        ]);

        $importer->importFile($counterparty->fresh(), base_path('tests/Fixtures/pobedov-feed.yml'));

        $product->refresh();

        $this->assertTrue($product->is_processed);
        $this->assertTrue($product->is_active);
        $this->assertSame($manualCategory->id, $product->category_id);
        $this->assertSame('Pobedov Brand', $product->brand);
        $this->assertSame('Peremoga', $product->model);
        $this->assertSame(11, $product->stock);
    }

    public function test_reimport_preserves_enabled_category_state(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Pobedov',
            'slug' => 'pobedov',
            'feed_format' => 'yml',
        ]);

        $importer = app(CounterpartyFeedImporter::class);
        $importer->importFile($counterparty, base_path('tests/Fixtures/pobedov-feed.yml'));

        $category = Category::query()
            ->where('name', 'Чоловічі футболки та майки')
            ->firstOrFail();
        $category->update(['is_active' => true]);

        $importer->importFile($counterparty->fresh(), base_path('tests/Fixtures/pobedov-feed.yml'));

        $this->assertTrue($category->fresh()->is_active);
    }

    public function test_reimport_preserves_category_tree_structure(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Pobedov',
            'slug' => 'pobedov',
            'feed_format' => 'yml',
        ]);

        $importer = app(CounterpartyFeedImporter::class);
        $importer->importFile($counterparty, base_path('tests/Fixtures/pobedov-feed.yml'));

        $rootCategory = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '9')
            ->firstOrFail();
        $leafCategory = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '26')
            ->firstOrFail();

        $manualParent = Category::create([
            'name' => 'Ручна батьківська',
            'slug' => 'manual-parent',
            'is_active' => true,
        ]);

        $leafCategory->update([
            'parent_id' => $manualParent->id,
            'sort_order' => 42,
        ]);

        $importer->importFile($counterparty->fresh(), base_path('tests/Fixtures/pobedov-feed.yml'));

        $leafCategory->refresh();

        $this->assertSame($manualParent->id, $leafCategory->parent_id);
        $this->assertSame(42, $leafCategory->sort_order);
        $this->assertNull($rootCategory->fresh()->parent_id);
    }

    public function test_reimport_preserves_product_active_state_independently_from_processed(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Pobedov',
            'slug' => 'pobedov',
            'feed_format' => 'yml',
        ]);

        $importer = app(CounterpartyFeedImporter::class);
        $importer->importFile($counterparty, base_path('tests/Fixtures/pobedov-feed.yml'));

        $processedInactive = Product::query()->where('external_id', '00000006297')->firstOrFail();
        $processedInactive->update([
            'is_processed' => true,
            'is_active' => false,
        ]);

        $processedActive = Product::query()->where('external_id', '00000007193')->firstOrFail();
        $processedActive->update([
            'is_processed' => true,
            'is_active' => true,
        ]);

        $importer->importFile($counterparty->fresh(), base_path('tests/Fixtures/pobedov-feed.yml'));

        $processedInactive->refresh();
        $processedActive->refresh();

        $this->assertTrue($processedInactive->is_processed);
        $this->assertFalse($processedInactive->is_active);
        $this->assertTrue($processedActive->is_processed);
        $this->assertTrue($processedActive->is_active);
    }

    public function test_import_reuses_existing_category_with_same_name_and_parent(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Pobedov',
            'slug' => 'pobedov',
            'feed_format' => 'yml',
        ]);

        $rootCategory = Category::create([
            'name' => 'Чоловічий одяг',
            'slug' => 'existing-mens-clothing',
            'is_active' => true,
        ]);
        $leafCategory = Category::create([
            'name' => 'Чоловічі футболки та майки',
            'slug' => 'existing-mens-tshirts',
            'parent_id' => $rootCategory->id,
            'is_active' => true,
        ]);

        app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/pobedov-feed.yml'),
        );

        // Feed categories stay separate; products land in the active catalog twin by name.
        $this->assertSame(2, Category::query()->whereNull('counterparty_id')->count());
        $this->assertGreaterThanOrEqual(2, Category::query()->where('counterparty_id', $counterparty->id)->count());

        $product = Product::query()->where('external_id', '00000006297')->firstOrFail();
        $this->assertSame($leafCategory->id, $product->category_id);
        $this->assertTrue($product->is_processed);
        $this->assertTrue($product->is_active);
        $this->assertTrue($leafCategory->fresh()->is_active);
        $this->assertTrue($rootCategory->fresh()->is_active);
        $this->assertNull($leafCategory->fresh()->counterparty_id);
    }

    public function test_reimport_preserves_brand_and_model_before_processing(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Pobedov',
            'slug' => 'pobedov',
            'feed_format' => 'yml',
        ]);

        $importer = app(CounterpartyFeedImporter::class);
        $importer->importFile($counterparty, base_path('tests/Fixtures/pobedov-feed.yml'));

        $product = Product::query()->where('external_id', '00000006297')->firstOrFail();
        $product->update([
            'brand' => 'Pobedov',
            'model' => 'Freedom',
        ]);

        $importer->importFile($counterparty->fresh(), base_path('tests/Fixtures/pobedov-feed.yml'));

        $product->refresh();

        $this->assertFalse($product->is_processed);
        $this->assertSame('Pobedov', $product->brand);
        $this->assertSame('Freedom', $product->model);
    }

    public function test_assign_catalog_attributes_query_updates_only_filled_fields(): void
    {
        $category = Category::create(['name' => 'Футболки', 'slug' => 'assign-tshirts']);
        $otherCategory = Category::create(['name' => 'Шорти', 'slug' => 'assign-shorts']);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Футболка',
            'slug' => 'assign-tshirt',
            'price' => 650,
            'stock' => 3,
            'brand' => 'Old Brand',
            'model' => 'Old Model',
        ]);

        ProductResource::assignCatalogAttributesQuery(
            Product::query()->whereKey($product->id),
            ['brand' => 'Pobedov'],
        );

        $product->refresh();

        $this->assertSame($category->id, $product->category_id);
        $this->assertSame('Pobedov', $product->brand);
        $this->assertSame('Old Model', $product->model);

        ProductResource::assignCatalogAttributesQuery(
            Product::query()->whereKey($product->id),
            ['category_id' => $otherCategory->id, 'model' => 'Freedom'],
        );

        $product->refresh();

        $this->assertSame($otherCategory->id, $product->category_id);
        $this->assertSame('Pobedov', $product->brand);
        $this->assertSame('Freedom', $product->model);
    }

    public function test_sync_command_imports_active_counterparties(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Pobedov',
            'slug' => 'pobedov',
            'feed_format' => 'yml',
            'is_active' => true,
            'auto_sync' => true,
        ]);

        $this->artisan('counterparties:sync-feeds', [
            'counterparty' => 'pobedov',
            '--file' => base_path('tests/Fixtures/pobedov-feed.yml'),
        ])->assertSuccessful();

        $this->assertSame(2, Product::query()->where('counterparty_id', $counterparty->id)->count());
        $this->assertNotNull($counterparty->fresh()->last_synced_at);
    }

    public function test_import_automatically_discovers_variant_groups_from_feed_group_id(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Pobedov',
            'slug' => 'pobedov',
            'feed_format' => 'yml',
        ]);

        $result = app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/pobedov-feed-grouped.yml'),
        );

        $this->assertSame(1, $result['variant_groups_created']);
        $this->assertSame(2, ProductVariantGroup::query()->firstOrFail()->products()->count());
    }
}
