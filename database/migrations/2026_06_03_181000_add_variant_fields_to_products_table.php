<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('variant_group_id')->nullable()->after('category_id')->constrained('product_variant_groups')->nullOnDelete();
            $table->string('variant_id')->nullable()->after('external_id')->index();
            $table->json('variant_options')->nullable()->after('specifications');
            $table->json('variant_secondary_specs')->nullable()->after('variant_options');
            $table->boolean('is_primary_variant')->default(false)->after('variant_secondary_specs')->index();
            $table->string('seo_title')->nullable()->after('content');
            $table->text('meta_description')->nullable()->after('seo_title');
            $table->string('h1')->nullable()->after('meta_description');
            $table->boolean('is_indexable')->default(true)->after('h1')->index();
            $table->string('canonical_type')->default('self')->after('is_indexable');
            $table->foreignId('canonical_product_id')->nullable()->after('canonical_type')->constrained('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('variant_group_id');
            $table->dropConstrainedForeignId('canonical_product_id');
            $table->dropColumn([
                'variant_id',
                'variant_options',
                'variant_secondary_specs',
                'is_primary_variant',
                'seo_title',
                'meta_description',
                'h1',
                'is_indexable',
                'canonical_type',
            ]);
        });
    }
};
