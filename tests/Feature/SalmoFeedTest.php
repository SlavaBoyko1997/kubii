<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Product;
use App\Services\CounterpartyFeedImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalmoFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_salmo_feed_imports_yml_with_vendor_code_brand_and_availability(): void
    {
        $counterparty = Counterparty::query()->where('slug', 'salmo')->firstOrFail();

        $this->assertSame('Salmo', $counterparty->name);
        $this->assertSame('https://salmo.ua/content/export/d88305e5d77b69741987dd034a0c66e6.xml', $counterparty->feed_url);
        $this->assertSame('xml', $counterparty->feed_format);
        $this->assertSame('salmo', $counterparty->feed_profile);
        $this->assertTrue($counterparty->is_active);
        $this->assertTrue($counterparty->auto_sync);

        $result = app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/salmo-feed.xml'),
        );

        $this->assertSame(2, $result['products']);
        $this->assertSame(4, $result['categories']);

        $glassesCategory = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '1229')
            ->first();

        $this->assertNotNull($glassesCategory);
        $this->assertSame('Окуляри', $glassesCategory->name);
        $this->assertFalse($glassesCategory->is_active);

        $glasses = Product::query()->where('source', 'salmo')->where('external_id', '17625')->firstOrFail();
        $tent = Product::query()->where('source', 'salmo')->where('external_id', '17628')->firstOrFail();

        $this->assertSame('NF-2002', $glasses->sku);
        $this->assertSame('Norfin', $glasses->brand);
        $this->assertSame(1, $glasses->stock);
        $this->assertSame('Окуляри поляризац. Norfin 02', $glasses->name);
        $this->assertSame('Очки поляризационные Norfin линзы зелёные REVO 02', $glasses->name_ru);
        $this->assertSame('Український опис окулярів.', $glasses->description);
        $this->assertSame('Русское описание очков.', $glasses->description_ru);
        $this->assertSame('https://salmo.ua/ru/okuliary-poliarizatciini-norfin-02/', $glasses->external_url);
        $this->assertSame('https://salmo.ua/content/images/26/main.jpg', $glasses->image_url);
        $this->assertSame(['https://salmo.ua/content/images/26/side.jpg'], $glasses->gallery_images);
        $this->assertSame('salmo-group-2457', $glasses->variant_id);
        $this->assertSame('серый', $glasses->specifications['Цвет'] ?? null);
        $this->assertSame($glassesCategory->id, $glasses->source_feed_category_id);
        $this->assertFalse($glasses->is_active);
        $this->assertFalse($glasses->is_processed);

        $this->assertSame('TENT-01', $tent->sku);
        $this->assertSame('Salmo', $tent->brand);
        $this->assertSame(0, $tent->stock);
    }
}
