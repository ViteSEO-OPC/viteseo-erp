<?php

namespace Tests\Feature\Hr;

use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Services\AttendancePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** (2026-07-28 audit) — the API must not accept settings the engine ignores. */
class InertPolicyModesTest extends HrTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(AttendancePolicyService::class)->get();
    }

    /** @param array<string, mixed> $payload */
    private function savePolicy(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAsHr()->putJson('/api/v1/hr/attendance-policy', $payload);
    }

    public function test_the_unbuilt_missing_clockout_modes_are_rejected(): void
    {
        foreach (['treat_as_undertime_to_now', 'treat_as_absent'] as $mode) {
            $this->savePolicy(['missing_clockout_mode' => $mode])
                ->assertStatus(422)
                ->assertJsonValidationErrors('missing_clockout_mode');
        }
    }

    public function test_the_implemented_missing_clockout_mode_is_still_accepted(): void
    {
        $this->savePolicy(['missing_clockout_mode' => 'no_deduction_flag_for_review'])->assertOk();
    }

    public function test_the_unbuilt_early_arrival_modes_are_rejected(): void
    {
        foreach (['offset_undertime', 'overtime_eligible'] as $mode) {
            $this->savePolicy(['early_arrival_mode' => $mode])
                ->assertStatus(422)
                ->assertJsonValidationErrors('early_arrival_mode');
        }
    }

    public function test_the_implemented_early_arrival_mode_is_still_accepted(): void
    {
        $this->savePolicy(['early_arrival_mode' => 'none'])->assertOk();
    }

    /** A rejected field must not block the rest of the card from saving. */
    public function test_a_rejected_mode_does_not_change_anything_else(): void
    {
        $this->savePolicy(['early_arrival_mode' => 'overtime_eligible', 'overtime_mode' => 'none'])
            ->assertStatus(422);

        $this->assertSame('approved_only', AttendancePolicy::first()->overtime_mode);
    }

    /** Editing the settings that DO work is unaffected. */
    public function test_working_settings_still_save(): void
    {
        $this->savePolicy(['overtime_mode' => 'auto_after_threshold', 'overtime_threshold_minutes' => 30])
            ->assertOk();

        $policy = AttendancePolicy::first();
        $this->assertSame('auto_after_threshold', $policy->overtime_mode);
        $this->assertSame(30, $policy->overtime_threshold_minutes);
    }
}
