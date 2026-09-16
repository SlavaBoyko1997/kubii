<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Support\AppUrl;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\File;

class VerifyCatalogUrlDeploy extends Command
{
    protected $signature = 'catalog:verify-deploy {path=/odyag-ta-vzuttya/flisovi-kofty/}';

    protected $description = 'Verify that catalog pagination URL fixes are deployed and working.';

    public function handle(Kernel $kernel): int
    {
        $checks = [];

        $checks['function_browser_url'] = function_exists('browser_url');
        $checks['store_controller_v20'] = str_contains(
            File::get(app_path('Http/Controllers/StoreController.php')),
            'catalog-results-html:v20:',
        );
        $checks['nav_blade_browser_url'] = str_contains(
            File::get(resource_path('views/store/_catalog-results-nav.blade.php')),
            'browser_url(',
        );
        $checks['middleware_localhost_scrub'] = str_contains(
            File::get(app_path('Http/Middleware/SecurityHeaders.php')),
            'sanitizeLocalhostHtmlUrls',
        );
        $checks['app_url'] = (string) config('app.url');
        $checks['app_env'] = (string) config('app.env');
        $checks['configured_origin'] = AppUrl::configuredOrigin() ?? '(none)';

        $path = '/'.trim((string) $this->argument('path'), '/').'/';
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'kubii.com.ua';
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        $request = Request::create($path, 'GET', server: [
            'HTTP_ACCEPT' => 'text/html',
            'HTTP_HOST' => $host,
            'SERVER_NAME' => $host,
            'HTTPS' => $scheme === 'https' ? 'on' : 'off',
        ]);
        $kernel->handle($request);

        $checks['request_url'] = request()->url();
        $checks['request_host'] = request()->getHost();
        $checks['browser_url_test'] = browser_url('http://localhost'.$path.'?page=2');

        $category = Category::query()
            ->where('is_active', true)
            ->get()
            ->first(fn (Category $candidate): bool => rtrim($candidate->catalogUrl(absolute: false), '/') === rtrim($path, '/'));

        if ($category !== null) {
            $paginationPath = AppUrl::relativePath($category->catalogUrl(absolute: false));
            $paginator = new LengthAwarePaginator(
                range(1, 20),
                40,
                20,
                1,
                ['path' => $paginationPath, 'pageName' => 'page'],
            );
            $checks['pagination_path'] = $paginator->path();
            $checks['next_page_url'] = $paginator->nextPageUrl();
            $checks['next_page_browser_url'] = browser_url($paginator->nextPageUrl());
        } else {
            $checks['pagination_path'] = '(category not found)';
            $checks['next_page_url'] = '(category not found)';
            $checks['next_page_browser_url'] = '(category not found)';
        }

        $this->table(['Check', 'Value'], collect($checks)->map(fn ($value, $key): array => [$key, is_bool($value) ? ($value ? 'yes' : 'NO') : $value])->all());

        $failed = collect($checks)->filter(function ($value, string $key): bool {
            if (is_bool($value)) {
                return $value === false;
            }

            if (in_array($key, ['next_page_url', 'next_page_browser_url', 'browser_url_test', 'pagination_path'], true)) {
                return is_string($value) && str_contains($value, 'localhost');
            }

            if ($key === 'app_env' && $value === 'local') {
                return true;
            }

            return false;
        });

        if ($failed->isNotEmpty()) {
            $this->error('Deployment verification failed. Send this full output to support.');

            return self::FAILURE;
        }

        $this->info('Catalog URL deployment looks correct.');

        return self::SUCCESS;
    }
}
