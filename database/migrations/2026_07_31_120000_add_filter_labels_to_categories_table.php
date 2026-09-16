<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->json('filter_labels')->nullable()->after('visible_spec_filters_ru');
            $table->json('filter_labels_ru')->nullable()->after('filter_labels');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn(['filter_labels', 'filter_labels_ru']);
        });
    }
};
