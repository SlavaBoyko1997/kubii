<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Product;
use App\Services\CounterpartyFeedImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TacticShopFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_tacticshop_feed_imports_categories_brand_stock_variants_and_localized_fields(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Tactic Shop',
            'slug' => 'tacticshop',
            'feed_format' => 'xml',
            'feed_profile' => 'atlantmarket',
        ]);

        $result = app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/tacticshop-feed.xml'),
        );

        $this->assertSame(2, $result['products']);
        $this->assertSame(5, $result['categories']);

        $karematCategory = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '1124')
            ->first();

        $this->assertNotNull($karematCategory);
        $this->assertSame('Каремати розкладні', $karematCategory->name);
        $this->assertFalse($karematCategory->is_active);

        $inStock = Product::query()->where('external_id', '689')->firstOrFail();
        $sale = Product::query()->where('external_id', '2069')->firstOrFail();

        $this->assertSame('Kiborg', $inStock->brand);
        $this->assertSame('8700', $inStock->sku);
        $this->assertSame(1, $inStock->stock);
        $this->assertSame('tacticshop-group-77455', $inStock->variant_id);
        $this->assertSame('Туристичний сірий килимок(каремат) під спальний мішок', $inStock->name);
        $this->assertSame('Туристический серый коврик (каремат) под спальный мешок', $inStock->name_ru);
        $this->assertStringContainsString('Спальний килимок виготовлений', $inStock->description);
        $this->assertStringContainsString('Спальный коврик изготовлен', $inStock->description_ru);
        $this->assertSame(520.0, (float) $inStock->price);
        $this->assertNull($inStock->sale_price);
        $this->assertFalse($inStock->is_active);
        $this->assertFalse($inStock->is_processed);
        $this->assertSame('https://tactic-shop.in.ua/ru/turystychnyi-siryi-kylymokkaremat-pid-spalnyi-mishok/', $inStock->external_url);
        $this->assertSame('Каремат', $inStock->specifications['Тип'] ?? null);
        $this->assertSame($karematCategory->id, $inStock->source_feed_category_id);

        $this->assertSame('HD-16', $sale->brand);
        $this->assertSame('7047-К', $sale->sku);
        $this->assertSame(0, $sale->stock);
        $this->assertSame('tacticshop-group-2069455', $sale->variant_id);
        $this->assertSame(5100.0, (float) $sale->price);
        $this->assertSame(4800.0, (float) $sale->sale_price);
    }
}
