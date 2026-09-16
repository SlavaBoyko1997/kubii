<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Product;
use App\Services\CounterpartyFeedImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CamotecFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_camotec_feed_imports_categories_variants_and_stock_flags(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Camotec Gurt',
            'slug' => 'camotec',
            'feed_format' => 'xml',
            'feed_profile' => 'camotec',
        ]);

        $result = app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/camotec-feed.xml'),
        );

        $this->assertSame(2, $result['products']);

        $parent = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '3')
            ->firstOrFail();
        $child = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '62')
            ->firstOrFail();

        $this->assertSame('Одяг', $parent->name);
        $this->assertSame('Поло', $child->name);
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertFalse($child->is_active);

        $inStock = Product::query()->where('external_id', '6160')->firstOrFail();
        $outOfStock = Product::query()->where('external_id', '6161')->firstOrFail();

        $this->assertSame('camotec-group-1696', $inStock->variant_id);
        $this->assertSame('camotec-group-1696', $outOfStock->variant_id);
        $this->assertSame($child->id, $inStock->category_id);
        $this->assertSame('https://gurt.camotec.ua/offer/polo-army-id-oliva-7045-o1696/', $inStock->external_url);
        $this->assertSame(1, $inStock->stock);
        $this->assertSame(0, $outOfStock->stock);
        $this->assertSame('S', $inStock->specifications['Розмір'] ?? null);
        $this->assertSame('Олива', $inStock->specifications['Колір'] ?? null);
        $this->assertFalse($inStock->is_active);
        $this->assertFalse($inStock->is_processed);
    }

    public function test_camotec_import_syncs_category_spec_filters_from_inactive_products(): void
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

        $category = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '62')
            ->firstOrFail();

        $this->assertContains('Розмір', $category->visible_spec_filters ?? []);
        $this->assertContains('Колір', $category->visible_spec_filters ?? []);
        $this->assertContains('Сезон', $category->visible_spec_filters ?? []);
    }

    public function test_camotec_ukrainian_param_names_are_available_in_russian_specifications(): void
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

        $product = Product::query()->where('external_id', '6160')->firstOrFail();

        $this->assertSame('S', $product->specifications_ru['Розмір'] ?? null);
        $this->assertSame('Олива', $product->specifications_ru['Колір'] ?? null);
    }

    public function test_xml_format_alias_imports_camotec_feed(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Camotec Gurt',
            'slug' => 'camotec-xml',
            'feed_format' => 'xml',
            'feed_profile' => 'camotec',
        ]);

        $result = app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/camotec-feed.xml'),
        );

        $this->assertSame(2, $result['products']);
    }

    public function test_standard_profile_still_uses_quantity_in_stock_for_pobedov(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Pobedov',
            'slug' => 'pobedov',
            'feed_format' => 'yml',
            'feed_profile' => 'standard',
        ]);

        app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/pobedov-feed.yml'),
        );

        $product = Product::query()->where('external_id', '00000006297')->firstOrFail();

        $this->assertSame(11, $product->stock);
        $this->assertSame('Футболка чоловіча однотонна темно-синя Pobedov Peremoga Військова', $product->name);
        $this->assertSame('Чудова базова футболка на кожен день.', $product->description);
    }

    public function test_camotec_rematches_when_feed_drops_article_from_name_and_rotates_offer_id(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Camotec Gurt',
            'slug' => 'camotec',
            'feed_format' => 'xml',
            'feed_profile' => 'camotec',
        ]);

        $category = Category::create([
            'name' => 'Взуття',
            'slug' => 'camotec-shoes',
            'counterparty_id' => $counterparty->id,
            'external_id' => '90',
            'is_active' => true,
        ]);

        $existing = Product::create([
            'category_id' => $category->id,
            'counterparty_id' => $counterparty->id,
            'source' => 'camotec',
            'external_id' => '9001',
            'name' => 'Черевики Scout MID Койот (8480), 41',
            'slug' => 'cherevyky-scout-mid-kojot-8480-41',
            'sku' => '2000008480',
            'price' => 3200,
            'stock' => 1,
            'is_processed' => true,
            'is_active' => true,
        ]);

        $feed = $this->writeTempFeed(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-07-21 12:00">
    <shop>
        <categories>
            <category id="90">Взуття</category>
        </categories>
        <offers>
            <offer id="9555" group_id="8480" available="true" in_stock="true">
                <name>Черевики Scout MID Койот, 41</name>
                <categoryId>90</categoryId>
                <currencyId>UAH</currencyId>
                <sizeTitle>41</sizeTitle>
                <colorTitle>Койот</colorTitle>
                <price>3350</price>
                <vendor>Camotec</vendor>
                <vendorCode>8480(41)</vendorCode>
                <quantityStatus>2</quantityStatus>
                <description><![CDATA[<p>Оновлені черевики Scout MID.</p>]]></description>
            </offer>
        </offers>
    </shop>
</yml_catalog>
XML);

        try {
            app(CounterpartyFeedImporter::class)->importFile($counterparty, $feed);
        } finally {
            @unlink($feed);
        }

        $this->assertSame(1, Product::query()->where('source', 'camotec')->count());

        $existing->refresh();
        $this->assertSame('9555', $existing->external_id);
        $this->assertSame('cherevyky-scout-mid-kojot-8480-41', $existing->slug);
        $this->assertSame('8480(41)', $existing->sku);
        $this->assertSame('Черевики Scout MID Койот, 41', $existing->name);
        $this->assertSame(3350.0, (float) $existing->price);
        $this->assertSame(2, $existing->stock);
        $this->assertTrue($existing->is_processed);
        $this->assertTrue($existing->is_active);
    }

    public function test_camotec_merges_duplicate_when_new_offer_already_exists_as_unprocessed(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Camotec Gurt',
            'slug' => 'camotec',
            'feed_format' => 'xml',
            'feed_profile' => 'camotec',
        ]);

        $category = Category::create([
            'name' => 'Взуття',
            'slug' => 'camotec-shoes-dup',
            'counterparty_id' => $counterparty->id,
            'external_id' => '91',
            'is_active' => true,
        ]);

        $processed = Product::create([
            'category_id' => $category->id,
            'counterparty_id' => $counterparty->id,
            'source' => 'camotec',
            'external_id' => '9001',
            'name' => 'Черевики Scout MID Койот (8480), 41',
            'slug' => 'scout-mid-old',
            'sku' => '2000009001',
            'price' => 3200,
            'stock' => 1,
            'is_processed' => true,
            'is_active' => true,
        ]);

        $duplicate = Product::create([
            'category_id' => $category->id,
            'counterparty_id' => $counterparty->id,
            'source' => 'camotec',
            'external_id' => '9555',
            'name' => 'Черевики Scout MID Койот, 41',
            'slug' => 'scout-mid-new-duplicate',
            'sku' => '8480(41)',
            'price' => 3300,
            'stock' => 1,
            'is_processed' => false,
            'is_active' => false,
        ]);

        $feed = $this->writeTempFeed(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-07-21 12:00">
    <shop>
        <categories>
            <category id="91">Взуття</category>
        </categories>
        <offers>
            <offer id="9555" group_id="8480" available="true" in_stock="true">
                <name>Черевики Scout MID Койот, 41</name>
                <categoryId>91</categoryId>
                <currencyId>UAH</currencyId>
                <sizeTitle>41</sizeTitle>
                <price>3400</price>
                <vendor>Camotec</vendor>
                <vendorCode>8480(41)</vendorCode>
                <quantityStatus>3</quantityStatus>
            </offer>
        </offers>
    </shop>
</yml_catalog>
XML);

        try {
            app(CounterpartyFeedImporter::class)->importFile($counterparty, $feed);
        } finally {
            @unlink($feed);
        }

        $this->assertDatabaseMissing('products', ['id' => $duplicate->id]);
        $this->assertSame(1, Product::query()->where('source', 'camotec')->count());

        $processed->refresh();
        $this->assertSame('9555', $processed->external_id);
        $this->assertSame('scout-mid-old', $processed->slug);
        $this->assertSame('8480(41)', $processed->sku);
        $this->assertSame(3400.0, (float) $processed->price);
        $this->assertTrue($processed->is_processed);
        $this->assertTrue($processed->is_active);
    }

    private function writeTempFeed(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'camotec-feed-');

        $this->assertNotFalse($path);
        file_put_contents($path, $contents);

        return $path;
    }
}
