<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\DatabaseBackupService;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DatabaseBackupDownloadController extends Controller
{
    public function __invoke(DatabaseBackupService $backup): BinaryFileResponse|RedirectResponse
    {
        abort_unless(auth()->user()?->is_admin, 403);

        if (! config('database.admin_backup.enabled', true)) {
            abort(404);
        }

        try {
            $result = $backup->export();
            $filename = basename($result['path']);

            return response()->download($result['path'], $filename, [
                'Content-Type' => 'application/gzip',
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $exception) {
            return redirect()
                ->to(DatabaseBackupDownloadController::fallbackUrl())
                ->with('database-backup-error', $exception->getMessage());
        }
    }

    public static function fallbackUrl(): string
    {
        return route('filament.admin.pages.database-backup');
    }
}
