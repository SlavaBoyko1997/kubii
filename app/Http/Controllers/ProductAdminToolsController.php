<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ProductImageArchive;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductAdminToolsController extends Controller
{
    public function downloadImages(Product $product, ProductImageArchive $archive): BinaryFileResponse
    {
        abort_unless(auth()->user()?->is_admin, 403);

        $zipPath = $archive->create($product);

        return response()
            ->download($zipPath, $archive->downloadFilename($product), [
                'Content-Type' => 'application/zip',
            ])
            ->deleteFileAfterSend(true);
    }
}
