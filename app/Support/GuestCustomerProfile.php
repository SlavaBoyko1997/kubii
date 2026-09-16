<?php

namespace App\Support;

use App\Models\Order;
use App\Models\User;
use App\Rules\PhoneNumber;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class GuestCustomerProfile
{
    public function hasRegisteredAccount(?string $email, ?string $phone): bool
    {
        return $this->registeredUsersQuery($email, $phone)->exists();
    }

    public function resolveForStandardCheckout(
        string $lastName,
        string $firstName,
        ?string $patronymic,
        string $email,
        string $phone,
    ): User {
        $email = mb_strtolower(trim($email));
        $phone = PhoneNumber::normalize($phone);
        $name = collect([$lastName, $firstName, $patronymic])->filter()->implode(' ');

        if ($this->hasRegisteredAccount($email, $phone)) {
            throw new \InvalidArgumentException('Registered account already exists.');
        }

        $byEmail = User::query()
            ->where('is_guest', true)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        $byPhone = User::query()
            ->where('is_guest', true)
            ->where('phone', $phone)
            ->first();

        if ($byEmail && $byPhone && $byEmail->id !== $byPhone->id) {
            $this->mergeGuestProfiles($byPhone, $byEmail);

            return $this->refreshGuestProfile($byEmail, $lastName, $firstName, $patronymic, $name, $email, $phone);
        }

        $guest = $byEmail ?? $byPhone;

        if ($guest) {
            return $this->refreshGuestProfile($guest, $lastName, $firstName, $patronymic, $name, $email, $phone);
        }

        return User::create([
            'name' => $name,
            'last_name' => $lastName,
            'first_name' => $firstName,
            'patronymic' => $patronymic,
            'email' => $email,
            'phone' => $phone,
            'password' => Hash::make(Str::random(64)),
            'is_guest' => true,
        ]);
    }

    public function resolveForQuickOrder(string $phone, ?string $customerName = null): User
    {
        $phone = PhoneNumber::normalize($phone);

        $registered = User::query()
            ->where('is_admin', false)
            ->where('is_guest', false)
            ->where('phone', $phone)
            ->first();

        if ($registered) {
            return $registered;
        }

        $guest = User::query()
            ->where('is_guest', true)
            ->where('phone', $phone)
            ->first();

        if ($guest) {
            if (filled($customerName) && blank($guest->name)) {
                $guest->update(['name' => $customerName]);
            }

            return $guest->fresh();
        }

        return User::create([
            'name' => $customerName ?: ('Клієнт '.$phone),
            'email' => $this->placeholderEmailForPhone($phone),
            'phone' => $phone,
            'password' => Hash::make(Str::random(64)),
            'is_guest' => true,
        ]);
    }

    public function attachOrder(Order $order): bool
    {
        if ($order->user_id) {
            return false;
        }

        if (filled($order->email) && ! str_contains(mb_strtolower($order->email), '@guest.kubii.local')) {
            try {
                $user = $this->resolveForStandardCheckoutFromOrder($order);
            } catch (\InvalidArgumentException) {
                $registered = $this->registeredUsersQuery($order->email, $order->phone)->first();

                if ($registered) {
                    $order->update(['user_id' => $registered->id]);

                    return true;
                }

                return false;
            }
        } elseif (filled($order->phone)) {
            $user = $this->resolveForQuickOrder($order->phone, $order->customer_name);
        } else {
            return false;
        }

        $order->update(['user_id' => $user->id]);

        return true;
    }

    public function upgradeGuestToRegistered(User $guest, array $attributes): User
    {
        $guest->update([
            ...$attributes,
            'is_guest' => false,
            'email_verified_at' => $guest->email_verified_at ?? now(),
        ]);

        return $guest->fresh();
    }

    public function placeholderEmailForPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', PhoneNumber::normalize($phone));

        return 'guest+'.$digits.'@guest.kubii.local';
    }

    private function resolveForStandardCheckoutFromOrder(Order $order): User
    {
        $nameParts = $this->splitCustomerName($order->customer_name);

        return $this->resolveForStandardCheckout(
            lastName: $nameParts['last_name'] ?? 'Клієнт',
            firstName: $nameParts['first_name'] ?? (string) $order->number,
            patronymic: $nameParts['patronymic'],
            email: (string) $order->email,
            phone: (string) $order->phone,
        );
    }

    /**
     * @return array{last_name: ?string, first_name: ?string, patronymic: ?string}
     */
    private function splitCustomerName(?string $customerName): array
    {
        $parts = preg_split('/\s+/', trim((string) $customerName), 3) ?: [];

        return [
            'last_name' => $parts[0] ?? null,
            'first_name' => $parts[1] ?? null,
            'patronymic' => $parts[2] ?? null,
        ];
    }

    private function refreshGuestProfile(
        User $guest,
        string $lastName,
        string $firstName,
        ?string $patronymic,
        string $name,
        string $email,
        string $phone,
    ): User {
        $guest->update([
            'name' => $name,
            'last_name' => $lastName,
            'first_name' => $firstName,
            'patronymic' => $patronymic,
            'email' => $email,
            'phone' => $phone,
        ]);

        return $guest->fresh();
    }

    private function mergeGuestProfiles(User $source, User $target): void
    {
        Order::query()->where('user_id', $source->id)->update(['user_id' => $target->id]);

        $source->delete();
    }

    private function registeredUsersQuery(?string $email, ?string $phone)
    {
        return User::query()
            ->where('is_admin', false)
            ->where('is_guest', false)
            ->where(function ($query) use ($email, $phone): void {
                if (filled($email)) {
                    $query->orWhereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))]);
                }

                if (filled($phone)) {
                    $query->orWhere('phone', PhoneNumber::normalize($phone));
                }
            });
    }
}
