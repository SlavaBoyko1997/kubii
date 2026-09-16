<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Services\SeoMeta;
use App\Services\SeoSchema;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class BlogController extends Controller
{
    public function index(Request $request, SeoMeta $seoMeta, SeoSchema $seoSchema): View
    {
        $posts = BlogPost::query()
            ->published()
            ->ordered()
            ->paginate(12)
            ->withQueryString();

        $url = localized_route('blog.index');
        $title = __('Блог Kubii');
        $description = __('Корисні статті про туризм, кемпінг, риболовлю та вибір спорядження від Kubii.');

        return view('store.blog.index', [
            'posts' => $posts,
            'seoMeta' => $seoMeta->page($title, $description, $url),
            'seoSchema' => $seoSchema->page($title, $description, $url, 'CollectionPage'),
            'canonicalUrl' => $url,
        ]);
    }

    public function show(BlogPost $blogPost, SeoMeta $seoMeta, SeoSchema $seoSchema): View
    {
        abort_unless($blogPost->isPublic(), 404);

        $url = $blogPost->url();

        return view('store.blog.show', [
            'post' => $blogPost,
            'seoMeta' => $seoMeta->article(
                $blogPost->seoTitle(),
                $blogPost->seoDescription(),
                $url,
                $blogPost->socialImageUrl(),
                $blogPost->published_at?->toAtomString(),
                $blogPost->updated_at?->toAtomString(),
            ),
            'seoSchema' => $seoSchema->blogPost(
                $blogPost->seoTitle(),
                $blogPost->seoDescription(),
                $url,
                $blogPost->socialImageUrl(),
                $blogPost->published_at?->toAtomString(),
                $blogPost->updated_at?->toAtomString(),
            ),
            'canonicalUrl' => $blogPost->canonicalUrl(),
        ]);
    }
}
