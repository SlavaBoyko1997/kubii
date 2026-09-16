<?php

namespace Tests\Feature;

use App\Models\CatalogCacheLog;
use App\Models\Counterparty;
use App\Models\CounterpartyFeedSyncLog;
use App\Support\CatalogCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CatalogCacheLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_warm_success_creates_log_entry(): void
    {
        $this->artisan('catalog:cache-warm', [
            '--fresh' => true,
            '--trigger' => CatalogCacheLog::TRIGGER_CLI,
        ])->assertSuccessful();

        $log = CatalogCacheLog::query()->firstOrFail();

        $this->assertSame(CatalogCacheLog::ACTION_WARM, $log->action);
        $this->assertSame(CatalogCacheLog::TRIGGER_CLI, $log->trigger);
        $this->assertSame(CatalogCacheLog::STATUS_SUCCESS, $log->status);
        $this->assertTrue($log->fresh);
        $this->assertGreaterThan(0, (int) $log->steps_total);
        $this->assertNull($log->error_message);
    }

    public function test_warm_lock_failure_creates_log_entry(): void
    {
        $progress = app(\App\Support\CatalogCacheWarmProgress::class);
        $progress->start('blocked-run');
        Cache::lock('catalog:cache-warm:lock', 1800)->get();

        $this->artisan('catalog:cache-warm', [
            '--fresh' => true,
            '--trigger' => CatalogCacheLog::TRIGGER_MANUAL,
        ])->assertFailed();

        $log = CatalogCacheLog::query()->firstOrFail();

        $this->assertSame(CatalogCacheLog::ACTION_WARM, $log->action);
        $this->assertSame(CatalogCacheLog::TRIGGER_MANUAL, $log->trigger);
        $this->assertSame(CatalogCacheLog::STATUS_FAILED, $log->status);
        $this->assertStringContainsString('вже виконується', (string) $log->error_message);
    }

    public function test_invalidate_and_log_creates_clear_entry(): void
    {
        app(CatalogCache::class)->invalidateAndLog(
            CatalogCacheLog::TRIGGER_SYSTEM,
            'Тестове очищення',
        );

        $log = CatalogCacheLog::query()->firstOrFail();

        $this->assertSame(CatalogCacheLog::ACTION_CLEAR, $log->action);
        $this->assertSame(CatalogCacheLog::TRIGGER_SYSTEM, $log->trigger);
        $this->assertSame(CatalogCacheLog::STATUS_SUCCESS, $log->status);
        $this->assertSame('Тестове очищення', $log->reason);
    }

    public function test_feed_import_logs_cache_clear(): void
    {
        $counterparty = Counterparty::query()->updateOrCreate(
            ['slug' => 'ranger'],
            [
                'name' => 'Ranger',
                'feed_format' => 'xml',
                'feed_profile' => 'ranger',
            ],
        );

        $this->artisan('counterparties:sync-feeds', [
            'counterparty' => $counterparty->slug,
            '--file' => base_path('tests/Fixtures/ranger-feed.xml'),
            '--trigger' => CounterpartyFeedSyncLog::TRIGGER_MANUAL,
        ])->assertSuccessful();

        $clearLog = CatalogCacheLog::query()
            ->where('action', CatalogCacheLog::ACTION_CLEAR)
            ->firstOrFail();

        $this->assertSame(CatalogCacheLog::TRIGGER_MANUAL, $clearLog->trigger);
        $this->assertSame('Імпорт фіду: Ranger', $clearLog->reason);
    }
}
