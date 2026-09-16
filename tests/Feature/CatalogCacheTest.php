<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Support\CatalogCache;
use App\Support\CatalogCacheWarmProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_catalog_cache_does_not_expire_with_time(): void
    {
        Cache::forever('catalog:version', 'stable-version');
        $cache = app(CatalogCache::class);
        $resolutions = 0;

        $this->assertSame('cached-value', $cache->remember('example', function () use (&$resolutions): string {
            $resolutions++;

            return 'cached-value';
        }));

        Carbon::setTestNow(now()->addDays(2));

        $this->assertSame('cached-value', $cache->remember('example', function () use (&$resolutions): string {
            $resolutions++;

            return 'new-value';
        }));
        $this->assertSame(1, $resolutions);

        Carbon::setTestNow();
    }

    public function test_dynamic_catalog_cache_expires_after_configured_ttl(): void
    {
        Cache::forever('catalog:version', 'stable-version');
        $cache = app(CatalogCache::class);
        $resolutions = 0;
        $startedAt = now();

        $resolver = function () use (&$resolutions): string {
            $resolutions++;

            return 'value-'.$resolutions;
        };

        $this->assertSame('value-1', $cache->rememberFor('dynamic-example', 10_800, $resolver));

        Carbon::setTestNow($startedAt->copy()->addSeconds(10_799));
        $this->assertSame('value-1', $cache->rememberFor('dynamic-example', 10_800, $resolver));

        Carbon::setTestNow($startedAt->copy()->addSeconds(10_801));
        $this->assertSame('value-2', $cache->rememberFor('dynamic-example', 10_800, $resolver));
        $this->assertSame(2, $resolutions);

        Carbon::setTestNow();
    }

    public function test_invalidation_rotates_cache_version_immediately(): void
    {
        Cache::forever('catalog:version', 'stable-version');
        $cache = app(CatalogCache::class);
        $cache->put('example', 'cached-value');

        $cache->invalidate();

        $this->assertNotSame('stable-version', Cache::get('catalog:version'));
        $this->assertTrue(Cache::get('catalog:dirty'));
        $this->assertNull(Cache::get('catalog:stable-version:example'));

        $resolutions = 0;
        $value = $cache->remember('example', function () use (&$resolutions): string {
            $resolutions++;

            return 'fresh-value';
        });

        $this->assertSame('fresh-value', $value);
        $this->assertSame(1, $resolutions);
    }

    public function test_publish_refresh_discards_stale_warm_when_catalog_changed_during_refresh(): void
    {
        Cache::forever('catalog:version', 'old-version');
        $cache = app(CatalogCache::class);
        $cache->put('example', 'old-value');

        $newVersion = $cache->beginRefresh();
        $cache->put('example', 'stale-warm-value');
        $cache->markRefreshStale();

        $cache->publishRefresh();

        $this->assertNotSame($newVersion, Cache::get('catalog:version'));
        $this->assertNull(Cache::get('catalog:'.$newVersion.':example'));
        $this->assertFalse(Cache::get('catalog:dirty'));

        $resolutions = 0;
        $value = $cache->remember('example', function () use (&$resolutions): string {
            $resolutions++;

            return 'live-value';
        });

        $this->assertSame('live-value', $value);
        $this->assertSame(1, $resolutions);
    }

    public function test_refresh_switches_versions_only_after_warming_and_removes_old_keys(): void
    {
        Cache::forever('catalog:version', 'old-version');
        $cache = app(CatalogCache::class);
        $cache->put('example', 'old-value');

        $newVersion = $cache->beginRefresh();
        $cache->put('example', 'new-value');

        $this->assertSame('old-version', Cache::get('catalog:version'));
        $this->assertSame('old-value', Cache::get('catalog:old-version:example'));
        $this->assertSame('new-value', Cache::get('catalog:'.$newVersion.':example'));

        $cache->publishRefresh();

        $this->assertSame($newVersion, Cache::get('catalog:version'));
        $this->assertFalse(Cache::get('catalog:dirty'));
        $this->assertNull(Cache::get('catalog:old-version:example'));
        $this->assertSame('new-value', Cache::get('catalog:'.$newVersion.':example'));
    }

    public function test_catalog_warm_progress_tracks_the_full_lifecycle(): void
    {
        $progress = app(CatalogCacheWarmProgress::class);

        $queued = $progress->start('test-run');
        $this->assertSame('queued', $queued['state']);
        $this->assertSame('Запускаємо фонове оновлення', $queued['stage']);
        $this->assertTrue($progress->isRunning());

        $running = $progress->update('test-run', 'Фільтри категорій', 25, 100);
        $this->assertSame('running', $running['state']);
        $this->assertSame(25, $running['percent']);

        $completed = $progress->complete('test-run');
        $this->assertSame('completed', $completed['state']);
        $this->assertSame(100, $completed['percent']);
        $this->assertFalse($progress->isRunning());
    }

    public function test_active_parent_category_includes_products_from_inactive_child_category(): void
    {
        $parent = Category::create(['name' => 'Туризм', 'slug' => 'tourism-active-parent', 'is_active' => true]);
        $child = Category::create([
            'name' => 'Намети',
            'slug' => 'tents-inactive-child',
            'parent_id' => $parent->id,
            'is_active' => false,
        ]);

        Product::create([
            'category_id' => $child->id,
            'name' => 'Намет тестовий',
            'slug' => 'inactive-child-tent',
            'price' => 1500,
            'stock' => 2,
            'is_active' => true,
        ]);

        $this->get($parent->catalogUrl())
            ->assertOk()
            ->assertSee('Намет тестовий');
    }

    public function test_invalidation_during_refresh_is_deferred(): void
    {
        Cache::forever('catalog:version', 'stable-version');
        $cache = app(CatalogCache::class);
        $cache->beginRefresh();

        $cache->invalidate();

        $this->assertFalse(Cache::get('catalog:dirty'));
        $this->assertSame('stable-version', Cache::get('catalog:version'));
    }

    public function test_fresh_cache_warm_publishes_category_filters(): void
    {
        $category = Category::create([
            'name' => 'Кеш фільтрів',
            'slug' => 'cache-warm-filters',
            'is_active' => true,
            'visible_spec_filters' => null,
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Товар з брендом',
            'slug' => 'cache-warm-filter-product',
            'brand' => 'TestBrand',
            'price' => 900,
            'stock' => 3,
            'is_active' => true,
            'specifications' => ['Колір' => 'Синій'],
        ]);

        $this->artisan('catalog:cache-warm', ['--fresh' => true])->assertSuccessful();

        $category->refresh();
        $this->assertContains('Колір', $category->visible_spec_filters ?? []);

        $version = Cache::get('catalog:version');
        $filterFieldsHash = sha1(json_encode([], JSON_UNESCAPED_UNICODE));
        $filterFieldsHtml = Cache::get('catalog:'.$version.':catalog-filter-fields-html:v2:category-'.$category->id.':'.$filterFieldsHash);

        $this->assertIsString($filterFieldsHtml);
        $this->assertStringContainsString('TestBrand', $filterFieldsHtml);
        $this->assertStringContainsString('Колір', $filterFieldsHtml);

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertSee('<legend>Бренд</legend>', false)
            ->assertSee('<legend>Ціна', false)
            ->assertSee('<legend>Колір</legend>', false);
    }

    public function test_cache_warm_skips_productless_category_scopes(): void
    {
        $emptyCategory = Category::create([
            'name' => 'Порожня категорія кешу',
            'slug' => 'empty-cache-category',
            'is_active' => true,
        ]);
        $publicCategory = Category::create([
            'name' => 'Публічна категорія кешу',
            'slug' => 'public-cache-category',
            'is_active' => true,
        ]);
        Product::create([
            'category_id' => $publicCategory->id,
            'name' => 'Публічний товар кешу',
            'slug' => 'public-cache-product',
            'price' => 700,
            'stock' => 2,
            'is_active' => true,
        ]);

        $this->artisan('catalog:cache-warm', ['--fresh' => true])->assertSuccessful();

        $version = Cache::get('catalog:version');
        $manifest = Cache::get('catalog:manifest:'.$version, []);

        $this->assertTrue(collect($manifest)->contains(
            fn (string $key): bool => str_contains($key, 'spec-facets:v6:category-'.$publicCategory->id),
        ));
        $this->assertFalse(collect($manifest)->contains(
            fn (string $key): bool => str_contains($key, 'spec-facets:v6:category-'.$emptyCategory->id),
        ));
    }

    public function test_category_spec_filter_key_discovery_is_cached_across_requests(): void
    {
        $category = Category::create([
            'name' => 'Кеш ключів фільтрів',
            'slug' => 'cache-spec-filter-keys',
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Товар зі специфікацією',
            'slug' => 'cache-spec-filter-keys-product',
            'price' => 500,
            'stock' => 5,
            'is_active' => true,
            'specifications' => ['Колір' => 'Синій'],
        ]);

        $this->get($category->catalogUrl())->assertOk();

        $version = Cache::get('catalog:version');
        $cachedKeys = Cache::get('catalog:'.$version.':spec-filter-keys:v1:category-'.$category->id);

        $this->assertIsArray($cachedKeys);
        $this->assertContains('Колір', $cachedKeys);

        DB::enableQueryLog();
        $this->get($category->catalogUrl())->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' | ');
        DB::disableQueryLog();

        $this->assertStringNotContainsString('specifications', $queries);
    }

    public function test_catalog_filter_fields_html_is_not_registered_as_permanent_cache(): void
    {
        $category = Category::create([
            'name' => 'Кеш HTML фільтрів',
            'slug' => 'cache-filter-fields-html',
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Товар для HTML фільтрів',
            'slug' => 'cache-filter-fields-product',
            'brand' => 'FilterBrand',
            'price' => 1200,
            'stock' => 4,
            'is_active' => true,
        ]);

        Cache::forever('catalog:version', 'filter-fields-version');

        $response = $this->get($category->catalogUrl());
        $response->assertOk()->assertSee('Фільтри', false);

        $this->assertSame('filter-fields-version', Cache::get('catalog:version'));

        $manifest = Cache::get('catalog:manifest:filter-fields-version', []);
        $this->assertNotEmpty($manifest, 'Expected catalog cache manifest to contain warmed keys.');

        $this->assertFalse(
            collect($manifest)->contains(fn (string $key): bool => str_contains($key, 'catalog-filter-fields-html:v1:category-'.$category->id)),
        );
    }

    public function test_large_catalog_renders_products_before_deferred_filters(): void
    {
        config()->set('performance.large_catalog_threshold', 1);

        $category = Category::create([
            'name' => 'Великий швидкий каталог',
            'slug' => 'large-fast-catalog',
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Товар швидкого каталогу',
            'slug' => 'large-fast-catalog-product',
            'brand' => 'FastBrand',
            'price' => 1200,
            'stock' => 4,
            'is_active' => true,
        ]);

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertSee('Товар швидкого каталогу')
            ->assertSee('data-deferred-filters', false);

        $this->getJson($category->catalogUrl(['filters_only' => 1]))
            ->assertOk()
            ->assertJsonPath('url', $category->catalogUrl(absolute: false))
            ->assertJson(fn ($json) => $json
                ->whereType('filters', 'string')
                ->missing('html')
                ->etc())
            ->assertSee('FastBrand');
    }

    public function test_home_root_categories_are_cached_without_database_lookup_on_second_read(): void
    {
        $category = Category::create([
            'name' => 'Головна категорія',
            'slug' => 'home-root-category',
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Товар головної',
            'slug' => 'home-root-product',
            'price' => 800,
            'stock' => 2,
            'is_active' => true,
        ]);

        Cache::forever('catalog:version', 'home-categories-version');

        $cache = app(CatalogCache::class);
        $first = $cache->homeRootCategories();

        Category::query()->whereKey($category->id)->update(['name' => 'Змінена назва']);

        $second = $cache->homeRootCategories();

        $this->assertSame($first, $second);
        $this->assertSame('Головна категорія', $second[0]['name'] ?? null);
    }

    public function test_stale_catalog_warm_progress_does_not_block_a_new_refresh(): void
    {
        $progress = app(CatalogCacheWarmProgress::class);
        $progress->start('stale-run');

        Carbon::setTestNow(now()->addMinutes(36));

        $this->assertFalse($progress->isRunning());
    }

    public function test_stale_warm_lock_is_cleared_when_progress_is_not_running(): void
    {
        $progress = app(CatalogCacheWarmProgress::class);
        $progress->fail('stale-run', 'Інший процес оновлення кешу вже виконується.');

        $lock = Cache::lock('catalog:cache-warm:lock', 1800);
        $lock->get();

        $this->artisan('catalog:cache-warm', ['--fresh' => true])->assertSuccessful();
    }

    public function test_catalog_menu_renders_ibis_style_mega_layout(): void
    {
        $root = Category::create([
            'name' => 'Рибальство',
            'slug' => 'mega-rybalstvo',
            'is_active' => true,
        ]);
        $child = Category::create([
            'name' => 'Вудилища',
            'slug' => 'mega-vudylyshcha',
            'parent_id' => $root->id,
            'is_active' => true,
        ]);
        $leaf = Category::create([
            'name' => 'Спінінгові',
            'slug' => 'mega-spininhovi',
            'parent_id' => $child->id,
            'is_active' => true,
        ]);

        foreach (range(1, 9) as $index) {
            $extra = Category::create([
                'name' => 'Додаткова '.$index,
                'slug' => 'mega-extra-'.$index,
                'parent_id' => $child->id,
                'is_active' => true,
            ]);

            Product::create([
                'category_id' => $extra->id,
                'name' => 'Товар '.$index,
                'slug' => 'mega-extra-product-'.$index,
                'price' => 800,
                'stock' => 2,
                'is_active' => true,
            ]);
        }

        Product::create([
            'category_id' => $leaf->id,
            'name' => 'Тестове вудилище',
            'slug' => 'mega-test-rod',
            'price' => 1200,
            'stock' => 3,
            'is_active' => true,
        ]);

        $this->get('/catalog-menu')
            ->assertOk()
            ->assertSee('data-mega-root-mobile="'.$root->id.'"', false)
            ->assertSee('data-mega-panel="'.$root->id.'"', false)
            ->assertSee('mega-column-title', false)
            ->assertDontSee('menu-groups-mobile', false)
            ->assertSee('Вудилища')
            ->assertSee('Спінінгові')
            ->assertSee('data-mega-more', false)
            ->assertSee('mega-extra', false);

        $this->get('/')
            ->assertOk()
            ->assertSee('data-catalog-nav', false)
            ->assertSee('data-mega-root="'.$root->id.'"', false);
    }
}
