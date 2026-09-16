<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index(['category_id', 'is_active', 'brand'], 'products_catalog_brand_idx');
            $table->index(['category_id', 'is_active', 'model'], 'products_catalog_model_idx');
            $table->index(['category_id', 'is_active', 'stock', 'price'], 'products_catalog_stock_price_idx');
            $table->index(['category_id', 'is_active', 'discount_percent'], 'products_catalog_sale_idx');
            $table->index(['variant_group_id', 'is_active', 'is_primary_variant'], 'products_variant_catalog_idx');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_catalog_brand_idx');
            $table->dropIndex('products_catalog_model_idx');
            $table->dropIndex('products_catalog_stock_price_idx');
            $table->dropIndex('products_catalog_sale_idx');
            $table->dropIndex('products_variant_catalog_idx');
        });
    }
};
