<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_cache_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('action', 20);
            $table->string('trigger', 20);
            $table->string('status', 20);
            $table->string('reason')->nullable();
            $table->boolean('fresh')->default(false);
            $table->unsignedInteger('steps_completed')->nullable();
            $table->unsignedInteger('steps_total')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->timestamps();

            $table->index('finished_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_cache_logs');
    }
};
