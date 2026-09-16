<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ProductPopularity
{
    private const SESSION_KEY = 'viewed_products';

    public function record(Product $product): void
    {
        $today = today()->toDateString();
        $viewedProducts = session()->get(self::SESSION_KEY, []);

        if (($viewedProducts[$product->id] ?? null) === $today) {
            return;
        }

        $updated = DB::table('product_views_daily')
            ->where('product_id', $product->id)
            ->where('viewed_on', $today)
            ->increment('views');

        if ($updated === 0) {
            try {
                DB::table('product_views_daily')->insert([
                    'product_id' => $product->id,
                    'viewed_on' => $today,
                    'views' => 1,
                ]);
            } catch (QueryException) {
                DB::table('product_views_daily')
                    ->where('product_id', $product->id)
                    ->where('viewed_on', $today)
                    ->increment('views');
            }
        }

        Product::query()->whereKey($product->id)->increment('monthly_views');

        $viewedProducts[$product->id] = $today;
        session()->put(self::SESSION_KEY, $viewedProducts);
    }
}
