<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Support\AppUrl;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class DiagnoseCatalogUrls extends Command
{
    protected $signature = 'catalog:diagnose-urls {path=/odyag-ta-vzuttya/flisovi-kofty/}';

    protected $description = 'Show how catalog pagination URLs are generated for a category path.';

    public function handle(Kernel $kernel): int
    {
        $path = '/'.trim((string) $this->argument('path'), '/').'/';
        $appUrl = rtrim((string) config('app.url'), '/');
        $host = parse_url($appUrl, PHP_URL_HOST) ?: 'localhost';
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'https';

        $this->line('APP_URL: '.$appUrl);
        $this->line('Configured origin: '.(AppUrl::configuredOrigin() ?? '(none)'));
        $this->line('Simulated Host: '.$host);

        $request = Request::create($path, 'GET', server: [
            'HTTP_ACCEPT' => 'text/html',
            'HTTP_HOST' => $host,
            'SERVER_NAME' => $host,
            'HTTPS' => $scheme === 'https' ? 'on' : 'off',
        ]);

        $kernel->handle($request);

        $this->line('Request origin: '.(AppUrl::requestOrigin() ?? '(none)'));
        $this->line('Generated root URL: '.url('/'));
        $this->line('Request URL: '.request()->url());
        $this->line('Relative request URL: '.AppUrl::relativePath(request()->url()));

        $category = Category::query()
            ->where('is_active', true)
            ->get()
            ->first(fn (Category $candidate): bool => rtrim($candidate->catalogUrl(absolute: false), '/') === rtrim($path, '/'));

        if ($category === null) {
            $this->warn('Active category not found for path '.$path);

            return self::FAILURE;
        }

        $paginationPath = AppUrl::relativePath($category->catalogUrl(absolute: false));
        $paginator = new LengthAwarePaginator(
            range(1, 20),
            40,
            20,
            1,
            ['path' => $paginationPath, 'pageName' => 'page'],
        );

        $this->line('Category: '.$category->name);
        $this->line('Pagination path: '.$paginator->path());
        $this->line('Next page URL: '.$paginator->nextPageUrl());
        $this->line('Browser URL: '.browser_url($paginator->nextPageUrl()));

        if (str_contains((string) $paginator->nextPageUrl(), 'localhost')) {
            $this->error('Pagination still contains localhost.');

            return self::FAILURE;
        }

        $this->info('Pagination URLs look correct.');

        return self::SUCCESS;
    }
}
