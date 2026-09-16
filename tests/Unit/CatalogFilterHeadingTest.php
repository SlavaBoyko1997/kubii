<?php

namespace Tests\Unit;

use App\Services\CatalogFilterHeading;
use Tests\TestCase;

class CatalogFilterHeadingTest extends TestCase
{
    public function test_suffix_is_empty_without_active_filters(): void
    {
        $heading = new CatalogFilterHeading;

        $this->assertSame('', $heading->suffix([]));
        $this->assertSame('', $heading->suffix([
            ['label' => 'бренд', 'values' => []],
        ]));
    }

    public function test_suffix_prefixes_each_value_with_its_characteristic_label(): void
    {
        $heading = new CatalogFilterHeading;

        $this->assertSame('бренд Camotec колір Чорний', $heading->suffix([
            ['label' => 'бренд', 'values' => ['Camotec']],
            ['label' => 'колір', 'values' => ['Чорний']],
        ]));
    }

    public function test_suffix_joins_two_values_of_the_same_group_with_a_connector(): void
    {
        $heading = new CatalogFilterHeading;

        $this->assertSame('бренд Camotec колір Чорний та Сірий', $heading->suffix([
            ['label' => 'бренд', 'values' => ['Camotec']],
            ['label' => 'колір', 'values' => ['Чорний', 'Сірий']],
        ]));
    }

    public function test_suffix_drops_a_group_with_more_than_two_values_to_avoid_spam(): void
    {
        $heading = new CatalogFilterHeading;

        $this->assertSame('', $heading->suffix([
            ['label' => 'бренд', 'values' => ['Tramp', 'Ranger', 'Norfin']],
        ]));

        $this->assertSame('сезон Зимові', $heading->suffix([
            ['label' => 'бренд', 'values' => ['Tramp', 'Ranger', 'Norfin']],
            ['label' => 'сезон', 'values' => ['Зимові']],
        ]));
    }

    public function test_suffix_appends_stock_and_sale_flags_after_groups(): void
    {
        $heading = new CatalogFilterHeading;

        $this->assertSame('бренд Tramp в наявності акційні', $heading->suffix(
            [['label' => 'бренд', 'values' => ['Tramp']]],
            ['в наявності', 'акційні'],
        ));
    }

    public function test_build_appends_suffix_to_base_name(): void
    {
        $heading = new CatalogFilterHeading;

        $this->assertSame('Одяг та взуття', $heading->build('Одяг та взуття', []));
        $this->assertSame('Одяг та взуття бренд Camotec колір Чорний', $heading->build('Одяг та взуття', [
            ['label' => 'бренд', 'values' => ['Camotec']],
            ['label' => 'колір', 'values' => ['Чорний']],
        ]));
    }
}
