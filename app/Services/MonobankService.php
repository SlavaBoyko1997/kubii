<?php

namespace App\Services;

use App\Exceptions\MonobankException;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MonobankService
{
    public function createHoldInvoice(Order $order): array
    {
        $this->ensureConfigured();

        if ($order->payment_method !== 'mono_checkout') {
            throw new MonobankException('Замовлення не використовує Monobank Checkout.');
        }

        $order->loadMissing('items');

        $payload = array_filter([
            'amount' => $this->kopiykas($order->payment_amount ?? $order->total),
            'ccy' => 980,
            'merchantPaymInfo' => array_filter([
                'reference' => $order->number,
                'destination' => 'Оплата замовлення №'.$order->number,
                'customerEmails' => filled($order->email) ? [$order->email] : null,
                'basketOrder' => $order->items->map(fn ($item): array => [
                    'name' => $item->product_name,
                    'qty' => (int) $item->quantity,
                    'sum' => $this->kopiykas($item->price),
                    'total' => $this->kopiykas($item->subtotal),
                ])->all(),
            ], fn (mixed $value): bool => $value !== null && $value !== []),
            'redirectUrl' => $this->redirectUrl($order),
            'webHookUrl' => $this->webhookUrl(),
            'paymentType' => 'hold',
            'validity' => 86400,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $this->log('hold.create.requested', $order, [
            'amount' => $payload['amount'],
            'reference' => $order->number,
        ]);

        $response = $this->request('/api/merchant/invoice/create', $payload, $order, 'hold.create');

        $invoiceId = (string) ($response['invoiceId'] ?? '');
        $pageUrl = (string) ($response['pageUrl'] ?? '');

        if ($invoiceId === '' || $pageUrl === '') {
            throw new MonobankException('Monobank не повернув дані для оплати.');
        }

        $order->update([
            'mono_invoice_id' => $invoiceId,
            'mono_response' => $response,
        ]);

        $this->log('hold.created', $order, [
            'invoice_id' => $invoiceId,
        ]);

        return [
            'invoice_id' => $invoiceId,
            'page_url' => $pageUrl,
        ];
    }

    public function verifyWebhookSignature(string $body, ?string $signature): bool
    {
        if (! (bool) config('services.monobank.verify_webhook', true)) {
            return true;
        }

        if (blank($signature)) {
            return false;
        }

        $signatureBytes = base64_decode($signature, true);

        if ($signatureBytes === false) {
            return false;
        }

        $publicKeyPem = base64_decode($this->publicKeyBase64(), true);

        if ($publicKeyPem === false) {
            return false;
        }

        $key = openssl_pkey_get_public($publicKeyPem);

        if ($key === false) {
            return false;
        }

        $verified = openssl_verify($body, $signatureBytes, $key, OPENSSL_ALGO_SHA256) === 1;
        openssl_free_key($key);

        return $verified;
    }

    public function applyWebhook(Order $order, array $payload): Order
    {
        return Cache::lock($this->lockName($order), 30)->block(5, function () use ($order, $payload): Order {
            return DB::transaction(function () use ($order, $payload): Order {
                $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
                $this->validateWebhookOrder($lockedOrder, $payload);

                if ($this->isStaleWebhook($lockedOrder, $payload)) {
                    $this->log('webhook.stale', $lockedOrder, [
                        'remote_status' => $payload['status'] ?? null,
                        'modified_date' => $payload['modifiedDate'] ?? null,
                    ]);

                    return $lockedOrder;
                }

                $remoteStatus = (string) ($payload['status'] ?? '');
                $currentStatus = (string) $lockedOrder->payment_status;
                $nextStatus = $this->mapWebhookStatus($remoteStatus, $currentStatus);

                $updates = [
                    'mono_invoice_id' => $payload['invoiceId'] ?? $lockedOrder->mono_invoice_id,
                    'mono_response' => $payload,
                ];

                if ($this->canTransition($currentStatus, $nextStatus)) {
                    $updates['payment_status'] = $nextStatus;

                    if ($nextStatus === 'holded' && ! $lockedOrder->mono_hold_at) {
                        $updates['mono_hold_at'] = now();
                    } elseif ($nextStatus === 'paid' && ! $lockedOrder->mono_paid_at) {
                        $updates['mono_paid_at'] = now();
                    } elseif (in_array($nextStatus, ['cancelled', 'reversed'], true) && ! $lockedOrder->mono_cancelled_at) {
                        $updates['mono_cancelled_at'] = now();
                    }
                }

                $lockedOrder->update($updates);
                $this->log('webhook.applied', $lockedOrder, [
                    'remote_status' => $remoteStatus,
                    'previous_status' => $currentStatus,
                    'payment_status' => $lockedOrder->payment_status,
                    'invoice_id' => $lockedOrder->mono_invoice_id,
                ]);

                return $lockedOrder->fresh();
            });
        });
    }

    public function finalizeHold(Order $order): Order
    {
        return Cache::lock($this->lockName($order), 30)->block(5, function () use ($order): Order {
            $order = $this->validateManualAction($order, 'finalize');
            $response = $this->request('/api/merchant/invoice/finalize', [
                'invoiceId' => $order->mono_invoice_id,
                'amount' => $this->kopiykas($order->payment_amount),
            ], $order, 'hold.finalize');

            if ((string) ($response['status'] ?? '') !== 'success') {
                throw new MonobankException($this->responseError($response, 'Monobank не підтвердив списання коштів.'));
            }

            return DB::transaction(function () use ($order, $response): Order {
                $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

                if ($lockedOrder->payment_status === 'paid') {
                    return $lockedOrder;
                }

                if ($lockedOrder->payment_status !== 'holded') {
                    throw new MonobankException('Статус платежу змінився. Списання скасовано.');
                }

                $lockedOrder->update([
                    'payment_status' => 'paid',
                    'mono_paid_at' => $lockedOrder->mono_paid_at ?? now(),
                    'mono_response' => $response,
                ]);

                $this->log('hold.finalized', $lockedOrder);

                return $lockedOrder->fresh();
            });
        });
    }

    public function cancelHold(Order $order): Order
    {
        return Cache::lock($this->lockName($order), 30)->block(5, function () use ($order): Order {
            $order = $this->validateManualAction($order, 'cancel');
            $response = $this->request('/api/merchant/invoice/cancel', [
                'invoiceId' => $order->mono_invoice_id,
            ], $order, 'hold.cancel');

            return DB::transaction(function () use ($order, $response): Order {
                $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

                if (in_array($lockedOrder->payment_status, ['cancelled', 'reversed'], true)) {
                    return $lockedOrder;
                }

                if ($lockedOrder->payment_status !== 'holded') {
                    throw new MonobankException('Статус платежу змінився. Скасування холду неможливе.');
                }

                $lockedOrder->update([
                    'payment_status' => 'reversed',
                    'mono_cancelled_at' => $lockedOrder->mono_cancelled_at ?? now(),
                    'mono_response' => $response,
                ]);

                $this->log('hold.cancelled', $lockedOrder);

                return $lockedOrder->fresh();
            });
        });
    }

    public function logSuspiciousWebhook(string $reason, array $context = []): void
    {
        Log::channel('monobank')->warning('Monobank suspicious webhook: '.$reason, $this->safeContext($context));
    }

    private function request(string $path, array $payload, Order $order, string $event): array
    {
        $this->ensureConfigured();

        try {
            $httpResponse = Http::withHeaders([
                'X-Token' => $this->token(),
            ])
                ->acceptJson()
                ->asJson()
                ->timeout(20)
                ->retry(2, 300)
                ->post($this->apiUrl($path), $payload);
        } catch (Throwable $exception) {
            $this->log($event.'.failed', $order, ['message' => $exception->getMessage()], 'error');
            throw new MonobankException('Monobank тимчасово недоступний. Спробуйте ще раз пізніше.', previous: $exception);
        }

        $response = $httpResponse->json();

        if ($httpResponse->failed()) {
            $message = $this->responseError(is_array($response) ? $response : [], 'Monobank відхилив запит.');
            $this->log($event.'.failed', $order, [
                'status' => $httpResponse->status(),
                'body' => is_array($response) ? $response : $httpResponse->body(),
            ], 'error');
            throw new MonobankException($message);
        }

        if (! is_array($response)) {
            throw new MonobankException('Monobank повернув некоректну відповідь.');
        }

        $this->log($event.'.response', $order, $response);

        return $response;
    }

    private function validateManualAction(Order $order, string $action): Order
    {
        $order = $order->fresh();

        if (! $order || $order->payment_method !== 'mono_checkout') {
            throw new MonobankException('Замовлення з оплатою Monobank не знайдено.');
        }

        if ($order->payment_status === 'paid') {
            throw new MonobankException($action === 'cancel'
                ? 'Не можна скасувати холд після списання коштів.'
                : 'Кошти за цим замовленням уже списані.');
        }

        if ($order->payment_status !== 'holded') {
            throw new MonobankException('Операція доступна лише для платежу зі статусом holded.');
        }

        if (blank($order->mono_invoice_id)) {
            throw new MonobankException('У замовленні відсутній ідентифікатор рахунку Monobank.');
        }

        if ($this->kopiykas($order->payment_amount) !== $this->kopiykas($order->total)) {
            throw new MonobankException('Сума замовлення змінилася після холду.');
        }

        return $order;
    }

    private function validateWebhookOrder(Order $order, array $payload): void
    {
        if ($order->payment_method !== 'mono_checkout') {
            throw new MonobankException('Webhook отримано для замовлення без Monobank Checkout.');
        }

        $reference = (string) ($payload['reference'] ?? '');

        if ($reference !== '' && $reference !== (string) $order->number) {
            throw new MonobankException('Номер замовлення в webhook не збігається.');
        }

        if (filled($order->mono_invoice_id)
            && filled($payload['invoiceId'] ?? null)
            && (string) $payload['invoiceId'] !== (string) $order->mono_invoice_id) {
            throw new MonobankException('Ідентифікатор рахунку в webhook не збігається.');
        }

        if (($payload['ccy'] ?? 980) !== 980) {
            throw new MonobankException('Валюта webhook не збігається з валютою замовлення.');
        }

        $expectedAmount = $this->kopiykas($order->payment_amount ?? $order->total);
        $payloadAmount = (int) ($payload['amount'] ?? 0);

        if ($payloadAmount > 0 && $payloadAmount !== $expectedAmount) {
            throw new MonobankException('Сума webhook не збігається із сумою замовлення.');
        }
    }

    private function isStaleWebhook(Order $order, array $payload): bool
    {
        $incoming = $payload['modifiedDate'] ?? null;
        $stored = is_array($order->mono_response) ? ($order->mono_response['modifiedDate'] ?? null) : null;

        if (! is_string($incoming) || ! is_string($stored) || $incoming === '' || $stored === '') {
            return false;
        }

        return strtotime($incoming) <= strtotime($stored);
    }

    private function mapWebhookStatus(string $status, string $currentStatus): string
    {
        return match ($status) {
            'success' => $currentStatus === 'holded' ? 'paid' : 'holded',
            'processing', 'created' => 'pending',
            'failure' => 'failed',
            'reversed' => 'reversed',
            default => throw new MonobankException('Невідомий статус Monobank: '.$status),
        };
    }

    private function canTransition(string $current, string $next): bool
    {
        if ($current === $next) {
            return false;
        }

        if ($current === 'paid') {
            return false;
        }

        if (in_array($current, ['cancelled', 'reversed'], true)) {
            return false;
        }

        if ($current === 'holded' && in_array($next, ['pending', 'failed'], true)) {
            return false;
        }

        return true;
    }

    private function publicKeyBase64(): string
    {
        return Cache::remember('monobank:pubkey', now()->addDay(), function (): string {
            $this->ensureConfigured();

            try {
                $response = Http::withHeaders(['X-Token' => $this->token()])
                    ->acceptJson()
                    ->timeout(20)
                    ->get($this->apiUrl('/api/merchant/pubkey'))
                    ->throw()
                    ->json();
            } catch (Throwable $exception) {
                throw new MonobankException('Не вдалося отримати публічний ключ Monobank.', previous: $exception);
            }

            $key = is_array($response) ? (string) ($response['key'] ?? '') : '';

            if ($key === '') {
                throw new MonobankException('Monobank не повернув публічний ключ.');
            }

            return $key;
        });
    }

    private function ensureConfigured(): void
    {
        if ($this->token() === '') {
            throw new MonobankException('Токен Monobank не налаштований.');
        }
    }

    private function token(): string
    {
        return trim((string) config('services.monobank.token'));
    }

    private function apiUrl(string $path): string
    {
        return rtrim((string) config('services.monobank.api_url'), '/').$path;
    }

    private function redirectUrl(Order $order): ?string
    {
        $url = filled(config('services.monobank.redirect_url'))
            ? (string) config('services.monobank.redirect_url')
            : route('payment.mono.result', ['order' => $order->number]);

        return $this->publicHttpsUrl($url);
    }

    private function webhookUrl(): ?string
    {
        $url = filled(config('services.monobank.webhook_url'))
            ? (string) config('services.monobank.webhook_url')
            : route('payment.mono.webhook');

        return $this->publicHttpsUrl($url);
    }

    private function publicHttpsUrl(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (parse_url($url, PHP_URL_SCHEME) !== 'https'
            || in_array($host, ['', 'localhost', '127.0.0.1', '::1'], true)) {
            return null;
        }

        return $url;
    }

    private function kopiykas(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function responseError(array $response, string $fallback): string
    {
        return (string) ($response['errText']
            ?? $response['errorDescription']
            ?? $response['failureReason']
            ?? $fallback);
    }

    private function lockName(Order $order): string
    {
        return 'monobank:order:'.$order->id;
    }

    private function log(string $event, Order $order, array $context = [], string $level = 'info'): void
    {
        Log::channel('monobank')->{$level}('Monobank '.$event, [
            'order_id' => $order->id,
            'order_number' => $order->number,
            ...$this->safeContext($context),
        ]);
    }

    private function safeContext(array $context): array
    {
        return collect($context)
            ->except(['cardData', 'pan', 'cvv'])
            ->all();
    }
}
