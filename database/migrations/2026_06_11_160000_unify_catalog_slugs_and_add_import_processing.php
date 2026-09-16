<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('is_processed')->default(true)->index()->after('is_active');
        });

        DB::table('categories')->update(['slug_ru' => DB::raw('slug')]);
        DB::table('products')->update(['slug_ru' => DB::raw('slug')]);

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE products ADD FULLTEXT INDEX products_search_fulltext_ru (name_ru, name, brand, model, sku)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products DROP INDEX products_search_fulltext_ru');
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('is_processed');
        });
    }
};
