<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\TelegramNotifier;
use App\Support\TelegramOrderMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendOrderTelegramNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $orderId)
    {
        $this->onQueue('default');
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(TelegramNotifier $telegram): void
    {
        if (! $telegram->enabled()) {
            return;
        }

        $order = Order::query()->with('items')->find($this->orderId);

        if (! $order) {
            return;
        }

        $sent = $telegram->sendMessage(TelegramOrderMessage::fromOrder($order));

        if (! $sent) {
            Log::warning('Order Telegram notification was not delivered', [
                'order_id' => $this->orderId,
                'order_number' => $order->number,
            ]);
        }
    }
}
