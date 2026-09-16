@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\CatalogCacheLog> $cacheLogs */
    $cacheLogs = $cacheLogs ?? collect();
@endphp

<style>
    .catalog-cache-log-panel {
        max-width: 820px;
        margin-top: 24px;
        padding: 20px 22px;
        border: 1px solid rgba(148, 163, 184, .25);
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 1px 4px rgba(15, 23, 42, .06);
    }

    .catalog-cache-log-panel h3 {
        margin: 0 0 6px;
        font-size: 16px;
        font-weight: 700;
        color: #17211c;
    }

    .catalog-cache-log-panel p {
        margin: 0 0 16px;
        color: #64748b;
        font-size: 13px;
    }

    .catalog-cache-log-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    .catalog-cache-log-table th,
    .catalog-cache-log-table td {
        padding: 10px 12px;
        border-bottom: 1px solid rgba(148, 163, 184, .18);
        text-align: left;
        vertical-align: top;
    }

    .catalog-cache-log-table th {
        color: #64748b;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    .catalog-cache-log-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        white-space: nowrap;
    }

    .catalog-cache-log-badge.is-auto {
        background: rgba(59, 130, 246, .12);
        color: #1d4ed8;
    }

    .catalog-cache-log-badge.is-manual {
        background: rgba(49, 95, 69, .12);
        color: #315f45;
    }

    .catalog-cache-log-badge.is-cli {
        background: rgba(100, 116, 139, .12);
        color: #475569;
    }

    .catalog-cache-log-badge.is-system {
        background: rgba(168, 85, 247, .12);
        color: #7e22ce;
    }

    .catalog-cache-log-badge.is-warm {
        background: rgba(14, 165, 233, .12);
        color: #0369a1;
    }

    .catalog-cache-log-badge.is-clear {
        background: rgba(245, 158, 11, .12);
        color: #b45309;
    }

    .catalog-cache-log-badge.is-success {
        background: rgba(34, 197, 94, .12);
        color: #15803d;
    }

    .catalog-cache-log-badge.is-failed {
        background: rgba(220, 38, 38, .12);
        color: #b91c1c;
    }

    .catalog-cache-log-empty {
        padding: 18px 0 4px;
        color: #64748b;
        font-size: 13px;
    }

    .catalog-cache-log-error {
        color: #b91c1c;
        font-size: 12px;
        line-height: 1.45;
    }

    .catalog-cache-log-detail {
        color: #475569;
        font-size: 12px;
        line-height: 1.45;
    }

    @media (prefers-color-scheme: dark) {
        .catalog-cache-log-panel {
            border-color: rgba(255, 255, 255, .1);
            background: #111827;
        }

        .catalog-cache-log-panel h3 {
            color: #f8fafc;
        }

        .catalog-cache-log-panel p,
        .catalog-cache-log-empty {
            color: #94a3b8;
        }

        .catalog-cache-log-detail {
            color: #cbd5e1;
        }
    }

    @media (max-width: 900px) {
        .catalog-cache-log-table thead {
            display: none;
        }

        .catalog-cache-log-table tr {
            display: block;
            padding: 10px 0;
            border-bottom: 1px solid rgba(148, 163, 184, .18);
        }

        .catalog-cache-log-table td {
            display: block;
            border: 0;
            padding: 4px 0;
        }
    }
</style>

<div class="catalog-cache-log-panel">
    <h3>Журнал кешу каталогу</h3>
    <p>Останні 30 подій: прогрів (авто, вручну, CLI) та очищення після імпорту фідів, БД тощо.</p>

    @if($cacheLogs->isEmpty())
        <div class="catalog-cache-log-empty">Ще не було жодного запису.</div>
    @else
        <table class="catalog-cache-log-table">
            <thead>
                <tr>
                    <th>Час</th>
                    <th>Дія</th>
                    <th>Джерело</th>
                    <th>Статус</th>
                    <th>Деталі</th>
                    <th>Тривалість</th>
                </tr>
            </thead>
            <tbody>
                @foreach($cacheLogs as $log)
                    <tr wire:key="catalog-cache-log-{{ $log->id }}">
                        <td>
                            <strong>{{ $log->finished_at?->format('d.m.Y H:i') ?? '—' }}</strong>
                            @if($log->started_at && $log->action === 'warm')
                                <div style="color:#94a3b8;font-size:11px;margin-top:2px;">
                                    старт {{ $log->started_at->format('H:i:s') }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <span @class([
                                'catalog-cache-log-badge',
                                'is-warm' => $log->action === 'warm',
                                'is-clear' => $log->action === 'clear',
                            ])>{{ $log->actionLabel() }}</span>
                        </td>
                        <td>
                            <span @class([
                                'catalog-cache-log-badge',
                                'is-auto' => $log->trigger === 'auto',
                                'is-manual' => $log->trigger === 'manual',
                                'is-cli' => $log->trigger === 'cli',
                                'is-system' => $log->trigger === 'system',
                            ])>{{ $log->triggerLabel() }}</span>
                        </td>
                        <td>
                            <span @class([
                                'catalog-cache-log-badge',
                                'is-success' => $log->status === 'success',
                                'is-failed' => $log->status === 'failed',
                            ])>{{ $log->statusLabel() }}</span>
                        </td>
                        <td>
                            @if($log->action === 'warm')
                                @if($log->status === 'success')
                                    <span class="catalog-cache-log-detail">
                                        {{ (int) $log->steps_completed }} / {{ (int) $log->steps_total }} кроків
                                        @if($log->fresh)
                                            · повний прогрів
                                        @endif
                                    </span>
                                @else
                                    <span class="catalog-cache-log-error">{{ $log->error_message }}</span>
                                @endif
                            @else
                                <span class="catalog-cache-log-detail">{{ $log->reason ?? 'Очищення кешу' }}</span>
                            @endif
                        </td>
                        <td>
                            @php($duration = $log->durationSeconds())
                            @if($duration !== null && $duration > 0)
                                @if($duration < 60)
                                    {{ $duration }} с
                                @else
                                    {{ intdiv($duration, 60) }} хв {{ $duration % 60 }} с
                                @endif
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
