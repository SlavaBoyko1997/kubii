<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Services\IbisFeedImporter;
use App\Services\SmartSearch;
use App\Support\CatalogCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ukrainian_is_default_and_russian_home_uses_prefixed_routes(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<html lang="uk">', false)
            ->assertSee('Каталог товарів')
            ->assertSee('<b>UK</b>', false)
            ->assertSee('class="ukraine-flag"', false)
            ->assertSee('href="http://localhost/favicon.svg"', false)
            ->assertSee('hreflang="ru" href="http://localhost/ru"', false);

        $this->get('/ru')
            ->assertOk()
            ->assertSee('<html lang="ru">', false)
            ->assertSee('Каталог товаров')
            ->assertSee('<b>RU</b>', false)
            ->assertDontSee('class="ukraine-flag"', false)
            ->assertSee('action="http://localhost/ru/search"', false)
            ->assertSee('hreflang="uk" href="http://localhost"', false);
    }

    public function test_category_and_product_use_shared_slugs_with_russian_content_and_seo(): void
    {
        $category = Category::create([
            'name' => 'Вудилища',
            'name_ru' => 'Удилища',
            'slug' => 'vudylyshcha',
            'slug_ru' => 'udilishcha',
            'seo_title' => 'Вудилища для риболовлі',
            'seo_title_ru' => 'Удилища для рыбалки',
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Вудилище тестове',
            'name_ru' => 'Удилище тестовое',
            'slug' => 'vudylyshche-testove',
            'slug_ru' => 'udilishche-testovoe',
            'description' => 'Український опис',
            'description_ru' => 'Русское описание',
            'seo_title' => 'Купити тестове вудилище',
            'seo_title_ru' => 'Купить тестовое удилище',
            'meta_description' => 'Опис українською',
            'meta_description_ru' => 'Описание на русском',
            'price' => 2500,
            'stock' => 3,
        ]);

        $this->get($category->catalogUrl(locale: 'ru'))
            ->assertOk()
            ->assertSee('Удилища')
            ->assertSee('Удилище тестовое')
            ->assertSee('Удилища для рыбалки');

        $this->get($product->url(locale: 'ru'))
            ->assertOk()
            ->assertSee('Удилище тестовое')
            ->assertSee('Русское описание')
            ->assertSee('<title>Купить тестовое удилище', false)
            ->assertSee('content="Описание на русском"', false)
            ->assertSee('rel="canonical" href="'.$product->url(locale: 'ru').'"', false)
            ->assertSee('hreflang="uk" href="'.$product->url(locale: 'uk').'"', false);

        $this->assertSame('/vudylyshcha/', $category->catalogUrl(absolute: false, locale: 'uk'));
        $this->assertSame('/ru/vudylyshcha/', $category->catalogUrl(absolute: false, locale: 'ru'));
        $this->assertSame('/vudylyshcha/products/vudylyshche-testove', $product->url(absolute: false, locale: 'uk'));
        $this->assertSame('/ru/vudylyshcha/products/vudylyshche-testove', $product->url(absolute: false, locale: 'ru'));
        $this->assertSame('vudylyshcha', $category->refresh()->getRawOriginal('slug_ru'));
        $this->assertSame('vudylyshche-testove', $product->refresh()->getRawOriginal('slug_ru'));
    }

    public function test_catalog_cache_is_separated_by_locale(): void
    {
        Cache::clear();
        $cache = app(CatalogCache::class);

        app()->setLocale('uk');
        $this->assertSame('українське', $cache->remember('locale-test', fn (): string => 'українське'));

        app()->setLocale('ru');
        $this->assertSame('русское', $cache->remember('locale-test', fn (): string => 'русское'));

        app()->setLocale('uk');
        $this->assertSame('українське', $cache->remember('locale-test', fn (): string => 'інше'));
    }

    public function test_sitemaps_include_both_languages_and_hreflang(): void
    {
        $category = Category::create([
            'name' => 'Намети',
            'name_ru' => 'Палатки',
            'slug' => 'namety',
            'slug_ru' => 'palatki',
        ]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Намет',
            'name_ru' => 'Палатка',
            'slug' => 'namet',
            'slug_ru' => 'palatka',
            'price' => 3000,
            'stock' => 2,
        ]);

        $this->get('/sitemap-categories.xml')
            ->assertOk()
            ->assertSee($category->catalogUrl(locale: 'uk'), false)
            ->assertSee($category->catalogUrl(locale: 'ru'), false)
            ->assertSee('hreflang="x-default"', false);

        $this->get('/sitemap-products-1.xml')
            ->assertOk()
            ->assertSee('hreflang="uk"', false);
    }

    public function test_product_sitemap_handles_null_updated_at(): void
    {
        $category = Category::create(['name' => 'Намети', 'slug' => 'sitemap-null-date']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Намет без дати',
            'slug' => 'sitemap-null-date-product',
            'price' => 3000,
            'stock' => 2,
            'is_indexable' => true,
            'canonical_type' => 'self',
        ]);

        \Illuminate\Support\Facades\DB::table('products')
            ->where('id', $product->id)
            ->update(['updated_at' => null]);

        $this->get('/sitemap-products-1.xml')
            ->assertOk()
            ->assertSee($product->url(), false)
            ->assertSee('<lastmod>'.now()->toDateString().'</lastmod>', false);
    }

    public function test_importer_stores_russian_catalog_fields(): void
    {
        $result = app(IbisFeedImporter::class)->import(base_path('tests/Fixtures/ibis-feed-multilingual.xml'), true);

        $this->assertSame(1, $result['products']);
        $this->assertDatabaseHas('products', [
            'external_id' => '70010001',
            'name' => 'Вудилище тестове',
            'name_ru' => 'Удилище тестовое',
            'description_ru' => 'Тестовое удилище.',
            'season_ru' => 'Лето',
            'material_ru' => 'Карбон',
            'is_processed' => false,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('categories', [
            'name' => 'Вудилища',
            'name_ru' => 'Удилища',
        ]);

        $product = Product::query()->where('external_id', '70010001')->firstOrFail();
        $this->assertSame('Лето', $product->specifications_ru['Сезон'] ?? null);
        $this->assertSame($product->getRawOriginal('slug'), $product->getRawOriginal('slug_ru'));
        $this->assertSame('2000000001', $product->sku);
        $this->assertNotSame($product->external_id, $product->sku);
        $this->assertStringNotContainsString($product->external_id, $product->getRawOriginal('slug'));

        $product->update(['is_active' => true]);
        $this->assertTrue(app(SmartSearch::class)->search($product->sku)['products']->contains('id', $product->id));
    }

    public function test_repeat_import_preserves_manually_assigned_category_and_processing_state(): void
    {
        $importer = app(IbisFeedImporter::class);
        $importer->import(base_path('tests/Fixtures/ibis-feed-multilingual.xml'), true);

        $manualCategory = Category::create([
            'name' => 'Обрана вручну',
            'slug' => 'obrana-vruchnu',
        ]);
        $product = Product::query()->where('external_id', '70010001')->firstOrFail();
        $product->update([
            'category_id' => $manualCategory->id,
            'is_processed' => true,
            'is_active' => true,
        ]);

        $importer->import(base_path('tests/Fixtures/ibis-feed-multilingual.xml'));

        $product->refresh();
        $this->assertSame($manualCategory->id, $product->category_id);
        $this->assertTrue($product->is_processed);
        $this->assertTrue($product->is_active);
        $this->assertSame('2000000001', $product->sku);
    }

    public function test_repeat_import_preserves_manually_disabled_active_flag(): void
    {
        $importer = app(IbisFeedImporter::class);
        $importer->import(base_path('tests/Fixtures/ibis-feed-multilingual.xml'), true);

        $product = Product::query()->where('external_id', '70010001')->firstOrFail();
        $product->update([
            'is_processed' => true,
            'is_active' => false,
        ]);

        $importer->import(base_path('tests/Fixtures/ibis-feed-multilingual.xml'));

        $product->refresh();

        $this->assertTrue($product->is_processed);
        $this->assertFalse($product->is_active);
    }

    public function test_ibis_category_slugs_do_not_include_counterparty_prefix(): void
    {
        $counterparty = \App\Models\Counterparty::query()->where('slug', 'ibis-gear')->firstOrFail();

        app(IbisFeedImporter::class)->importCounterpartyFile(
            $counterparty,
            base_path('tests/Fixtures/ibis-feed.xml'),
        );

        $category = Category::query()->where('name', 'Намети')->firstOrFail();

        $this->assertStringStartsNotWith('ibis-gear-', $category->getRawOriginal('slug'));
        $this->assertStringStartsNotWith('ibis-gear-', $category->publicSlug());
        $this->assertStringNotContainsString('/ibis-gear-', $category->catalogUrl());
    }

    public function test_ibis_repeat_import_does_not_hide_published_products(): void
    {
        $counterparty = \App\Models\Counterparty::query()->where('slug', 'ibis-gear')->firstOrFail();
        $importer = app(IbisFeedImporter::class);
        $file = base_path('tests/Fixtures/ibis-feed.xml');

        $importer->importCounterpartyFile($counterparty, $file);

        $product = Product::query()->where('external_id', '12271354')->firstOrFail();
        $product->update([
            'is_processed' => true,
            'is_active' => true,
        ]);

        $importer->importCounterpartyFile($counterparty, $file);

        $product->refresh();
        $this->assertTrue($product->is_processed);
        $this->assertTrue($product->is_active);
    }

    public function test_old_product_slug_redirects_to_the_clean_slug(): void
    {
        $category = Category::create([
            'name' => 'Котушки',
            'slug' => 'kotushky',
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Котушка Shimano',
            'slug' => 'kotushka-shimano',
            'price' => 2000,
            'stock' => 2,
        ]);

        DB::table('product_slug_redirects')->insert([
            'old_slug' => 'kotushka-shimano-12345678',
            'product_id' => $product->id,
        ]);

        $this->get('/kotushky/products/kotushka-shimano-12345678')
            ->assertRedirect($product->url())
            ->assertStatus(301);
    }
}
