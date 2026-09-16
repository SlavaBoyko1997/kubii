<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Product;
use App\Services\CounterpartyFeedImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TravelExtremeFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_travel_extreme_feed_imports_with_exact_stock_and_offer_id_sku(): void
    {
        $counterparty = Counterparty::query()->where('slug', 'travelextreme')->firstOrFail();

        $this->assertSame('Travel Extreme', $counterparty->name);
        $this->assertSame('https://opt.travel-extreme.com.ua/index.php?route=extension/feed/unixml/rozetka', $counterparty->feed_url);
        $this->assertSame('xml', $counterparty->feed_format);
        $this->assertSame('travelextreme', $counterparty->feed_profile);
        $this->assertTrue($counterparty->is_active);
        $this->assertTrue($counterparty->auto_sync);

        $result = app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/travelextreme-feed.xml'),
        );

        $this->assertSame(3, $result['products']);
        $this->assertSame(3, $result['categories']);

        $cookSystemCategory = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '1')
            ->first();

        $this->assertNotNull($cookSystemCategory);
        $this->assertSame('FM Системи приготування їжі', $cookSystemCategory->name);
        $this->assertFalse($cookSystemCategory->is_active);

        $inStock = Product::query()->where('external_id', 'FMS X1')->firstOrFail();
        $outOfStock = Product::query()->where('external_id', 'FMS X2R')->firstOrFail();
        $missingId = Product::query()->where('external_id', 'product-4617')->firstOrFail();

        $this->assertSame('FMS X1', $inStock->sku);
        $this->assertSame(446, $inStock->stock);
        $this->assertNull($inStock->brand);
        $this->assertSame('FM X1 Black Інтегрований набір посуду для приготування їжі', $inStock->name);
        $this->assertSame('https://opt.travel-extreme.com.ua/index.php?route=product/product&path=1&product_id=1', $inStock->external_url);
        $this->assertSame($cookSystemCategory->id, $inStock->source_feed_category_id);
        $this->assertSame('https://opt.travel-extreme.com.ua/image/import_files/e1/fms-x1-main.jpg', $inStock->image_url);
        $this->assertFalse($inStock->is_active);
        $this->assertFalse($inStock->is_processed);

        $this->assertSame('FMS X2R', $outOfStock->sku);
        $this->assertSame(0, $outOfStock->stock);
        $this->assertNull($outOfStock->image_url);

        $this->assertSame('product-4617', $missingId->sku);
        $this->assertSame(10, $missingId->stock);
        $this->assertSame('Палиці для скітуру Ski Trab Gavia 18', $missingId->name);
    }
}
