<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariantGroup;
use App\Services\CounterpartyFeedImporter;
use App\Services\IbisFeedImporter;
use App\Services\ProductVariantGrouper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductFeedGroupDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_with_same_feed_group_id_are_grouped_without_brand_or_model(): void
    {
        $category = Category::create(['name' => 'Футболки', 'slug' => 'feed-group-no-brand', 'is_active' => true]);

        foreach ([
            ['0001', 'Футболка Pobedov XL', 'XXXL'],
            ['0002', 'Футболка Pobedov L', 'L'],
        ] as [$externalId, $name, $size]) {
            Product::create([
                'category_id' => $category->id,
                'source' => 'pobedov',
                'external_id' => $externalId,
                'variant_id' => 'pobedov-group-229771',
                'name' => $name,
                'slug' => 'feed-group-no-brand-'.$externalId,
                'price' => 650,
                'stock' => 2,
                'is_active' => true,
                'brand' => null,
                'model' => null,
                'specifications' => ['Международный размер' => $size],
            ]);
        }

        $result = app(ProductVariantGrouper::class)->discover($category);

        $this->assertSame(1, $result['created']);
        $this->assertSame(2, ProductVariantGroup::query()->firstOrFail()->products()->count());
    }

    public function test_feed_group_products_are_not_merged_by_brand_heuristics(): void
    {
        $category = Category::create(['name' => 'Одяг', 'slug' => 'feed-group-brand-isolation', 'is_active' => true]);

        Product::create([
            'category_id' => $category->id,
            'source' => 'pobedov',
            'external_id' => 'grouped-1',
            'variant_id' => 'pobedov-group-555',
            'name' => 'Футболка Pobedov XL',
            'slug' => 'feed-group-brand-isolation-1',
            'price' => 650,
            'stock' => 2,
            'is_active' => true,
            'brand' => 'Pobedov',
            'model' => 'Peremoga',
            'specifications' => ['Международный размер' => 'XL'],
        ]);

        Product::create([
            'category_id' => $category->id,
            'source' => 'manual',
            'external_id' => 'solo-1',
            'variant_id' => 'manual-solo-1',
            'name' => 'Футболка Pobedov L',
            'slug' => 'feed-group-brand-isolation-2',
            'price' => 640,
            'stock' => 2,
            'is_active' => true,
            'brand' => 'Pobedov',
            'model' => 'Peremoga',
            'specifications' => ['Международный размер' => 'L'],
        ]);

        $result = app(ProductVariantGrouper::class)->discover($category);

        $this->assertSame(0, $result['created']);
        $this->assertNull(Product::query()->where('external_id', 'grouped-1')->value('variant_group_id'));
    }

    public function test_products_with_same_feed_group_id_are_grouped_together(): void
    {
        $category = Category::create(['name' => 'Футболки', 'slug' => 'feed-group-shirts', 'is_active' => true]);

        foreach ([
            ['0001', 'Футболка Pobedov XL', 'XXXL', 11],
            ['0002', 'Футболка Pobedov L', 'L', 5],
        ] as [$externalId, $name, $size, $stock]) {
            Product::create([
                'category_id' => $category->id,
                'source' => 'pobedov',
                'external_id' => $externalId,
                'variant_id' => 'pobedov-group-229771',
                'name' => $name,
                'slug' => 'feed-group-'.$externalId,
                'price' => 650,
                'stock' => $stock,
                'is_active' => true,
                'specifications' => ['Международный размер' => $size, 'Цвет' => 'Синій'],
            ]);
        }

        $result = app(ProductVariantGrouper::class)->discover($category);

        $this->assertSame(1, $result['created']);

        $group = ProductVariantGroup::query()->firstOrFail();
        $this->assertSame('feed_group_id', $group->grouping_level);
        $this->assertSame(2, $group->products()->count());
        $this->assertSame(['0001', '0002'], $group->products()->orderBy('external_id')->pluck('external_id')->all());
        $this->assertSame(
            '0001',
            $group->products()->where('is_primary_variant', true)->value('external_id'),
        );
    }

    public function test_discover_on_parent_category_includes_child_category_products(): void
    {
        $parent = Category::create(['name' => 'Одяг', 'slug' => 'parent-clothes', 'is_active' => true]);
        $child = Category::create(['name' => 'Футболки', 'slug' => 'child-shirts', 'parent_id' => $parent->id, 'is_active' => true]);

        foreach ([
            ['0001', 'Футболка Pobedov XL', 'XXXL'],
            ['0002', 'Футболка Pobedov L', 'L'],
        ] as [$externalId, $name, $size]) {
            Product::create([
                'category_id' => $child->id,
                'source' => 'pobedov',
                'external_id' => $externalId,
                'variant_id' => 'pobedov-group-parent-child',
                'name' => $name,
                'slug' => 'parent-child-group-'.$externalId,
                'price' => 650,
                'stock' => 2,
                'is_active' => true,
                'specifications' => ['Международный размер' => $size],
            ]);
        }

        $result = app(ProductVariantGrouper::class)->discover($parent);

        $this->assertSame(2, $result['categories_scanned']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(2, ProductVariantGroup::query()->firstOrFail()->products()->count());
    }

    public function test_ibis_feed_import_maps_group_id_to_shared_variant_id(): void
    {
        app(IbisFeedImporter::class)->import(base_path('tests/Fixtures/ibis-feed-multilingual.xml'), true);

        $product = Product::query()->where('external_id', '70010001')->firstOrFail();

        $this->assertSame('ibis-gear-group-7001', $product->variant_id);
    }

    public function test_counterparty_feed_products_with_same_group_id_can_be_discovered(): void
    {
        $counterparty = \App\Models\Counterparty::create([
            'name' => 'Pobedov',
            'slug' => 'pobedov',
            'feed_format' => 'yml',
        ]);

        app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/pobedov-feed-grouped.yml'),
        );

        $category = Category::query()->where('external_id', '26')->firstOrFail();
        $category->update(['is_active' => true]);

        Product::query()
            ->where('source', 'pobedov')
            ->update(['is_active' => true]);

        $result = app(ProductVariantGrouper::class)->discover($category->fresh());

        $this->assertSame(1, $result['created']);
        $this->assertSame(2, ProductVariantGroup::query()->firstOrFail()->products()->count());
        $this->assertSame(
            ['Международный размер'],
            ProductVariantGroup::query()->value('variant_option_keys'),
        );
    }

    public function test_import_groups_products_saved_to_mapped_catalog_category(): void
    {
        $counterparty = \App\Models\Counterparty::create([
            'name' => 'Pobedov',
            'slug' => 'pobedov',
            'feed_format' => 'yml',
        ]);
        $targetCategory = Category::create([
            'name' => 'Чоловічі футболки та майки',
            'slug' => 'mapped-shirts-after-import',
            'is_active' => true,
        ]);

        $result = app(CounterpartyFeedImporter::class)->importFile(
            $counterparty,
            base_path('tests/Fixtures/pobedov-feed-grouped.yml'),
        );

        $this->assertSame(1, $result['variant_groups_created']);
        $this->assertSame(2, ProductVariantGroup::query()->firstOrFail()->products()->count());
        $this->assertSame(
            [$targetCategory->id],
            Product::query()
                ->where('source', 'pobedov')
                ->distinct()
                ->pluck('category_id')
                ->all(),
        );
    }

    public function test_discover_selected_feed_category_scans_products_saved_to_target_category(): void
    {
        $counterparty = \App\Models\Counterparty::create([
            'name' => 'Camotec',
            'slug' => 'camotec',
            'feed_format' => 'xml',
        ]);
        $targetCategory = Category::create([
            'name' => 'Черевики',
            'slug' => 'target-boots-for-feed-discovery',
            'is_active' => true,
        ]);
        $feedCategory = Category::create([
            'name' => 'Черевики',
            'slug' => 'feed-boots-for-discovery',
            'counterparty_id' => $counterparty->id,
            'external_id' => '170',
            'target_category_id' => $targetCategory->id,
            'is_active' => false,
        ]);

        foreach ([41, 42, 46] as $size) {
            Product::create([
                'category_id' => $targetCategory->id,
                'source_feed_category_id' => $feedCategory->id,
                'counterparty_id' => $counterparty->id,
                'source' => 'camotec',
                'external_id' => 'scout-'.$size,
                'variant_id' => 'camotec-group-scout-mid-koiot',
                'name' => 'Черевики Scout MID Койот, '.$size,
                'slug' => 'feed-selected-scout-'.$size,
                'sku' => '8480('.$size.')',
                'price' => 4595,
                'stock' => 1,
                'is_active' => true,
                'brand' => 'camotec',
                'specifications' => ['Розмір' => (string) $size],
            ]);
        }

        $result = app(ProductVariantGrouper::class)->discover($feedCategory, includeInactive: true);

        $this->assertSame(1, $result['created']);
        $this->assertSame(3, ProductVariantGroup::query()->firstOrFail()->products()->count());
    }

    public function test_singleton_feed_group_ids_can_fall_back_to_same_source_heuristics(): void
    {
        $category = Category::create(['name' => 'Черевики', 'slug' => 'singleton-feed-group-boots', 'is_active' => true]);

        foreach ([41, 42, 46] as $size) {
            Product::create([
                'category_id' => $category->id,
                'source' => 'camotec',
                'external_id' => 'scout-singleton-'.$size,
                'variant_id' => 'camotec-group-scout-'.$size,
                'name' => 'Черевики Scout MID Койот, '.$size,
                'slug' => 'singleton-feed-scout-'.$size,
                'sku' => '8480('.$size.')',
                'price' => 4595,
                'stock' => 1,
                'is_active' => true,
                'brand' => 'camotec',
                'specifications' => [
                    'Виробник' => 'camotec',
                    'Колір' => 'Койот',
                    'Сезон' => 'Зима, Осінь',
                    'Розмір' => (string) $size,
                    'Призначення' => 'Активний відпочинок (риболовля, полювання та туризм), pro, patrol, military, outdoor',
                ],
            ]);
        }

        $result = app(ProductVariantGrouper::class)->discover($category);

        $this->assertSame(1, $result['created']);
        $this->assertSame(['Розмір'], ProductVariantGroup::query()->firstOrFail()->variant_option_keys);
        $this->assertSame(3, ProductVariantGroup::query()->firstOrFail()->products()->count());
    }

    public function test_products_with_trailing_shoe_size_in_name_and_sku_are_grouped(): void
    {
        $category = Category::create(['name' => 'Черевики', 'slug' => 'camotec-boots', 'is_active' => true]);

        foreach ([41, 42, 46] as $size) {
            Product::create([
                'category_id' => $category->id,
                'source' => 'camotec',
                'external_id' => 'scout-'.$size,
                'name' => 'Черевики Scout MID Койот, '.$size,
                'slug' => 'cereviki-scout-mid-koiot-8480-'.$size,
                'sku' => '8480('.$size.')',
                'price' => 4595,
                'stock' => 1,
                'is_active' => true,
                'brand' => 'camotec',
                'model' => null,
                'specifications' => [
                    'Розмір' => (string) $size,
                    'Матеріал' => 'Нубук',
                    'Колір' => 'Койот',
                ],
            ]);
        }

        $result = app(ProductVariantGrouper::class)->discover($category);

        $this->assertSame(1, $result['created']);

        $group = ProductVariantGroup::query()->firstOrFail();

        $this->assertSame('approved', $group->status);
        $this->assertSame(['Розмір'], $group->variant_option_keys);
        $this->assertSame(3, $group->products()->count());
        $this->assertSame(
            ['41', '42', '46'],
            $group->products()
                ->orderBy('sku')
                ->get()
                ->pluck('variant_options.Розмір')
                ->sort()
                ->values()
                ->all(),
        );
    }
}
