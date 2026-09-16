<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GoogleMerchantFeedController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        $disk = Storage::disk('public');
        $path = (string) config('google_merchant.path', 'feeds/google-merchant-feed.xml');

        abort_unless($disk->exists($path), Response::HTTP_NOT_FOUND);

        return response()->file($disk->path($path), [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
