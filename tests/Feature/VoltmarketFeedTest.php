<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Product;
use App\Services\VoltmarketFeedImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoltmarketFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_voltmarket_feed_imports_only_selected_sections(): void
    {
        $counterparty = Counterparty::query()->where('slug', 'voltmarket')->firstOrFail();

        $this->assertSame('Voltmarket', $counterparty->name);
        $this->assertSame('atom', $counterparty->feed_format);
        $this->assertSame('voltmarket', $counterparty->feed_profile);

        $result = app(VoltmarketFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/voltmarket-feed.xml'),
        );

        $this->assertSame(5, $result['products']);
        $this->assertSame(7, $result['categories']);

        $this->assertDatabaseMissing('products', ['source' => 'voltmarket', 'external_id' => '9999']);

        $generator = Product::query()->where('source', 'voltmarket')->where('external_id', '7487')->firstOrFail();
        $charger = Product::query()->where('source', 'voltmarket')->where('external_id', '4596')->firstOrFail();

        $this->assertSame('VM-7487', $generator->sku);
        $this->assertSame('Könner&Söhnen', $generator->brand);
        $this->assertSame(1, $generator->stock);
        $this->assertSame('2.0', $generator->displaySpecifications()['Потужність, кВт'] ?? null);
        $this->assertSame('настінний', $generator->displaySpecifications()['Тип монтажу'] ?? null);
        $this->assertSame('1', $generator->displaySpecifications()['Кількість фаз'] ?? null);
        $this->assertSame('автономного електроживлення;для дачі;для дому;для підприємства', $generator->displaySpecifications()['Призначення'] ?? null);
        $this->assertSame('https://voltmarket.ua/image/catalog/generator.jpg', $generator->image_url);
        $this->assertFalse($generator->is_active);
        $this->assertFalse($generator->is_processed);

        $this->assertSame(0, $charger->stock);
        $this->assertSame(735.0, (float) $charger->price);
        $this->assertSame(625.0, (float) $charger->sale_price);
        $this->assertSame(15, $charger->discount_percent);

        $fuelRoot = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('name', 'Паливні генератори')
            ->firstOrFail();
        $batteryRoot = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('name', 'Акумуляторні батареї')
            ->firstOrFail();

        $this->assertDatabaseHas('categories', [
            'counterparty_id' => $counterparty->id,
            'parent_id' => $fuelRoot->id,
            'name' => 'Бензинові генератори',
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('categories', [
            'counterparty_id' => $counterparty->id,
            'parent_id' => $batteryRoot->id,
            'name' => 'Літієві акумулятори',
            'is_active' => false,
        ]);

        foreach (['Джерела безперебійного живлення', 'Сонячні інвертори', 'Зарядні пристрої', 'Акумуляторні батареї'] as $categoryName) {
            $this->assertDatabaseHas('categories', [
                'counterparty_id' => $counterparty->id,
                'name' => $categoryName,
                'is_active' => false,
            ]);
        }
    }

    public function test_sync_command_routes_voltmarket_profile_to_atom_importer(): void
    {
        $counterparty = Counterparty::query()->where('slug', 'voltmarket')->firstOrFail();

        $this->artisan('counterparties:sync-feeds', [
            'counterparty' => 'voltmarket',
            '--file' => base_path('tests/Fixtures/voltmarket-feed.xml'),
        ])->assertSuccessful();

        $counterparty->refresh();

        $this->assertSame(5, $counterparty->last_sync_products_count);
        $this->assertNull($counterparty->last_sync_error);
        $this->assertSame(5, Product::query()->where('source', 'voltmarket')->count());
    }
}
