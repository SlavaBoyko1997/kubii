<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variant_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('primary_product_id')->nullable()->index();
            $table->string('brand')->nullable()->index();
            $table->string('title');
            $table->string('group_key')->unique();
            $table->string('grouping_level')->default('exact_model')->index();
            $table->unsignedTinyInteger('confidence')->default(0)->index();
            $table->json('variant_option_keys')->nullable();
            $table->json('secondary_spec_keys')->nullable();
            $table->json('candidate_summary')->nullable();
            $table->string('status')->default('approved')->index();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_groups');
    }
};
