<?php

declare(strict_types=1);

namespace Tests\Feature\Crm\Concerns;

use App\Modules\Crm\Models\CallProviderSetting;
use App\Modules\Crm\Services\Calling\TwilioCallProvider;
use App\Modules\Crm\Services\Calling\TwilioConfig;
use App\Support\CurrentOrg;
use Illuminate\Support\Facades\Config;

/**
 * Zerem places browser calls through ONE centralized ViteSEO Twilio account — the server-side TWILIO_*
 * configuration — shared by every authorized CRM user. That is intentional (see TwilioConfig).
 */
trait ConfiguresCentralTwilio
{
    /**
     * Switch browser calling on with the centralized account, exactly as the server's TWILIO_*
     * environment does in production. Obvious fakes; nothing leaves the process.
     *
     * @param  array<string, mixed>  $overrides  keyed like config/twilio.php
     */
    protected function configureCentralTwilio(array $overrides = []): void
    {
        Config::set('calling.provider', TwilioCallProvider::NAME);

        foreach (array_merge([
            'account_sid' => 'ACtest00000000000000000000000000',
            'auth_token' => 'test-auth-token',
            'api_key' => 'SKtest00000000000000000000000000',
            'api_secret' => 'test-api-secret',
            'twiml_app_sid' => 'APtest00000000000000000000000000',
            'caller_id' => '+639170000000',
            'webhook_base_url' => 'https://example.test',
        ], $overrides) as $key => $value) {
            Config::set("twilio.{$key}", $value);
        }
    }

    /**
     * A complete, enabled and "verified" per-organization row for a DIFFERENT Twilio account — what a
     * settings screen could store. Used to prove it never replaces the centralized account while
     * per-organization accounts are off (Zerem's configuration).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function storeOrganizationTwilioSettings(array $overrides = []): CallProviderSetting
    {
        $row = new CallProviderSetting;
        $row->forceFill(array_merge([
            'org_id' => CurrentOrg::id(),
            'provider' => TwilioCallProvider::NAME,
            'enabled' => true,
            'account_sid' => 'ACother00000000000000000000000000',
            'auth_token' => 'other-auth-token',
            'api_key' => 'SKother00000000000000000000000000',
            'api_secret' => 'other-api-secret',
            'twiml_app_sid' => 'APother00000000000000000000000000',
            'caller_id' => '+639175555555',
            'webhook_base_url' => 'https://other.example',
            'last_test_status' => TwilioConfig::VERIFIED_STATUS,
            'last_tested_at' => now(),
        ], $overrides))->save();

        return $row;
    }
}
