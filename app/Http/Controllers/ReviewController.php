<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function store(Request $request, Product $product): RedirectResponse
    {
        abort_unless($product->is_active, 404);

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['required', 'string', 'min:10', 'max:1500'],
            'pros' => ['nullable', 'string', 'max:1000'],
            'cons' => ['nullable', 'string', 'max:1000'],
        ]);

        $product->reviews()->create($validated + [
            'user_id' => $request->user()->id,
            'is_verified_purchase' => $request->user()->orders()
                ->whereHas('items', fn ($query) => $query->where('product_id', $product->id))
                ->exists(),
            'is_visible' => true,
        ]);

        return back()->with('success', __('Дякуємо! Ваш відгук опубліковано.'));
    }

    public function reply(Request $request, Review $review): RedirectResponse
    {
        abort_if($review->parent_id || ! $review->is_visible, 404);

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $review->replies()->create($validated + [
            'product_id' => $review->product_id,
            'user_id' => $request->user()->id,
            'is_visible' => true,
        ]);

        return back()->with('success', __('Вашу відповідь опубліковано.'));
    }

    public function vote(Request $request, Review $review): RedirectResponse
    {
        abort_if($review->parent_id || ! $review->is_visible, 404);

        $validated = $request->validate([
            'is_helpful' => ['required', 'boolean'],
        ]);

        $review->votes()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['is_helpful' => $validated['is_helpful']],
        );

        return back()->with('success', __('Дякуємо за вашу оцінку відгуку.'));
    }
}
