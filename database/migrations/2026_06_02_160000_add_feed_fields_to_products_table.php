<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('source')->nullable()->index()->after('id');
            $table->string('external_id')->nullable()->unique()->after('source');
            $table->string('external_url')->nullable()->after('external_id');
            $table->decimal('sale_price', 10, 2)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['source', 'external_id', 'external_url', 'sale_price']);
        });
    }
};
