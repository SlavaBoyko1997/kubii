<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Product;
use App\Services\CounterpartyFeedImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedCategoryMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_applies_feed_category_target_mapping_for_new_products(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Camotec Gurt',
            'slug' => 'camotec',
            'feed_format' => 'xml',
            'feed_profile' => 'camotec',
        ]);

        app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/camotec-feed.xml'),
        );

        $feedCategory = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '62')
            ->firstOrFail();
        $catalogCategory = Category::create([
            'name' => 'Поло каталог',
            'slug' => 'catalog-polo',
            'is_active' => true,
        ]);

        $feedCategory->update(['target_category_id' => $catalogCategory->id]);

        app(CounterpartyFeedImporter::class)->importFile(
            $counterparty->fresh(),
            base_path('tests/Fixtures/camotec-feed.xml'),
        );

        $product = Product::query()->where('external_id', '6160')->firstOrFail();

        $this->assertSame($catalogCategory->id, $product->category_id);
        $this->assertSame($feedCategory->id, $product->source_feed_category_id);
        $this->assertTrue($product->is_processed);
        $this->assertTrue($product->is_active);
    }

    public function test_import_auto_enables_when_parent_feed_category_is_active(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Camotec Gurt',
            'slug' => 'camotec-parent-active',
            'feed_format' => 'xml',
            'feed_profile' => 'camotec',
        ]);

        app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/camotec-feed.xml'),
        );

        $parentFeedCategory = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '3')
            ->firstOrFail();
        $childFeedCategory = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '62')
            ->firstOrFail();

        $parentFeedCategory->update(['is_active' => true]);
        $childFeedCategory->update(['is_active' => false]);

        app(CounterpartyFeedImporter::class)->importFile(
            $counterparty->fresh(),
            base_path('tests/Fixtures/camotec-feed.xml'),
        );

        $product = Product::query()->where('external_id', '6160')->firstOrFail();

        $this->assertSame($childFeedCategory->id, $product->category_id);
        $this->assertTrue($product->is_processed);
        $this->assertTrue($product->is_active);
    }

    public function test_import_inherits_target_mapping_from_parent_feed_category(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Camotec Gurt',
            'slug' => 'camotec-parent-map',
            'feed_format' => 'xml',
            'feed_profile' => 'camotec',
        ]);

        app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/camotec-feed.xml'),
        );

        $parentFeedCategory = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '3')
            ->firstOrFail();
        $catalogCategory = Category::create([
            'name' => 'Одяг каталог',
            'slug' => 'catalog-odyah',
            'is_active' => true,
        ]);

        $parentFeedCategory->update(['target_category_id' => $catalogCategory->id]);

        app(CounterpartyFeedImporter::class)->importFile(
            $counterparty->fresh(),
            base_path('tests/Fixtures/camotec-feed.xml'),
        );

        $product = Product::query()->where('external_id', '6160')->firstOrFail();
        $childFeedCategory = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '62')
            ->firstOrFail();

        $this->assertSame($catalogCategory->id, $product->category_id);
        $this->assertSame($childFeedCategory->id, $product->source_feed_category_id);
        $this->assertTrue($product->is_processed);
        $this->assertTrue($product->is_active);
    }

    public function test_import_matches_active_catalog_category_by_feed_category_name(): void
    {
        $catalogCategory = Category::create([
            'name' => 'Поло',
            'slug' => 'catalog-polo-by-name',
            'is_active' => true,
        ]);

        $counterparty = Counterparty::create([
            'name' => 'Camotec Gurt',
            'slug' => 'camotec-name-match',
            'feed_format' => 'xml',
            'feed_profile' => 'camotec',
        ]);

        app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/camotec-feed.xml'),
        );

        $product = Product::query()->where('external_id', '6160')->firstOrFail();
        $feedCategory = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '62')
            ->firstOrFail();

        $this->assertSame($catalogCategory->id, $product->category_id);
        $this->assertSame($feedCategory->id, $product->source_feed_category_id);
        $this->assertTrue($product->is_processed);
        $this->assertTrue($product->is_active);
    }

    public function test_import_matches_active_parent_catalog_category_by_name_when_leaf_missing(): void
    {
        $catalogParent = Category::create([
            'name' => 'Одяг',
            'slug' => 'catalog-odyah-by-name',
            'is_active' => true,
        ]);

        $counterparty = Counterparty::create([
            'name' => 'Camotec Gurt',
            'slug' => 'camotec-parent-name-match',
            'feed_format' => 'xml',
            'feed_profile' => 'camotec',
        ]);

        app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/camotec-feed.xml'),
        );

        $product = Product::query()->where('external_id', '6160')->firstOrFail();
        $feedLeaf = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '62')
            ->firstOrFail();

        // Feed leaf is "Поло" (no catalog twin); parent feed "Одяг" matches active catalog.
        $this->assertSame($catalogParent->id, $product->category_id);
        $this->assertSame($feedLeaf->id, $product->source_feed_category_id);
        $this->assertTrue($product->is_processed);
        $this->assertTrue($product->is_active);
    }

    public function test_name_rematch_keeps_processed_flag_and_updates_title(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Camotec Gurt',
            'slug' => 'camotec-rematch-processed',
            'feed_format' => 'xml',
            'feed_profile' => 'camotec',
        ]);

        $catalogCategory = Category::create([
            'name' => 'Поло каталог rematch',
            'slug' => 'catalog-polo-rematch',
            'is_active' => true,
        ]);

        $existing = Product::create([
            'category_id' => $catalogCategory->id,
            'counterparty_id' => $counterparty->id,
            'source' => $counterparty->slug,
            'external_id' => '9001',
            'name' => 'Поло Army ID Олива (7045), S',
            'slug' => 'polo-army-old',
            'sku' => '2000007045',
            'price' => 900,
            'stock' => 1,
            'is_processed' => true,
            'is_active' => true,
        ]);

        app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/camotec-feed.xml'),
        );

        $existing->refresh();

        $this->assertSame('6160', $existing->external_id);
        $this->assertSame('Поло Army ID Олива (7045), S', $existing->name);
        $this->assertSame($catalogCategory->id, $existing->category_id);
        $this->assertTrue($existing->is_processed);
        $this->assertTrue($existing->is_active);
        $this->assertDatabaseHas('products', [
            'id' => $existing->id,
            'external_id' => '6160',
            'is_processed' => true,
        ]);
        $this->assertSame(1, Product::query()->where('source', $counterparty->slug)->where('external_id', '6160')->count());
    }

    public function test_reimport_preserves_individually_assigned_category(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Pobedov',
            'slug' => 'pobedov',
            'feed_format' => 'yml',
        ]);

        $importer = app(CounterpartyFeedImporter::class);
        $importer->importFile($counterparty, base_path('tests/Fixtures/pobedov-feed.yml'));

        $manualCategory = Category::create([
            'name' => 'Футболки',
            'slug' => 'manual-tshirts',
            'is_active' => true,
        ]);
        $product = Product::query()->where('external_id', '00000006297')->firstOrFail();
        $feedCategoryId = $product->source_feed_category_id;

        ProductResource::moveToCategoryQuery(
            Product::query()->whereKey($product->id),
            $manualCategory->id,
            rememberFeedCategoryMapping: false,
        );

        $importer->importFile($counterparty->fresh(), base_path('tests/Fixtures/pobedov-feed.yml'));

        $product->refresh();

        $this->assertSame($manualCategory->id, $product->category_id);
        $this->assertSame($feedCategoryId, $product->source_feed_category_id);
        $this->assertTrue($product->is_processed);
    }

    public function test_import_does_not_collide_with_trashed_category_slug(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Camotec Gurt',
            'slug' => 'camotec-trash',
            'feed_format' => 'xml',
            'feed_profile' => 'camotec',
        ]);

        $trashed = Category::create([
            'name' => 'Стара категорія',
            'slug' => 'odiah',
            'is_active' => false,
        ]);
        $trashed->delete();

        app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/camotec-feed.xml'),
        );

        $feedCategory = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '3')
            ->firstOrFail();

        $this->assertNotSame('odiah', $feedCategory->slug);
        $this->assertSame('Одяг', $feedCategory->getRawOriginal('name'));
        $this->assertSame('odiah', Category::onlyTrashed()->value('slug'));
    }

    public function test_import_reuses_trashed_category_mapped_by_external_id(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Camotec Gurt',
            'slug' => 'camotec-reuse',
            'feed_format' => 'xml',
            'feed_profile' => 'camotec',
        ]);

        app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/camotec-feed.xml'),
        );

        $feedCategory = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '62')
            ->firstOrFail();
        $feedCategory->delete();

        app(CounterpartyFeedImporter::class)->importFile(
            $counterparty->fresh(),
            base_path('tests/Fixtures/camotec-feed.xml'),
        );

        $this->assertSame(
            1,
            Category::withTrashed()
                ->where('counterparty_id', $counterparty->id)
                ->where('external_id', '62')
                ->count(),
        );
    }

    public function test_move_to_category_remembers_feed_category_mapping(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Camotec Gurt',
            'slug' => 'camotec-map',
            'feed_format' => 'xml',
            'feed_profile' => 'camotec',
        ]);

        app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/camotec-feed.xml'),
        );

        $feedCategory = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '62')
            ->firstOrFail();
        $catalogCategory = Category::create([
            'name' => 'Каталог поло',
            'slug' => 'catalog-polo-map',
            'is_active' => true,
        ]);

        ProductResource::moveToCategoryQuery(
            Product::query()->where('counterparty_id', $counterparty->id),
            $catalogCategory->id,
        );

        $feedCategory->refresh();

        $this->assertSame($catalogCategory->id, $feedCategory->target_category_id);

        app(CounterpartyFeedImporter::class)->importFile(
            $counterparty->fresh(),
            base_path('tests/Fixtures/camotec-feed.xml'),
        );

        $this->assertSame(
            $catalogCategory->id,
            Product::query()->where('external_id', '6161')->value('category_id'),
        );
    }
}
