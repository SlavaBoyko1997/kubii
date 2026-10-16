<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotifier
{
    public function enabled(): bool
    {
        if (! config('services.telegram.orders_enabled', true)) {
            return false;
        }

        return $this->botToken() !== null && $this->chatId() !== null;
    }

    public function botToken(): ?string
    {
        $token = trim((string) config('services.telegram.bot_token', ''));

        return $token !== '' ? $token : null;
    }

    public function chatId(): ?string
    {
        $chatId = trim((string) config('services.telegram.chat_id', ''));

        if ($chatId === '') {
            return null;
        }

        if (preg_match('/^100\d{9,}$/', $chatId) === 1) {
            return '-'.$chatId;
        }

        return $chatId;
    }

    public function sendMessage(string $text): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $text = mb_substr($text, 0, 4000);

        try {
            $response = Http::timeout(10)
                ->asForm()
                ->post($this->apiUrl('sendMessage'), [
                    'chat_id' => $this->chatId(),
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);

            if (! $response->successful() || ! ($response->json('ok') ?? false)) {
                Log::warning('Telegram sendMessage failed', [
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Telegram sendMessage exception', [
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function apiUrl(string $method): string
    {
        return 'https://api.telegram.org/bot'.$this->botToken().'/'.$method;
    }
}
