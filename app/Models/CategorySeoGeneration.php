<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategorySeoGeneration extends Model
{
    protected $fillable = [
        'category_id',
        'locale',
        'status',
        'requested_word_count',
        'requested_fields',
        'overwrite_mode',
        'selection_mode',
        'settings',
        'context_summary',
        'seo_title',
        'meta_description',
        'h1',
        'intro',
        'seo_text',
        'faq',
        'internal_links',
        'validation_errors',
        'errors',
        'model',
        'prompt_version',
        'response_id',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'estimated_cost',
        'generated_by',
        'generated_at',
        'approved_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_fields' => 'array',
            'settings' => 'array',
            'context_summary' => 'array',
            'faq' => 'array',
            'internal_links' => 'array',
            'validation_errors' => 'array',
            'errors' => 'array',
            'estimated_cost' => 'decimal:6',
            'generated_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
