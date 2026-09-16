<?php

namespace Tests\Unit;

use App\Support\AppUrl;
use Tests\TestCase;

class AppUrlTest extends TestCase
{
    public function test_for_browser_strips_localhost_origin(): void
    {
        $this->assertSame(
            '/tourism/?page=2',
            AppUrl::forBrowser('http://localhost/tourism/?page=2'),
        );
    }

    public function test_sanitize_html_urls_removes_localhost_from_href_attributes(): void
    {
        $html = '<a href="http://127.0.0.1:8000/tourism/?page=2">next</a>';

        $this->assertSame(
            '<a href="/tourism/?page=2">next</a>',
            AppUrl::sanitizeHtmlUrls($html),
        );
    }

    public function test_absolute_if_possible_uses_request_host(): void
    {
        $this->get('https://shop.example.test/tourism/');

        $this->assertSame(
            'https://shop.example.test/tourism/?page=2',
            AppUrl::absoluteIfPossible('/tourism/?page=2'),
        );
    }

    public function test_sanitize_localhost_html_urls_only_strips_localhost(): void
    {
        $html = '<a href="http://localhost/tourism/?page=2">next</a><link rel="canonical" href="https://tripfish.com.ua/tourism/">';

        $this->assertSame(
            '<a href="/tourism/?page=2">next</a><link rel="canonical" href="https://tripfish.com.ua/tourism/">',
            AppUrl::sanitizeLocalhostHtmlUrls($html),
        );
    }

    public function test_configured_origin_uses_app_url(): void
    {
        config(['app.url' => 'https://tripfish.com.ua']);

        $this->assertSame('https://tripfish.com.ua', AppUrl::configuredOrigin());
    }
}
