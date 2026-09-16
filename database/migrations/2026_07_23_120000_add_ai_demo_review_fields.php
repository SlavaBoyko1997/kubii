<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_demo')->default(false)->after('is_guest');
            $table->json('demo_metadata')->nullable()->after('is_demo');
        });

        Schema::table('reviews', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('products')->nullOnDelete();
            $table->boolean('is_demo')->default(false)->after('is_visible');
            $table->boolean('is_ai_generated')->default(false)->after('is_demo');
            $table->string('source')->nullable()->after('is_ai_generated');
            $table->string('environment')->nullable()->after('source');
            $table->uuid('generation_batch_id')->nullable()->after('environment');
            $table->string('model')->nullable()->after('generation_batch_id');
            $table->string('prompt_version')->nullable()->after('model');
            $table->string('input_hash', 64)->nullable()->after('prompt_version');
            $table->json('metadata')->nullable()->after('input_hash');
            $table->timestamp('review_date')->nullable()->after('metadata');
            $table->timestamp('generated_at')->nullable()->after('review_date');

            $table->index(['generation_batch_id', 'is_demo'], 'reviews_demo_batch_index');
            $table->index(['product_id', 'is_demo', 'is_ai_generated'], 'reviews_product_demo_ai_index');
            $table->unique(['product_id', 'input_hash'], 'reviews_product_input_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropUnique('reviews_product_input_hash_unique');
            $table->dropIndex('reviews_product_demo_ai_index');
            $table->dropIndex('reviews_demo_batch_index');
            $table->dropConstrainedForeignId('product_variant_id');
            $table->dropColumn([
                'is_demo',
                'is_ai_generated',
                'source',
                'environment',
                'generation_batch_id',
                'model',
                'prompt_version',
                'input_hash',
                'metadata',
                'review_date',
                'generated_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['is_demo', 'demo_metadata']);
        });
    }
};
