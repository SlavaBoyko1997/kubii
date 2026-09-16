<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('counterparty_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();
            $table->string('external_id')->nullable()->after('counterparty_id');
            $table->unique(['counterparty_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['counterparty_id', 'external_id']);
            $table->dropConstrainedForeignId('counterparty_id');
            $table->dropColumn('external_id');
        });
    }
};
