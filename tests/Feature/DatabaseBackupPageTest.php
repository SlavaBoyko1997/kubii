<?php

namespace Tests\Feature;

use App\Filament\Pages\DatabaseBackup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DatabaseBackupPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_database_backup_page(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)
            ->test(DatabaseBackup::class)
            ->assertOk()
            ->assertSet('status.connection', 'sqlite');
    }

    public function test_non_admin_cannot_access_database_backup_page(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->assertFalse(DatabaseBackup::canAccess());

        Livewire::actingAs($user)
            ->test(DatabaseBackup::class)
            ->assertForbidden();
    }

    public function test_navigation_hidden_when_backup_disabled(): void
    {
        config(['database.admin_backup.enabled' => false]);

        $this->assertFalse(DatabaseBackup::shouldRegisterNavigation());
    }

    public function test_non_admin_cannot_download_database_backup(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('filament.admin.database-backup.export'))
            ->assertForbidden();
    }

    public function test_admin_export_route_requires_mysql(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('filament.admin.database-backup.export'))
            ->assertRedirect(route('filament.admin.pages.database-backup'))
            ->assertSessionHas('database-backup-error');
    }
}
