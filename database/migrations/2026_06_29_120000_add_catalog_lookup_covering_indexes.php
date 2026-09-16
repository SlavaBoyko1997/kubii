<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('products', 'products_catalog_visibility_category_idx')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->index(
                    ['is_active', 'is_visible_in_catalog', 'category_id'],
                    'products_catalog_visibility_category_idx'
                );
            });
        }

        if (! Schema::hasIndex('product_views_daily', 'product_views_recent_covering_idx')) {
            Schema::table('product_views_daily', function (Blueprint $table): void {
                $table->index(
                    ['viewed_on', 'product_id', 'views'],
                    'product_views_recent_covering_idx'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('products', 'products_catalog_visibility_category_idx')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropIndex('products_catalog_visibility_category_idx');
            });
        }

        if (Schema::hasIndex('product_views_daily', 'product_views_recent_covering_idx')) {
            Schema::table('product_views_daily', function (Blueprint $table): void {
                $table->dropIndex('product_views_recent_covering_idx');
            });
        }
    }
};
