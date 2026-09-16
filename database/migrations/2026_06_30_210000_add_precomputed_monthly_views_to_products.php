<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedBigInteger('monthly_views')->default(0);
        });

        if (Schema::hasIndex('products', 'products_catalog_popular_v2_idx')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropIndex('products_catalog_popular_v2_idx');
            });
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->index(
                ['category_id', 'is_active', 'is_visible_in_catalog', 'monthly_views', 'is_featured', 'created_at', 'id'],
                'products_catalog_popular_v2_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_catalog_popular_v2_idx');
            $table->dropColumn('monthly_views');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->index(
                ['category_id', 'is_active', 'is_visible_in_catalog', 'is_featured', 'created_at', 'id'],
                'products_catalog_popular_v2_idx',
            );
        });
    }
};
