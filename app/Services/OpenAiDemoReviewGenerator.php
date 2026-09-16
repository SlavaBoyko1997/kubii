<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OpenAiDemoReviewGenerator
{
    public const PROMPT_VERSION = 'demo-product-reviews-v1';

    public const STYLES = [
        'mixed' => 'змішаний',
        'short' => 'короткий',
        'normal' => 'звичайний',
        'detailed' => 'детальний',
        'conversational' => 'розмовний',
    ];

    /**
     * @return array{reviews: list<array<string, mixed>>, response_id: ?string, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}}
     */
    public function generate(Product $product, int $count, string $style, int $minRating = 4, int $maxRating = 5): array
    {
        $apiKey = (string) config('services.openai.api_key');
        $model = (string) config('services.openai.model');

        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY не налаштований.');
        }

        if ($model === '') {
            throw new RuntimeException('OPENAI_MODEL не налаштований.');
        }

        $payload = [
            'model' => $model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => $this->systemPrompt(),
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'task' => 'generate_demo_product_reviews',
                        'language' => 'uk',
                        'count' => $count,
                        'style' => $style,
                        'rating' => [
                            'min' => $minRating,
                            'max' => $maxRating,
                            'distribution_hint' => $this->ratingDistributionHint($minRating, $maxRating),
                        ],
                        'product' => $this->productPayload($product),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'demo_product_reviews',
                    'strict' => true,
                    'schema' => $this->jsonSchema($minRating, $maxRating),
                ],
            ],
        ];

        $response = $this->postWithRetries($payload);
        $json = $this->extractOutputText($response);

        if ($json === '') {
            throw new RuntimeException('OpenAI повернув порожню відповідь.');
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI повернув невалідний JSON.');
        }

        $reviews = $this->validateReviews($decoded, $count, $minRating, $maxRating);

        return [
            'reviews' => $reviews,
            'response_id' => $response->json('id'),
            'usage' => [
                'input_tokens' => (int) ($response->json('usage.input_tokens') ?? 0),
                'output_tokens' => (int) ($response->json('usage.output_tokens') ?? 0),
                'total_tokens' => (int) ($response->json('usage.total_tokens') ?? 0),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWithRetries(array $payload): Response
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = Http::withToken((string) config('services.openai.api_key'))
                    ->acceptJson()
                    ->asJson()
                    ->timeout((int) config('services.openai.timeout', 60))
                    ->post('https://api.openai.com/v1/responses', $payload);

                if ($response->successful()) {
                    return $response;
                }

                $message = match ($response->status()) {
                    401 => 'OpenAI API: невірний або відсутній API-ключ.',
                    429 => 'OpenAI API: rate limit, спробуйте пізніше.',
                    default => 'OpenAI API помилка HTTP '.$response->status().': '.Str::limit($response->body(), 500),
                };

                throw new RuntimeException($message);
            } catch (\Throwable $exception) {
                $lastException = $exception;

                if ($attempt < 3) {
                    usleep(300_000 * $attempt);
                }
            }
        }

        throw $lastException instanceof \Throwable
            ? $lastException
            : new RuntimeException('OpenAI API недоступний.');
    }

    private function extractOutputText(Response $response): string
    {
        $outputText = $response->json('output_text');

        if (is_string($outputText) && trim($outputText) !== '') {
            return trim($outputText);
        }

        $parts = [];

        foreach ((array) $response->json('output') as $output) {
            foreach ((array) data_get($output, 'content', []) as $content) {
                $text = data_get($content, 'text');

                if (is_string($text)) {
                    $parts[] = $text;
                }
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return list<array<string, mixed>>
     */
    private function validateReviews(array $decoded, int $count, int $minRating, int $maxRating): array
    {
        $reviews = $decoded['reviews'] ?? null;

        if (! is_array($reviews) || count($reviews) !== $count) {
            throw new RuntimeException('OpenAI повернув неправильну кількість відгуків.');
        }

        $validStyles = array_keys(self::STYLES);

        return collect($reviews)->map(function (mixed $review) use ($validStyles, $minRating, $maxRating): array {
            if (! is_array($review)) {
                throw new RuntimeException('Некоректний елемент reviews.');
            }

            $text = trim((string) ($review['text'] ?? ''));
            $rating = (int) ($review['rating'] ?? 0);
            $style = (string) ($review['style'] ?? 'normal');

            if ($text === '' || str_word_count($text, 0, 'АБВГҐДЕЄЖЗИІЇЙКЛМНОПРСТУФХЦЧШЩЬЮЯабвгґдеєжзиіїйклмнопрстуфхцчшщьюя') > 55) {
                throw new RuntimeException('Некоректна довжина відгуку.');
            }

            if ($rating < $minRating || $rating > $maxRating || ! in_array($rating, [3, 4, 5], true)) {
                throw new RuntimeException('OpenAI повернув рейтинг поза дозволеним діапазоном.');
            }

            if (! in_array($style, $validStyles, true)) {
                throw new RuntimeException('OpenAI повернув невідомий стиль.');
            }

            foreach (['mentions_delivery', 'mentions_service', 'has_intentional_typo'] as $field) {
                if (! is_bool($review[$field] ?? null)) {
                    throw new RuntimeException("OpenAI повернув не boolean {$field}.");
                }
            }

            return [
                'text' => $text,
                'rating' => $rating,
                'style' => $style,
                'mentions_delivery' => (bool) $review['mentions_delivery'],
                'mentions_service' => (bool) $review['mentions_service'],
                'has_intentional_typo' => (bool) $review['has_intentional_typo'],
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function productPayload(Product $product): array
    {
        $specifications = collect((array) $product->specifications)
            ->filter(fn (mixed $value, mixed $key): bool => filled($key) && filled($value))
            ->take(30)
            ->all();

        return array_filter([
            'name' => $this->plain((string) $product->name, 180),
            'description' => $this->plain((string) ($product->description ?: $product->content), 1400),
            'specifications' => $specifications,
            'price' => $product->sale_price ?: $product->price,
            'brand' => $product->brand,
            'model' => $product->model,
        ], fn (mixed $value): bool => filled($value));
    }

    public function inputHash(Product $product, int $count, string $style, string $dateFrom, string $dateTo, string $batchId): string
    {
        return hash('sha256', json_encode([
            'product_id' => $product->id,
            'count' => $count,
            'style' => $style,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'batch_id' => $batchId,
            'prompt_version' => self::PROMPT_VERSION,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function plain(string $value, int $limit): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return Str::limit(trim($value), $limit, '');
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Ти генеруєш виключно тестові демонстраційні відгуки для непублічного середовища розробки інтернет-магазину.
Відгуки не належать реальним покупцям і не будуть показуватися на production-сайті без ручного рішення адміністратора.
Створюй природні українські тестові відгуки на основі назви, опису, характеристик і ціни товару.
Довжина одного відгуку — від 5 до 45 слів.
Відгуки повинні відрізнятися довжиною, вступом, структурою, словами, деталями та стилем.
Можливі теми: якість товару, відповідність опису, зручність, комплектація, доставка, пакування, сервіс, дзвінок менеджера, співвідношення ціни та якості.
Рейтинг кожного тестового відгуку має бути тільки в дозволеному діапазоні з користувацького запиту. Більшість відгуків повинні мати високий рейтинг, але допускай помірно критичні 3–4 зірки, якщо вони дозволені.
Не створюй занадто рекламні тексти. Не використовуй однакові шаблони.
Не більше 15% текстів можуть містити одну незначну природну описку або пропущений розділовий знак.
Не використовуй нецензурну лексику, образи, російськомовні слова, політичні теми або персональні дані.
Не генеруй імена, прізвища, телефони або email.
Поверни виключно валідний JSON відповідно до переданої схеми.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonSchema(int $minRating, int $maxRating): array
    {
        $ratings = collect([3, 4, 5])
            ->filter(fn (int $rating): bool => $rating >= $minRating && $rating <= $maxRating)
            ->values()
            ->all();

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['reviews'],
            'properties' => [
                'reviews' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['text', 'rating', 'style', 'mentions_delivery', 'mentions_service', 'has_intentional_typo'],
                        'properties' => [
                            'text' => ['type' => 'string'],
                            'rating' => ['type' => 'integer', 'enum' => $ratings],
                            'style' => ['type' => 'string', 'enum' => array_keys(self::STYLES)],
                            'mentions_delivery' => ['type' => 'boolean'],
                            'mentions_service' => ['type' => 'boolean'],
                            'has_intentional_typo' => ['type' => 'boolean'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function ratingDistributionHint(int $minRating, int $maxRating): string
    {
        if ($minRating <= 3 && $maxRating >= 5) {
            return 'mostly 5-star and 4-star reviews, occasional 3-star reviews; avoid too many 3-star reviews';
        }

        if ($minRating <= 3) {
            return 'mostly 4-star reviews, occasional 3-star reviews';
        }

        return 'approximately 25% 4-star and 75% 5-star';
    }
}
