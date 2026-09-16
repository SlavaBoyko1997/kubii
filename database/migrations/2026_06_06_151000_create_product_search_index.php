<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement("CREATE VIRTUAL TABLE product_search USING fts5(
            product_id UNINDEXED,
            name,
            brand,
            model,
            sku,
            tokenize = 'unicode61 remove_diacritics 2'
        )");

        DB::statement("INSERT INTO product_search (product_id, name, brand, model, sku)
            SELECT id, name, COALESCE(brand, ''), COALESCE(model, ''), COALESCE(sku, '')
            FROM products");

        DB::unprepared("
            CREATE TRIGGER products_search_insert AFTER INSERT ON products BEGIN
                INSERT INTO product_search (product_id, name, brand, model, sku)
                VALUES (new.id, new.name, COALESCE(new.brand, ''), COALESCE(new.model, ''), COALESCE(new.sku, ''));
            END;

            CREATE TRIGGER products_search_delete AFTER DELETE ON products BEGIN
                DELETE FROM product_search WHERE product_id = old.id;
            END;

            CREATE TRIGGER products_search_update AFTER UPDATE OF name, brand, model, sku ON products BEGIN
                DELETE FROM product_search WHERE product_id = old.id;
                INSERT INTO product_search (product_id, name, brand, model, sku)
                VALUES (new.id, new.name, COALESCE(new.brand, ''), COALESCE(new.model, ''), COALESCE(new.sku, ''));
            END;
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::unprepared('
            DROP TRIGGER IF EXISTS products_search_insert;
            DROP TRIGGER IF EXISTS products_search_delete;
            DROP TRIGGER IF EXISTS products_search_update;
            DROP TABLE IF EXISTS product_search;
        ');
    }
};
