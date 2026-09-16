<?php

namespace Tests\Unit;

use App\Support\DatabaseBackupService;
use RuntimeException;
use Tests\TestCase;

class DatabaseBackupServiceTest extends TestCase
{
    public function test_status_returns_connection_metadata(): void
    {
        $status = app(DatabaseBackupService::class)->status();

        $this->assertSame('sqlite', $status['connection']);
        $this->assertSame(':memory:', $status['database']);
        $this->assertArrayHasKey('tables', $status);
        $this->assertArrayHasKey('dump_available', $status);
        $this->assertArrayHasKey('via_docker', $status);
        $this->assertArrayHasKey('upload_max_filesize', $status);
        $this->assertArrayHasKey('upload_limit_ok', $status);
        $this->assertArrayHasKey('livewire_tmp_writable', $status);
    }

    public function test_resolve_import_path_accepts_file_in_storage_app(): void
    {
        $directory = storage_path('app/private/database-imports');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $directory.'/test-import.sql';
        file_put_contents($path, 'SELECT 1;');

        try {
            $resolved = app(DatabaseBackupService::class)->resolveImportPath('storage/app/private/database-imports/test-import.sql');

            $this->assertSame(realpath($path), $resolved);
        } finally {
            @unlink($path);
        }
    }

    public function test_resolve_import_path_rejects_paths_outside_storage_app(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fishtrip-backup-').'.sql';
        file_put_contents($path, 'SELECT 1;');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('поза дозволеною директорією');

            app(DatabaseBackupService::class)->resolveImportPath($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_export_requires_mysql_connection(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Підтримується лише MySQL');

        app(DatabaseBackupService::class)->export();
    }

    public function test_export_can_be_disabled_via_config(): void
    {
        config(['database.admin_backup.enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Резервне копіювання через адмінку вимкнено');

        app(DatabaseBackupService::class)->export();
    }

    public function test_import_rejects_unknown_extensions(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fishtrip-backup-').'.txt';
        file_put_contents($path, 'not-a-dump');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Підтримуються лише файли .sql та .sql.gz');

            app(DatabaseBackupService::class)->import($path);
        } finally {
            @unlink($path);
        }
    }
}
