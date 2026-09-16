<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class SocialImage
{
    private const WIDTH = 1200;

    private const HEIGHT = 630;

    private const MAX_SOURCE_BYTES = 12_000_000;

    private const MIN_SOURCE_WIDTH = 300;

    private const MIN_SOURCE_HEIGHT = 250;

    public function render(?string $sourceUrl, string $cacheKey): string
    {
        $directory = storage_path('framework/cache/social-images');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.sha1($cacheKey.'|'.$sourceUrl).'.jpg';

        if (File::exists($path)) {
            return $path;
        }

        try {
            $bytes = $this->sourceBytes($sourceUrl);
            $source = $bytes ? @imagecreatefromstring($bytes) : false;

            if (! $source || imagesx($source) < self::MIN_SOURCE_WIDTH || imagesy($source) < self::MIN_SOURCE_HEIGHT) {
                if ($source) {
                    imagedestroy($source);
                }

                return $this->fallback();
            }

            $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
            $background = imagecolorallocate($canvas, 244, 247, 244);
            imagefill($canvas, 0, 0, $background);

            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $scale = min(1120 / $sourceWidth, 550 / $sourceHeight);
            $width = max(1, (int) round($sourceWidth * $scale));
            $height = max(1, (int) round($sourceHeight * $scale));
            $left = (int) floor((self::WIDTH - $width) / 2);
            $top = (int) floor((self::HEIGHT - $height) / 2);

            imagecopyresampled(
                $canvas,
                $source,
                $left,
                $top,
                0,
                0,
                $width,
                $height,
                $sourceWidth,
                $sourceHeight,
            );
            imagejpeg($canvas, $path, 86);
            imagedestroy($source);
            imagedestroy($canvas);

            return $path;
        } catch (Throwable) {
            return $this->fallback();
        }
    }

    private function sourceBytes(?string $sourceUrl): ?string
    {
        if (blank($sourceUrl)) {
            return null;
        }

        $localPath = $this->localPath($sourceUrl);

        if ($localPath && File::isFile($localPath)) {
            return File::get($localPath);
        }

        if (! $this->isSafeRemoteUrl($sourceUrl)) {
            return null;
        }

        $response = Http::timeout(7)
            ->connectTimeout(3)
            ->withHeaders(['User-Agent' => 'Kubii-SocialImage/1.0'])
            ->withOptions(['allow_redirects' => false])
            ->get($sourceUrl);

        if (! $response->successful()
            || ! str_starts_with((string) $response->header('Content-Type'), 'image/')
            || strlen($response->body()) > self::MAX_SOURCE_BYTES) {
            return null;
        }

        return $response->body();
    }

    private function localPath(string $sourceUrl): ?string
    {
        $path = parse_url($sourceUrl, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $path = '/'.ltrim(rawurldecode($path), '/');

        if (str_contains($path, '..')) {
            return null;
        }

        if (Str::startsWith($path, '/storage/')) {
            return storage_path('app/public/'.Str::after($path, '/storage/'));
        }

        return public_path(ltrim($path, '/'));
    }

    private function isSafeRemoteUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        if (in_array(mb_strtolower($host), ['localhost', 'localhost.localdomain'], true)
            || Str::endsWith(mb_strtolower($host), ['.local', '.internal'])) {
            return false;
        }

        $addresses = gethostbynamel($host);

        return is_array($addresses)
            && $addresses !== []
            && collect($addresses)->every(
                fn (string $address): bool => filter_var(
                    $address,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
                ) !== false,
            );
    }

    private function fallback(): string
    {
        return public_path('images/social-default.jpg');
    }
}
