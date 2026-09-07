<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * RC3 Phase 2 — a deterministic email template (Purpose + Tone → Subject + Body with {{tokens}}).
 * Database-backed, first-class asset (active/inactive + ownership). Rendered by TemplateRenderer; the
 * model holds the RAW authored text only — no AI, no Blade.
 */
#[Fillable([
    'purpose',
    'tone',
    'subject',
    'body',
    'is_active',
    'created_by',
])]
class EmailTemplate extends Model
{
    use BelongsToOrg;
    protected $table = 'crm_email_templates';

    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @param Builder<EmailTemplate> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
