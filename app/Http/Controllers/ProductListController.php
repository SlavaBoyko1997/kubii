<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\ProductLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductListController extends Controller
{
    public function favorites(ProductLists $lists): View
    {
        return view('store.favorites', [
            'products' => $lists->favoriteProducts(),
        ]);
    }

    public function comparison(ProductLists $lists): View
    {
        return view('store.comparison', [
            'products' => $lists->comparisonProducts(),
        ]);
    }

    public function toggleFavorite(Request $request, Product $product, ProductLists $lists): RedirectResponse|JsonResponse
    {
        abort_unless($product->is_active, 404);

        $added = $lists->toggleFavorite($product);

        return $this->respond($request, $lists, $added, $added ? __('Товар додано в обране.') : __('Товар видалено з обраного.'));
    }

    public function toggleComparison(Request $request, Product $product, ProductLists $lists): RedirectResponse|JsonResponse
    {
        abort_unless($product->is_active, 404);

        $added = $lists->toggleComparison($product);

        return $this->respond($request, $lists, $added, $added ? __('Товар додано до порівняння.') : __('Товар видалено з порівняння.'));
    }

    private function respond(Request $request, ProductLists $lists, bool $added, string $message): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'added' => $added,
                'favoriteIds' => $lists->favoriteIds(),
                'comparisonIds' => $lists->comparisonIds(),
                'favoriteCount' => count($lists->favoriteIds()),
                'comparisonCount' => count($lists->comparisonIds()),
            ]);
        }

        return back()->with('success', $message);
    }
}
