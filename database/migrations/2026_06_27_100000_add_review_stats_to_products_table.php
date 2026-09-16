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
            $table->unsignedInteger('reviews_count')->default(0)->after('is_processed');
            $table->decimal('reviews_avg_rating', 3, 2)->nullable()->after('reviews_count');
        });

        DB::statement(<<<'SQL'
            UPDATE products
            SET
                reviews_count = COALESCE((
                    SELECT COUNT(*)
                    FROM reviews
                    WHERE reviews.product_id = products.id
                      AND reviews.parent_id IS NULL
                      AND reviews.is_visible = 1
                ), 0),
                reviews_avg_rating = (
                    SELECT ROUND(AVG(reviews.rating), 2)
                    FROM reviews
                    WHERE reviews.product_id = products.id
                      AND reviews.parent_id IS NULL
                      AND reviews.is_visible = 1
                )
        SQL);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['reviews_count', 'reviews_avg_rating']);
        });
    }
};
