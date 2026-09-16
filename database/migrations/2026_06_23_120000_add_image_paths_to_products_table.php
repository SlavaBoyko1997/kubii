<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('image_path')->nullable()->after('image_url');
            $table->json('gallery_paths')->nullable()->after('gallery_images');
            $table->json('content_image_paths')->nullable()->after('content_images');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['image_path', 'gallery_paths', 'content_image_paths']);
        });
    }
};
