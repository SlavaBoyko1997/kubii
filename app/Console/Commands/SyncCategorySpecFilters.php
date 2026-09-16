<?php

namespace App\Console\Commands;

use App\Support\CategorySpecFilterSynchronizer;
use Illuminate\Console\Command;

class SyncCategorySpecFilters extends Command
{
    protected $signature = 'categories:sync-spec-filters {--all : Sync every category} {category? : Category ID}';

    protected $description = 'Merge product specification keys into category visible spec filters.';

    public function handle(CategorySpecFilterSynchronizer $synchronizer): int
    {
        if ($this->option('all')) {
            $updated = $synchronizer->syncAll();

            $this->components->info("Updated {$updated} categories.");

            return self::SUCCESS;
        }

        $categoryId = $this->argument('category');

        if ($categoryId === null) {
            $this->components->error('Provide a category ID or use --all.');

            return self::FAILURE;
        }

        $updated = $synchronizer->syncByCategoryIds([(int) $categoryId]);

        $this->components->info($updated > 0
            ? 'Category filters updated.'
            : 'Nothing to update for this category.');

        return self::SUCCESS;
    }
}
