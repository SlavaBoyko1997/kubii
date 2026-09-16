<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class EnsureAdminUser extends Command
{
    protected $signature = 'admin:ensure {--email=admin} {--password=admin}';

    protected $description = 'Create or reset the local Filament admin user';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->option('email')));
        $password = (string) $this->option('password');

        $user = User::query()->updateOrCreate(['email' => $email], [
            'name' => 'Kubii Admin',
            'last_name' => 'Kubii',
            'first_name' => 'Admin',
            'patronymic' => null,
            'phone' => null,
            'password' => Hash::make($password),
            'is_admin' => true,
            'is_guest' => false,
            'email_verified_at' => now(),
        ]);

        $this->info("Admin ready: {$user->email}");
        $this->line('Open /admin and sign in with the credentials above.');

        return self::SUCCESS;
    }
}
