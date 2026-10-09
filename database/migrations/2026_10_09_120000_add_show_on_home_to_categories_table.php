<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('categories') || Schema::hasColumn('categories', 'show_on_home')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table): void {
            $table->boolean('show_on_home')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('categories') || ! Schema::hasColumn('categories', 'show_on_home')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('show_on_home');
        });
    }
};
