<?php

namespace App\Services;

use App\Models\Category;
use App\Models\CategorySeoGeneration;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OpenAiCategorySeoGenerator
{
    public const PROMPT_VERSION = 'category-seo-v1';

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $fields
     * @return array{content: array<string, mixed>, response_id: ?string, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}, validation_errors: list<string>}
     */
    public function generate(Category $category, array $context, string $locale, int $wordCount, array $fields): array
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
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => json_encode([
                    'task' => 'generate_category_seo_content',
                    'locale' => $locale,
                    'word_count_hint' => $wordCount,
                    'requested_fields' => $fields,
                    'category_context' => $context,
                    'existing_neighbor_summaries' => $this->neighborSummaries($category, $locale),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'category_seo_content',
                    'strict' => true,
                    'schema' => $this->jsonSchema(),
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

        $content = $this->normalizeContent($decoded, $fields, (array) ($context['internal_link_whitelist'] ?? []));
        $validationErrors = $this->validateContent($content, $category, $context, $wordCount, $fields);

        return [
            'content' => $content,
            'response_id' => $response->json('id'),
            'usage' => [
                'input_tokens' => (int) ($response->json('usage.input_tokens') ?? 0),
                'output_tokens' => (int) ($response->json('usage.output_tokens') ?? 0),
                'total_tokens' => (int) ($response->json('usage.total_tokens') ?? 0),
            ],
            'validation_errors' => $validationErrors,
        ];
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function normalizeContent(array $decoded, array $fields, array $allowedLinks): array
    {
        $allowedUrls = collect($allowedLinks)->pluck('url')->filter()->values()->all();
        $content = [
            'seo_title' => in_array('seo_title', $fields, true) ? trim((string) ($decoded['seo_title'] ?? '')) : null,
            'meta_description' => in_array('meta_description', $fields, true) ? trim((string) ($decoded['meta_description'] ?? '')) : null,
            'h1' => in_array('h1', $fields, true) ? trim((string) ($decoded['h1'] ?? '')) : null,
            'intro' => in_array('intro', $fields, true) ? trim(strip_tags((string) ($decoded['intro'] ?? ''))) : null,
            'seo_text' => in_array('seo_text', $fields, true) ? $this->sanitizeHtml((string) ($decoded['seo_text_html'] ?? ''), $allowedUrls) : null,
            'faq' => in_array('faq', $fields, true) ? $this->normalizeFaq((array) ($decoded['faq'] ?? [])) : [],
            'internal_links' => $this->normalizeInternalLinks((array) ($decoded['internal_links'] ?? []), $allowedUrls),
        ];

        return $content;
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    private function normalizeFaq(array $faq): array
    {
        return collect($faq)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): array => [
                'question' => trim(strip_tags((string) ($row['question'] ?? ''))),
                'answer' => trim(strip_tags((string) ($row['answer'] ?? ''))),
            ])
            ->filter(fn (array $row): bool => $row['question'] !== '' && $row['answer'] !== '')
            ->unique(fn (array $row): string => mb_strtolower($row['question']))
            ->take(6)
            ->values()
            ->all();
    }

    /**
     * @return list<array{title: string, url: string}>
     */
    private function normalizeInternalLinks(array $links, array $allowedUrls): array
    {
        return collect($links)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): array => [
                'title' => trim(strip_tags((string) ($row['title'] ?? ''))),
                'url' => trim((string) ($row['url'] ?? '')),
            ])
            ->filter(fn (array $row): bool => $row['title'] !== '' && in_array($row['url'], $allowedUrls, true))
            ->unique('url')
            ->take(5)
            ->values()
            ->all();
    }

    private function sanitizeHtml(string $html, array $allowedUrls): string
    {
        $html = strip_tags($html, '<p><h2><h3><ul><ol><li><strong><a>');
        $html = preg_replace('/\s+on[a-z]+\s*=\s*(["\']).*?\1/iu', '', $html) ?? '';
        $html = preg_replace('/\s+(style|class|id)\s*=\s*(["\']).*?\2/iu', '', $html) ?? '';
        $html = preg_replace_callback('/<a\s+[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>/iu', function (array $matches) use ($allowedUrls): string {
            $url = trim(html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return in_array($url, $allowedUrls, true)
                ? '<a href="'.e($url).'">'
                : '<a>';
        }, $html) ?? '';

        return trim($html);
    }

    /**
     * @return list<string>
     */
    private function validateContent(array $content, Category $category, array $context, int $wordCount, array $fields): array
    {
        $errors = [];

        if (in_array('seo_title', $fields, true)) {
            $title = (string) ($content['seo_title'] ?? '');
            if ($title === '') {
                $errors[] = 'SEO Title порожній.';
            } elseif (mb_strlen($title) > 75) {
                $errors[] = 'SEO Title довший за 75 символів.';
            }
        }

        if (in_array('meta_description', $fields, true)) {
            $description = (string) ($content['meta_description'] ?? '');
            if ($description === '') {
                $errors[] = 'Meta Description порожній.';
            } elseif (mb_strlen($description) > 180) {
                $errors[] = 'Meta Description довший за 180 символів.';
            }

            $duplicate = app(CategorySeoUniqueness::class)->duplicateMetaDescription($description, (string) data_get($context, 'language', 'uk'), (int) $category->id);
            if ($duplicate) {
                $errors[] = 'Meta Description не унікальний: '.$duplicate.'.';
            }
        }

        if (in_array('h1', $fields, true) && trim((string) ($content['h1'] ?? '')) === '') {
            $errors[] = 'H1 порожній.';
        }

        if (in_array('seo_text', $fields, true)) {
            $text = (string) ($content['seo_text'] ?? '');
            $plain = trim(strip_tags($text));
            $words = str_word_count($plain, 0, 'АБВГҐДЕЄЖЗИІЇЙКЛМНОПРСТУФХЦЧШЩЬЮЯабвгґдеєжзиіїйклмнопрстуфхцчшщьюя');

            if ($plain === '' || $words < max(80, (int) ($wordCount * 0.35))) {
                $errors[] = 'SEO-текст занадто короткий.';
            }

            if ($words > $wordCount * 1.8) {
                $errors[] = 'SEO-текст значно довший за бажаний обсяг.';
            }

            if (preg_match('/<\s*(script|style|iframe)|https?:\/\//iu', $text)) {
                $errors[] = 'SEO-текст містить заборонений HTML або зовнішній URL.';
            }

            $duplicate = app(CategorySeoUniqueness::class)->duplicateSeoText($text, (string) data_get($context, 'language', 'uk'), (int) $category->id);
            if ($duplicate) {
                $errors[] = 'SEO-текст не унікальний: '.$duplicate.'.';
            }
        }

        if (in_array('faq', $fields, true) && count((array) ($content['faq'] ?? [])) < 3) {
            $errors[] = 'FAQ має містити мінімум 3 питання.';
        }

        return $errors;
    }

    private function postWithRetries(array $payload): Response
    {
        $lastException = null;

        foreach ([10, 30, 60] as $index => $backoff) {
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
                    429 => 'OpenAI API: rate limit.',
                    500, 502, 503 => 'OpenAI API тимчасово недоступний HTTP '.$response->status().'.',
                    default => 'OpenAI API помилка HTTP '.$response->status().': '.Str::limit($response->body(), 500),
                };

                throw new RuntimeException($message);
            } catch (\Throwable $exception) {
                $lastException = $exception;

                if ($index < 2) {
                    sleep(min($backoff, 3));
                }
            }
        }

        throw $lastException instanceof \Throwable ? $lastException : new RuntimeException('OpenAI API недоступний.');
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
     * @return list<array<string, string>>
     */
    private function neighborSummaries(Category $category, string $locale): array
    {
        return CategorySeoGeneration::query()
            ->where('locale', $locale)
            ->where('category_id', '!=', $category->id)
            ->whereIn('status', ['generated', 'published', 'approved'])
            ->latest()
            ->limit(8)
            ->get(['h1', 'meta_description', 'seo_text'])
            ->map(fn (CategorySeoGeneration $generation): array => [
                'h1' => (string) $generation->h1,
                'meta_description' => (string) $generation->meta_description,
                'summary' => Str::limit(strip_tags((string) $generation->seo_text), 360, ''),
            ])
            ->all();
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Ти senior SEO-спеціаліст та e-commerce copywriter.
Ти створюєш SEO-контент для українського інтернет-магазину товарів для риболовлі, туризму, кемпінгу та активного відпочинку Kubii.
Головна мета — корисний текст для людини, який допомагає пошуковим системам зрозуміти категорію.
Не пиши текст виключно для SEO. Не використовуй keyword stuffing. Не повторюй назву категорії у кожному абзаці.
Не вигадуй характеристики, бренди, товари, підкатегорії, URL, умови доставки, гарантії, акції або ціни. Використовуй тільки факти з переданого контексту.
Внутрішні посилання використовуй тільки з whitelist і тільки якщо вони реально допомагають користувачу.
SEO text повертай чистим HTML з тегами p, h2, h3, ul, ol, li, strong, a. Не використовуй script, style, iframe, JavaScript або зовнішні URL.
Уникай порожніх фраз: "широкий асортимент", "найкращі ціни", "висока якість", "ідеальний вибір", "у нашому інтернет-магазині ви можете".
Не використовуй у фінальному контенті фрази "цей текст", "SEO", "ключові слова", "пошукова система".
Не починай всі тексти однаково і не використовуй один шаблон для різних категорій.
Meta Description для кожної категорії має бути унікальним: не повторюй формулювання з existing_neighbor_summaries і не роби шаблон із просто заміненою назвою категорії.
Відповідь повинна бути валідним JSON за схемою.
PROMPT;
    }

    private function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['seo_title', 'meta_description', 'h1', 'intro', 'seo_text_html', 'faq', 'internal_links'],
            'properties' => [
                'seo_title' => ['type' => 'string'],
                'meta_description' => ['type' => 'string'],
                'h1' => ['type' => 'string'],
                'intro' => ['type' => 'string'],
                'seo_text_html' => ['type' => 'string'],
                'faq' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['question', 'answer'],
                        'properties' => [
                            'question' => ['type' => 'string'],
                            'answer' => ['type' => 'string'],
                        ],
                    ],
                ],
                'internal_links' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'url'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
