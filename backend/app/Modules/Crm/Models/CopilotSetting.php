<?php

namespace App\Modules\Crm\Models;

use App\Modules\Crm\Services\Copilot\CopilotSettingService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The Copilot's AI toggle — one platform-wide row. PERSISTENCE ONLY: whether AI actually runs also
 * depends on the ops switch and the credential, and that decision lives in
 * {@see CopilotSettingService}.
 *
 * @property bool $ai_enabled
 * @property int|null $updated_by
 */
#[Fillable(['ai_enabled', 'updated_by'])]
class CopilotSetting extends Model
{
    protected $table = 'crm_copilot_settings';

    /** Kept in lockstep with the column default, so an unsaved instance also reads as OFF. */
    protected $attributes = [
        'ai_enabled' => false,
    ];

    protected function casts(): array
    {
        return [
            'ai_enabled' => 'boolean',
        ];
    }
}
