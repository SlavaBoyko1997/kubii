<?php

namespace Tests\Feature;

use App\Jobs\ImportCounterpartyFeedJob;
use App\Models\Counterparty;
use App\Services\CounterpartyFeedImporter;
use App\Services\VoltmarketFeedImporter;
use App\Support\CounterpartyFeedImportProgress;
use App\Support\CounterpartyFeedSyncLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImportCounterpartyFeedJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_imports_feed_and_marks_progress_completed(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Camotec Gurt',
            'slug' => 'camotec',
            'feed_format' => 'xml',
            'feed_profile' => 'camotec',
        ]);

        $progress = app(CounterpartyFeedImportProgress::class);
        $progress->start($counterparty->id);

        $this->mock(CounterpartyFeedImporter::class, function ($mock) use ($counterparty): void {
            $mock->shouldReceive('trackProgressFor')
                ->once()
                ->with($counterparty->id)
                ->andReturnSelf();
            $mock->shouldReceive('import')
                ->once()
                ->withArgs(fn (Counterparty $record): bool => $record->is($counterparty))
                ->andReturn([
                    'products' => 12,
                    'categories' => 3,
                    'variant_groups_created' => 2,
                    'variant_groups_skipped' => 0,
                ]);
        });

        (new ImportCounterpartyFeedJob($counterparty->id))->handle(
            app(CounterpartyFeedImporter::class),
            app(VoltmarketFeedImporter::class),
            app(CounterpartyFeedImportProgress::class),
            app(CounterpartyFeedSyncLogger::class),
        );

        $status = $progress->status($counterparty->id);

        $this->assertSame('completed', $status['state']);
        $this->assertSame(100, $status['percent']);
        $this->assertSame(12, $status['products_imported']);
        $this->assertFalse($progress->isRunning($counterparty->id));
    }

    public function test_job_marks_progress_failed_after_exception(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Pobedov',
            'slug' => 'pobedov',
            'feed_format' => 'yml',
        ]);

        $progress = app(CounterpartyFeedImportProgress::class);
        $progress->start($counterparty->id);

        $this->mock(CounterpartyFeedImporter::class, function ($mock) use ($counterparty): void {
            $mock->shouldReceive('trackProgressFor')
                ->once()
                ->with($counterparty->id)
                ->andReturnSelf();
            $mock->shouldReceive('import')->once()->andThrow(new \RuntimeException('Feed failed'));
        });

        try {
            (new ImportCounterpartyFeedJob($counterparty->id))->handle(
                app(CounterpartyFeedImporter::class),
                app(VoltmarketFeedImporter::class),
                app(CounterpartyFeedImportProgress::class),
                app(CounterpartyFeedSyncLogger::class),
            );
        } catch (\RuntimeException) {
        }

        $status = $progress->status($counterparty->id);

        $this->assertSame('failed', $status['state']);
        $this->assertSame('Feed failed', $status['message']);
        $this->assertFalse($progress->isRunning($counterparty->id));
    }

    public function test_edit_counterparty_dispatches_feed_import_job(): void
    {
        Queue::fake();

        $counterparty = Counterparty::create([
            'name' => 'Camotec Gurt',
            'slug' => 'camotec',
            'feed_url' => 'https://gurt.camotec.ua/offer-list-yml.xml',
            'feed_format' => 'xml',
            'feed_profile' => 'camotec',
        ]);

        ImportCounterpartyFeedJob::dispatch($counterparty->id);

        Queue::assertPushed(ImportCounterpartyFeedJob::class, function (ImportCounterpartyFeedJob $job) use ($counterparty): bool {
            return $job->counterpartyId === $counterparty->id;
        });
    }

    public function test_sync_command_with_progress_completes_status(): void
    {
        $counterparty = Counterparty::create([
            'name' => 'Atlant Market',
            'slug' => 'atlantmarket',
            'feed_format' => 'xml',
            'feed_profile' => 'atlantmarket',
        ]);

        $progress = app(CounterpartyFeedImportProgress::class);
        $progress->start($counterparty->id);

        $this->artisan('counterparties:sync-feeds', [
            'counterparty' => 'atlantmarket',
            '--file' => base_path('tests/Fixtures/atlantmarket-feed.xml'),
            '--with-progress' => true,
            '--counterparty-id' => $counterparty->id,
        ])->assertSuccessful();

        $status = $progress->status($counterparty->id);

        $this->assertSame('completed', $status['state']);
        $this->assertSame(100, $status['percent']);
        $this->assertSame(3, $status['products_imported']);
    }
}
