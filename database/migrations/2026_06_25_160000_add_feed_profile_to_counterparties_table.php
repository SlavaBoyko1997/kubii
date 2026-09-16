<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('counterparties', function (Blueprint $table): void {
            $table->string('feed_profile')->default('standard')->after('feed_format');
        });
    }

    public function down(): void
    {
        Schema::table('counterparties', function (Blueprint $table): void {
            $table->dropColumn('feed_profile');
        });
    }
};
