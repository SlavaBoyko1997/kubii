<?php

namespace Database\Seeders;

use App\Models\BlogPost;
use Illuminate\Database\Seeder;

class BlogPostSeeder extends Seeder
{
    public function run(): void
    {
        $posts = [
            [
                'title' => 'Як обрати спальний мішок для кемпінгу',
                'slug' => 'yak-obraty-spalnyi-mishok-dlia-kempingu',
                'short_description' => 'Короткий гід по температурному режиму, наповнювачу та формату спальника для походів і кемпінгу.',
                'content' => <<<'HTML'
<h2>На що звернути увагу</h2>
<p>Температура комфорту, тип наповнювача та вага — три головні параметри при виборі спальника. Для літніх походів достатньо легкого моделі, для осені та весни — з комфортною температурою від +5 °C.</p>
<h3>Наповнювач</h3>
<ul>
<li><strong>Синтетика</strong> — дешевше, швидше сохне, підходить для вологого клімату.</li>
<li><strong>Пух</strong> — легший і тепліший, але вимагає акуратного догляду.</li>
</ul>
<p>У каталозі Kubii є моделі для різних сезонів — від ультралегких до зимових.</p>
HTML,
                'seo_title' => 'Як обрати спальний мішок для кемпінгу — поради Kubii',
                'status' => BlogPost::STATUS_PUBLISHED,
                'is_featured' => true,
                'sort_order' => 1,
                'published_at' => now()->subDays(5),
            ],
            [
                'title' => 'Підготовка до риболовлі: базовий набір спорядження',
                'slug' => 'pidgotovka-do-rybolovli-bazovyi-nabir',
                'short_description' => 'Що взяти новачку на першу рибалку: вудилище, оснащення, одяг і дрібниці, без яких не обійтися.',
                'content' => <<<'HTML'
<h2>Базовий набір</h2>
<p>Для початку достатньо спінінга середнього класу, набору приманок, підсаку та зручного одягу за погодою. Не забудьте про головний убір і крем від сонця — на водоймі сонце активніше, ніж здається.</p>
<h3>Одяг і аксесуари</h3>
<p>Шари одягу, непромокальний дощовик і зручне взуття важливіші за дорогі снасті. Решту можна докуповувати поступово під свій стиль ловлі.</p>
HTML,
                'status' => BlogPost::STATUS_PUBLISHED,
                'is_featured' => false,
                'sort_order' => 2,
                'published_at' => now()->subDays(2),
            ],
            [
                'title' => 'Чернетка: майбутній гід по наметах',
                'slug' => 'chernetyka-na-maybutnii-hid-po-nametah',
                'short_description' => 'Ця стаття ще в роботі і не повинна бути видима на сайті.',
                'content' => '<p>Текст чернетки для внутрішнього перегляду в адмінці.</p>',
                'status' => BlogPost::STATUS_DRAFT,
                'is_featured' => false,
                'sort_order' => 99,
                'published_at' => null,
            ],
        ];

        foreach ($posts as $post) {
            BlogPost::query()->updateOrCreate(
                ['slug' => $post['slug']],
                $post,
            );
        }
    }
}
