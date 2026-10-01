<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\CopilotSetting;
use App\Modules\Crm\Services\Copilot\CopilotSettingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Settings → Integrations → Copilot AI. The toggle, each gate's state, and the result.
 *
 * An allow-list: status words only. Nothing about the credential beyond the word the NERBY capability
 * already reports — never a key, its last four characters, its length or its ciphertext.
 *
 * @property CopilotSetting $resource
 */
class CopilotSettingResource extends JsonResource
{
    public function __construct(CopilotSetting $resource, private readonly CopilotSettingService $settings)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'aiEnabled' => (bool) $this->resource->ai_enabled,
            'opsEnabled' => (bool) config('copilot.ai.enabled'),
            'providerStatus' => $this->settings->providerStatus(),
            'readiness' => $this->settings->readiness(),
            'aiAvailable' => $this->settings->aiAvailable(),
            'updatedAt' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
