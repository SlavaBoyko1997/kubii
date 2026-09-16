<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Product;
use App\Services\CounterpartyFeedImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IidloFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_iidlo_feed_imports_with_prom_xml_profile(): void
    {
        $counterparty = Counterparty::query()->where('slug', 'iidlo')->firstOrFail();

        $this->assertSame('ЇDLO', $counterparty->name);
        $this->assertSame('https://iidlo.com/content/export/68.xml', $counterparty->feed_url);
        $this->assertSame('xml', $counterparty->feed_format);
        $this->assertSame('atlantmarket', $counterparty->feed_profile);
        $this->assertTrue($counterparty->is_active);
        $this->assertTrue($counterparty->auto_sync);

        $result = app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/iidlo-feed.xml'),
        );

        $this->assertSame(2, $result['products']);
        $this->assertSame(3, $result['categories']);

        $breakfastCategory = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '1052')
            ->first();

        $this->assertNotNull($breakfastCategory);
        $this->assertSame('СНІДАНКИ', $breakfastCategory->name);
        $this->assertFalse($breakfastCategory->is_active);

        $inStock = Product::query()->where('external_id', '297')->firstOrFail();
        $outOfStock = Product::query()->where('external_id', '335')->firstOrFail();

        $this->assertSame('ЇDLO', $inStock->brand);
        $this->assertSame('004', $inStock->sku);
        $this->assertSame(1, $inStock->stock);
        $this->assertSame('iidlo-group-103367', $inStock->variant_id);
        $this->assertSame('Гранола горіхова з шоколадом ЇDLO', $inStock->name);
        $this->assertSame('Гранола горіхова з шоколадом ЇDLO', $inStock->name_ru);
        $this->assertStringContainsString('швидкого приготування', $inStock->description);
        $this->assertSame('https://idlo.com/hranola-horikhova-z-shokoladom-yidlo/', $inStock->external_url);
        $this->assertSame($breakfastCategory->id, $inStock->source_feed_category_id);
        $this->assertFalse($inStock->is_active);
        $this->assertFalse($inStock->is_processed);

        $this->assertSame('027', $outOfStock->sku);
        $this->assertSame(0, $outOfStock->stock);
        $this->assertSame(190.0, (float) $outOfStock->price);
        $this->assertSame(170.0, (float) $outOfStock->sale_price);
        $this->assertSame('12 мес.', $outOfStock->displaySpecifications()['Гарантия'] ?? null);
    }
}
