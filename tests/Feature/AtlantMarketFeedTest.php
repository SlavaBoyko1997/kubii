<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Product;
use App\Services\CounterpartyFeedImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AtlantMarketFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_atlantmarket_feed_imports_categories_brand_stock_and_specs(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Atlant Market',
            'slug' => 'atlantmarket',
            'feed_format' => 'xml',
            'feed_profile' => 'atlantmarket',
        ]);

        $result = app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/atlantmarket-feed.xml'),
        );

        $this->assertSame(3, $result['products']);
        $this->assertSame(4, $result['categories']);

        $multitoolCategory = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '1906')
            ->first();

        $this->assertNotNull($multitoolCategory);
        $this->assertSame('Ganzo мультитули', $multitoolCategory->name);
        $this->assertFalse($multitoolCategory->is_active);

        $inStock = Product::query()->where('external_id', '44136')->firstOrFail();
        $outOfStock = Product::query()->where('external_id', '99999')->firstOrFail();
        $battery = Product::query()->where('external_id', '44161')->firstOrFail();

        $this->assertSame('Ganzo', $inStock->brand);
        $this->assertSame('G203', $inStock->sku);
        $this->assertSame(1, $inStock->stock);
        $this->assertFalse($inStock->is_active);
        $this->assertFalse($inStock->is_processed);
        $this->assertSame('https://atlantmarket.com.ua/multitul-multi-tool-ganzo-g203/', $inStock->external_url);
        $this->assertSame('G203', $inStock->specifications['Артикул виробника'] ?? null);
        $this->assertSame('G203', $inStock->specifications_ru['Артикул виробника'] ?? null);
        $this->assertSame('G203', $inStock->displaySpecifications()['Артикул виробника'] ?? null);
        $this->assertSame('217 г', $inStock->displaySpecifications()['Вага'] ?? null);
        $this->assertSame('синій , блакитний', $inStock->displaySpecifications()['Колір мультитула'] ?? null);

        $this->assertSame('Fenix', $battery->brand);
        $this->assertSame('ARB-L18-2600', $battery->sku);
        $this->assertSame('18650', $battery->displaySpecifications()['Типорозмір елемента живлення'] ?? null);

        $this->assertSame(0, $outOfStock->stock);
        $this->assertSame($multitoolCategory->id, $inStock->source_feed_category_id);
    }
}
