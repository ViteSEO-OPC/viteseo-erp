<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One Copilot AI request's metadata. No question text, no CRM context, no answer, no credential —
 * see the migration.
 *
 * @property string $outcome
 * @property string|null $reason
 * @property array<string, int>|null $capabilities
 */
#[Fillable([
    'org_id',
    'user_id',
    'question_hash',
    'question_length',
    'capabilities',
    'model',
    'instruction_version',
    'duration_ms',
    'prompt_tokens',
    'completion_tokens',
    'outcome',
    'reason',
])]
class CopilotAiLog extends Model
{
    use BelongsToOrg;

    public const UPDATED_AT = null;

    protected $table = 'crm_copilot_ai_logs';

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
        ];
    }
}
