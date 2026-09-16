<?php

namespace Tests\Unit;

use App\Support\StoredAsset;
use Tests\TestCase;

class StoredAssetTest extends TestCase
{
    public function test_pobedov_feed_ip_image_is_normalized_to_https_cdn(): void
    {
        $url = StoredAsset::url('http://65.108.104.155/pic/cd5f36d7-badb-11ee-a00b-f02f749621c0.jpg');

        $this->assertSame(
            'https://file.pobedov.com/pic/cd5f36d7-badb-11ee-a00b-f02f749621c0.jpg',
            $url,
        );
    }

    public function test_pobedov_http_domain_is_upgraded_to_https(): void
    {
        $url = StoredAsset::url('http://file.pobedov.com/pic/example.jpg');

        $this->assertSame('https://file.pobedov.com/pic/example.jpg', $url);
    }

    public function test_other_https_urls_are_left_unchanged(): void
    {
        $url = StoredAsset::url('https://example.com/image.jpg');

        $this->assertSame('https://example.com/image.jpg', $url);
    }
}
