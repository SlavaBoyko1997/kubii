<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_filter_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('filter_key', 120);
            $table->string('filter_value', 160)->nullable();
            $table->unsignedBigInteger('clicks')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'filter_key', 'filter_value'], 'catalog_filter_clicks_unique');
            $table->index(['category_id', 'filter_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_filter_clicks');
    }
};
