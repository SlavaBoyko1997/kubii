<?php

namespace Tests\Unit;

use App\Support\BlogHtmlSanitizer;
use App\Support\BlogVideoEmbed;
use Tests\TestCase;

class BlogVideoEmbedTest extends TestCase
{
    public function test_youtube_watch_url_resolves_to_embed(): void
    {
        $url = BlogVideoEmbed::resolveEmbedUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $url);
    }

    public function test_vimeo_url_resolves_to_player_embed(): void
    {
        $url = BlogVideoEmbed::resolveEmbedUrl('https://vimeo.com/123456789');

        $this->assertSame('https://player.vimeo.com/video/123456789', $url);
    }

    public function test_embed_html_wraps_iframe(): void
    {
        $html = BlogVideoEmbed::embedHtml('https://youtu.be/dQw4w9WgXcQ');

        $this->assertNotNull($html);
        $this->assertStringContainsString('blog-video-embed', $html);
        $this->assertStringContainsString('youtube-nocookie.com/embed/dQw4w9WgXcQ', $html);
    }

    public function test_html_sanitizer_allows_trusted_iframe_and_strips_unknown(): void
    {
        $html = BlogHtmlSanitizer::sanitize(
            '<p>Intro</p><iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"></iframe><iframe src="https://evil.example/embed"></iframe>'
        );

        $this->assertStringContainsString('<p>Intro</p>', $html);
        $this->assertStringContainsString('youtube-nocookie.com/embed/dQw4w9WgXcQ', $html);
        $this->assertStringNotContainsString('evil.example', $html);
    }
}
