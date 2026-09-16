<?php

namespace App\Support;

class DemoReviewNameGenerator
{
    private const FIRST_NAMES = [
        'Олександр', 'Андрій', 'Максим', 'Сергій', 'Ігор', 'Віталій', 'Дмитро', 'Роман',
        'Олена', 'Наталія', 'Ірина', 'Марина', 'Катерина', 'Юлія', 'Тетяна', 'Вікторія',
    ];

    private const LAST_NAMES = [
        'Коваль', 'Бондаренко', 'Мельник', 'Шевченко', 'Кравченко', 'Ткаченко',
        'Олійник', 'Поліщук', 'Марченко', 'Савченко', 'Гончаренко', 'Романюк',
    ];

    /**
     * @return array{first_name: string, last_name: string, display_name: string}
     */
    public function make(): array
    {
        $firstName = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)];
        $lastName = self::LAST_NAMES[array_rand(self::LAST_NAMES)];

        $formats = [
            "{$firstName} {$lastName}",
            "{$lastName} {$firstName}",
            "{$firstName} ".mb_substr($lastName, 0, 1).'.',
        ];

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => $formats[array_rand($formats)],
        ];
    }
}
