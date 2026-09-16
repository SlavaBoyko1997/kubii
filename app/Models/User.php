<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'last_name', 'first_name', 'patronymic', 'email', 'phone', 'password', 'is_admin', 'is_guest', 'is_demo', 'demo_metadata', 'email_verified_at', 'google_id', 'google_avatar'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_guest' => 'boolean',
            'is_demo' => 'boolean',
            'demo_metadata' => 'array',
        ];
    }

    public function isRegisteredCustomer(): bool
    {
        return ! $this->is_admin && ! $this->is_guest;
    }

    public function fullName(): string
    {
        $name = collect([$this->last_name, $this->first_name, $this->patronymic])
            ->filter()
            ->implode(' ');

        return $name !== '' ? $name : $this->name;
    }

    public function initials(): string
    {
        return collect([$this->last_name, $this->first_name])
            ->filter()
            ->map(fn (string $part): string => mb_substr($part, 0, 1))
            ->implode('');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function reviewVotes(): HasMany
    {
        return $this->hasMany(ReviewVote::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->is_admin;
    }
}
