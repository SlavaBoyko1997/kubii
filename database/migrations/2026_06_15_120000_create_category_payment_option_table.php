<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_payment_option', function (Blueprint $table): void {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_option_id')->constrained()->cascadeOnDelete();
            $table->primary(['category_id', 'payment_option_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_payment_option');
    }
};
