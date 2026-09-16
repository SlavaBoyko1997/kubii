<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('order_type')->default('standard')->after('number');
            $table->string('customer_name')->nullable()->change();
            $table->string('city')->nullable()->change();
            $table->string('delivery_address')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('order_type');
            $table->string('customer_name')->nullable(false)->change();
            $table->string('city')->nullable(false)->change();
            $table->string('delivery_address')->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
