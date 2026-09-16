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
                ['is_active', 'discount_percent', 'id'],
                'products_home_sale_idx'
            );
            $table->index(
                ['is_active', 'is_featured', 'id'],
                'products_home_featured_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_home_sale_idx');
            $table->dropIndex('products_home_featured_idx');
        });
    }
};
