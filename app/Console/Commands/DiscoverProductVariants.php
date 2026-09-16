<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Services\ProductVariantGrouper;
use Illuminate\Console\Command;

class DiscoverProductVariants extends Command
{
    protected $signature = 'variants:discover {--category= : Category ID to scan}';

    protected $description = 'Discover smart product variant group suggestions for admin review. Category option includes all subcategories.';

    public function handle(ProductVariantGrouper $grouper): int
    {
        @set_time_limit(0);

        $category = $this->option('category')
            ? Category::query()->findOrFail((int) $this->option('category'))
            : null;

        $result = $grouper->discover(
            $category,
            function (string $categoryName, int $created, int $skipped, int $categoriesScanned): void {
                $this->line("#{$categoriesScanned} {$categoryName}: {$created} suggestions, {$skipped} skipped");
            },
            includeInactive: true,
        );

        $this->newLine();
        $this->info(number_format($result['created']).' variant group suggestions saved, '.number_format($result['skipped']).' skipped.');

        return self::SUCCESS;
    }
}
