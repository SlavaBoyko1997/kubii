<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->index(
                ['category_id', 'is_active', 'is_visible_in_catalog', 'season_ru'],
                'products_catalog_season_ru_v2_idx'
            );
            $table->index(
                ['category_id', 'is_active', 'is_visible_in_catalog', 'usage_type_ru'],
                'products_catalog_usage_ru_v2_idx'
            );
            $table->index(
                ['category_id', 'is_active', 'is_visible_in_catalog', 'material_ru'],
                'products_catalog_material_ru_v2_idx'
            );
            $table->index(
                ['category_id', 'is_active', 'is_visible_in_catalog', 'is_featured', 'created_at', 'id'],
                'products_catalog_popular_v2_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_catalog_season_ru_v2_idx');
            $table->dropIndex('products_catalog_usage_ru_v2_idx');
            $table->dropIndex('products_catalog_material_ru_v2_idx');
            $table->dropIndex('products_catalog_popular_v2_idx');
        });
    }
};
