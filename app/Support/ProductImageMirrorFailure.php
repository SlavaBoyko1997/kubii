<?php

namespace App\Support;

class ProductImageMirrorFailure
{
    /**
     * @return array{reason: string, detail: string, url: string, request_url: string|null, product_id: int|null}
     */
    public static function entry(string $reason, string $url, string $detail = '', ?int $productId = null, ?string $requestUrl = null): array
    {
        return [
            'reason' => $reason,
            'detail' => $detail,
            'url' => $url,
            'request_url' => $requestUrl,
            'product_id' => $productId,
        ];
    }

    public static function label(string $reason): string
    {
        if (str_starts_with($reason, 'http:')) {
            $code = substr($reason, 5);

            return match ($code) {
                '403' => 'HTTP 403 — доступ заборонено',
                '404' => 'HTTP 404 — файл не знайдено',
                '429' => 'HTTP 429 — занадто багато запитів',
                default => str_starts_with($code, '5') ? 'Помилка сервера (HTTP '.$code.')' : 'HTTP '.$code,
            };
        }

        return match ($reason) {
            'blocked_url' => 'Недозволена адреса (SSRF-захист)',
            'dns_blocked' => 'Хост не пройшов DNS-перевірку',
            'not_image' => 'Відповідь не є зображенням',
            'empty' => 'Порожня відповідь',
            'too_large' => 'Файл занадто великий',
            'invalid_image' => 'Пошкоджене або непідтримуване зображення',
            'too_small' => 'Зображення замале (< 40 px)',
            'save_failed' => 'Не вдалося зберегти WebP локально',
            'network' => 'Мережева помилка або таймаут',
            'job_failed' => 'Завдання вичерпало всі спроби',
            default => $reason,
        };
    }
}
