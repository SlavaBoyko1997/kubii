<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\Cart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CartController extends Controller
{
    public function index(Cart $cart): View
    {
        return view('store.cart', [
            'items' => $cart->items(),
            'total' => $cart->total(),
        ]);
    }

    public function store(Request $request, Product $product, Cart $cart): RedirectResponse|JsonResponse
    {
        abort_unless($product->is_active, 404);

        $validated = $request->validate([
            'quantity' => ['nullable', 'integer', 'min:1', 'max:'.$product->stock],
        ]);

        if (! $product->isPurchasable()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $product->isPricePending() ? __('Ціну ще не розраховано.') : __('На жаль, товар закінчився.')], 422);
            }

            return back()->with('error', $product->isPricePending() ? __('Ціну ще не розраховано.') : __('На жаль, товар закінчився.'));
        }

        $cart->add($product, $validated['quantity'] ?? 1);

        return $this->respond($request, $cart, __('Товар додано до кошика.'));
    }

    public function update(Request $request, Product $product, Cart $cart): RedirectResponse|JsonResponse
    {
        abort_unless($product->is_active, 404);

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:'.$product->stock],
        ]);

        $cart->update($product, $validated['quantity']);

        return $this->respond($request, $cart, __('Кошик оновлено.'));
    }

    public function destroy(Request $request, Product $product, Cart $cart): RedirectResponse|JsonResponse
    {
        $cart->remove($product);

        return $this->respond($request, $cart, __('Товар видалено з кошика.'));
    }

    public function clear(Request $request, Cart $cart): RedirectResponse|JsonResponse
    {
        $cart->clear();

        return $this->respond($request, $cart, __('Кошик очищено.'));
    }

    public function showMerge(Request $request, Cart $cart): View|RedirectResponse
    {
        $guestCart = $request->session()->get('cart_merge_pending');

        if (! is_array($guestCart) || $guestCart === []) {
            return redirect()->to($request->session()->pull('cart_merge_destination', localized_route('cart.index')));
        }

        $guestItems = $cart->itemsFrom($guestCart);
        $accountItems = $cart->items();

        return view('store.cart-merge', [
            'guestItems' => $guestItems,
            'guestTotal' => $guestItems->sum('subtotal'),
            'accountItems' => $accountItems,
            'accountTotal' => $accountItems->sum('subtotal'),
        ]);
    }

    public function merge(Request $request, Cart $cart): RedirectResponse
    {
        $guestCart = $request->session()->pull('cart_merge_pending', []);
        $destination = $request->session()->pull('cart_merge_destination', localized_route('cart.index'));

        if (is_array($guestCart) && $guestCart !== []) {
            $cart->mergeIntoAccount($guestCart);
        }

        return redirect()->to($destination)->with('success', __('Товари з обох кошиків об’єднано.'));
    }

    public function keepAccount(Request $request): RedirectResponse
    {
        $request->session()->forget('cart_merge_pending');
        $destination = $request->session()->pull('cart_merge_destination', localized_route('cart.index'));

        return redirect()->to($destination)->with('success', __('Збережено кошик вашого акаунта.'));
    }

    public function keepDevice(Request $request, Cart $cart): RedirectResponse
    {
        $guestCart = $request->session()->pull('cart_merge_pending', []);
        $destination = $request->session()->pull('cart_merge_destination', localized_route('cart.index'));

        if (is_array($guestCart) && $guestCart !== []) {
            $cart->replaceAccount($guestCart);
        }

        return redirect()->to($destination)->with('success', __('Збережено кошик із цього пристрою.'));
    }

    private function respond(Request $request, Cart $cart, string $message): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'count' => $cart->count(),
                'productIds' => $cart->productIds(),
                'drawer' => view('store._cart-drawer', [
                    'drawerItems' => $cart->items(),
                    'drawerTotal' => $cart->total(),
                ])->render(),
                'cart' => view('store._cart-content', [
                    'items' => $cart->items(),
                    'total' => $cart->total(),
                ])->render(),
                'checkoutOrder' => view('store._checkout-order', [
                    'items' => $cart->items(),
                    'total' => $cart->total(),
                ])->render(),
                'checkoutSummary' => view('store._checkout-summary', [
                    'items' => $cart->items(),
                    'total' => $cart->total(),
                ])->render(),
            ]);
        }

        return back()->with('success', $message);
    }
}
