<?php

namespace Tests\Feature;

use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_see_registered_customers_in_filament(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $customer = User::factory()->create([
            'last_name' => 'Іваненко',
            'first_name' => 'Петро',
            'email' => 'petro@example.com',
            'phone' => '+380991234567',
        ]);
        User::factory()->create(['is_admin' => true, 'email' => 'other-admin@example.com']);

        Livewire::actingAs($admin)
            ->test(ListCustomers::class)
            ->assertCanSeeTableRecords([$customer])
            ->assertSee('petro@example.com')
            ->assertSee('+380991234567')
            ->assertDontSee('other-admin@example.com');
    }

    public function test_admin_can_view_customer_profile(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $customer = User::factory()->create([
            'last_name' => 'Коваленко',
            'first_name' => 'Оlena',
            'email' => 'olena@example.com',
        ]);

        Livewire::actingAs($admin)
            ->test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
            ->assertSet('data.email', 'olena@example.com')
            ->assertSet('data.last_name', 'Коваленко')
            ->assertSet('data.first_name', 'Оlena');
    }

    public function test_non_admin_cannot_access_customer_resource(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)
            ->get('/admin/customers')
            ->assertForbidden();
    }
}
