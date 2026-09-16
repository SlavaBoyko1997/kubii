<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class ProductImageArchive
{
    public function create(Product $product): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive is not available in this PHP build.');
        }

        $folder = $this->folderName($product);
        $entries = $this->entries($product);

        if ($entries === []) {
            throw new RuntimeException('No downloadable images were found for this product.');
        }

        $zipPath = storage_path('app/temp/product-images-'.$product->id.'-'.uniqid('', true).'.zip');

        if (! is_dir(dirname($zipPath)) && ! mkdir(dirname($zipPath), 0775, true) && ! is_dir(dirname($zipPath))) {
            throw new RuntimeException('Unable to create a temporary directory for the archive.');
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the image archive.');
        }

        foreach ($entries as $entry) {
            $zipPathInside = $folder.'/'.$entry['name'];

            if ($entry['type'] === 'disk') {
                $absolutePath = Storage::disk('public')->path($entry['path']);
                $zip->addFile($absolutePath, $zipPathInside);

                continue;
            }

            $zip->addFromString($zipPathInside, $entry['bytes']);
        }

        $zip->close();

        return $zipPath;
    }

    public function downloadFilename(Product $product): string
    {
        return $this->folderName($product).'.zip';
    }

    /**
     * @return list<array{type: 'disk', path: string, name: string}|array{type: 'bytes', bytes: string, name: string}>
     */
    private function entries(Product $product): array
    {
        $entries = [];
        $index = 1;
        $seenPaths = [];
        $seenUrls = [];

        $addLocalPath = function (?string $path, string $label) use (&$entries, &$index, &$seenPaths): void {
            $normalized = $this->normalizeStoragePath($path);

            if ($normalized === null || isset($seenPaths[$normalized])) {
                return;
            }

            if (! Storage::disk('public')->exists($normalized)) {
                return;
            }

            $seenPaths[$normalized] = true;
            $entries[] = [
                'type' => 'disk',
                'path' => $normalized,
                'name' => $this->entryName($index++, $label, $normalized),
            ];
        };

        $addRemoteUrl = function (?string $url, string $label) use (&$entries, &$index, &$seenUrls): void {
            $url = trim((string) $url);

            if ($url === '' || isset($seenUrls[$url])) {
                return;
            }

            $seenUrls[$url] = true;

            $response = Http::timeout(30)
                ->withHeaders(['User-Agent' => 'Kubii/1.0'])
                ->get($url);

            if (! $response->successful()) {
                return;
            }

            $entries[] = [
                'type' => 'bytes',
                'bytes' => $response->body(),
                'name' => $this->entryName($index++, $label, parse_url($url, PHP_URL_PATH) ?: $url),
            ];
        };

        $addLocalPath($product->image_path, 'main');

        foreach ($product->gallery_paths ?? [] as $galleryIndex => $path) {
            $addLocalPath($path, sprintf('gallery-%02d', $galleryIndex + 1));
        }

        foreach ($product->gallery_images ?? [] as $galleryIndex => $url) {
            $localPath = $this->pathFromPublicUrl($url);

            if ($localPath !== null) {
                $addLocalPath($localPath, sprintf('gallery-%02d', $galleryIndex + 1));

                continue;
            }

            $addRemoteUrl($url, sprintf('gallery-%02d', $galleryIndex + 1));
        }

        if ($entries === [] && filled($product->image_url)) {
            $localPath = $this->pathFromPublicUrl($product->image_url);

            if ($localPath !== null) {
                $addLocalPath($localPath, 'main');
            } else {
                $addRemoteUrl($product->image_url, 'main');
            }
        }

        return $entries;
    }

    private function folderName(Product $product): string
    {
        $code = trim((string) ($product->sku ?: $product->external_id ?: $product->id));
        $safe = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $code) ?? '';
        $safe = trim($safe, '._-');

        return $safe !== '' ? $safe : 'product-'.$product->id;
    }

    private function entryName(int $index, string $label, string $referencePath): string
    {
        $extension = strtolower(pathinfo($referencePath, PATHINFO_EXTENSION) ?: 'jpg');
        $extension = preg_replace('/[^a-z0-9]+/', '', $extension) ?: 'jpg';

        return sprintf('%02d-%s.%s', $index, $label, $extension);
    }

    private function normalizeStoragePath(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $path = trim($path);
        $path = preg_replace('#^public/#', '', $path) ?? $path;

        if (str_starts_with($path, '/storage/')) {
            $path = substr($path, strlen('/storage/'));
        }

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return $path !== '' ? $path : null;
    }

    private function pathFromPublicUrl(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || ! str_contains($path, '/storage/')) {
            return null;
        }

        return $this->normalizeStoragePath($path);
    }
}
