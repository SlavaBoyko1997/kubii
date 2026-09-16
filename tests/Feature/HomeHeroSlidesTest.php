<?php

namespace Tests\Feature;

use App\Models\HeroSlide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeHeroSlidesTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_renders_active_hero_slides_in_sort_order(): void
    {
        HeroSlide::query()->create([
            'title' => 'Другий банер',
            'subtitle' => 'Другий підзаголовок',
            'image_url' => 'https://example.com/second.jpg',
            'is_active' => true,
            'sort_order' => 2,
        ]);
        HeroSlide::query()->create([
            'title' => 'Перший банер',
            'subtitle' => 'Перший підзаголовок',
            'image_url' => 'https://example.com/first.jpg',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        HeroSlide::query()->create([
            'title' => 'Вимкнений банер',
            'is_active' => false,
            'sort_order' => 0,
        ]);

        $response = $this->get('/')->assertOk();

        $response->assertSeeInOrder(['Перший банер', 'Другий банер']);
        $response->assertDontSee('Вимкнений банер');
        $response->assertSee('https://example.com/first.jpg', false);
    }

    public function test_home_page_falls_back_to_default_slide_when_no_active_slides_exist(): void
    {
        HeroSlide::query()->create([
            'title' => 'Вимкнений банер',
            'is_active' => false,
        ]);

        $response = $this->get('/')->assertOk();

        $response->assertSee('Все для туризму та рибалки');
    }

    public function test_hero_slide_button_url_renders_as_link_instead_of_catalog_opener(): void
    {
        HeroSlide::query()->create([
            'title' => 'Банер з посиланням',
            'button_label' => 'До акцій',
            'button_url' => 'https://example.com/sale',
            'is_active' => true,
        ]);

        $response = $this->get('/')->assertOk();

        $response->assertSee('href="https://example.com/sale"', false);
        $response->assertSee('До акцій');
    }

    public function test_hero_slide_url_without_button_label_makes_banner_clickable_without_cta_button(): void
    {
        HeroSlide::query()->create([
            'title' => 'Клікабельний банер',
            'button_label' => null,
            'button_url' => 'https://example.com/banner',
            'image_url' => 'https://example.com/banner.jpg',
            'is_active' => true,
        ]);

        $response = $this->get('/')->assertOk();

        $response->assertSee('class="hero-slide-link"', false);
        $response->assertSee('href="https://example.com/banner"', false);
        $response->assertSee('aria-label="Клікабельний банер"', false);
        $response->assertDontSee('<a class="hero-button" href="https://example.com/banner"', false);
    }
}
