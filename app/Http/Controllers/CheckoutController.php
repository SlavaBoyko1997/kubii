<?php

namespace App\Http\Controllers;

use App\Exceptions\NovaPoshtaException;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Services\NovaPoshtaService;
use App\Support\Cart;
use App\Support\GuestCustomerProfile;
use App\Support\NovaPoshtaShipment;
use App\Support\PaymentOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function create(
        Request $request,
        Cart $cart,
        PaymentOptions $paymentOptions,
        NovaPoshtaShipment $shipment,
    ): View|RedirectResponse
    {
        if ($cart->items()->isEmpty()) {
            return redirect()->to(localized_route('cart.index'))->with('error', __('Спочатку додайте товари до кошика.'));
        }

        $lastOrder = $request->user()?->orders()
            ->where('order_type', 'standard')
            ->whereIn('delivery_type', ['nova_poshta_warehouse', 'nova_poshta_postomat', 'nova_poshta_courier'])
            ->whereNotNull('nova_poshta_city_ref')
            ->latest()
            ->first();

        $enabledPaymentOptions = $paymentOptions->forCart($cart->items());
        $shipmentProfile = $shipment->profile($cart->items());
        $defaultDeliveryType = match ($lastOrder?->delivery_type) {
            'nova_poshta_postomat' => $shipmentProfile['postomat_available'] ? 'nova_poshta_postomat' : 'nova_poshta_warehouse',
            'nova_poshta_courier' => 'nova_poshta_courier',
            default => 'nova_poshta_warehouse',
        };
        $lastPaymentMethod = $lastOrder?->payment_method;
        $defaultPaymentMethod = $enabledPaymentOptions->contains('code', $lastPaymentMethod)
            ? $lastPaymentMethod
            : $enabledPaymentOptions->first()?->code;

        $checkoutDefaults = [
            'delivery_type' => $defaultDeliveryType,
            'nova_poshta_city_ref' => $lastOrder?->nova_poshta_city_ref,
            'nova_poshta_city_name' => $lastOrder?->nova_poshta_city_name,
            'nova_poshta_warehouse_ref' => $lastOrder?->delivery_type === $defaultDeliveryType
                ? $lastOrder?->nova_poshta_warehouse_ref
                : null,
            'nova_poshta_warehouse_name' => $lastOrder?->delivery_type === $defaultDeliveryType
                ? $lastOrder?->nova_poshta_warehouse_name
                : null,
            'nova_poshta_street_ref' => $lastOrder?->delivery_type === 'nova_poshta_courier'
                ? $lastOrder?->nova_poshta_street_ref
                : null,
            'nova_poshta_street_name' => $lastOrder?->delivery_type === 'nova_poshta_courier'
                ? $lastOrder?->nova_poshta_street_name
                : null,
            'nova_poshta_building' => $lastOrder?->delivery_type === 'nova_poshta_courier'
                ? $lastOrder?->nova_poshta_building
                : null,
            'nova_poshta_flat' => $lastOrder?->delivery_type === 'nova_poshta_courier'
                ? $lastOrder?->nova_poshta_flat
                : null,
            'payment_method' => $defaultPaymentMethod,
        ];

        return view('store.checkout', [
            'items' => $cart->items(),
            'total' => $cart->total(),
            'checkoutDefaults' => $checkoutDefaults,
            'paymentOptions' => $enabledPaymentOptions,
            'shipmentProfile' => $shipmentProfile,
        ]);
    }

    public function accountCheck(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', new PhoneNumber],
        ]);

        $email = mb_strtolower(trim((string) ($validated['email'] ?? '')));
        $phone = filled($validated['phone'] ?? null)
            ? PhoneNumber::normalize((string) $validated['phone'])
            : '';
        $profile = app(GuestCustomerProfile::class);
        $emailExists = $email !== '' && $profile->hasRegisteredAccount($email, null);
        $phoneExists = $phone !== '' && $profile->hasRegisteredAccount(null, $phone);
        $exists = $emailExists || $phoneExists;

        if ($exists) {
            $request->session()->put('url.intended', localized_route('checkout.create'));
        }

        return response()->json([
            'exists' => $exists,
            'email_exists' => $emailExists,
            'phone_exists' => $phoneExists,
            'login_email' => $emailExists ? $email : null,
        ]);
    }

    public function store(
        Request $request,
        Cart $cart,
        NovaPoshtaService $novaPoshta,
        NovaPoshtaShipment $shipment,
        PaymentOptions $paymentOptions,
    ): RedirectResponse
    {
        $user = $request->user();

        if ($user) {
            $request->merge([
                'last_name' => $user->last_name ?: $request->input('last_name'),
                'first_name' => $user->first_name ?: $request->input('first_name'),
                'patronymic' => $user->patronymic ?: $request->input('patronymic'),
                'phone' => $user->phone,
                'email' => $user->email,
            ]);
        }

        $validated = $request->validate([
            'last_name' => ['required', 'string', 'max:80'],
            'first_name' => ['required', 'string', 'max:80'],
            'patronymic' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'max:30', new PhoneNumber],
            'email' => ['required', 'email', 'max:255'],
            'delivery_type' => ['required', Rule::in(['nova_poshta_warehouse', 'nova_poshta_postomat', 'nova_poshta_courier'])],
            'nova_poshta_city_ref' => ['required', 'string', 'max:50'],
            'nova_poshta_warehouse_ref' => ['required_unless:delivery_type,nova_poshta_courier', 'nullable', 'string', 'max:50'],
            'nova_poshta_street_ref' => ['required_if:delivery_type,nova_poshta_courier', 'nullable', 'string', 'max:50'],
            'nova_poshta_street_name' => ['required_if:delivery_type,nova_poshta_courier', 'nullable', 'string', 'max:255'],
            'nova_poshta_street_description' => ['nullable', 'string', 'max:255'],
            'nova_poshta_building' => ['required_if:delivery_type,nova_poshta_courier', 'nullable', 'string', 'max:30'],
            'nova_poshta_flat' => ['nullable', 'string', 'max:30'],
            'payment_method' => ['required', Rule::in($paymentOptions->codesForCart($cart->items()))],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], [
            'payment_method.in' => __('Обраний спосіб оплати зараз недоступний. Оберіть інший.'),
            'nova_poshta_city_ref.required' => __('Оберіть місто зі списку Нової Пошти.'),
            'nova_poshta_warehouse_ref.required_unless' => __('Оберіть відділення або поштомат зі списку.'),
            'nova_poshta_street_ref.required_if' => __('Оберіть вулицю зі списку Нової Пошти.'),
            'nova_poshta_street_name.required_if' => __('Оберіть вулицю зі списку Нової Пошти.'),
            'nova_poshta_building.required_if' => __('Вкажіть номер будинку.'),
        ]);

        try {
            $city = $novaPoshta->getCityByRef($validated['nova_poshta_city_ref']);
        } catch (NovaPoshtaException $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'nova_poshta_city_ref' => __('Не вдалося перевірити дані доставки Нової Пошти. Спробуйте ще раз пізніше.'),
            ]);
        }

        if (! $city) {
            throw ValidationException::withMessages([
                'nova_poshta_city_ref' => __('Оберіть місто зі списку Нової Пошти.'),
            ]);
        }

        $items = $cart->items();
        $shipmentProfile = $shipment->profile($items);

        if ($validated['delivery_type'] === 'nova_poshta_courier') {
            try {
                $street = $novaPoshta->findStreet(
                    $city['Ref'],
                    $validated['nova_poshta_street_ref'],
                    $validated['nova_poshta_street_description'] ?? $validated['nova_poshta_street_name'],
                );
            } catch (NovaPoshtaException $exception) {
                report($exception);

                throw ValidationException::withMessages([
                    'nova_poshta_street_ref' => __('Не вдалося перевірити адресу доставки. Спробуйте ще раз пізніше.'),
                ]);
            }

            if (! $street) {
                throw ValidationException::withMessages([
                    'nova_poshta_street_ref' => __('Оберіть вулицю зі списку Нової Пошти.'),
                ]);
            }

            $building = trim((string) $validated['nova_poshta_building']);
            $flat = trim((string) ($validated['nova_poshta_flat'] ?? ''));
            $deliveryAddress = collect([
                $street['Label'],
                $building !== '' ? __('буд. :number', ['number' => $building]) : null,
                $flat !== '' ? __('кв. :number', ['number' => $flat]) : null,
            ])->filter()->implode(', ');

            $validated = [
                ...$validated,
                'city' => $city['Description'],
                'delivery_address' => $deliveryAddress,
                'nova_poshta_city_name' => $city['Description'],
                'nova_poshta_street_name' => $street['Label'],
                'nova_poshta_warehouse_ref' => null,
                'nova_poshta_warehouse_name' => null,
                'nova_poshta_warehouse_number' => null,
                'nova_poshta_warehouse_address' => null,
            ];
        } else {
            try {
                $warehouse = $novaPoshta->getWarehouseByRef($validated['nova_poshta_warehouse_ref']);
            } catch (NovaPoshtaException $exception) {
                report($exception);

                throw ValidationException::withMessages([
                    'nova_poshta_city_ref' => __('Не вдалося перевірити дані доставки Нової Пошти. Спробуйте ще раз пізніше.'),
                ]);
            }

            if (! $warehouse || $warehouse['CityRef'] !== $city['Ref']) {
                throw ValidationException::withMessages([
                    'nova_poshta_warehouse_ref' => __('Оберіть відділення або поштомат у вибраному місті.'),
                ]);
            }

            $expectsPostomat = $validated['delivery_type'] === 'nova_poshta_postomat';

            if ($expectsPostomat && ! $shipmentProfile['postomat_available']) {
                throw ValidationException::withMessages([
                    'delivery_type' => __('Поштомат недоступний: у кошику є товар, що перевищує допустиму вагу або габарити. Оберіть вантажне відділення.'),
                ]);
            }

            if ($warehouse['is_postomat'] !== $expectsPostomat) {
                throw ValidationException::withMessages([
                    'nova_poshta_warehouse_ref' => $expectsPostomat
                        ? __('Для цього способу доставки потрібно обрати поштомат.')
                        : __('Для цього способу доставки потрібно обрати звичайне відділення.'),
                ]);
            }

            if (! $expectsPostomat && ! $shipment->warehouseSupports($warehouse, $shipmentProfile)) {
                throw ValidationException::withMessages([
                    'nova_poshta_warehouse_ref' => __('Це відділення не приймає вагу або габарити товарів у кошику. Оберіть вантажне відділення.'),
                ]);
            }

            $validated = [
                ...$validated,
                'city' => $city['Description'],
                'delivery_address' => $warehouse['ShortAddress'] ?: $warehouse['Description'],
                'nova_poshta_city_name' => $city['Description'],
                'nova_poshta_warehouse_name' => $warehouse['Description'],
                'nova_poshta_warehouse_number' => $warehouse['Number'],
                'nova_poshta_warehouse_address' => $warehouse['ShortAddress'],
                'nova_poshta_street_ref' => null,
                'nova_poshta_street_name' => null,
                'nova_poshta_building' => null,
                'nova_poshta_flat' => null,
            ];
        }

        $guestUserId = null;

        if ($user) {
            $profileUpdates = collect($validated)
                ->only(['last_name', 'first_name', 'patronymic'])
                ->filter(fn (mixed $value, string $key): bool => blank($user->{$key}) && filled($value))
                ->all();

            if ($profileUpdates !== []) {
                $profileUpdates['name'] = collect([
                    $profileUpdates['last_name'] ?? $user->last_name,
                    $profileUpdates['first_name'] ?? $user->first_name,
                    $profileUpdates['patronymic'] ?? $user->patronymic,
                ])->filter()->implode(' ');

                $user->update($profileUpdates);
                $user->refresh();
            }

            if (! $user->phone) {
                return back()->withErrors(['phone' => __('У вашому профілі не вказано номер телефону. Зверніться до менеджера магазину.')])->withInput();
            }

            $validated['phone'] = $user->phone;
            $validated['email'] = $user->email;
            $validated['customer_name'] = $user->fullName();
        } else {
            $validated['phone'] = PhoneNumber::normalize($validated['phone']);
            $validated['customer_name'] = collect([$validated['last_name'], $validated['first_name'], $validated['patronymic'] ?? null])->filter()->implode(' ');

            $profile = app(GuestCustomerProfile::class);

            if ($profile->hasRegisteredAccount($validated['email'], $validated['phone'])) {
                $normalizedEmail = mb_strtolower(trim($validated['email']));
                $request->session()->put('url.intended', localized_route('checkout.create'));

                return back()
                    ->withInput()
                    ->with('checkout_existing_account', true)
                    ->with('checkout_login_email', User::query()
                        ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                        ->where('is_guest', false)
                        ->exists()
                        ? $normalizedEmail
                        : null);
            }

            $guestUserId = $profile->resolveForStandardCheckout(
                lastName: $validated['last_name'],
                firstName: $validated['first_name'],
                patronymic: $validated['patronymic'] ?? null,
                email: $validated['email'],
                phone: $validated['phone'],
            )->id;
        }

        unset($validated['last_name'], $validated['first_name'], $validated['patronymic']);

        if ($items->isEmpty()) {
            return redirect()->to(localized_route('cart.index'))->with('error', __('Ваш кошик порожній.'));
        }

        $order = DB::transaction(function () use ($validated, $items, $guestUserId): Order {
            $order = Order::create([
                ...$validated,
                'user_id' => auth()->id() ?: $guestUserId,
                'total' => 0,
            ]);

            $total = 0;

            foreach ($items as $item) {
                $product = Product::query()->lockForUpdate()->findOrFail($item['product']->id);

                if (! $product->isPurchasable()) {
                    abort(422, __('Ціну товару «:product» ще не розраховано.', ['product' => $product->name]));
                }

                if ($product->stock < $item['quantity']) {
                    abort(422, __('Недостатньо товару «:product» на складі.', ['product' => $product->name]));
                }

                $order->items()->create([
                    'product_id' => $product->id,
                    'variant_id' => $product->variant_id,
                    'product_name' => $product->name,
                    'price' => $product->salePrice(),
                    'quantity' => $item['quantity'],
                    'subtotal' => $product->salePrice() * $item['quantity'],
                ]);

                $total += $product->salePrice() * $item['quantity'];
                $product->decrement('stock', $item['quantity']);
            }

            $order->update([
                'total' => $total,
                'payment_status' => 'pending',
                'payment_amount' => in_array($validated['payment_method'], ['liqpay_hold', 'mono_checkout'], true) ? $total : null,
                'payment_currency' => 'UAH',
            ]);

            return $order;
        });

        $cart->clear();
        $request->session()->put('placed_order_id', $order->id);

        if ($order->payment_method === 'liqpay_hold') {
            return redirect()->to(route('payment.liqpay.checkout', $order, false));
        }

        if ($order->payment_method === 'mono_checkout') {
            return redirect()->to(route('payment.mono.checkout', $order, false));
        }

        return redirect()->to(localized_route('checkout.success', $order));
    }

    public function quick(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'phone' => auth()->check() ? ['nullable'] : ['required', 'string', 'max:30', new PhoneNumber],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $product = Product::query()->where('is_active', true)->findOrFail($validated['product_id']);
        $quantity = $validated['quantity'] ?? 1;

        if ($request->user() && ! $request->user()->phone) {
            throw ValidationException::withMessages([
                'phone' => __('У вашому профілі не вказано номер телефону. Зверніться до менеджера магазину.'),
            ]);
        }

        $order = DB::transaction(function () use ($product, $quantity, $validated, $request): Order {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);

            if (! $product->isPurchasable()) {
                throw ValidationException::withMessages([
                    'product_id' => $product->isPricePending()
                        ? __('Ціну цього товару ще не розраховано.')
                        : __('На жаль, товар вже недоступний для замовлення.'),
                ]);
            }

            if ($product->stock < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => __('На жаль, обраної кількості товару вже немає на складі.'),
                ]);
            }

            $userId = $request->user()?->id;

            if (! $userId) {
                $userId = app(GuestCustomerProfile::class)
                    ->resolveForQuickOrder(PhoneNumber::normalize($validated['phone']))
                    ->id;
            }

            $price = $product->salePrice();
            $order = Order::create([
                'order_type' => 'quick',
                'user_id' => $userId,
                'phone' => auth()->user()?->phone ?: PhoneNumber::normalize($validated['phone']),
                'payment_method' => 'cash_on_delivery',
                'total' => $price * $quantity,
                'comment' => __('Швидке замовлення. Потрібне підтвердження деталей доставки телефоном.'),
            ]);

            $order->items()->create([
                'product_id' => $product->id,
                'variant_id' => $product->variant_id,
                'product_name' => $product->name,
                'price' => $price,
                'quantity' => $quantity,
                'subtotal' => $price * $quantity,
            ]);

            $product->decrement('stock', $quantity);

            return $order;
        });

        $request->session()->put('placed_order_id', $order->id);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('Швидке замовлення прийнято. Ми зателефонуємо для підтвердження.'),
                'redirect' => localized_route('checkout.success', $order),
            ]);
        }

        return redirect()->to(localized_route('checkout.success', $order));
    }

    public function success(Order $order): View
    {
        abort_unless(
            session('placed_order_id') === $order->id || ($order->user_id !== null && auth()->id() === $order->user_id),
            404,
        );

        $trackPurchase = ! session()->has('ga_purchase_'.$order->id);
        if ($trackPurchase) {
            session()->put('ga_purchase_'.$order->id, true);
        }

        return view('store.success', [
            'order' => $order->loadMissing('items.product'),
            'trackPurchase' => $trackPurchase,
        ]);
    }
}
