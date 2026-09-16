<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_interactions', function (Blueprint $table) {
            $table->id();
            $table->string('query_hash', 40);
            $table->string('normalized_query', 160);
            $table->string('result_type', 20);
            $table->unsignedBigInteger('result_id');
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamp('last_clicked_at')->nullable();
            $table->timestamps();

            $table->unique(['query_hash', 'result_type', 'result_id'], 'search_interactions_result_unique');
            $table->index(['query_hash', 'clicks'], 'search_interactions_rank_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_interactions');
    }
};
