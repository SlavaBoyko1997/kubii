<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columnAdded = ! Schema::hasColumn('products', 'is_visible_in_catalog');

        if ($columnAdded) {
            Schema::table('products', function (Blueprint $table): void {
                $table->boolean('is_visible_in_catalog')->default(true)->after('is_active');
            });
        }

        if ($columnAdded && DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                UPDATE products
                LEFT JOIN product_variant_groups ON product_variant_groups.id = products.variant_group_id
                SET products.is_visible_in_catalog = (
                    products.variant_group_id IS NULL
                    OR products.is_primary_variant = 1
                    OR product_variant_groups.status NOT IN ('approved', 'published')
                )
            SQL);
        }

        $indexes = [
            'products_catalog_brand_v2_idx' => ['category_id', 'is_active', 'is_visible_in_catalog', 'brand'],
            'products_catalog_model_v2_idx' => ['category_id', 'is_active', 'is_visible_in_catalog', 'model'],
            'products_catalog_season_v2_idx' => ['category_id', 'is_active', 'is_visible_in_catalog', 'season'],
            'products_catalog_usage_v2_idx' => ['category_id', 'is_active', 'is_visible_in_catalog', 'usage_type'],
            'products_catalog_material_v2_idx' => ['category_id', 'is_active', 'is_visible_in_catalog', 'material'],
            'products_catalog_weight_v2_idx' => ['category_id', 'is_active', 'is_visible_in_catalog', 'weight_grams'],
            'products_catalog_stock_price_v2_idx' => ['category_id', 'is_active', 'is_visible_in_catalog', 'stock', 'price'],
            'products_catalog_sale_v2_idx' => ['category_id', 'is_active', 'is_visible_in_catalog', 'discount_percent'],
        ];

        foreach ($indexes as $name => $columns) {
            if (Schema::hasIndex('products', $name)) {
                continue;
            }

            Schema::table('products', function (Blueprint $table) use ($columns, $name): void {
                $table->index($columns, $name);
            });
        }

        foreach ([
            'products_catalog_brand_idx',
            'products_catalog_model_idx',
            'products_catalog_stock_price_idx',
            'products_catalog_sale_idx',
        ] as $name) {
            if (! Schema::hasIndex('products', $name)) {
                continue;
            }

            Schema::table('products', function (Blueprint $table) use ($name): void {
                $table->dropIndex($name);
            });
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->index(
                ['category_id', 'is_active', 'is_visible_in_catalog', 'brand'],
                'products_catalog_brand_rollback_idx'
            );
        });

        foreach ([
            'products_catalog_brand_v2_idx',
            'products_catalog_model_v2_idx',
            'products_catalog_season_v2_idx',
            'products_catalog_usage_v2_idx',
            'products_catalog_material_v2_idx',
            'products_catalog_weight_v2_idx',
            'products_catalog_stock_price_v2_idx',
            'products_catalog_sale_v2_idx',
        ] as $name) {
            if (Schema::hasIndex('products', $name)) {
                Schema::table('products', function (Blueprint $table) use ($name): void {
                    $table->dropIndex($name);
                });
            }
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->index(
                ['category_id', 'is_active', 'brand'],
                'products_catalog_brand_idx'
            );
            $table->index(
                ['category_id', 'is_active', 'model'],
                'products_catalog_model_idx'
            );
            $table->index(
                ['category_id', 'is_active', 'stock', 'price'],
                'products_catalog_stock_price_idx'
            );
            $table->index(
                ['category_id', 'is_active', 'discount_percent'],
                'products_catalog_sale_idx'
            );
            $table->dropIndex('products_catalog_brand_rollback_idx');
            $table->dropColumn('is_visible_in_catalog');
        });
    }
};
