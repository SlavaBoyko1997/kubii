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
        Schema::table('products', function (Blueprint $table) {
            $table->string('model')->nullable()->index()->after('brand');
            $table->string('season')->nullable()->index()->after('model');
            $table->string('usage_type')->nullable()->index()->after('season');
            $table->string('material')->nullable()->index()->after('usage_type');
            $table->unsignedInteger('weight_grams')->nullable()->index()->after('material');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['model', 'season', 'usage_type', 'material', 'weight_grams']);
        });
    }
};
