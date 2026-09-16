<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('counterparty_id')
                ->nullable()
                ->after('source')
                ->constrained()
                ->nullOnDelete();

            $table->dropUnique(['external_id']);
            $table->unique(['source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['source', 'external_id']);
            $table->unique('external_id');

            $table->dropConstrainedForeignId('counterparty_id');
        });
    }
};
