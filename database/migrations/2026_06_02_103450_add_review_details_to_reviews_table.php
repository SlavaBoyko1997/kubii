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
        Schema::table('reviews', function (Blueprint $table) {
            $table->text('pros')->nullable()->after('body');
            $table->text('cons')->nullable()->after('pros');
            $table->boolean('is_verified_purchase')->default(false)->after('cons');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['pros', 'cons', 'is_verified_purchase']);
        });
    }
};
