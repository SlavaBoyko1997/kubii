<?php

namespace Tests\Feature;

use App\Models\Counterparty;
use App\Models\CounterpartyFeedSyncLog;
use App\Services\CounterpartyFeedImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CounterpartyFeedSyncLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_cli_import_creates_sync_log_entry(): void
    {
        $counterparty = Counterparty::query()->updateOrCreate(
            ['slug' => 'ranger'],
            [
                'name' => 'Ranger',
                'feed_format' => 'xml',
                'feed_profile' => 'ranger',
            ],
        );

        $this->mock(CounterpartyFeedImporter::class, function ($mock) use ($counterparty): void {
            $mock->shouldReceive('withCacheClearTrigger')
                ->once()
                ->andReturnSelf();
            $mock->shouldReceive('importFile')
                ->once()
                ->andReturn([
                    'products' => 5,
                    'categories' => 3,
                    'variant_groups_created' => 1,
                    'variant_groups_skipped' => 0,
                ]);
        });

        $this->artisan('counterparties:sync-feeds', [
            'counterparty' => $counterparty->slug,
            '--file' => base_path('tests/Fixtures/ranger-feed.xml'),
            '--trigger' => CounterpartyFeedSyncLog::TRIGGER_MANUAL,
        ])->assertSuccessful();

        $log = CounterpartyFeedSyncLog::query()->firstOrFail();

        $this->assertSame($counterparty->id, $log->counterparty_id);
        $this->assertSame(CounterpartyFeedSyncLog::TRIGGER_MANUAL, $log->trigger);
        $this->assertSame(CounterpartyFeedSyncLog::STATUS_SUCCESS, $log->status);
        $this->assertSame(5, $log->products_count);
        $this->assertSame(3, $log->categories_count);
        $this->assertSame(1, $log->variant_groups_created);
        $this->assertNull($log->error_message);
    }

    public function test_failed_import_creates_error_log_entry(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Broken',
            'slug' => 'broken-feed',
            'feed_url' => 'https://example.test/feed.xml',
            'feed_format' => 'xml',
            'feed_profile' => 'standard',
        ]);

        $this->mock(CounterpartyFeedImporter::class, function ($mock): void {
            $mock->shouldReceive('withCacheClearTrigger')
                ->once()
                ->andReturnSelf();
            $mock->shouldReceive('import')
                ->once()
                ->andThrow(new \RuntimeException('Feed download failed'));
        });

        $this->artisan('counterparties:sync-feeds', [
            'counterparty' => 'broken-feed',
            '--trigger' => CounterpartyFeedSyncLog::TRIGGER_AUTO,
        ])->assertFailed();

        $log = CounterpartyFeedSyncLog::query()->firstOrFail();

        $this->assertSame(CounterpartyFeedSyncLog::TRIGGER_AUTO, $log->trigger);
        $this->assertSame(CounterpartyFeedSyncLog::STATUS_FAILED, $log->status);
        $this->assertSame('Feed download failed', $log->error_message);
    }
}
