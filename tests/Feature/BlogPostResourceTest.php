<?php

namespace Tests\Feature;

use App\Filament\Resources\BlogPosts\Pages\CreateBlogPost;
use App\Filament\Resources\BlogPosts\Pages\EditBlogPost;
use App\Filament\Resources\BlogPosts\Pages\ListBlogPosts;
use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BlogPostResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_see_blog_posts_in_list(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $post = BlogPost::factory()->published()->create([
            'title' => 'Стаття в адмінці',
        ]);

        Livewire::actingAs($admin)
            ->test(ListBlogPosts::class)
            ->assertCanSeeTableRecords([$post])
            ->assertSee('Стаття в адмінці');
    }

    public function test_admin_can_create_blog_post(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)
            ->test(CreateBlogPost::class)
            ->fillForm([
                'title' => 'Нова стаття блогу',
                'slug' => 'nova-stattya-blohu',
                'short_description' => 'Короткий опис.',
                'content' => '<p>Текст нової статті.</p>',
                'status' => BlogPost::STATUS_PUBLISHED,
                'is_featured' => true,
                'sort_order' => 3,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('blog_posts', [
            'title' => 'Нова стаття блогу',
            'slug' => 'nova-stattya-blohu',
            'status' => BlogPost::STATUS_PUBLISHED,
            'is_featured' => true,
        ]);
    }

    public function test_admin_can_edit_and_unpublish_blog_post(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $post = BlogPost::factory()->published()->create([
            'title' => 'Стаття для редагування',
        ]);

        Livewire::actingAs($admin)
            ->test(EditBlogPost::class, ['record' => $post->getRouteKey()])
            ->fillForm([
                'title' => 'Оновлена стаття',
                'status' => BlogPost::STATUS_DRAFT,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('blog_posts', [
            'id' => $post->id,
            'title' => 'Оновлена стаття',
            'status' => BlogPost::STATUS_DRAFT,
            'published_at' => null,
        ]);
    }

    public function test_non_admin_cannot_access_blog_post_resource(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get('/admin/blog-posts')
            ->assertForbidden();
    }
}
