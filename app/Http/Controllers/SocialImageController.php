<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Services\SocialImage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SocialImageController extends Controller
{
    public function product(Product $product, int $version, SocialImage $socialImage): BinaryFileResponse
    {
        abort_unless($product->is_active, 404);

        return $this->response(
            $socialImage->render($product->gallery()[0] ?? null, "product:{$product->id}:{$version}"),
        );
    }

    public function category(Category $category, int $version, SocialImage $socialImage): BinaryFileResponse
    {
        abort_unless($category->is_active, 404);

        return $this->response(
            $socialImage->render($category->imageUrl(), "category:{$category->id}:{$version}"),
        );
    }

    private function response(string $path): BinaryFileResponse
    {
        return response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
