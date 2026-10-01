<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Models\CopilotSetting;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\NerbyProviderSettingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Settings → Integrations → Copilot AI, and the one boolean every CRM user reads.
 *
 * The product rules these pin: the toggle is Super Admin only and OFF by default; AI is available only
 * when the ops switch AND the toggle are on AND the existing NERBY credential is available; an
 * available NERBY credential never switches the Copilot on by itself; and nothing here — viewing,
 * toggling, or reading availability — writes the NERBY provider row or contacts any provider.
 */
class CopilotSettingTest extends TestCase
{
    use RefreshDatabase;

    private const SETTINGS = '/api/v1/crm/settings/integrations/copilot';

    private const CONFIG = '/api/v1/crm/copilot/config';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['copilot.ai.enabled' => true]);
        Http::fake();
    }

    protected function tearDown(): void
    {
        // Nothing on this surface may ever reach a provider.
        Http::assertNothingSent();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    /** The usable NERBY OpenAI row: key present, enabled, connection-tested. */
    private function credential(array $overrides = []): NerbyProviderSetting
    {
        return NerbyProviderSetting::query()->updateOrCreate(
            ['provider' => NerbyProvider::OPENAI->value],
            [
                'enabled' => true,
                'api_key' => 'sk-or-v1-'.str_repeat('b', 24),
                'key_last4' => 'bbbb',
                'options' => ['model' => 'gpt-4o-mini'],
                'last_test_status' => NerbyProviderSettingService::TEST_OK,
                'last_tested_at' => now(),
                ...$overrides,
            ],
        );
    }

    private function nerbyRow(): array
    {
        return NerbyProviderSetting::query()->where('provider', NerbyProvider::OPENAI->value)->first()->getAttributes();
    }

    // ── the Super Admin screen ──────────────────────────────────────────────────────────────

    public function test_it_is_off_by_default_and_viewing_creates_no_row(): void
    {
        $this->credential();
        Sanctum::actingAs($this->user('super_admin'));

        $this->getJson(self::SETTINGS)
            ->assertOk()
            ->assertExactJson(['data' => [
                'aiEnabled' => false,
                'opsEnabled' => true,
                'providerStatus' => 'available',
                'readiness' => 'toggle_off',
                'aiAvailable' => false,
                'updatedAt' => null,
            ]]);

        $this->assertSame(0, CopilotSetting::query()->count());
    }

    public function test_a_super_admin_switches_it_on_and_off_without_touching_the_nerby_row(): void
    {
        $this->credential();
        $before = $this->nerbyRow();
        $admin = $this->user('super_admin');
        Sanctum::actingAs($admin);

        $this->putJson(self::SETTINGS, ['ai_enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.aiEnabled', true)
            ->assertJsonPath('data.readiness', 'available')
            ->assertJsonPath('data.aiAvailable', true);
        $this->assertSame($admin->id, CopilotSetting::query()->sole()->updated_by);

        $this->putJson(self::SETTINGS, ['ai_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.aiEnabled', false)
            ->assertJsonPath('data.aiAvailable', false);

        // Still one row, and the credential it borrows is untouched.
        $this->assertSame(1, CopilotSetting::query()->count());
        $this->assertSame($before, $this->nerbyRow());
    }

    public function test_the_response_never_carries_any_part_of_the_credential(): void
    {
        $this->credential();
        Sanctum::actingAs($this->user('super_admin'));

        $raw = $this->putJson(self::SETTINGS, ['ai_enabled' => true])->assertOk()->getContent();

        foreach (['sk-or-v1', 'bbbb', 'api_key', 'apiKey', 'key_last4', 'keyLast4'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
    }

    public function test_the_toggle_must_be_a_boolean(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        $this->putJson(self::SETTINGS, [])->assertUnprocessable();
        $this->putJson(self::SETTINGS, ['ai_enabled' => 'maybe'])->assertUnprocessable();
        $this->assertSame(0, CopilotSetting::query()->count());
    }

    /** @return array<string, array{0: string}> */
    public static function nonSuperAdminRoles(): array
    {
        return ['crm_admin' => ['crm_admin'], 'crm_bd' => ['crm_bd']];
    }

    #[DataProvider('nonSuperAdminRoles')]
    public function test_only_a_super_admin_may_view_or_change_it(string $role): void
    {
        $this->credential();
        Sanctum::actingAs($this->user($role));

        $this->getJson(self::SETTINGS)->assertForbidden();
        $this->putJson(self::SETTINGS, ['ai_enabled' => true])->assertForbidden();

        $this->assertSame(0, CopilotSetting::query()->count());
    }

    public function test_an_unauthenticated_caller_is_refused(): void
    {
        $this->getJson(self::SETTINGS)->assertUnauthorized();
        $this->putJson(self::SETTINGS, ['ai_enabled' => true])->assertUnauthorized();
        $this->getJson(self::CONFIG)->assertUnauthorized();
    }

    // ── readiness: every gate is reported by name ───────────────────────────────────────────

    public function test_readiness_names_the_gate_that_failed(): void
    {
        CopilotSetting::query()->create(['ai_enabled' => true]);
        Sanctum::actingAs($this->user('super_admin'));

        // No credential stored at all.
        $this->getJson(self::SETTINGS)->assertJsonPath('data.readiness', 'not_configured')->assertJsonPath('data.aiAvailable', false);

        foreach ([
            'disabled' => ['enabled' => false],
            'insufficient_credit' => ['last_test_status' => NerbyProviderSettingService::TEST_INSUFFICIENT_CREDIT],
            'unverified' => ['last_test_status' => null],
            'available' => [],
        ] as $expected => $state) {
            $this->credential($state);
            $this->getJson(self::SETTINGS)
                ->assertJsonPath('data.readiness', $expected)
                ->assertJsonPath('data.aiAvailable', $expected === 'available');
        }

        // The ops kill switch outranks everything, including a fully working setup.
        config(['copilot.ai.enabled' => false]);
        $this->getJson(self::SETTINGS)
            ->assertJsonPath('data.opsEnabled', false)
            ->assertJsonPath('data.readiness', 'ops_disabled')
            ->assertJsonPath('data.aiAvailable', false);
    }

    // ── the one boolean every CRM user reads ────────────────────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function crmRoles(): array
    {
        return ['super_admin' => ['super_admin'], 'crm_admin' => ['crm_admin'], 'crm_bd' => ['crm_bd']];
    }

    #[DataProvider('crmRoles')]
    public function test_every_crm_role_reads_only_the_availability_boolean(string $role): void
    {
        $this->credential();
        CopilotSetting::query()->create(['ai_enabled' => true]);
        Sanctum::actingAs($this->user($role));

        $this->getJson(self::CONFIG)->assertOk()->assertExactJson(['data' => ['aiAvailable' => true]]);
    }

    public function test_a_user_without_a_crm_role_cannot_read_availability(): void
    {
        Sanctum::actingAs(User::factory()->create(['status' => 'active']));

        $this->getJson(self::CONFIG)->assertForbidden();
    }

    public function test_availability_needs_all_three_gates(): void
    {
        Sanctum::actingAs($this->user('crm_bd'));
        $available = fn (): bool => $this->getJson(self::CONFIG)->assertOk()->json('data.aiAvailable');

        // NERBY credential fully available: that alone never switches the Copilot on.
        $this->credential();
        $this->assertFalse($available());

        // Toggle on + credential available + ops on: available.
        CopilotSetting::query()->create(['ai_enabled' => true]);
        $this->assertTrue($available());

        // Ops kill switch off.
        config(['copilot.ai.enabled' => false]);
        $this->assertFalse($available());
        config(['copilot.ai.enabled' => true]);

        // Credential unusable.
        $this->credential(['last_test_status' => NerbyProviderSettingService::TEST_INSUFFICIENT_CREDIT]);
        $this->assertFalse($available());
    }

    public function test_reading_availability_never_writes(): void
    {
        $this->credential();
        $before = $this->nerbyRow();
        Sanctum::actingAs($this->user('crm_bd'));

        $this->getJson(self::CONFIG)->assertOk();

        $this->assertSame(0, CopilotSetting::query()->count());
        $this->assertSame($before, $this->nerbyRow());
    }
}
