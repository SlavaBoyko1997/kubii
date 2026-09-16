<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Product;
use App\Services\CounterpartyFeedImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MangalzavodFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_mangalzavod_feed_imports_categories_brand_stock_and_localized_fields(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Mangal Zavod',
            'slug' => 'mangalzavod',
            'feed_format' => 'xml',
            'feed_profile' => 'atlantmarket',
        ]);

        $result = app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/mangalzavod-feed.xml'),
        );

        $this->assertSame(2, $result['products']);
        $this->assertSame(4, $result['categories']);

        $safeCategory = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '5269777')
            ->first();

        $this->assertNotNull($safeCategory);
        $this->assertSame('Оружейные сейфы', $safeCategory->name);
        $this->assertFalse($safeCategory->is_active);

        $inStock = Product::query()->where('external_id', '71463768')->firstOrFail();
        $outOfStock = Product::query()->where('external_id', '2605682724')->firstOrFail();

        $this->assertSame('Metalzavod', $inStock->brand);
        $this->assertSame('3011110300000', $inStock->sku);
        $this->assertSame(1, $inStock->stock);
        $this->assertSame('Сейф збройовий ШОЕ-1000, сірий', $inStock->name);
        $this->assertSame('Сейф оружейный ШОЕ-1000, серый', $inStock->name_ru);
        $this->assertStringContainsString('Сейф призначений для зберігання', $inStock->description);
        $this->assertStringContainsString('Сейф предназначен для хранения', $inStock->description_ru);
        $this->assertSame(4800.0, (float) $inStock->price);
        $this->assertSame(3840.0, (float) $inStock->sale_price);
        $this->assertFalse($inStock->is_active);
        $this->assertFalse($inStock->is_processed);
        $this->assertSame('https://mangalzavod.com.ua/p71463768-sejf-oruzhejnyj-shoe.html', $inStock->external_url);
        $this->assertSame('15.5', $inStock->specifications['Вес'] ?? null);
        $this->assertSame('Серый', $inStock->specifications['Цвет'] ?? null);

        $this->assertSame(0, $outOfStock->stock);
        $this->assertSame($safeCategory->id, $inStock->source_feed_category_id);
    }
}
