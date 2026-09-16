<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlogPostTest extends TestCase
{
    use RefreshDatabase;

    public function test_blog_index_shows_only_published_posts(): void
    {
        BlogPost::factory()->published()->create([
            'title' => 'Опублікована стаття',
            'slug' => 'published-post',
        ]);
        BlogPost::factory()->draft()->create([
            'title' => 'Чернетка статті',
            'slug' => 'draft-post',
        ]);

        $response = $this->get('/blog')->assertOk();

        $response->assertSee('Опублікована стаття');
        $response->assertDontSee('Чернетка статті');
    }

    public function test_published_post_page_is_accessible_with_seo_tags(): void
    {
        $post = BlogPost::factory()->published()->create([
            'title' => 'Гід по наметах',
            'slug' => 'gid-po-nametah',
            'short_description' => 'Короткий опис для картки та SEO.',
            'seo_title' => 'SEO заголовок наметів',
            'content' => '<h2>Розділ</h2><p>Текст статті.</p>',
        ]);

        $response = $this->get('/blog/gid-po-nametah')->assertOk();

        $response->assertSee('Гід по наметах', false);
        $response->assertSee('Розділ', false);
        $response->assertSee('SEO заголовок наметів', false);
        $response->assertSee('og:type" content="article"', false);
        $response->assertSee('"@type":"Article"', false);
        $response->assertSee($post->url(), false);
    }

    public function test_html_embed_block_renders_markup_on_post_page(): void
    {
        BlogPost::factory()->published()->create([
            'title' => 'HTML стаття',
            'slug' => 'html-stattya',
            'content' => json_encode([
                'type' => 'doc',
                'content' => [[
                    'type' => 'customBlock',
                    'attrs' => [
                        'id' => 'blogHtmlEmbed',
                        'config' => [
                            'html' => '<div class="promo-box"><strong>Акція</strong> до кінця місяця</div>',
                        ],
                    ],
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        $response = $this->get('/blog/html-stattya')->assertOk();

        $response->assertSee('promo-box', false);
        $response->assertSee('Акція', false);
        $response->assertSee('до кінця місяця', false);
    }

    public function test_draft_and_future_posts_return_404(): void
    {
        BlogPost::factory()->draft()->create([
            'slug' => 'hidden-draft',
        ]);
        BlogPost::factory()->create([
            'title' => 'Майбутня стаття',
            'slug' => 'future-post',
            'status' => BlogPost::STATUS_PUBLISHED,
            'published_at' => now()->addDay(),
        ]);

        $this->get('/blog/hidden-draft')->assertNotFound();
        $this->get('/blog/future-post')->assertNotFound();
    }

    public function test_slug_must_be_unique(): void
    {
        BlogPost::factory()->create(['slug' => 'same-slug']);

        $this->expectException(QueryException::class);

        BlogPost::factory()->create(['slug' => 'same-slug']);
    }

    public function test_seo_description_falls_back_to_short_description(): void
    {
        $post = BlogPost::factory()->published()->create([
            'slug' => 'seo-fallback-post',
            'short_description' => 'Короткий опис для meta.',
            'seo_description' => null,
        ]);

        $this->assertSame('Короткий опис для meta.', $post->seoDescription());
    }
}
