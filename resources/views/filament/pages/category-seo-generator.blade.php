<x-filament-panels::page>
    <style>
        .seo-ai { display:grid; gap:18px; max-width:1280px; }
        .seo-card { padding:20px; border:1px solid rgba(148,163,184,.28); border-radius:16px; background:#fff; box-shadow:0 1px 4px rgba(15,23,42,.05); }
        .seo-grid { display:grid; grid-template-columns:2fr 1fr; gap:18px; }
        .seo-inline { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
        .seo-field { display:grid; gap:6px; margin-bottom:14px; }
        .seo-field label { font-size:13px; font-weight:800; color:#1f2937; }
        .seo-field input, .seo-field select { width:100%; border:1px solid #d1d5db; border-radius:10px; padding:10px 12px; background:#fff; color:#111827; }
        .seo-field select[multiple] { min-height:150px; }
        .seo-checks { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:8px 14px; margin:10px 0 16px; }
        .seo-checks label { display:flex; align-items:center; gap:8px; color:#374151; font-size:14px; }
        .seo-button { display:inline-flex; align-items:center; justify-content:center; min-height:40px; border:0; border-radius:10px; padding:9px 15px; background:#315f45; color:#fff; cursor:pointer; font-weight:800; }
        .seo-button.secondary { border:1px solid #cbd5e1; background:#fff; color:#315f45; }
        .seo-button.danger { background:#b91c1c; }
        .seo-audit { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; }
        .seo-audit div { padding:12px; border-radius:12px; background:#f8fafc; }
        .seo-audit small { display:block; color:#64748b; font-size:11px; }
        .seo-audit strong { display:block; margin-top:4px; color:#0f172a; font-size:18px; }
        .seo-table-wrap { overflow-x:auto; }
        .seo-table { width:100%; border-collapse:collapse; font-size:13px; }
        .seo-table th, .seo-table td { border-bottom:1px solid #e5e7eb; padding:10px; text-align:left; vertical-align:top; }
        .seo-table th { color:#475569; font-size:11px; text-transform:uppercase; }
        .seo-status { display:inline-flex; border-radius:999px; padding:4px 9px; background:#eef2ff; color:#3730a3; font-size:12px; font-weight:800; }
        .seo-status.failed, .seo-status.rejected { background:#fef2f2; color:#991b1b; }
        .seo-status.generated, .seo-status.published { background:#ecfdf5; color:#047857; }
        .seo-status.pending, .seo-status.generating, .seo-status.generated_with_warnings { background:#fff7ed; color:#9a3412; }
        .seo-preview { min-width:420px; max-width:640px; color:#334155; }
        .seo-preview details { margin-top:8px; }
        .seo-warning { margin-top:8px; padding:9px 11px; border-radius:10px; background:#fff7ed; color:#9a3412; font-size:12px; }
        @media(max-width:900px){ .seo-grid,.seo-inline,.seo-audit,.seo-checks{grid-template-columns:1fr;} }
    </style>

    <div class="seo-ai">
        <div class="seo-grid">
            <div class="seo-card">
                <div class="seo-inline">
                    <div class="seo-field">
                        <label>Режим вибору</label>
                        <select wire:model.live="selectionMode">
                            <option value="single">Одна категорія</option>
                            <option value="multiple">Декілька категорій</option>
                            <option value="all">Усі категорії</option>
                            <option value="missing_seo">Категорії без SEO</option>
                            <option value="missing_title">Без Meta Title</option>
                            <option value="missing_description">Без Meta Description</option>
                            <option value="branch">Категорії певної гілки</option>
                        </select>
                    </div>
                    <div class="seo-field">
                        <label>Мова</label>
                        <select wire:model.live="localeMode">
                            <option value="uk">Генерувати українську</option>
                            <option value="ru">Генерувати російську</option>
                            <option value="both">Генерувати обидві</option>
                        </select>
                    </div>
                    <div class="seo-field">
                        <label>Бажана кількість слів SEO-тексту</label>
                        <input type="number" min="200" max="1500" wire:model.live.debounce.500ms="wordCount">
                    </div>
                </div>

                @if($selectionMode === 'single')
                    <div class="seo-field">
                        <label>Категорія</label>
                        <select wire:model.live="singleCategoryId">
                            <option value="">Оберіть категорію</option>
                            @foreach($this->categoryOptions as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if($selectionMode === 'multiple')
                    <div class="seo-field">
                        <label>Категорії</label>
                        <select multiple wire:model.live="categoryIds">
                            @foreach($this->categoryOptions as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if($selectionMode === 'branch')
                    <div class="seo-field">
                        <label>Батьківська категорія / гілка</label>
                        <select wire:model.live="branchCategoryId">
                            <option value="">Оберіть гілку</option>
                            @foreach($this->categoryOptions as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="seo-inline">
                    <div class="seo-field">
                        <label>Існуючий контент</label>
                        <select wire:model.live="overwriteMode">
                            <option value="missing">Не перезаписувати існуючі дані</option>
                            <option value="selected">Перегенерувати тільки вибрані поля</option>
                            <option value="all">Перегенерувати все</option>
                        </select>
                    </div>
                    <div class="seo-field">
                        <label>Фільтр</label>
                        <label style="display:flex;gap:8px;align-items:center;margin-top:10px"><input type="checkbox" wire:model.live="activeOnly"> Тільки активні категорії</label>
                    </div>
                    <div class="seo-field">
                        <label>Макс. задач за запуск</label>
                        <input type="number" min="1" max="500" wire:model.live.debounce.500ms="maxJobsPerRun">
                    </div>
                </div>

                <div class="seo-inline">
                    <div class="seo-field">
                        <label>Бюджет на запуск, $</label>
                        <input type="number" min="0.01" max="100" step="0.01" wire:model.live.debounce.500ms="maxBudgetUsd">
                    </div>
                    <div class="seo-field">
                        <label>Безпечний режим</label>
                        <label style="display:flex;gap:8px;align-items:center;margin-top:10px"><input type="checkbox" wire:model.live="dryRun"> Dry-run: тільки порахувати, не запускати</label>
                    </div>
                </div>

                <div class="seo-checks">
                    <label><input type="checkbox" value="seo_title" wire:model.live="fields"> SEO Title</label>
                    <label><input type="checkbox" value="meta_description" wire:model.live="fields"> Meta Description</label>
                    <label><input type="checkbox" value="h1" wire:model.live="fields"> H1</label>
                    <label><input type="checkbox" value="intro" wire:model.live="fields"> Короткий вступ</label>
                    <label><input type="checkbox" value="seo_text" wire:model.live="fields"> Основний SEO-текст</label>
                    <label><input type="checkbox" value="faq" wire:model.live="fields"> FAQ</label>
                    <label><input type="checkbox" value="faq_schema" wire:model.live="fields"> SEO FAQ schema</label>
                </div>

                <button class="seo-button" type="button" wire:click="generate" wire:loading.attr="disabled" wire:target="generate">
                    <span wire:loading.remove wire:target="generate">{{ $dryRun ? 'Порахувати без запуску' : 'Запустити генерацію в черзі' }}</span>
                    <span wire:loading wire:target="generate">Створюю задачі…</span>
                </button>
                @php($estimate = $this->estimateRun())
                <div class="seo-warning">
                    Оцінка: категорій {{ $estimate['categories'] }}, задач {{ $estimate['jobs'] }},
                    слів ~{{ number_format($estimate['estimated_words']) }},
                    токенів ~{{ number_format($estimate['estimated_input_tokens'] + $estimate['estimated_output_tokens']) }},
                    вартість ~$ {{ number_format($estimate['estimated_cost'], 4) }}.
                </div>
                @if($overwriteMode !== 'missing')
                    <div class="seo-warning">Публікація таких результатів може перезаписати поточні SEO-поля. Перед публікацією перевір preview.</div>
                @endif
            </div>

            <div class="seo-card">
                @php($audit = $this->audit())
                <h2>SEO audit категорій</h2>
                <div class="seo-audit">
                    <div><small>Всього</small><strong>{{ $audit['total'] }}</strong></div>
                    <div><small>Без SEO Title</small><strong>{{ $audit['without_title'] }}</strong></div>
                    <div><small>Без Meta Description</small><strong>{{ $audit['without_description'] }}</strong></div>
                    <div><small>Без H1</small><strong>{{ $audit['without_h1'] }}</strong></div>
                    <div><small>Без SEO-тексту</small><strong>{{ $audit['without_seo_text'] }}</strong></div>
                    <div><small>Без FAQ</small><strong>{{ $audit['without_faq'] }}</strong></div>
                    <div><small>Повністю заповнені</small><strong>{{ $audit['complete'] }}</strong></div>
                </div>
                <p class="seo-warning">За замовчуванням AI-результат не публікується — спочатку зʼявляється preview в історії.</p>
            </div>
        </div>

        <div class="seo-card" wire:poll.5s>
            <h2>Preview та історія генерацій</h2>
            @php($stats = $this->generationStats())
            <div class="seo-inline">
                <div class="seo-field">
                    <label>Показувати</label>
                    <select wire:model.live="historyStatus">
                        <option value="actionable">Готові до approve ({{ $stats['actionable'] }})</option>
                        <option value="pending">Pending ({{ $stats['pending'] }})</option>
                        <option value="generating">Generating ({{ $stats['generating'] }})</option>
                        <option value="failed">Failed ({{ $stats['failed'] }})</option>
                        <option value="published">Published ({{ $stats['published'] }})</option>
                        <option value="rejected">Rejected ({{ $stats['rejected'] }})</option>
                        <option value="all">Всі ({{ $stats['all'] }})</option>
                    </select>
                </div>
                <div class="seo-field">
                    <label>Масові дії</label>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <button class="seo-button" type="button" wire:click="publishGenerated" wire:confirm="Опублікувати всі готові AI SEO-варіанти?">Опублікувати готові</button>
                        <button class="seo-button danger" type="button" wire:click="rejectPendingAndFailed" wire:confirm="Відхилити всі pending/failed генерації?">Очистити pending/failed</button>
                        <button class="seo-button danger" type="button" wire:click="deleteRejected" wire:confirm="Видалити всі rejected генерації з історії?">Видалити rejected</button>
                    </div>
                </div>
            </div>
            <div class="seo-table-wrap">
                <table class="seo-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Категорія</th>
                            <th>Мова</th>
                            <th>Статус</th>
                            <th>AI preview</th>
                            <th>Поточне значення</th>
                            <th>Токени / $</th>
                            <th>Дата</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->recentGenerations as $generation)
                            <tr>
                                <td>{{ $generation->id }}</td>
                                <td>
                                    <strong>{{ $generation->category?->name }}</strong><br>
                                    <small>{{ $generation->category?->catalogUrl(absolute:false, locale:$generation->locale) }}</small>
                                </td>
                                <td>{{ strtoupper($generation->locale) }}</td>
                                <td>
                                    <span class="seo-status {{ $generation->status }}">{{ $generation->status }}</span>
                                    @if($generation->validation_errors)
                                        <div class="seo-warning">{{ implode(' ', $generation->validation_errors) }}</div>
                                    @endif
                                    @if($generation->errors)
                                        <div class="seo-warning">{{ data_get($generation->errors, '0.message') }}</div>
                                    @endif
                                </td>
                                <td class="seo-preview">
                                    <strong>{{ $generation->seo_title ?: '—' }}</strong><br>
                                    <small>{{ $generation->meta_description }}</small>
                                    <div>{{ $generation->h1 }}</div>
                                    <p>{{ $generation->intro }}</p>
                                    @if($generation->seo_text)
                                        <details><summary>SEO text</summary><div>{!! $generation->seo_text !!}</div></details>
                                    @endif
                                    @if($generation->faq)
                                        <details><summary>FAQ</summary>
                                            <ol>
                                                @foreach($generation->faq as $item)
                                                    <li><strong>{{ $item['question'] ?? '' }}</strong><br>{{ $item['answer'] ?? '' }}</li>
                                                @endforeach
                                            </ol>
                                        </details>
                                    @endif
                                </td>
                                <td class="seo-preview">
                                    @php($cat = $generation->category)
                                    @if($cat)
                                        <strong>{{ $cat->translated('seo_title', $generation->locale) ?: '—' }}</strong><br>
                                        <small>{{ $cat->translated('meta_description', $generation->locale) ?: '—' }}</small>
                                        <div>{{ $cat->translated('h1', $generation->locale) ?: '—' }}</div>
                                        <p>{{ \Illuminate\Support\Str::limit(strip_tags((string) $cat->translated('description', $generation->locale)), 240) }}</p>
                                    @endif
                                </td>
                                <td>{{ $generation->total_tokens }}<br>${{ number_format((float) $generation->estimated_cost, 6) }}</td>
                                <td>{{ $generation->created_at?->format('d.m.Y H:i') }}</td>
                                <td>
                                    @if(in_array($generation->status, ['generated', 'generated_with_warnings', 'approved'], true))
                                        <button class="seo-button" type="button" wire:click="publish({{ $generation->id }})" wire:confirm="Опублікувати AI SEO для цієї категорії?">Опублікувати</button>
                                        <button class="seo-button secondary" type="button" wire:click="regenerate({{ $generation->id }})">↻</button>
                                        <button class="seo-button danger" type="button" wire:click="reject({{ $generation->id }})">Відхилити</button>
                                    @endif
                                    @if(in_array($generation->status, ['pending', 'generating'], true))
                                        <button class="seo-button danger" type="button" wire:click="cancel({{ $generation->id }})" wire:confirm="Скасувати цю генерацію?">Скасувати</button>
                                    @endif
                                    @if($generation->status === 'published')
                                        <button class="seo-button secondary" type="button" wire:click="restore({{ $generation->id }})" wire:confirm="Відновити цю версію?">Відновити</button>
                                    @endif
                                    <button class="seo-button danger" type="button" wire:click="deleteGeneration({{ $generation->id }})" wire:confirm="Видалити цей запис історії?">Видалити</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9">Генерацій ще немає.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
