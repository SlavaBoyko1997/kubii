<?php

namespace App\Services;

use App\Exceptions\LiqPayException;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class LiqPayService
{
    private const VERSION = 3;

    public function createHoldPayment(Order $order): array
    {
        $this->ensureConfigured();

        if ($order->payment_method !== 'liqpay_hold') {
            throw new LiqPayException('Замовлення не використовує оплату LiqPay hold.');
        }

        $parameters = array_filter([
            'version' => self::VERSION,
            'public_key' => $this->publicKey(),
            'action' => 'hold',
            'amount' => $this->money($order->payment_amount ?? $order->total),
            'currency' => 'UAH',
            'description' => 'Оплата замовлення №'.$order->number,
            'order_id' => $order->number,
            'result_url' => $this->resultUrl($order),
            'server_url' => $this->serverUrl(),
            'language' => app()->getLocale() === 'ru' ? 'ru' : 'uk',
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        if ((bool) config('services.liqpay.sandbox')) {
            $parameters['sandbox'] = 1;
        }

        $data = $this->encodeData($parameters);

        $this->log('hold.created', $order, [
            'amount' => $parameters['amount'],
            'currency' => $parameters['currency'],
            'sandbox' => $parameters['sandbox'] ?? 0,
        ]);

        $signature = $this->generateSignature($data);
        $url = (string) config('services.liqpay.checkout_url');

        return [
            'url' => $url,
            'checkout_url' => $url.'?'.http_build_query([
                'data' => $data,
                'signature' => $signature,
            ], encoding_type: PHP_QUERY_RFC3986),
            'data' => $data,
            'signature' => $signature,
        ];
    }

    public function encodeData(array $parameters): string
    {
        return base64_encode(json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function generateSignature(string $data): string
    {
        $this->ensureConfigured();

        return base64_encode(sha1($this->privateKey().$data.$this->privateKey(), true));
    }

    public function verifySignature(string $data, string $signature): bool
    {
        try {
            return hash_equals($this->generateSignature($data), $signature);
        } catch (LiqPayException) {
            return false;
        }
    }

    public function decodeData(string $data): array
    {
        $decoded = base64_decode($data, true);

        if ($decoded === false) {
            throw new LiqPayException('Некоректне кодування callback data.');
        }

        $payload = json_decode($decoded, true);

        if (! is_array($payload)) {
            throw new LiqPayException('Некоректний JSON у callback data.');
        }

        return $payload;
    }

    public function applyCallback(Order $order, array $payload): Order
    {
        return Cache::lock($this->lockName($order), 30)->block(5, function () use ($order, $payload): Order {
            return DB::transaction(function () use ($order, $payload): Order {
                $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
                $this->validateCallbackOrder($lockedOrder, $payload);

                $remoteStatus = (string) ($payload['status'] ?? '');
                $nextStatus = $this->mapCallbackStatus($remoteStatus);
                $currentStatus = (string) $lockedOrder->payment_status;

                $updates = [
                    'liqpay_payment_id' => $payload['payment_id'] ?? $lockedOrder->liqpay_payment_id,
                    'liqpay_transaction_id' => $payload['transaction_id']
                        ?? $payload['liqpay_order_id']
                        ?? $lockedOrder->liqpay_transaction_id,
                    'liqpay_response' => $payload,
                ];

                if ($this->canTransition($currentStatus, $nextStatus)) {
                    $updates['payment_status'] = $nextStatus;

                    if ($nextStatus === 'holded' && ! $lockedOrder->liqpay_hold_at) {
                        $updates['liqpay_hold_at'] = now();
                    } elseif ($nextStatus === 'paid' && ! $lockedOrder->liqpay_paid_at) {
                        $updates['liqpay_paid_at'] = now();
                    } elseif (in_array($nextStatus, ['cancelled', 'reversed'], true) && ! $lockedOrder->liqpay_cancelled_at) {
                        $updates['liqpay_cancelled_at'] = now();
                    }
                }

                $lockedOrder->update($updates);
                $this->log('callback.applied', $lockedOrder, [
                    'remote_status' => $remoteStatus,
                    'previous_status' => $currentStatus,
                    'payment_status' => $lockedOrder->payment_status,
                    'payment_id' => $lockedOrder->liqpay_payment_id,
                ]);

                return $lockedOrder->fresh();
            });
        });
    }

    public function captureHold(Order $order): Order
    {
        return Cache::lock($this->lockName($order), 30)->block(5, function () use ($order): Order {
            $order = $this->validateManualAction($order, 'capture');
            $response = $this->request([
                'action' => 'hold_completion',
                'amount' => $this->money($order->payment_amount),
                'currency' => 'UAH',
                'order_id' => $order->number,
            ], $order, 'hold.capture');

            if (! in_array((string) ($response['status'] ?? ''), ['success', 'sandbox'], true)) {
                throw new LiqPayException($this->responseError($response, 'LiqPay не підтвердив списання коштів.'));
            }

            return DB::transaction(function () use ($order, $response): Order {
                $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

                if ($lockedOrder->payment_status === 'paid') {
                    return $lockedOrder;
                }

                if ($lockedOrder->payment_status !== 'holded') {
                    throw new LiqPayException('Статус платежу змінився. Списання скасовано.');
                }

                $lockedOrder->update([
                    'payment_status' => 'paid',
                    'liqpay_paid_at' => $lockedOrder->liqpay_paid_at ?? now(),
                    'liqpay_payment_id' => $response['payment_id'] ?? $lockedOrder->liqpay_payment_id,
                    'liqpay_transaction_id' => $response['transaction_id']
                        ?? $response['liqpay_order_id']
                        ?? $lockedOrder->liqpay_transaction_id,
                    'liqpay_response' => $response,
                ]);

                $this->log('hold.captured', $lockedOrder, ['status' => $response['status'] ?? null]);

                return $lockedOrder->fresh();
            });
        });
    }

    public function cancelHold(Order $order): Order
    {
        return Cache::lock($this->lockName($order), 30)->block(5, function () use ($order): Order {
            $order = $this->validateManualAction($order, 'cancel');
            $response = $this->request([
                'action' => 'hold_completion',
                'amount' => '0.00',
                'currency' => 'UAH',
                'order_id' => $order->number,
            ], $order, 'hold.cancel');

            if (! in_array((string) ($response['status'] ?? ''), ['success', 'reversed', 'sandbox'], true)) {
                throw new LiqPayException($this->responseError($response, 'LiqPay не підтвердив скасування холду.'));
            }

            return DB::transaction(function () use ($order, $response): Order {
                $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

                if (in_array($lockedOrder->payment_status, ['cancelled', 'reversed'], true)) {
                    return $lockedOrder;
                }

                if ($lockedOrder->payment_status !== 'holded') {
                    throw new LiqPayException('Статус платежу змінився. Скасування холду неможливе.');
                }

                $lockedOrder->update([
                    'payment_status' => 'reversed',
                    'liqpay_cancelled_at' => $lockedOrder->liqpay_cancelled_at ?? now(),
                    'liqpay_payment_id' => $response['payment_id'] ?? $lockedOrder->liqpay_payment_id,
                    'liqpay_transaction_id' => $response['transaction_id']
                        ?? $response['liqpay_order_id']
                        ?? $lockedOrder->liqpay_transaction_id,
                    'liqpay_response' => $response,
                ]);

                $this->log('hold.cancelled', $lockedOrder, ['status' => $response['status'] ?? null]);

                return $lockedOrder->fresh();
            });
        });
    }

    public function logSuspiciousCallback(string $reason, array $context = []): void
    {
        Log::channel('liqpay')->warning('LiqPay suspicious callback: '.$reason, $this->safeContext($context));
    }

    private function request(array $parameters, Order $order, string $event): array
    {
        $this->ensureConfigured();

        $parameters = [
            'version' => self::VERSION,
            'public_key' => $this->publicKey(),
            ...$parameters,
        ];
        $data = $this->encodeData($parameters);
        $this->log($event.'.requested', $order, [
            'action' => $parameters['action'],
            'amount' => $parameters['amount'],
        ]);

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(20)
                ->retry(2, 300)
                ->post((string) config('services.liqpay.api_url'), [
                    'data' => $data,
                    'signature' => $this->generateSignature($data),
                ])
                ->throw()
                ->json();
        } catch (Throwable $exception) {
            $this->log($event.'.failed', $order, ['message' => $exception->getMessage()], 'error');
            throw new LiqPayException('LiqPay тимчасово недоступний. Спробуйте ще раз пізніше.', previous: $exception);
        }

        if (! is_array($response)) {
            throw new LiqPayException('LiqPay повернув некоректну відповідь.');
        }

        $this->log($event.'.response', $order, $response);

        return $response;
    }

    private function validateManualAction(Order $order, string $action): Order
    {
        $order = $order->fresh();

        if (! $order || $order->payment_method !== 'liqpay_hold') {
            throw new LiqPayException('Замовлення з оплатою LiqPay не знайдено.');
        }

        if ($order->payment_status === 'paid') {
            throw new LiqPayException($action === 'cancel'
                ? 'Не можна скасувати холд після списання коштів.'
                : 'Кошти за цим замовленням уже списані.');
        }

        if ($order->payment_status !== 'holded') {
            throw new LiqPayException('Операція доступна лише для платежу зі статусом holded.');
        }

        if (blank($order->liqpay_payment_id) && blank($order->liqpay_transaction_id)) {
            throw new LiqPayException('У замовленні відсутній ідентифікатор платежу LiqPay.');
        }

        if ($this->cents($order->payment_amount) !== $this->cents($order->total)) {
            throw new LiqPayException('Сума замовлення змінилася після холду.');
        }

        return $order;
    }

    private function validateCallbackOrder(Order $order, array $payload): void
    {
        if ($order->payment_method !== 'liqpay_hold') {
            throw new LiqPayException('Callback отримано для замовлення без LiqPay hold.');
        }

        if ((string) ($payload['order_id'] ?? '') !== (string) $order->number) {
            throw new LiqPayException('Номер замовлення в callback не збігається.');
        }

        if (! in_array((string) ($payload['action'] ?? ''), ['hold', 'hold_completion'], true)) {
            throw new LiqPayException('Callback має неприпустимий тип операції.');
        }

        if (strtoupper((string) ($payload['currency'] ?? '')) !== 'UAH') {
            throw new LiqPayException('Валюта callback не збігається з валютою замовлення.');
        }

        if ($this->cents($payload['amount'] ?? null) !== $this->cents($order->payment_amount ?? $order->total)) {
            throw new LiqPayException('Сума callback не збігається із сумою замовлення.');
        }
    }

    private function mapCallbackStatus(string $status): string
    {
        return match ($status) {
            'holded', 'sandbox' => 'holded',
            'success' => 'paid',
            'reversed' => 'reversed',
            'failure', 'error' => 'failed',
            'hold_wait', 'wait_secure', 'processing' => 'pending',
            default => throw new LiqPayException('Невідомий статус LiqPay: '.$status),
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

    private function ensureConfigured(): void
    {
        if ($this->publicKey() === '' || $this->privateKey() === '') {
            throw new LiqPayException('Ключі LiqPay не налаштовані.');
        }
    }

    private function publicKey(): string
    {
        return trim((string) config('services.liqpay.public_key'));
    }

    private function privateKey(): string
    {
        return trim((string) config('services.liqpay.private_key'));
    }

    private function resultUrl(Order $order): ?string
    {
        $url = filled(config('services.liqpay.result_url'))
            ? (string) config('services.liqpay.result_url')
            : route('payment.liqpay.result', ['order' => $order->number]);

        return $this->publicHttpsUrl($url);
    }

    private function serverUrl(): ?string
    {
        $url = filled(config('services.liqpay.server_url'))
            ? (string) config('services.liqpay.server_url')
            : route('payment.liqpay.callback');

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

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function responseError(array $response, string $fallback): string
    {
        return (string) ($response['err_description'] ?? $response['error_description'] ?? $fallback);
    }

    private function lockName(Order $order): string
    {
        return 'liqpay:order:'.$order->id;
    }

    private function log(string $event, Order $order, array $context = [], string $level = 'info'): void
    {
        Log::channel('liqpay')->{$level}('LiqPay '.$event, [
            'order_id' => $order->id,
            'order_number' => $order->number,
            ...$this->safeContext($context),
        ]);
    }

    private function safeContext(array $context): array
    {
        return collect($context)
            ->except(['data', 'signature', 'private_key', 'sender_card_mask2', 'sender_card_bank', 'sender_card_country'])
            ->all();
    }
}
