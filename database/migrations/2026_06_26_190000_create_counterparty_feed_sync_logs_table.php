<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counterparty_feed_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('counterparty_id')->constrained()->cascadeOnDelete();
            $table->string('trigger', 20);
            $table->string('status', 20);
            $table->unsignedInteger('products_count')->nullable();
            $table->unsignedInteger('categories_count')->nullable();
            $table->unsignedInteger('variant_groups_created')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->timestamps();

            $table->index(['counterparty_id', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counterparty_feed_sync_logs');
    }
};
