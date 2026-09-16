<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['specifications', 'specifications_ru'] as $column) {
            DB::table('products')
                ->whereNotNull($column)
                ->update([
                    $column => DB::raw(
                        "JSON_REMOVE({$column}, '$.\"Код товару\"', '$.\"Код товара\"')"
                    ),
                ]);
        }
    }

    public function down(): void
    {
        //
    }
};
