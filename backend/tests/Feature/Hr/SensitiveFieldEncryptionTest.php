<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Http\Resources\EmployeeResource;
use App\Modules\Hr\Models\AuditLog;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Bank accounts and government ID numbers were plaintext, and returned in full. */
class SensitiveFieldEncryptionTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
            'bank_account' => '1234-5678-9012-34',
            'bank_account_name' => 'Juan Dela Cruz',
            'tin' => '123-456-789-000',
            'sss_number' => '34-1234567-8',
            'philhealth_number' => '12-345678901-2',
            'pagibig_number' => '1234-5678-9012',
        ]);
    }

    // --- at rest ------------------------------------------------------------------------------

    public function test_the_stored_columns_are_unreadable(): void
    {
        // Raw query on purpose — Eloquent would decrypt and prove nothing about what is on disk.
        $row = DB::table('hr_employees')->where('id', $this->employee->id)->first();

        foreach (Employee::SENSITIVE_FIELDS as $field) {
            $this->assertNotSame(
                $this->employee->{$field},
                $row->{$field},
                "{$field} is stored in plain text",
            );
            $this->assertSame(
                $this->employee->{$field},
                Crypt::decryptString($row->{$field}),
                "{$field} did not round-trip through encryption",
            );
        }
    }

    /**
     * The envelope is ~228 characters for a 17-character account number, so `varchar(255)` fits
     * today and truncates the first time someone enters a longer name — destroying the value,
     * because a truncated ciphertext cannot be decrypted.
     */
    public function test_a_long_value_survives_the_round_trip(): void
    {
        $long = str_repeat('A', 120);

        $this->employee->update(['bank_account_name' => $long]);

        $this->assertSame($long, $this->employee->fresh()->bank_account_name);
    }

    // --- what the ordinary payload carries ----------------------------------------------------

    public function test_the_employee_payload_is_masked(): void
    {
        $payload = (new EmployeeResource($this->employee))->toArray(Request::create('/'));

        // The last four CHARACTERS, not digits — '1234-5678-9012-34' ends in '2-34'. Character-wise
        // is predictable and cannot accidentally reveal more of a short value; digit-extraction
        // would have to decide what to do with a mostly-punctuation string.
        $this->assertSame('••••2-34', $payload['bank_account']);
        $this->assertSame('••••Cruz', $payload['bank_account_name']);
        $this->assertSame('••••9012', $payload['pagibig_number']);

        foreach (Employee::SENSITIVE_FIELDS as $field) {
            $this->assertStringNotContainsString(
                (string) $this->employee->{$field},
                (string) $payload[$field],
                "{$field} leaked its full value into the payload",
            );
        }
    }

    /** A short value is masked entirely — showing the last four of five discloses most of it. */
    public function test_a_short_value_is_masked_completely(): void
    {
        $this->assertSame('••••', Employee::maskSensitive('1234'));
        $this->assertSame('••••', Employee::maskSensitive('12'));
        $this->assertNull(Employee::maskSensitive(null));
    }

    /** Demo data stores placeholder values already in mask shape; re-masking must not alter them. */
    public function test_an_already_masked_value_is_left_alone(): void
    {
        $this->assertSame('••••4471', Employee::maskSensitive('••••4471'));
    }

    // --- the reveal -----------------------------------------------------------------------------

    public function test_hr_can_reveal_the_full_values(): void
    {
        $this->actingAsHr()
            ->getJson("/api/v1/hr/employees/{$this->employee->id}/sensitive")
            ->assertOk()
            ->assertJsonPath('data.bank_account', '1234-5678-9012-34')
            ->assertJsonPath('data.tin', '123-456-789-000');
    }

    /** THE ACTUAL CONTROL: looking leaves a trace. */
    public function test_every_reveal_is_audited(): void
    {
        $this->actingAsHr()->getJson("/api/v1/hr/employees/{$this->employee->id}/sensitive")->assertOk();

        $entry = AuditLog::latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertStringContainsString('bank and government ID', $entry->description);
        $this->assertStringContainsString(
            (string) $this->employee->user->name,
            $entry->description,
            'the entry must name whose details were viewed',
        );
    }

    public function test_two_reveals_write_two_entries(): void
    {
        $before = AuditLog::count();

        $this->actingAsHr()->getJson("/api/v1/hr/employees/{$this->employee->id}/sensitive");
        $this->actingAsHr()->getJson("/api/v1/hr/employees/{$this->employee->id}/sensitive");

        $this->assertSame($before + 2, AuditLog::count(), 'each view must be recorded, not just the first');
    }

    /** An ordinary employee has no business reading someone else's bank details. */
    public function test_a_non_hr_user_cannot_reveal_them(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/v1/hr/employees/{$this->employee->id}/sensitive")
            ->assertForbidden();
    }
}
