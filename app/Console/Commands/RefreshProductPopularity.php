<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshProductPopularity extends Command
{
    protected $signature = 'catalog:refresh-popularity';

    protected $description = 'Precompute product views from the last thirty days for fast catalog sorting';

    public function handle(): int
    {
        $from = today()->subDays(29)->toDateString();
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::update(
                'UPDATE products
                 LEFT JOIN (
                    SELECT product_id, SUM(views) AS monthly_views
                    FROM product_views_daily
                    WHERE viewed_on >= ?
                    GROUP BY product_id
                 ) AS recent_views ON recent_views.product_id = products.id
                 SET products.monthly_views = COALESCE(recent_views.monthly_views, 0)',
                [$from],
            );
        } else {
            DB::transaction(function () use ($from): void {
                Product::query()->where('monthly_views', '!=', 0)->update(['monthly_views' => 0]);

                DB::table('product_views_daily')
                    ->selectRaw('product_id, SUM(views) AS monthly_views')
                    ->where('viewed_on', '>=', $from)
                    ->groupBy('product_id')
                    ->orderBy('product_id')
                    ->each(function (object $row): void {
                        Product::query()->whereKey($row->product_id)->update([
                            'monthly_views' => (int) $row->monthly_views,
                        ]);
                    });
            });
        }

        $this->info('Product popularity refreshed for the last thirty days.');

        return self::SUCCESS;
    }
}
