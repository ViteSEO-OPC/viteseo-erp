<?php

namespace App\Modules\Crm\Services\Copilot;

use App\Models\User;
use App\Modules\Crm\Models\CopilotSetting;
use App\Modules\Crm\Services\Discovery\AiStrategyCapability;

/**
 * May the Copilot use AI right now — and if not, why not?
 *
 * THREE GATES, ALL REQUIRED, checked cheapest first:
 *
 *   1. `copilot.ai.enabled` — the ops kill switch (COPILOT_AI_ENABLED, default false). Off means no
 *      database read, no credential read, no network.
 *   2. The product toggle in `crm_copilot_settings`, changed by a Super Admin in Settings. Absent
 *      row = OFF.
 *   3. The existing NERBY OpenAI/OpenRouter credential, as {@see AiStrategyCapability::credential()}
 *      already judges it (key present, enabled, last test ok, no recorded credit refusal).
 *
 * The toggle is NEVER inferred from NERBY: an available credential does not switch the Copilot on.
 * This class is the only writer of the toggle, and it never writes anything else — in particular
 * not the NERBY provider row.
 */
class CopilotSettingService
{
    /** Every gate passed. */
    public const AVAILABLE = 'available';

    /** The ops kill switch is off on this server. */
    public const OPS_DISABLED = 'ops_disabled';

    /** A Super Admin has not switched Copilot AI on. */
    public const TOGGLE_OFF = 'toggle_off';

    public function __construct(private readonly AiStrategyCapability $capability) {}

    /** The stored toggle, or an unsaved OFF instance. Never writes. */
    public function current(): CopilotSetting
    {
        return CopilotSetting::query()->oldest('id')->first() ?? new CopilotSetting;
    }

    public function setAiEnabled(bool $enabled, User $by): CopilotSetting
    {
        $setting = $this->current();
        $setting->fill(['ai_enabled' => $enabled, 'updated_by' => $by->id])->save();

        return $setting;
    }

    /** The shared credential's state, in {@see AiStrategyCapability}'s own vocabulary. */
    public function providerStatus(): string
    {
        return $this->capability->credential();
    }

    /**
     * Why AI is or is not available: {@see AVAILABLE}, {@see OPS_DISABLED}, {@see TOGGLE_OFF}, or the
     * credential's status word when that is the gate that failed.
     */
    public function readiness(): string
    {
        if (! config('copilot.ai.enabled')) {
            return self::OPS_DISABLED;
        }
        if (! $this->current()->ai_enabled) {
            return self::TOGGLE_OFF;
        }
        $provider = $this->providerStatus();

        return $provider === AiStrategyCapability::AVAILABLE ? self::AVAILABLE : $provider;
    }

    public function aiAvailable(): bool
    {
        return $this->readiness() === self::AVAILABLE;
    }
}
