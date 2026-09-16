<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->updateOrCreate(['email' => 'admin'], [
            'name' => 'Kubii Admin',
            'last_name' => 'Kubii',
            'first_name' => 'Admin',
            'patronymic' => null,
            'password' => bcrypt('admin'),
            'is_admin' => true,
            'is_guest' => false,
            'email_verified_at' => now(),
        ]);

        $categories = collect([
            ['name' => 'Туризм', 'slug' => 'tourism', 'image_url' => 'https://images.unsplash.com/photo-1504280390367-361c6d9f38f4?auto=format&fit=crop&w=500&q=85'],
            ['name' => 'Риболовля', 'slug' => 'fishing', 'image_url' => 'https://images.unsplash.com/photo-1541742425281-c1d3fc8aff96?auto=format&fit=crop&w=500&q=85'],
            ['name' => 'Намети', 'slug' => 'tents', 'parent' => 'tourism', 'image_url' => 'https://images.unsplash.com/photo-1504280390367-361c6d9f38f4?auto=format&fit=crop&w=500&q=85'],
            ['name' => 'Рюкзаки', 'slug' => 'backpacks', 'parent' => 'tourism', 'image_url' => 'https://images.unsplash.com/photo-1622260614153-03223fb72052?auto=format&fit=crop&w=500&q=85'],
            ['name' => 'Спальні мішки', 'slug' => 'sleeping-bags', 'parent' => 'tourism', 'image_url' => 'https://images.unsplash.com/photo-1478131143081-80f7f84ca84d?auto=format&fit=crop&w=500&q=85'],
            ['name' => 'Одяг та взуття', 'slug' => 'clothing', 'parent' => 'tourism', 'image_url' => 'https://images.unsplash.com/photo-1526481280695-3c687fd643ed?auto=format&fit=crop&w=500&q=85'],
            ['name' => 'Аксесуари', 'slug' => 'accessories', 'parent' => 'tourism', 'image_url' => 'https://images.unsplash.com/photo-1537905569824-f89f14cceb68?auto=format&fit=crop&w=500&q=85'],
            ['name' => 'Кемпінгові меблі', 'slug' => 'camping-furniture', 'parent' => 'tourism', 'image_url' => 'https://images.unsplash.com/photo-1504851149312-7a075b496cc7?auto=format&fit=crop&w=500&q=85'],
            ['name' => 'Крісла та стільці', 'slug' => 'camping-chairs', 'parent' => 'camping-furniture', 'image_url' => 'https://images.unsplash.com/photo-1591129841117-3adfd313e34f?auto=format&fit=crop&w=500&q=85'],
            ['name' => 'Складні крісла', 'slug' => 'folding-camping-chairs', 'parent' => 'camping-chairs', 'image_url' => 'https://images.unsplash.com/photo-1591129841117-3adfd313e34f?auto=format&fit=crop&w=500&q=85'],
            ['name' => 'Стільці для риболовлі', 'slug' => 'fishing-chairs', 'parent' => 'camping-chairs', 'image_url' => 'https://images.unsplash.com/photo-1591129841117-3adfd313e34f?auto=format&fit=crop&w=500&q=85'],
            ['name' => 'Столи туристичні', 'slug' => 'camping-tables', 'parent' => 'camping-furniture', 'image_url' => 'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?auto=format&fit=crop&w=500&q=85'],
            ['name' => 'Гамаки', 'slug' => 'hammocks', 'parent' => 'camping-furniture', 'image_url' => 'https://images.unsplash.com/photo-1520277739336-7bf67edfa768?auto=format&fit=crop&w=500&q=85'],
            ['name' => 'Вудилища', 'slug' => 'fishing-rods', 'parent' => 'fishing', 'image_url' => 'https://images.unsplash.com/photo-1541742425281-c1d3fc8aff96?auto=format&fit=crop&w=500&q=85'],
            ['name' => 'Спінінгові вудилища', 'slug' => 'spinning-rods', 'parent' => 'fishing-rods', 'image_url' => 'https://images.unsplash.com/photo-1541742425281-c1d3fc8aff96?auto=format&fit=crop&w=500&q=85'],
            ['name' => 'Легкі спінінги', 'slug' => 'light-spinning-rods', 'parent' => 'spinning-rods', 'image_url' => 'https://images.unsplash.com/photo-1541742425281-c1d3fc8aff96?auto=format&fit=crop&w=500&q=85'],
            ['name' => 'Фідерні вудилища', 'slug' => 'feeder-rods', 'parent' => 'fishing-rods', 'image_url' => 'https://images.unsplash.com/photo-1499744937866-d7e566a20a61?auto=format&fit=crop&w=500&q=85'],
            ['name' => 'Котушки', 'slug' => 'reels', 'parent' => 'fishing', 'image_url' => 'https://images.unsplash.com/photo-1514462384829-ffca942b71ae?auto=format&fit=crop&w=500&q=85'],
            ['name' => 'Приманки', 'slug' => 'lures', 'parent' => 'fishing', 'image_url' => 'https://images.unsplash.com/photo-1625508123293-596d93d1b7f7?auto=format&fit=crop&w=500&q=85'],
            ['name' => 'Ліски та шнури', 'slug' => 'fishing-lines', 'parent' => 'fishing', 'image_url' => 'https://images.unsplash.com/photo-1499744937866-d7e566a20a61?auto=format&fit=crop&w=500&q=85'],
            ['name' => 'Гачки та оснащення', 'slug' => 'hooks-rigs', 'parent' => 'fishing', 'image_url' => 'https://images.unsplash.com/photo-1514462384829-ffca942b71ae?auto=format&fit=crop&w=500&q=85'],
            ['name' => 'Рибальські аксесуари', 'slug' => 'fishing-accessories', 'parent' => 'fishing', 'image_url' => 'https://images.unsplash.com/photo-1541742425281-c1d3fc8aff96?auto=format&fit=crop&w=500&q=85'],
        ])->map(function (array $category, int $index): Category {
            $parent = $category['parent'] ?? null;
            unset($category['parent']);

            return Category::query()->updateOrCreate(
                ['slug' => $category['slug']],
                $category + ['sort_order' => $index, 'parent_id' => $parent ? Category::query()->where('slug', $parent)->value('id') : null],
            );
        });

        $products = [
            ['category' => 'tents', 'name' => 'Намет туристичний Naturehike Cloud Up 2', 'slug' => 'naturehike-cloud-up-2', 'price' => 4200, 'stock' => 12, 'image_url' => 'https://images.unsplash.com/photo-1478131143081-80f7f84ca84d?auto=format&fit=crop&w=700&q=90'],
            ['category' => 'reels', 'name' => 'Котушка Shimano Nexave 4000', 'slug' => 'shimano-nexave-4000', 'price' => 2350, 'stock' => 8, 'image_url' => 'https://images.unsplash.com/photo-1514462384829-ffca942b71ae?auto=format&fit=crop&w=700&q=90'],
            ['category' => 'backpacks', 'name' => 'Рюкзак туристичний Deuter Aircontact 65+10', 'slug' => 'deuter-aircontact-65', 'price' => 5600, 'stock' => 6, 'image_url' => 'https://images.unsplash.com/photo-1622260614153-03223fb72052?auto=format&fit=crop&w=700&q=90'],
            ['category' => 'fishing-rods', 'name' => 'Вудилище Favorite X1 2.4m', 'slug' => 'favorite-x1-24', 'price' => 2100, 'stock' => 15, 'image_url' => 'https://images.unsplash.com/photo-1541742425281-c1d3fc8aff96?auto=format&fit=crop&w=700&q=90'],
            ['category' => 'lures', 'name' => 'Воблер Jackall Mag Squad 115SP', 'slug' => 'jackall-mag-squad', 'price' => 470, 'stock' => 21, 'image_url' => 'https://images.unsplash.com/photo-1625508123293-596d93d1b7f7?auto=format&fit=crop&w=700&q=90'],
            ['category' => 'sleeping-bags', 'name' => 'Спальний мішок Tramp Fjord 300', 'slug' => 'tramp-fjord-300', 'price' => 1890, 'stock' => 10, 'image_url' => 'https://images.unsplash.com/photo-1475483768296-6163e08872a1?auto=format&fit=crop&w=700&q=90'],
            ['category' => 'clothing', 'name' => 'Трекінгові черевики Alpine Trek', 'slug' => 'alpine-trek-boots', 'price' => 3280, 'stock' => 9, 'image_url' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=700&q=90'],
            ['category' => 'accessories', 'name' => 'Ліхтар туристичний Goal Zero', 'slug' => 'goal-zero-lantern', 'price' => 980, 'stock' => 18, 'image_url' => 'https://images.unsplash.com/photo-1511497584788-876760111969?auto=format&fit=crop&w=700&q=90'],
            ['category' => 'tents', 'name' => 'Намет кемпінговий Tramp Lite Camp 4', 'slug' => 'tramp-lite-camp-4', 'price' => 6790, 'stock' => 4, 'image_url' => 'https://images.unsplash.com/photo-1504851149312-7a075b496cc7?auto=format&fit=crop&w=700&q=90'],
            ['category' => 'backpacks', 'name' => 'Рюкзак Osprey Talon 33', 'slug' => 'osprey-talon-33', 'price' => 4850, 'stock' => 7, 'image_url' => 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=700&q=90'],
            ['category' => 'fishing-rods', 'name' => 'Спінінг Shimano Catana EX 2.4m', 'slug' => 'shimano-catana-ex', 'price' => 2670, 'stock' => 13, 'image_url' => 'https://images.unsplash.com/photo-1499744937866-d7e566a20a61?auto=format&fit=crop&w=700&q=90'],
            ['category' => 'accessories', 'name' => 'Термос Stanley Adventure 1L', 'slug' => 'stanley-adventure-1l', 'price' => 1640, 'stock' => 11, 'image_url' => 'https://images.unsplash.com/photo-1602143407151-7111542de6e8?auto=format&fit=crop&w=700&q=90'],
        ];

        foreach ($products as $index => $product) {
            $category = $product['category'];
            unset($product['category']);

            Product::query()->updateOrCreate(['slug' => $product['slug']], [
                ...$product,
                'sku' => 'FT-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'category_id' => $categories->firstWhere('slug', $category)->id,
                'description' => 'Надійне спорядження для подорожей і риболовлі. Практична модель для регулярного використання на природі.',
                'content' => 'Ця модель створена для регулярних виїздів на природу: продумана конструкція, практичні матеріали та комфортне використання у подорожах різного формату.',
                'brand' => str($product['name'])->before(' ')->toString(),
                'model' => str($product['slug'])->upper()->toString(),
                'season' => 'Всесезонний',
                'usage_type' => 'Активний відпочинок',
                'material' => 'Комбінований',
                'weight_grams' => 1200 + ($index * 90),
                'discount_percent' => $index % 3 === 0 ? 15 : 0,
                'gallery_images' => [$product['image_url'], 'https://images.unsplash.com/photo-1504280390367-361c6d9f38f4?auto=format&fit=crop&w=900&q=85'],
                'content_images' => [$product['image_url'], 'https://images.unsplash.com/photo-1504851149312-7a075b496cc7?auto=format&fit=crop&w=900&q=85'],
                'specifications' => ['Бренд' => str($product['name'])->before(' ')->toString(), 'Модель' => str($product['slug'])->upper()->toString(), 'Матеріал' => 'Комбінований', 'Сезон' => 'Всесезонний', 'Тип використання' => 'Активний відпочинок', 'Вага' => (1200 + ($index * 90)).' г', 'Гарантія' => '12 місяців', 'Країна виробництва' => 'Україна'],
                'is_featured' => true,
            ]);
        }

        $brands = ['Naturehike', 'Tramp', 'Favorite', 'Shimano', 'Osprey', 'Skif Outdoor'];
        $seasons = ['Літо', 'Демісезон', 'Всесезонний'];
        $materials = ['Поліестер', 'Алюміній', 'Нейлон', 'Сталь', 'Комбінований'];
        $images = [
            'https://images.unsplash.com/photo-1504280390367-361c6d9f38f4?auto=format&fit=crop&w=700&q=85',
            'https://images.unsplash.com/photo-1622260614153-03223fb72052?auto=format&fit=crop&w=700&q=85',
            'https://images.unsplash.com/photo-1541742425281-c1d3fc8aff96?auto=format&fit=crop&w=700&q=85',
            'https://images.unsplash.com/photo-1504851149312-7a075b496cc7?auto=format&fit=crop&w=700&q=85',
        ];

        $categories->filter(fn (Category $category): bool => $category->parent_id !== null)->each(function (Category $category) use ($brands, $seasons, $materials, $images): void {
            foreach (range(1, 24) as $index) {
                $brand = $brands[$index % count($brands)];
                $model = 'Series '.str_pad((string) $index, 2, '0', STR_PAD_LEFT);
                $material = $materials[$index % count($materials)];
                $season = $seasons[$index % count($seasons)];
                $sku = 'FT-C'.$category->id.'-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT);

                Product::query()->updateOrCreate(['slug' => "{$category->slug}-demo-{$index}"], [
                    'category_id' => $category->id,
                    'name' => "{$category->name} {$brand} {$model}",
                    'sku' => $sku,
                    'description' => "Практичний товар категорії «{$category->name}» для відпочинку на природі. Збалансовані матеріали та надійна конструкція для регулярного використання.",
                    'content' => "Товар із категорії «{$category->name}» підійде для підготовлених мандрівок і спонтанних виїздів. Модель {$model} поєднує простий догляд, зрозуміле використання та витривалі матеріали.",
                    'brand' => $brand,
                    'model' => $model,
                    'season' => $season,
                    'usage_type' => $category->parent?->slug === 'fishing' ? 'Риболовля' : 'Туризм і кемпінг',
                    'material' => $material,
                    'weight_grams' => 300 + ($index * 175),
                    'specifications' => ['Бренд' => $brand, 'Модель' => $model, 'Матеріал' => $material, 'Сезон' => $season, 'Тип використання' => $category->parent?->slug === 'fishing' ? 'Риболовля' : 'Туризм і кемпінг', 'Вага' => (300 + ($index * 175)).' г', 'Гарантія' => '12 місяців'],
                    'price' => 450 + ($index * 185),
                    'discount_percent' => $index % 5 === 0 ? 20 : ($index % 7 === 0 ? 10 : 0),
                    'image_url' => $images[$index % count($images)],
                    'gallery_images' => [$images[($index + 1) % count($images)], $images[($index + 2) % count($images)]],
                    'content_images' => [$images[($index + 2) % count($images)], $images[($index + 3) % count($images)]],
                    'stock' => $index % 7 === 0 ? 0 : 3 + $index,
                    'is_featured' => $index <= 4,
                    'is_active' => true,
                ]);
            }
        });

        $customer = User::query()->updateOrCreate(['email' => 'customer@kubii.test'], [
            'name' => 'Олена Коваль',
            'last_name' => 'Коваль',
            'first_name' => 'Олена',
            'patronymic' => null,
            'phone' => '+380991234567',
            'password' => bcrypt('admin'),
        ]);

        Product::query()->where('is_featured', true)->take(30)->get()->each(function (Product $product, int $index) use ($customer): void {
            Review::query()->updateOrCreate([
                'product_id' => $product->id,
                'user_id' => $customer->id,
            ], [
                'rating' => $index % 3 === 0 ? 4 : 5,
                'title' => $index % 2 === 0 ? 'Вдалий вибір' : 'Рекомендую',
                'body' => 'Товар відповідає опису. Зручно користуватися на природі, якість матеріалів приємно здивувала.',
                'is_visible' => true,
            ]);
        });
    }
}
