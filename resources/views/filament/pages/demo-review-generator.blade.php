<x-filament-panels::page>
    <style>
        .demo-reviews { display: grid; gap: 18px; max-width: 1180px; }
        .demo-card { padding: 20px; border: 1px solid rgba(148, 163, 184, .28); border-radius: 16px; background: #fff; box-shadow: 0 1px 4px rgba(15, 23, 42, .05); }
        .demo-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 18px; }
        .demo-field { display: grid; gap: 6px; margin-bottom: 14px; }
        .demo-field label { color: #1f2937; font-size: 13px; font-weight: 700; }
        .demo-field input, .demo-field textarea, .demo-field select { width: 100%; border: 1px solid #d1d5db; border-radius: 10px; padding: 10px 12px; background: #fff; color: #111827; }
        .demo-field textarea { min-height: 180px; resize: vertical; }
        .demo-inline { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
        .demo-checks { display: grid; gap: 10px; margin: 12px 0; }
        .demo-checks label { display: flex; align-items: center; gap: 8px; color: #374151; font-size: 14px; }
        .demo-button { display: inline-flex; align-items: center; justify-content: center; min-height: 40px; border: 0; border-radius: 10px; padding: 9px 15px; background: #315f45; color: #fff; cursor: pointer; font-weight: 800; }
        .demo-button.secondary { border: 1px solid #cbd5e1; background: #fff; color: #315f45; }
        .demo-button.danger { background: #b91c1c; }
        .demo-summary { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
        .demo-summary div { padding: 12px; border-radius: 12px; background: #f8fafc; }
        .demo-summary small { display: block; color: #64748b; font-size: 11px; }
        .demo-summary strong { display: block; margin-top: 4px; color: #0f172a; font-size: 16px; }
        .demo-warning { margin-top: 12px; padding: 11px 13px; border-radius: 10px; background: #fff7ed; color: #9a3412; font-size: 13px; }
        .demo-success { margin-top: 12px; padding: 11px 13px; border-radius: 10px; background: #ecfdf5; color: #047857; font-size: 13px; }
        .demo-help { margin-top: 10px; color: #64748b; font-size: 12px; line-height: 1.5; }
        .demo-button[disabled] { cursor: wait; opacity: .65; }
        .demo-status { display: inline-flex; border-radius: 999px; padding: 4px 9px; background: #eef2ff; color: #3730a3; font-size: 12px; font-weight: 800; }
        .demo-status.failed, .demo-status.completed_with_errors { background: #fef2f2; color: #991b1b; }
        .demo-status.completed { background: #ecfdf5; color: #047857; }
        .demo-status.running, .demo-status.queued { background: #fff7ed; color: #9a3412; }
        .demo-error-text { max-width: 360px; color: #991b1b; font-size: 12px; white-space: normal; }
        .demo-uuid { display: inline-block; max-width: 190px; overflow: hidden; text-overflow: ellipsis; vertical-align: middle; }
        .demo-table-wrap { overflow-x: auto; }
        .demo-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .demo-table th, .demo-table td { border-bottom: 1px solid #e5e7eb; padding: 10px; text-align: left; vertical-align: top; }
        .demo-table th { color: #475569; font-size: 11px; text-transform: uppercase; }
        .demo-text { min-width: 280px; }
        @media (max-width: 900px) { .demo-grid, .demo-inline, .demo-summary { grid-template-columns: 1fr; } }
    </style>

    <div class="demo-reviews">
        <div class="demo-grid">
            <div class="demo-card">
                <div class="demo-field">
                    <label>Коди товарів</label>
                    <textarea wire:model="productCodes" placeholder="Вставте SKU / external_id / variant_id / ID, кожен з нового рядка"></textarea>
                </div>

                <div class="demo-inline">
                    <div class="demo-field">
                        <label>Кількість відгуків для кожного товару</label>
                        <input type="number" min="1" max="20" wire:model="count">
                    </div>
                    <div class="demo-field">
                        <label>Дата від</label>
                        <input type="date" min="2026-06-27" max="{{ now()->toDateString() }}" wire:model="dateFrom">
                    </div>
                    <div class="demo-field">
                        <label>Стиль</label>
                        <select wire:model="style">
                            @foreach(\App\Services\OpenAiDemoReviewGenerator::STYLES as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="demo-inline">
                    <div class="demo-field">
                        <label>Мінімальний рейтинг</label>
                        <input type="number" min="4" max="5" wire:model="minRating">
                    </div>
                    <div class="demo-field">
                        <label>Максимальний рейтинг</label>
                        <input type="number" min="4" max="5" wire:model="maxRating">
                    </div>
                    <div class="demo-field">
                        <label>Модель OpenAI</label>
                        <input type="text" value="{{ config('services.openai.model') ?: 'OPENAI_MODEL не задано' }}" disabled>
                    </div>
                </div>

                <div class="demo-checks">
                    <label><input type="checkbox" wire:model="autoSave"> Автоматично зберігати після генерації</label>
                    <label><input type="checkbox" wire:model="visible"> Одразу увімкнути відгуки на сайті</label>
                </div>

                <button class="demo-button" type="button" wire:click="run" wire:loading.attr="disabled" wire:target="run">
                    <span wire:loading.remove wire:target="run">
                        {{ $autoSave ? 'Запустити генерацію в черзі' : 'Згенерувати попередній перегляд' }}
                    </span>
                    <span wire:loading wire:target="run">
                        {{ $autoSave ? 'Створюю batch…' : 'Генерую preview…' }}
                    </span>
                </button>

                <div class="demo-help" wire:loading wire:target="run">
                    Натискання прийнято, зачекайте кілька секунд. Якщо auto-save увімкнений — відгуки створяться після обробки черги.
                </div>

                @if($lastBatchId)
                    <div class="demo-success">
                        Batch створено: <code>{{ $lastBatchId }}</code><br>
                        Для ручної обробки: <code>php artisan queue:work redis --once --queue=reviews --timeout=120 --memory=512</code>
                    </div>
                @endif
            </div>

            <div class="demo-card">
                @php($summary = $this->summary())
                <div class="demo-summary">
                    <div><small>Товарів</small><strong>{{ $summary['products'] }}</strong></div>
                    <div><small>Відгуків / товар</small><strong>{{ $summary['per_product'] }}</strong></div>
                    <div><small>Всього відгуків</small><strong>{{ $summary['total_reviews'] }}</strong></div>
                    <div><small>Орієнтовно токенів</small><strong>{{ number_format($summary['estimated_tokens'], 0, '.', ' ') }}</strong></div>
                    <div><small>Орієнтовна вартість</small><strong>${{ number_format($summary['estimated_cost'], 6) }}</strong></div>
                    <div><small>Ліміт</small><strong>200 / запуск</strong></div>
                </div>

                @if($lastBatchId)
                    <div class="demo-warning">Останній batch: <code>{{ $lastBatchId }}</code></div>
                @endif

                @if($notFoundCodes)
                    <div class="demo-warning">
                        Не знайдено: {{ implode(', ', $notFoundCodes) }}
                    </div>
                @endif
            </div>
        </div>

        @if($previewRows)
            <div class="demo-card">
                <h2>Попередній перегляд</h2>
                <div class="demo-table-wrap">
                    <table class="demo-table">
                        <thead>
                            <tr>
                                <th>Код</th>
                                <th>Товар</th>
                                <th>Імʼя</th>
                                <th>Дата</th>
                                <th>Рейтинг</th>
                                <th>Стиль</th>
                                <th>Текст</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($previewRows as $index => $row)
                                <tr>
                                    <td>{{ $row['product_code'] }}</td>
                                    <td>{{ $row['product_name'] }}</td>
                                    <td><input type="text" wire:model="previewRows.{{ $index }}.author_name"></td>
                                    <td>{{ $row['review_date'] }}</td>
                                    <td><input type="number" min="4" max="5" wire:model="previewRows.{{ $index }}.rating" style="width:70px"></td>
                                    <td>{{ $row['style'] }} @if($row['has_intentional_typo']) · описка @endif</td>
                                    <td class="demo-text"><textarea wire:model="previewRows.{{ $index }}.text" style="min-height:82px"></textarea></td>
                                    <td>
                                        <button class="demo-button secondary" type="button" wire:click="savePreviewRow({{ $index }})">Зберегти</button>
                                        <button class="demo-button secondary" type="button" wire:click="regeneratePreviewRow({{ $index }})">↻</button>
                                        <button class="demo-button danger" type="button" wire:click="deletePreviewRow({{ $index }})">×</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <button class="demo-button" type="button" wire:click="savePreview">Зберегти всі</button>
            </div>
        @endif

        <div class="demo-card">
            <h2>Щоденна генерація для кінцевих категорій</h2>
            <p class="demo-help">
                Запускає той самий сценарій, що й розклад: 3–5 товарів у наявності з кожної кінцевої категорії,
                1 AI-відгук на товар, рейтинг 3–5. Товари з 7 AI-відгуками не беруться.
                Якщо в кінцевій категорії AI-відгуки вже є у 40% товарів — категорія пропускається.
            </p>

            <div class="demo-inline">
                <div class="demo-field">
                    <label>Категорія</label>
                    <select wire:model="dailyCategoryId">
                        <option value="">Всі кінцеві категорії</option>
                        @foreach($this->dailyCategoryOptions as $categoryId => $categoryName)
                            <option value="{{ $categoryId }}">{{ $categoryName }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="demo-field">
                    <label>Максимум відгуків за запуск</label>
                    <input type="number" min="1" max="500" wire:model="dailyMaxTotal">
                </div>
                <div class="demo-field">
                    <label>Стартова дата відгуків</label>
                    <input type="date" min="2026-06-27" max="{{ now()->toDateString() }}" wire:model="dateFrom">
                </div>
                <div class="demo-field">
                    <label>Ліміти</label>
                    <input type="text" value="до {{ \App\Services\DemoReviewPersistence::MAX_AI_REVIEWS_PER_PRODUCT }} AI-відгуків / товар; до {{ \App\Services\DailyDemoReviewScheduler::MAX_AI_REVIEWED_PRODUCTS_PERCENT }}% товарів / категорію" disabled>
                </div>
            </div>

            <div class="demo-checks">
                <label><input type="checkbox" wire:model="dailyDryRun"> Тільки перевірити вибір товарів, без запуску генерації</label>
            </div>

            <button class="demo-button" type="button" wire:click="runDaily" wire:loading.attr="disabled" wire:target="runDaily">
                <span wire:loading.remove wire:target="runDaily">
                    {{ $dailyDryRun ? 'Перевірити daily-вибір' : 'Запустити daily-генерацію зараз' }}
                </span>
                <span wire:loading wire:target="runDaily">Обробляю…</span>
            </button>

            @if($dailyPreviewRows)
                <div class="demo-warning">
                    Dry-run показує перші {{ count($dailyPreviewRows) }} вибраних товарів. Реальний запуск створить batch і відправить jobs у чергу reviews.
                </div>
                <div class="demo-table-wrap" style="margin-top:12px">
                    <table class="demo-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Код</th>
                                <th>Категорія</th>
                                <th>AI-відгуків</th>
                                <th>Товар</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($dailyPreviewRows as $row)
                                <tr>
                                    <td>{{ $row['id'] }}</td>
                                    <td>{{ $row['code'] }}</td>
                                    <td>{{ $row['category_id'] }}</td>
                                    <td>{{ $row['ai_reviews'] }}</td>
                                    <td>{{ $row['name'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="demo-card" wire:poll.5s>
            <h2>Останні batch-запуски</h2>
            <div class="demo-table-wrap">
                <table class="demo-table">
                    <thead>
                        <tr>
                            <th>UUID</th>
                            <th>Адмін</th>
                            <th>Тип</th>
                            <th>Статус</th>
                            <th>Товари</th>
                            <th>Відгуки</th>
                            <th>Токени</th>
                            <th>Вартість</th>
                            <th>Помилка</th>
                            <th>Дата</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->recentBatches as $batch)
                            @php($lastError = collect($batch->errors ?? [])->last())
                            <tr>
                                <td><code class="demo-uuid" title="{{ $batch->id }}">{{ $batch->id }}</code></td>
                                <td>{{ $batch->admin?->name ?? '—' }}</td>
                                <td>{{ $batch->settings['trigger'] ?? 'manual' }}</td>
                                <td><span class="demo-status {{ $batch->status }}">{{ $batch->status }}</span></td>
                                <td>{{ $batch->products_count }}</td>
                                <td>{{ $batch->successful_reviews_count }} / {{ $batch->requested_reviews_count }}</td>
                                <td>{{ $batch->total_tokens }}</td>
                                <td>${{ number_format((float) $batch->estimated_cost, 6) }}</td>
                                <td class="demo-error-text">
                                    @if(is_array($lastError))
                                        {{ $lastError['message'] ?? '—' }}
                                    @elseif($batch->status === 'queued')
                                        Очікує обробки черги reviews
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $batch->created_at?->format('d.m.Y H:i') }}</td>
                                <td><button class="demo-button danger" type="button" wire:click="deleteBatch('{{ $batch->id }}')" wire:confirm="Видалити всі тестові відгуки цього batch?">Видалити</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="11">Запусків ще немає.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
