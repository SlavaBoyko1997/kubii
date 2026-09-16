<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('delivery_type')->nullable()->after('delivery_address');
            $table->string('nova_poshta_city_ref')->nullable()->after('delivery_type');
            $table->string('nova_poshta_city_name')->nullable()->after('nova_poshta_city_ref');
            $table->string('nova_poshta_warehouse_ref')->nullable()->after('nova_poshta_city_name');
            $table->string('nova_poshta_warehouse_name')->nullable()->after('nova_poshta_warehouse_ref');
            $table->string('nova_poshta_warehouse_number')->nullable()->after('nova_poshta_warehouse_name');
            $table->string('nova_poshta_warehouse_address')->nullable()->after('nova_poshta_warehouse_number');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'delivery_type',
                'nova_poshta_city_ref',
                'nova_poshta_city_name',
                'nova_poshta_warehouse_ref',
                'nova_poshta_warehouse_name',
                'nova_poshta_warehouse_number',
                'nova_poshta_warehouse_address',
            ]);
        });
    }
};
