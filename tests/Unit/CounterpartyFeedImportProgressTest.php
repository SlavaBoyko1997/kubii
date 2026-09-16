<?php

namespace Tests\Unit;

use App\Support\CounterpartyFeedImportProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CounterpartyFeedImportProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_progress_moves_from_queued_to_completed(): void
    {
        $progress = app(CounterpartyFeedImportProgress::class);

        $progress->start(7);
        $this->assertTrue($progress->isRunning(7));

        $progress->markRunning(7);
        $progress->update(7, 'Імпорт товарів', 500, 1000, ['products_imported' => 500]);

        $status = $progress->status(7);
        $this->assertSame('running', $status['state']);
        $this->assertSame(500, $status['current']);
        $this->assertGreaterThan(18, $status['percent']);

        $progress->complete(7, [
            'products' => 1000,
            'categories' => 42,
            'variant_groups_created' => 15,
        ]);

        $completed = $progress->status(7);
        $this->assertSame('completed', $completed['state']);
        $this->assertSame(100, $completed['percent']);
        $this->assertSame(1000, $completed['products_imported']);
        $this->assertFalse($progress->isRunning(7));
    }

    public function test_progress_failure_keeps_message(): void
    {
        $progress = app(CounterpartyFeedImportProgress::class);
        $progress->start(9);
        $progress->fail(9, 'XML parse error');

        $status = $progress->status(9);

        $this->assertSame('failed', $status['state']);
        $this->assertSame('XML parse error', $status['message']);
    }

    public function test_stale_queued_import_is_marked_failed(): void
    {
        $progress = app(CounterpartyFeedImportProgress::class);
        $progress->start(5);

        Cache::put($progress->key(5), [
            ...($progress->status(5) ?? []),
            'started_at' => now()->subMinutes(2)->toIso8601String(),
        ], now()->addHour());

        $failed = $progress->failStaleQueued(5);

        $this->assertNotNull($failed);
        $this->assertSame('failed', $failed['state']);
        $this->assertFalse($progress->isRunning(5));
    }
}
