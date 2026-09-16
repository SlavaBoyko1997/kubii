<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('name_ru')->nullable()->after('name');
            $table->string('slug_ru')->nullable()->unique()->after('slug');
            $table->string('seo_title')->nullable()->after('image_path');
            $table->string('seo_title_ru')->nullable()->after('seo_title');
            $table->text('meta_description')->nullable()->after('seo_title_ru');
            $table->text('meta_description_ru')->nullable()->after('meta_description');
            $table->string('h1')->nullable()->after('meta_description_ru');
            $table->string('h1_ru')->nullable()->after('h1');
            $table->text('description')->nullable()->after('h1_ru');
            $table->text('description_ru')->nullable()->after('description');
            $table->json('visible_spec_filters_ru')->nullable()->after('visible_spec_filters');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('name_ru')->nullable()->after('name');
            $table->string('slug_ru')->nullable()->unique()->after('slug');
            $table->text('description_ru')->nullable()->after('description');
            $table->longText('content_ru')->nullable()->after('content');
            $table->string('seo_title_ru')->nullable()->after('seo_title');
            $table->text('meta_description_ru')->nullable()->after('meta_description');
            $table->string('h1_ru')->nullable()->after('h1');
            $table->string('season_ru')->nullable()->after('season');
            $table->string('usage_type_ru')->nullable()->after('usage_type');
            $table->string('material_ru')->nullable()->after('material');
            $table->json('specifications_ru')->nullable()->after('specifications');
            $table->json('variant_options_ru')->nullable()->after('variant_options');
            $table->json('variant_secondary_specs_ru')->nullable()->after('variant_secondary_specs');
        });

        DB::table('categories')->whereNull('slug_ru')->update([
            'slug_ru' => DB::raw('slug'),
        ]);
        DB::table('products')->whereNull('slug_ru')->update([
            'slug_ru' => DB::raw('slug'),
        ]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'name_ru',
                'slug_ru',
                'description_ru',
                'content_ru',
                'seo_title_ru',
                'meta_description_ru',
                'h1_ru',
                'season_ru',
                'usage_type_ru',
                'material_ru',
                'specifications_ru',
                'variant_options_ru',
                'variant_secondary_specs_ru',
            ]);
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn([
                'name_ru',
                'slug_ru',
                'seo_title',
                'seo_title_ru',
                'meta_description',
                'meta_description_ru',
                'h1',
                'h1_ru',
                'description',
                'description_ru',
                'visible_spec_filters_ru',
            ]);
        });
    }
};
