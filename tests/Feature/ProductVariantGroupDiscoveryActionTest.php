<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductVariantGroups\Pages\ListProductVariantGroups;
use App\Jobs\DiscoverProductVariantGroupsJob;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariantGroup;
use App\Models\User;
use App\Support\CatalogCache;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ProductVariantGroupDiscoveryActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dispatches_variant_discovery_job_from_filament_action(): void
    {
        Bus::fake([DiscoverProductVariantGroupsJob::class]);
        config(['queue.default' => 'redis']);

        $admin = User::factory()->create(['is_admin' => true]);
        $parent = Category::create(['name' => 'Одяг', 'slug' => 'admin-parent-clothes', 'is_active' => true]);

        Livewire::actingAs($admin)
            ->test(ListProductVariantGroups::class)
            ->mountAction(TestAction::make('discoverVariants'))
            ->fillForm(['category_id' => $parent->id])
            ->callMountedAction();

        Bus::assertDispatched(DiscoverProductVariantGroupsJob::class, function (DiscoverProductVariantGroupsJob $job) use ($admin, $parent): bool {
            return $job->categoryId === $parent->id
                && $job->userId === $admin->id
                && $job->includeInactive === true;
        });
    }

    public function test_admin_cannot_dispatch_discovery_on_sync_queue(): void
    {
        Bus::fake([DiscoverProductVariantGroupsJob::class]);
        config(['queue.default' => 'sync']);

        $admin = User::factory()->create(['is_admin' => true]);
        $parent = Category::create(['name' => 'Одяг', 'slug' => 'sync-parent-clothes', 'is_active' => true]);

        Livewire::actingAs($admin)
            ->test(ListProductVariantGroups::class)
            ->mountAction(TestAction::make('discoverVariants'))
            ->fillForm(['category_id' => $parent->id])
            ->callMountedAction();

        Bus::assertNotDispatched(DiscoverProductVariantGroupsJob::class);
    }

    public function test_discovery_job_creates_approved_variant_groups_including_inactive_products(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $parent = Category::create(['name' => 'Одяг', 'slug' => 'job-parent-clothes', 'is_active' => true]);
        $category = Category::create([
            'name' => 'Футболки',
            'slug' => 'job-group-shirts',
            'parent_id' => $parent->id,
            'is_active' => false,
        ]);

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
                'slug' => 'job-group-'.$externalId,
                'price' => 650,
                'stock' => 2,
                'is_active' => false,
                'specifications' => ['Международный размер' => $size],
            ]);
        }

        (new DiscoverProductVariantGroupsJob($parent->id, $admin->id, includeInactive: true))
            ->handle(app(\App\Services\ProductVariantGrouper::class));

        $group = ProductVariantGroup::query()->firstOrFail();

        $this->assertSame('approved', $group->status);
        $this->assertSame(2, $group->products()->count());
    }

    public function test_discovery_invalidates_catalog_cache_only_once(): void
    {
        $category = Category::create(['name' => 'Футболки', 'slug' => 'cache-once-shirts', 'is_active' => true]);

        foreach ([
            ['a1', 'Футболка A XL', 'XL'],
            ['a2', 'Футболка A L', 'L'],
            ['b1', 'Футболка B XL', 'XL'],
            ['b2', 'Футболка B L', 'L'],
        ] as [$externalId, $name, $size]) {
            Product::create([
                'category_id' => $category->id,
                'source' => 'pobedov',
                'external_id' => $externalId,
                'variant_id' => str_starts_with($externalId, 'a') ? 'pobedov-group-aaa' : 'pobedov-group-bbb',
                'name' => $name,
                'slug' => 'cache-once-'.$externalId,
                'price' => 650,
                'stock' => 2,
                'is_active' => true,
                'specifications' => ['Международный размер' => $size],
            ]);
        }

        $cache = Mockery::mock(CatalogCache::class);
        $cache->shouldReceive('invalidate')->once();
        $this->app->instance(CatalogCache::class, $cache);

        $result = app(\App\Services\ProductVariantGrouper::class)->discover($category, includeInactive: true);

        $this->assertSame(2, $result['created']);
        $this->assertSame(2, ProductVariantGroup::query()->count());
    }
}
