<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\GuestCustomerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestCustomerProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_checkout_creates_guest_profile(): void
    {
        $profile = app(GuestCustomerProfile::class);

        $guest = $profile->resolveForStandardCheckout(
            lastName: 'Петренко',
            firstName: 'Іван',
            patronymic: 'Петрович',
            email: 'ivan@example.com',
            phone: '+380991234567',
        );

        $this->assertTrue($guest->is_guest);
        $this->assertDatabaseHas('users', [
            'id' => $guest->id,
            'email' => 'ivan@example.com',
            'phone' => '+380991234567',
            'is_guest' => true,
        ]);
    }

    public function test_repeat_guest_checkout_reuses_profile(): void
    {
        $profile = app(GuestCustomerProfile::class);

        $first = $profile->resolveForStandardCheckout('Петренко', 'Іван', null, 'ivan@example.com', '+380991234567');
        $second = $profile->resolveForStandardCheckout('Петренко', 'Іван', null, 'ivan@example.com', '+380991234567');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, User::query()->where('is_guest', true)->count());
    }

    public function test_registered_account_blocks_new_guest_profile(): void
    {
        User::factory()->create([
            'email' => 'owned@example.com',
            'phone' => '+380991234501',
            'is_guest' => false,
        ]);

        $this->assertTrue(app(GuestCustomerProfile::class)->hasRegisteredAccount('other@example.com', '+380991234501'));
    }
}
