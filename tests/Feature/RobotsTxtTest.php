<?php

namespace Tests\Feature;

use Tests\TestCase;

class RobotsTxtTest extends TestCase
{
    public function test_production_robots_allows_storefront_and_blocks_crawl_traps(): void
    {
        config()->set('seo.indexing_enabled', true);

        $response = $this->get('/robots.txt');

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee("User-agent: GPTBot\nDisallow: /", false)
            ->assertSee("User-agent: *\nAllow: /", false)
            ->assertSee('Disallow: /admin/', false)
            ->assertSee('Disallow: /ru/search/suggestions', false)
            ->assertSee('Disallow: /*?*sort=', false)
            ->assertSee('Disallow: /*?*filter=', false)
            ->assertSee('Disallow: /*?*brand%5B', false)
            ->assertSee('Disallow: /*?*model%5B', false)
            ->assertSee('Disallow: /*?*season%5B', false)
            ->assertSee('Disallow: /*?*usage_type%5B', false)
            ->assertSee('Disallow: /*?*material%5B', false)
            ->assertSee('Disallow: /*?*spec%5B', false)
            ->assertSee('Disallow: /*?*min_price=', false)
            ->assertSee('Disallow: /*?*max_price=', false)
            ->assertSee('Disallow: /*?*max_weight=', false)
            ->assertSee('Disallow: /*?*in_stock=', false)
            ->assertSee('Disallow: /*?*on_sale=', false)
            ->assertSee('Disallow: /*?*fast_filters=', false)
            ->assertSee('Disallow: /*?*filters_only=', false)
            ->assertSee('Sitemap: '.route('sitemap.index'), false)
            ->assertDontSee('Disallow: /products/', false);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString("Disallow: /ru/\n", $response->getContent());
    }

    public function test_non_production_robots_blocks_the_whole_site(): void
    {
        config()->set('seo.indexing_enabled', false);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee("User-agent: *\nDisallow: /\n", false)
            ->assertDontSee('Sitemap:', false);
    }
}
