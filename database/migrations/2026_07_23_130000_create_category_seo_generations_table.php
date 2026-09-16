<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->text('seo_intro')->nullable()->after('description_ru');
            $table->text('seo_intro_ru')->nullable()->after('seo_intro');
            $table->json('seo_faq')->nullable()->after('seo_intro_ru');
            $table->json('seo_faq_ru')->nullable()->after('seo_faq');
            $table->timestamp('ai_seo_generated_at')->nullable()->after('seo_faq_ru')->index();
        });

        Schema::create('category_seo_generations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('locale', 8)->default('uk')->index();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('requested_word_count')->default(500);
            $table->json('requested_fields')->nullable();
            $table->string('overwrite_mode')->default('missing');
            $table->string('selection_mode')->nullable();
            $table->json('settings')->nullable();
            $table->json('context_summary')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('h1')->nullable();
            $table->text('intro')->nullable();
            $table->longText('seo_text')->nullable();
            $table->json('faq')->nullable();
            $table->json('internal_links')->nullable();
            $table->json('validation_errors')->nullable();
            $table->json('errors')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->string('response_id')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('estimated_cost', 10, 6)->default(0);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['category_id', 'locale', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_seo_generations');

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn([
                'seo_intro',
                'seo_intro_ru',
                'seo_faq',
                'seo_faq_ru',
                'ai_seo_generated_at',
            ]);
        });
    }
};
