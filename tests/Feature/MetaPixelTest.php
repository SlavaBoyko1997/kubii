<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaPixelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google_analytics.measurement_id' => null,
            'services.google_analytics.enabled' => false,
            'services.meta_pixel.pixel_id' => '2193598034547819',
            'services.meta_pixel.enabled' => true,
        ]);
    }

    public function test_store_layout_includes_meta_pixel_when_configured(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('<!-- Meta Pixel Code -->', false)
            ->assertSee('https://connect.facebook.net/en_US/fbevents.js', false)
            ->assertSee("fbq('init', '2193598034547819')", false)
            ->assertSee("fbq('track', 'PageView')", false)
            ->assertSee('https://www.facebook.com/tr?id=2193598034547819', false)
            ->assertSee('noscript=1', false)
            ->assertSee('<!-- End Meta Pixel Code -->', false);
    }

    public function test_meta_pixel_is_omitted_when_not_configured(): void
    {
        config(['services.meta_pixel.pixel_id' => '']);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('connect.facebook.net/en_US/fbevents.js', false)
            ->assertDontSee('facebook.com/tr?id=', false);
    }

    public function test_production_csp_allows_meta_pixel_domains(): void
    {
        $response = $this->get('https://localhost/');
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://connect.facebook.net', $csp);
        $this->assertStringContainsString('https://www.facebook.com', $csp);
        $this->assertStringContainsString('https://capig.datah04.com', $csp);
    }
}
