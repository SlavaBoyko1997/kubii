<?php

namespace App\Console\Commands;

use App\Models\DemoReviewGenerationBatch;
use App\Models\Review;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearDemoProductReviews extends Command
{
    protected $signature = 'demo-reviews:clear {--batch= : Clear only one generation_batch_id} {--force : Do not ask confirmation}';

    protected $description = 'Delete AI demo product reviews.';

    public function handle(): int
    {
        $batchId = $this->option('batch');
        $query = Review::query()->where('is_demo', true)->where('is_ai_generated', true);

        if ($batchId) {
            $query->where('generation_batch_id', $batchId);
        }

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('No demo reviews found.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Delete {$count} demo reviews?")) {
            return self::FAILURE;
        }

        DB::transaction(function () use ($query, $batchId): void {
            $query->get()->each->delete();

            if ($batchId) {
                DemoReviewGenerationBatch::query()->whereKey($batchId)->delete();
            }
        });

        $this->info("Deleted {$count} demo reviews.");

        return self::SUCCESS;
    }
}
