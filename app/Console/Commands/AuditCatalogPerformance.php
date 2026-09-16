<?php

namespace App\Console\Commands;

use App\Support\CatalogCache;
use App\Support\CatalogPerformanceProfiler;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class AuditCatalogPerformance extends Command
{
    protected $signature = 'catalog:audit
        {path=/ribalstvo/ : Relative catalog URL}
        {--cold : Remove cached catalog data for this category before profiling}
        {--explain : Run EXPLAIN for every SELECT slower than the configured threshold}';

    protected $description = 'Profile a catalog request, slow SQL, memory, response size and execution phases.';

    public function handle(Kernel $kernel, CatalogCache $cache, CatalogPerformanceProfiler $profiler): int
    {
        config()->set('performance.catalog_profiling', true);
        $path = '/'.ltrim((string) $this->argument('path'), '/');

        if ($this->option('cold')) {
            $this->forgetCategoryRuntimeCache($path, $cache);
        }

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = [
                'duration_ms' => round($query->time, 2),
                'sql' => $query->sql,
                'bindings' => $query->bindings,
            ];
        });

        $start = microtime(true);
        $initialMemory = memory_get_usage(true);
        $request = Request::create($path, 'GET');

        if ($request->boolean('fast_filters') || $request->boolean('filters_only')) {
            $request->headers->set('Accept', 'application/json');
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        }

        $response = $kernel->handle($request);
        $content = $response->getContent();
        $totalMs = round((microtime(true) - $start) * 1000, 2);
        $phaseMs = array_sum($profiler->timings());
        $slowThreshold = (float) config('performance.slow_query_log.threshold_ms', 50);
        $slowQueries = collect($queries)
            ->filter(fn (array $query): bool => $query['duration_ms'] >= $slowThreshold)
            ->sortByDesc('duration_ms')
            ->values();

        $this->table(['Metric', 'Value'], [
            ['Status', $response->getStatusCode()],
            ['Total', $totalMs.' ms'],
            ['SQL queries', count($queries)],
            ['SQL total', round(array_sum(array_column($queries, 'duration_ms')), 2).' ms'],
            ['Slow SQL', $slowQueries->count()],
            ['Peak memory delta', round((memory_get_peak_usage(true) - $initialMemory) / 1048576, 2).' MB'],
            ['Response size', round(strlen($content) / 1024, 2).' KB'],
        ]);

        $timings = $profiler->timings();
        $timings['response_render_and_middleware'] = max(0, round($totalMs - $phaseMs, 2));
        $this->newLine();
        $this->table(
            ['Phase', 'Time'],
            collect($timings)->map(fn (float $time, string $phase): array => [$phase, $time.' ms'])->values()->all(),
        );

        $this->newLine();
        $this->table(
            ['#', 'Time', 'SQL'],
            $slowQueries->map(fn (array $query, int $index): array => [
                $index + 1,
                $query['duration_ms'].' ms',
                str($query['sql'])->squish()->limit(180),
            ])->all(),
        );

        $repeatedQueries = collect($queries)
            ->groupBy('sql')
            ->map(fn ($group, string $sql): array => [
                'count' => $group->count(),
                'duration_ms' => round($group->sum('duration_ms'), 2),
                'sql' => $sql,
            ])
            ->filter(fn (array $group): bool => $group['count'] > 1)
            ->sortByDesc('duration_ms')
            ->take(12)
            ->values();

        if ($repeatedQueries->isNotEmpty()) {
            $this->newLine();
            $this->components->info('Repeated SQL');
            $this->table(
                ['Count', 'Total', 'SQL'],
                $repeatedQueries->map(fn (array $group): array => [
                    $group['count'],
                    $group['duration_ms'].' ms',
                    str($group['sql'])->squish()->limit(180),
                ])->all(),
            );
        }

        if ($this->option('explain')) {
            foreach ($slowQueries as $index => $query) {
                if (! str_starts_with(ltrim(strtolower($query['sql'])), 'select')) {
                    continue;
                }

                $this->newLine();
                $this->components->info('EXPLAIN #'.($index + 1));

                try {
                    $rows = DB::select('EXPLAIN '.$query['sql'], $query['bindings']);
                    $this->table(
                        ['table', 'type', 'key', 'rows', 'filtered', 'extra'],
                        collect($rows)->map(fn (object $row): array => [
                            $row->table ?? '',
                            $row->type ?? '',
                            $row->key ?? '',
                            $row->rows ?? '',
                            $row->filtered ?? '',
                            $row->Extra ?? '',
                        ])->all(),
                    );
                } catch (Throwable $exception) {
                    $this->components->warn($exception->getMessage());
                }
            }
        }

        $kernel->terminate($request, $response);

        return self::SUCCESS;
    }

    private function forgetCategoryRuntimeCache(string $path, CatalogCache $cache): void
    {
        $segments = collect(explode('/', trim($path, '/')))->filter()->values();

        if ($segments->first() === 'ru') {
            $segments->shift();
        }

        $searchIndex = $segments->search('search');
        $candidatePath = ($searchIndex === false ? $segments : $segments->take($searchIndex))->implode('/');
        $index = $cache->categoryPathIndex();
        $categoryId = $index['canonical'][mb_strtolower($candidatePath)]
            ?? $index['legacy'][mb_strtolower($candidatePath)]
            ?? null;

        if (! $categoryId) {
            return;
        }

        $version = Cache::get('catalog:version');
        $manifest = Cache::get('catalog:manifest:'.$version, []);

        foreach ($manifest as $key) {
            if (
                str_contains($key, 'category-'.$categoryId)
                && (
                    str_contains($key, 'catalog-data:')
                    || str_contains($key, 'base-facets:')
                    || str_contains($key, 'filter-popularity:')
                    || str_contains($key, 'seo-schema:')
                    || str_contains($key, 'seo-meta:')
                )
            ) {
                Cache::forget($key);
            }
        }
    }
}
