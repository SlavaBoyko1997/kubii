<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Product;
use App\Services\CounterpartyFeedImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RangerFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_ranger_feed_imports_categories_brand_stock_and_localized_fields(): void
    {
        $counterparty = Counterparty::query()->updateOrCreate(
            ['slug' => 'ranger'],
            [
                'name' => 'Ranger',
                'feed_format' => 'xml',
                'feed_profile' => 'ranger',
            ],
        );

        $result = app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/ranger-feed.xml'),
        );

        $this->assertSame(6, $result['products']);
        $this->assertSame(3, $result['categories']);

        $tentsCategory = Category::query()
            ->where('counterparty_id', $counterparty->id)
            ->where('external_id', '7')
            ->first();

        $this->assertNotNull($tentsCategory);
        $this->assertSame('Намети та парасолі', $tentsCategory->name);
        $this->assertFalse($tentsCategory->is_active);

        $inStock = Product::query()->where('external_id', 'RA9939')->firstOrFail();
        $outOfStock = Product::query()->where('external_id', 'RA6616')->firstOrFail();
        $camera = Product::query()->where('external_id', 'RA8830')->firstOrFail();

        $this->assertSame('Ranger', $inStock->brand);
        $this->assertSame('RA9939', $inStock->sku);
        $this->assertSame(29, $inStock->stock);
        $this->assertSame('Чохол для термоса Ranger 1,6 L (Арт. RA 9939)', $inStock->name);
        $this->assertSame('Чехол для термоса Ranger 1,6 L (Ар. RA 9939)', $inStock->name_ru);
        $this->assertSame('Український опис чохла.', $inStock->description);
        $this->assertSame('Русский опис чехла.', $inStock->description_ru);
        $this->assertFalse($inStock->is_active);
        $this->assertFalse($inStock->is_processed);

        $this->assertSame(0, $outOfStock->stock);
        $this->assertSame(0, $camera->stock);
        $this->assertSame('Відеокамера підводная Ranger Record Lux (Арт. RA 8830)', $camera->name);
        $this->assertSame([
            'Матеріал' => 'Oxford 600D',
            'Колір' => 'чорний, помаранчевий',
            'Вага' => '0,5 кг',
        ], $camera->specifications);
        $this->assertSame([
            'Материал' => 'пластик',
            'Цвет' => 'зеленый',
            'Вес' => '0,5 кг',
        ], $camera->specifications_ru);

        $tubus = Product::query()->where('external_id', 'RA9924')->firstOrFail();
        $this->assertSame([
            'Матеріал' => 'Oxford 600D',
            'Колір' => 'чорний, помаранчевий',
            'Розмір в розкладеному вигляді' => '33х11х11 см (ВхДхШ)',
            'Вага' => '0,5 кг',
        ], $tubus->specifications);
        $this->assertSame([
            'Материал' => 'пластиковая труба, Oxford 600D',
            'Цвет' => 'зеленый',
            'Размер в разложенном виде' => '33х11х11 см (ВхДхШ)',
            'Вес' => '0,5 кг',
        ], $tubus->specifications_ru);

        $bivvyCover = Product::query()->where('external_id', 'RA6608')->firstOrFail();
        $this->assertSame([
            'Тканина' => 'Нейлон 210D PU',
            'Колір' => 'оливково-зелений',
            'Розмір в розкладеному вигляді' => '180х400х330 см (ВхГхШ)',
            'Розмір в складеному вигляді' => '45х25х25 см (ДхГхШ)',
            'Розмір водяної колони' => '5000мм',
            'Вага' => '3,5 кг',
        ], $bivvyCover->specifications);

        $gazebo = Product::query()->where('external_id', 'RA6666')->firstOrFail();
        $this->assertSame([
            'Каркас' => 'метал 16 та 20 мм',
            'Тканина' => 'Нейлон 210D PU, підлога з прочного ПВХ',
            'Колір' => 'оливково-зелений',
            'Розмір в розкладеному вигляді' => '205х355х205-275 см (ВхГхШ)',
            'Розмір в складеному вигляді' => '140х24х24 см (ДхГхШ)',
            'Розмір вікна' => '110х94 см (ВхШ)',
            'Розмір кармана' => '23х46 см (ВхШ)',
            'Розмір водяної колони' => '8000мм',
            'Вага' => '18 кг',
        ], $gazebo->specifications);
    }
}
