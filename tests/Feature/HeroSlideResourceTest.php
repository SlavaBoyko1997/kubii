<?php

namespace Tests\Feature;

use App\Filament\Resources\HeroSlides\Pages\CreateHeroSlide;
use App\Filament\Resources\HeroSlides\Pages\EditHeroSlide;
use App\Filament\Resources\HeroSlides\Pages\ListHeroSlides;
use App\Models\HeroSlide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HeroSlideResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_see_hero_slides_in_list(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $slide = HeroSlide::query()->create([
            'title' => 'Тестовий банер',
            'subtitle' => 'Опис банера',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Livewire::actingAs($admin)
            ->test(ListHeroSlides::class)
            ->assertCanSeeTableRecords([$slide])
            ->assertSee('Тестовий банер');
    }

    public function test_admin_can_create_hero_slide(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)
            ->test(CreateHeroSlide::class)
            ->fillForm([
                'title' => 'Новий банер',
                'subtitle' => 'Новий підзаголовок',
                'image_url' => 'https://example.com/new.jpg',
                'is_active' => true,
                'sort_order' => 5,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('hero_slides', [
            'title' => 'Новий банер',
            'is_active' => true,
            'sort_order' => 5,
        ]);
    }

    public function test_admin_can_edit_and_deactivate_hero_slide(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $slide = HeroSlide::query()->create([
            'title' => 'Банер для редагування',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Livewire::actingAs($admin)
            ->test(EditHeroSlide::class, ['record' => $slide->getRouteKey()])
            ->fillForm([
                'title' => 'Оновлений банер',
                'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('hero_slides', [
            'id' => $slide->id,
            'title' => 'Оновлений банер',
            'is_active' => false,
        ]);
    }

    public function test_non_admin_cannot_access_hero_slide_resource(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)
            ->get('/admin/hero-slides')
            ->assertForbidden();
    }
}
