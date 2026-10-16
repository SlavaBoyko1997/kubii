<?php

namespace App\Console\Commands;

use App\Services\TelegramNotifier;
use Illuminate\Console\Command;

class TelegramTestCommand extends Command
{
    protected $signature = 'telegram:test {--message=Test BASH order notification}';

    protected $description = 'Send a test message to the configured Telegram chat';

    public function handle(TelegramNotifier $telegram): int
    {
        if (! $telegram->enabled()) {
            $this->error('Telegram is not configured. Set TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID and TELEGRAM_ORDERS_ENABLED=true.');

            return self::FAILURE;
        }

        $ok = $telegram->sendMessage((string) $this->option('message'));

        if (! $ok) {
            $this->error('Failed to send Telegram message. Check logs and bot permissions.');

            return self::FAILURE;
        }

        $this->info('Telegram test message sent to chat '.$telegram->chatId());

        return self::SUCCESS;
    }
}
