<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->index('product_id', 'reviews_product_id_index');
            $table->dropUnique(['product_id', 'user_id']);
            $table->foreignId('parent_id')->nullable()->after('id')->constrained('reviews')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('reviews')->whereNotNull('parent_id')->delete();
        DB::statement('DELETE FROM reviews WHERE id NOT IN (SELECT MIN(id) FROM reviews GROUP BY product_id, user_id)');

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->unsignedTinyInteger('rating')->nullable(false)->change();
            $table->unique(['product_id', 'user_id']);
            $table->dropIndex('reviews_product_id_index');
        });
    }
};
