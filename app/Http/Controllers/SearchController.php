<?php

namespace App\Http\Controllers;

use App\Services\SeoMeta;
use App\Services\SeoSchema;
use App\Services\SmartSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function suggestions(Request $request, SmartSearch $search): JsonResponse
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:160']]);
        $results = $search->search((string) ($validated['q'] ?? ''));

        return response()->json([
            'query' => $results['query'],
            'corrected' => $results['corrected'],
            'all_url' => localized_route('search.index', ['q' => $results['query']]),
            'categories' => $results['categories']->map(fn ($category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'path' => $category->breadcrumbTrail()->pluck('name')->implode(' / '),
                'url' => $category->catalogUrl(),
            ])->all(),
            'products' => $results['products']->map(fn ($product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'brand' => $product->brand,
                'category' => $product->category?->name,
                'image' => $product->imageUrl(),
                'price' => $product->isPricePending() ? null : number_format($product->salePrice(), 0, ',', ' ').' ₴',
                'stock' => $product->stock > 0,
                'url' => $product->url(),
            ])->all(),
        ]);
    }

    public function index(Request $request, SmartSearch $search, SeoSchema $seoSchema, SeoMeta $seoMeta): View
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:160']]);
        $results = $search->search((string) ($validated['q'] ?? ''), 40, 12);

        $url = localized_route('search.index', $results['query'] !== '' ? ['q' => $results['query']] : []);

        return view('store.search', [
            ...$results,
            'canonicalUrl' => $url,
            'seoSchema' => $seoSchema->search($results['query'], $results['products'], $url),
            'seoMeta' => $seoMeta->search($results['query'], $url),
        ]);
    }

    public function click(Request $request, SmartSearch $search): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:160'],
            'type' => ['required', 'in:product,category'],
            'id' => ['required', 'integer', 'min:1'],
        ]);

        $search->recordClick($validated['query'], $validated['type'], (int) $validated['id']);

        return response()->json(['ok' => true]);
    }
}
