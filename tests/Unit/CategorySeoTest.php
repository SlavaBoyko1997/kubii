<?php

namespace Tests\Unit;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategorySeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_seo_fallbacks_use_localized_templates(): void
    {
        $category = Category::create([
            'name' => 'Вудилища',
            'name_ru' => 'Удилища',
            'slug' => 'vudylyshcha',
            'is_active' => true,
        ]);

        $this->assertSame('Вудилища купити в Україні — Trip&Fish', $category->seoTitle('uk'));
        $this->assertStringStartsWith('Купуйте вудилища в інтернет-магазині Trip&Fish.', $category->metaDescription('uk'));
        $this->assertSame('Вудилища', $category->pageH1('uk'));

        $this->assertSame('Удилища купить в Украине — Trip&Fish', $category->seoTitle('ru'));
        $this->assertStringStartsWith('Покупайте удилища в интернет-магазине Trip&Fish.', $category->metaDescription('ru'));
        $this->assertSame('Удилища', $category->pageH1('ru'));
    }

    public function test_category_seo_title_uses_direct_parent_for_nested_categories(): void
    {
        $tourism = Category::create([
            'name' => 'Туризм та кемпінг',
            'name_ru' => 'Туризм и кемпинг',
            'slug' => 'turyzm-ta-kemping',
            'is_active' => true,
        ]);

        $tents = Category::create([
            'name' => 'Намети',
            'name_ru' => 'Палатки',
            'slug' => 'namety',
            'parent_id' => $tourism->id,
            'is_active' => true,
        ]);

        $single = Category::create([
            'name' => 'Одномісні',
            'name_ru' => 'Одноместные',
            'slug' => 'odnomisni',
            'parent_id' => $tents->id,
            'is_active' => true,
        ]);

        $fishing = Category::create([
            'name' => 'Рибальство',
            'slug' => 'rybalstvo',
            'is_active' => true,
        ]);

        $rods = Category::create([
            'name' => 'Вудилища',
            'slug' => 'vudylyshcha-nested',
            'parent_id' => $fishing->id,
            'is_active' => true,
        ]);

        $spinning = Category::create([
            'name' => 'Спінінгові',
            'slug' => 'spiningovi',
            'parent_id' => $rods->id,
            'is_active' => true,
        ]);

        $sleepingBags = Category::create([
            'name' => 'Спальні мішки',
            'slug' => 'spalni-mishky',
            'parent_id' => $tourism->id,
            'is_active' => true,
        ]);

        $this->assertSame('Туризм та кемпінг Намети купити в Україні — Trip&Fish', $tents->seoTitle('uk'));
        $this->assertSame('Намети Одномісні купити в Україні — Trip&Fish', $single->seoTitle('uk'));
        $this->assertSame('Рибальство Вудилища купити в Україні — Trip&Fish', $rods->seoTitle('uk'));
        $this->assertSame('Вудилища Спінінгові купити в Україні — Trip&Fish', $spinning->seoTitle('uk'));
        $this->assertSame('Туризм та кемпінг Спальні мішки купити в Україні — Trip&Fish', $sleepingBags->seoTitle('uk'));
        $this->assertSame('Палатки Одноместные купить в Украине — Trip&Fish', $single->seoTitle('ru'));
    }

    public function test_category_custom_seo_fields_have_priority_over_fallbacks(): void
    {
        $category = Category::create([
            'name' => 'Намети',
            'slug' => 'namety',
            'seo_title' => 'Намети купити онлайн — Trip&Fish',
            'seo_title_ru' => 'Палатки купить онлайн — Trip&Fish',
            'meta_description' => 'Кастомний meta description.',
            'meta_description_ru' => 'Кастомное meta description.',
            'h1' => 'Намети для туризму та кемпінгу',
            'h1_ru' => 'Палатки для туризма и кемпинга',
            'is_active' => true,
        ]);

        $this->assertSame('Намети купити онлайн — Trip&Fish', $category->seoTitle('uk'));
        $this->assertSame('Кастомний meta description.', $category->metaDescription('uk'));
        $this->assertSame('Намети для туризму та кемпінгу', $category->pageH1('uk'));

        $this->assertSame('Палатки купить онлайн — Trip&Fish', $category->seoTitle('ru'));
        $this->assertSame('Кастомное meta description.', $category->metaDescription('ru'));
        $this->assertSame('Палатки для туризма и кемпинга', $category->pageH1('ru'));
    }

    public function test_category_seo_title_matching_name_uses_generated_fallback(): void
    {
        $category = Category::create([
            'name' => 'Одяг та взуття',
            'name_ru' => 'Одежда и обувь',
            'slug' => 'odiah-ta-vzuttia',
            'seo_title' => 'Одяг та взуття',
            'seo_title_ru' => 'Одежда и обувь',
            'is_active' => true,
        ]);

        $this->assertSame('Одяг та взуття купити в Україні — Trip&Fish', $category->seoTitle('uk'));
        $this->assertSame('Одежда и обувь купить в Украине — Trip&Fish', $category->seoTitle('ru'));
    }

    public function test_category_description_is_used_when_meta_description_is_empty(): void
    {
        $category = Category::create([
            'name' => 'Котушки',
            'slug' => 'kotushky',
            'description' => 'Короткий опис категорії для SEO.',
            'is_active' => true,
        ]);

        $this->assertSame('Короткий опис категорії для SEO.', $category->metaDescription('uk'));
    }

    public function test_filtered_page_h1_falls_back_to_plain_h1_without_a_suffix(): void
    {
        $category = Category::create([
            'name' => 'Спальні мішки',
            'name_ru' => 'Спальные мешки',
            'slug' => 'spalni-mishky',
            'h1' => 'Спальні мішки для походів',
            'is_active' => true,
        ]);

        $this->assertSame('Спальні мішки для походів', $category->filteredPageH1('', 'uk'));
        $this->assertSame('Спальні мішки Tramp Зимові', $category->filteredPageH1('Tramp Зимові', 'uk'));
    }

    public function test_filtered_seo_title_ignores_custom_seo_title_and_appends_suffix(): void
    {
        $category = Category::create([
            'name' => 'Спальні мішки',
            'name_ru' => 'Спальные мешки',
            'slug' => 'spalni-mishky',
            'seo_title' => 'Спальні мішки — найкращий вибір',
            'is_active' => true,
        ]);

        $this->assertSame('Спальні мішки — найкращий вибір', $category->filteredSeoTitle('', 'uk'));
        $this->assertSame('Спальні мішки Tramp купити в Україні — Trip&Fish', $category->filteredSeoTitle('Tramp', 'uk'));
        $this->assertSame('Спальные мешки Tramp купить в Украине — Trip&Fish', $category->filteredSeoTitle('Tramp', 'ru'));
    }

    public function test_filtered_meta_description_appends_suffix_before_boilerplate(): void
    {
        $category = Category::create([
            'name' => 'Спальні мішки',
            'name_ru' => 'Спальные мешки',
            'slug' => 'spalni-mishky',
            'is_active' => true,
        ]);

        $this->assertStringStartsWith(
            'Купуйте спальні мішки tramp зимові в інтернет-магазині Trip&Fish.',
            $category->filteredMetaDescription('Tramp Зимові', 'uk'),
        );
    }
}
