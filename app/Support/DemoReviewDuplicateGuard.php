<?php

namespace App\Support;

use App\Models\Review;

class DemoReviewDuplicateGuard
{
    public function isDuplicateForProduct(int $productId, string $text): bool
    {
        $candidate = $this->normalize($text);

        if ($candidate === '') {
            return true;
        }

        $existing = Review::query()
            ->where('product_id', $productId)
            ->where('is_demo', true)
            ->where('is_ai_generated', true)
            ->pluck('body');

        foreach ($existing as $body) {
            $normalized = $this->normalize((string) $body);

            if ($candidate === $normalized) {
                return true;
            }

            similar_text($candidate, $normalized, $percent);

            if ($percent >= 88.0) {
                return true;
            }
        }

        return false;
    }

    public function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }
}
