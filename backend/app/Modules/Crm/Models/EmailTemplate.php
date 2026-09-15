<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * RC3 Phase 2 — a deterministic email template (Purpose + Tone → Subject + Body with {{tokens}}).
 * Database-backed, first-class asset (active/inactive + ownership). Rendered by TemplateRenderer; the
 * model holds the RAW authored text only — no AI, no Blade.
 *
 * `content` (nullable) is a designed template's validated content (EmailDesignSchema v1), never HTML, shown in
 * the reusable email design `email_design_id` names. NULL is a plain-text template, exactly as before, with no
 * design. When set, `body` is the plain-text form of design and content together, derived on the server with
 * its `{{tokens}}` intact, so every reader of `body` keeps working unchanged.
 */
#[Fillable([
    'purpose',
    'tone',
    'subject',
    'body',
    'content',
    'email_design_id',
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
            'content' => 'array',
        ];
    }

    /** Whether this template is built with the visual designer rather than written as plain text. */
    public function isDesigned(): bool
    {
        return $this->content !== null;
    }

    /** @return BelongsTo<EmailDesign, $this> */
    public function design(): BelongsTo
    {
        return $this->belongsTo(EmailDesign::class, 'email_design_id');
    }

    /** @param Builder<EmailTemplate> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
