<?php

namespace App\Http\Controllers;

use App\Exceptions\LiqPayException;
use App\Models\Order;
use App\Services\LiqPayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class LiqPayController extends Controller
{
    public function checkout(Order $order, LiqPayService $liqPay): View
    {
        $this->authorizeOrderAccess($order);

        if ($order->payment_method !== 'liqpay_hold' || $order->payment_status !== 'pending') {
            return view('store.liqpay-result', ['order' => $order]);
        }

        try {
            $payment = $liqPay->createHoldPayment($order);
        } catch (LiqPayException $exception) {
            report($exception);

            return view('store.liqpay-result', [
                'order' => $order,
                'paymentError' => __('Не вдалося відкрити оплату LiqPay. Замовлення збережене, менеджер зв’яжеться з вами.'),
            ]);
        }

        return view('store.liqpay-redirect', [
            'order' => $order,
            'payment' => $payment,
        ]);
    }

    public function callback(Request $request, LiqPayService $liqPay): Response
    {
        $data = (string) $request->input('data', '');
        $signature = (string) $request->input('signature', '');

        if ($data === '' || $signature === '') {
            $liqPay->logSuspiciousCallback('missing data or signature');

            return response('Invalid callback', 400);
        }

        if (! $liqPay->verifySignature($data, $signature)) {
            $liqPay->logSuspiciousCallback('invalid signature');

            return response('Invalid signature', 400);
        }

        try {
            $payload = $liqPay->decodeData($data);
            $order = Order::query()->where('number', (string) ($payload['order_id'] ?? ''))->first();

            if (! $order) {
                $liqPay->logSuspiciousCallback('order not found', ['order_id' => $payload['order_id'] ?? null]);

                return response('Order not found', 404);
            }

            $liqPay->applyCallback($order, $payload);
        } catch (LiqPayException $exception) {
            $liqPay->logSuspiciousCallback($exception->getMessage());

            return response('Invalid callback', 422);
        }

        return response('OK');
    }

    public function result(Order $order): RedirectResponse
    {
        $this->authorizeOrderAccess($order);

        return redirect()->to(localized_route('checkout.success', $order));
    }

    private function authorizeOrderAccess(Order $order): void
    {
        abort_unless(
            session('placed_order_id') === $order->id
                || ($order->user_id !== null && auth()->id() === $order->user_id),
            404,
        );
    }
}
