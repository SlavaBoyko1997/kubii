<?php

namespace App\Http\Controllers;

use App\Exceptions\MonobankException;
use App\Models\Order;
use App\Services\MonobankService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class MonobankController extends Controller
{
    public function checkout(Order $order, MonobankService $monobank): View
    {
        $this->authorizeOrderAccess($order);

        if ($order->payment_method !== 'mono_checkout' || $order->payment_status !== 'pending') {
            return view('store.mono-result', ['order' => $order]);
        }

        try {
            $payment = $monobank->createHoldInvoice($order);
        } catch (MonobankException $exception) {
            report($exception);

            return view('store.mono-result', [
                'order' => $order,
                'paymentError' => app()->environment('local')
                    ? __('Не вдалося відкрити оплату Monobank: :message', ['message' => $exception->getMessage()])
                    : __('Не вдалося відкрити оплату Monobank. Замовлення збережене, менеджер зв’яжеться з вами.'),
            ]);
        }

        return view('store.mono-redirect', [
            'order' => $order,
            'payment' => $payment,
        ]);
    }

    public function webhook(Request $request, MonobankService $monobank): Response
    {
        $body = $request->getContent();
        $signature = $request->header('X-Sign');

        if ($body === '') {
            $monobank->logSuspiciousWebhook('empty body');

            return response('Invalid webhook', 400);
        }

        if (! $monobank->verifyWebhookSignature($body, $signature)) {
            $monobank->logSuspiciousWebhook('invalid signature');

            return response('Invalid signature', 400);
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($payload)) {
                throw new MonobankException('Некоректний JSON у webhook.');
            }

            $order = null;

            if (filled($payload['reference'] ?? null)) {
                $order = Order::query()->where('number', (string) $payload['reference'])->first();
            }

            if (! $order && filled($payload['invoiceId'] ?? null)) {
                $order = Order::query()->where('mono_invoice_id', (string) $payload['invoiceId'])->first();
            }

            if (! $order) {
                $monobank->logSuspiciousWebhook('order not found', [
                    'reference' => $payload['reference'] ?? null,
                    'invoice_id' => $payload['invoiceId'] ?? null,
                ]);

                return response('Order not found', 404);
            }

            $monobank->applyWebhook($order, $payload);
        } catch (MonobankException $exception) {
            $monobank->logSuspiciousWebhook($exception->getMessage());

            return response('Invalid webhook', 422);
        } catch (\JsonException) {
            $monobank->logSuspiciousWebhook('invalid json');

            return response('Invalid webhook', 400);
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
