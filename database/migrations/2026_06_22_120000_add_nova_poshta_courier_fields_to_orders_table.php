<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('nova_poshta_street_ref')->nullable()->after('nova_poshta_warehouse_address');
            $table->string('nova_poshta_street_name')->nullable()->after('nova_poshta_street_ref');
            $table->string('nova_poshta_building')->nullable()->after('nova_poshta_street_name');
            $table->string('nova_poshta_flat')->nullable()->after('nova_poshta_building');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'nova_poshta_street_ref',
                'nova_poshta_street_name',
                'nova_poshta_building',
                'nova_poshta_flat',
            ]);
        });
    }
};
