<?php

namespace App\Console\Commands;

use App\Support\CatalogMergedCategoryPruner;
use Illuminate\Console\Command;

class PruneMergedCategories extends Command
{
    protected $signature = 'catalog:prune-merged-categories {--dry-run : Only list categories that would be deleted}';

    protected $description = 'Delete inactive catalog categories that were merged into another (target_category_id)';

    public function handle(CatalogMergedCategoryPruner $pruner): int
    {
        if ($this->option('dry-run')) {
            $ids = $pruner->preview();
            $this->info('Merge sources to delete: '.count($ids));

            foreach ($ids as $id) {
                $this->line((string) $id);
            }

            return self::SUCCESS;
        }

        $deleted = $pruner->prune();
        $this->info('Deleted merged categories: '.count($deleted));

        return self::SUCCESS;
    }
}
