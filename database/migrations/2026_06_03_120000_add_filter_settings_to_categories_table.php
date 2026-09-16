<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->json('visible_filters')->nullable()->after('sort_order');
            $table->json('visible_spec_filters')->nullable()->after('visible_filters');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['visible_filters', 'visible_spec_filters']);
        });
    }
};
