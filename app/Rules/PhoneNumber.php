<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^\+?[0-9\s().-]+$/', trim($value))) {
            $fail('Вкажіть коректний номер телефону.');

            return;
        }

        $digits = preg_replace('/\D+/', '', $value);

        if (strlen($digits) < 10 || strlen($digits) > 15) {
            $fail('Номер телефону має містити від 10 до 15 цифр.');
        }
    }

    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        return str_starts_with(trim($phone), '+') ? '+'.$digits : $digits;
    }
}
