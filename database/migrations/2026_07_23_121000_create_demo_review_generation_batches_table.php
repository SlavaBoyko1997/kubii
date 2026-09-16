<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_review_generation_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('queued');
            $table->unsignedInteger('products_count')->default(0);
            $table->unsignedInteger('requested_reviews_count')->default(0);
            $table->unsignedInteger('successful_reviews_count')->default(0);
            $table->unsignedInteger('failed_reviews_count')->default(0);
            $table->unsignedInteger('jobs_total')->default(0);
            $table->unsignedInteger('jobs_finished')->default(0);
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('estimated_cost', 10, 6)->default(0);
            $table->json('settings')->nullable();
            $table->json('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_review_generation_batches');
    }
};
