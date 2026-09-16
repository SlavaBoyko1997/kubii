<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Product;
use App\Services\CounterpartyFeedImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrampOptFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_trampopt_feed_imports_categories_brand_stock_variants_and_specs(): void
    {
        $counterparty = Counterparty::query()->firstOrCreate(
            ['slug' => 'trampopt'],
            [
                'name' => 'Tramp Opt',
                'feed_format' => 'yml',
                'feed_profile' => 'trampopt',
            ],
        );

        $result = app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/trampopt-feed.yml'),
        );

        $this->assertSame(3, $result['products']);
        $this->assertSame(4, $result['categories']);

        $mugsCategory = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '170')
            ->first();

        $this->assertNotNull($mugsCategory);
        $this->assertSame('Кружки та стакани (з характ.)', $mugsCategory->name);
        $this->assertFalse($mugsCategory->is_active);

        $outOfStock = Product::query()->where('external_id', 'TRC-022')->firstOrFail();
        $variant = Product::query()->where('external_id', 'TRC-013')->firstOrFail();
        $inStock = Product::query()->where('external_id', '39704100')->firstOrFail();

        $this->assertSame('Tramp', $outOfStock->brand);
        $this->assertSame('TRC-022', $outOfStock->sku);
        $this->assertSame(0, $outOfStock->stock);
        $this->assertSame('trampopt-group-946', $outOfStock->variant_id);
        $this->assertSame('Набір 4 стопки TRAMP, 25 мл', $outOfStock->name);
        $this->assertSame(294.0, (float) $outOfStock->price);
        $this->assertFalse($outOfStock->is_active);
        $this->assertFalse($outOfStock->is_processed);
        $this->assertSame('сталь', $outOfStock->specifications['material'] ?? null);
        $this->assertSame($mugsCategory->id, $outOfStock->source_feed_category_id);

        $this->assertSame('trampopt-group-946', $variant->variant_id);
        $this->assertSame('TRC-013', $variant->sku);

        $this->assertSame('HEY-sport', $inStock->brand);
        $this->assertSame('39704100', $inStock->sku);
        $this->assertSame(50, $inStock->stock);
        $this->assertSame('trampopt-39704100', $inStock->variant_id);
    }

    public function test_reimport_updates_existing_product_when_name_changes(): void
    {
        $counterparty = Counterparty::query()->firstOrCreate(
            ['slug' => 'trampopt'],
            [
                'name' => 'Tramp Opt',
                'feed_format' => 'yml',
                'feed_profile' => 'trampopt',
            ],
        );

        $importer = app(CounterpartyFeedImporter::class);
        $importer->importFile($counterparty, base_path('tests/Fixtures/trampopt-feed.yml'));

        $product = Product::query()->where('external_id', 'TRC-022')->firstOrFail();
        $originalId = $product->id;
        $originalSlug = $product->slug;

        $renamedFeed = $this->writeTempFeed(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-07-21 12:00">
    <shop>
        <categories>
            <category id="167">Посуд</category>
            <category id="170" parentId="167">Кружки та стакани (з характ.)</category>
        </categories>
        <offers>
            <offer id="TRC-022" available="" selling_type="r" group_id="946">
                <name>Оновлений набір стопок TRAMP, 25 мл</name>
                <categoryId>170</categoryId>
                <price>310</price>
                <currencyId>UAH</currencyId>
                <vendor>Tramp</vendor>
                <vendorCode>TRC-022</vendorCode>
                <quantity_in_stock>4</quantity_in_stock>
                <description><![CDATA[Оновлений опис.]]></description>
            </offer>
        </offers>
    </shop>
</yml_catalog>
XML);

        try {
            $importer->importFile($counterparty->fresh(), $renamedFeed);
        } finally {
            @unlink($renamedFeed);
        }

        $this->assertSame(1, Product::query()->where('sku', 'TRC-022')->count());

        $product->refresh();
        $this->assertSame($originalId, $product->id);
        $this->assertSame($originalSlug, $product->slug);
        $this->assertSame('TRC-022', $product->sku);
        $this->assertSame('Оновлений набір стопок TRAMP, 25 мл', $product->name);
        $this->assertSame(310.0, (float) $product->price);
        $this->assertSame(4, $product->stock);
    }

    public function test_reimport_rematches_by_product_code_when_offer_id_changes(): void
    {
        $counterparty = Counterparty::query()->firstOrCreate(
            ['slug' => 'trampopt'],
            [
                'name' => 'Tramp Opt',
                'feed_format' => 'yml',
                'feed_profile' => 'trampopt',
            ],
        );

        $importer = app(CounterpartyFeedImporter::class);
        $importer->importFile($counterparty, base_path('tests/Fixtures/trampopt-feed.yml'));

        $product = Product::query()->where('external_id', 'TRC-022')->firstOrFail();
        $originalId = $product->id;
        $originalSlug = $product->slug;
        $product->update([
            'is_processed' => true,
            'is_active' => true,
            'brand' => 'Tramp Custom',
        ]);

        $rotatedIdFeed = $this->writeTempFeed(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-07-21 12:00">
    <shop>
        <categories>
            <category id="167">Посуд</category>
            <category id="170" parentId="167">Кружки та стакани (з характ.)</category>
        </categories>
        <offers>
            <offer id="NEW-FEED-ID-022" available="" selling_type="r" group_id="946">
                <name>Набір 4 стопки TRAMP, 25 мл (оновлено)</name>
                <categoryId>170</categoryId>
                <price>299</price>
                <currencyId>UAH</currencyId>
                <vendor>Tramp</vendor>
                <vendorCode>TRC-022</vendorCode>
                <quantity_in_stock>2</quantity_in_stock>
                <description><![CDATA[Той самий артикул, інший offer id.]]></description>
            </offer>
        </offers>
    </shop>
</yml_catalog>
XML);

        try {
            $importer->importFile($counterparty->fresh(), $rotatedIdFeed);
        } finally {
            @unlink($rotatedIdFeed);
        }

        $this->assertSame(1, Product::query()->where('sku', 'TRC-022')->count());
        $this->assertNull(Product::query()->where('external_id', 'TRC-022')->first());

        $product->refresh();
        $this->assertSame($originalId, $product->id);
        $this->assertSame($originalSlug, $product->slug);
        $this->assertSame('NEW-FEED-ID-022', $product->external_id);
        $this->assertSame('TRC-022', $product->sku);
        $this->assertSame('Набір 4 стопки TRAMP, 25 мл (оновлено)', $product->name);
        $this->assertTrue($product->is_processed);
        $this->assertTrue($product->is_active);
        $this->assertSame('Tramp Custom', $product->brand);
    }

    private function writeTempFeed(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'trampopt-feed-');

        $this->assertNotFalse($path);
        file_put_contents($path, $contents);

        return $path;
    }
}
