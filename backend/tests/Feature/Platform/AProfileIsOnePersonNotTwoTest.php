<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\EmployeeService;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * One person, one profile — across four systems that each read a different table.
 *
 * A person's details live in two places. `hr_employees` holds the record HR
 * maintains: the name parts, the phone, the photo, the addresses. `users` holds
 * what CRM and Projects actually read, because those modules have no employee row
 * to read from — a client may not have availed HR at all.
 *
 * Name and email already cross that seam: `EmployeeService::updateIdentity()`
 * writes them through. Phone and photo do not, and on the live installation the
 * gap is total — every one of the 13 employees has a phone and a photo on their
 * HR record, `users.phone` is empty for all 17 accounts, and only 2 carry an
 * avatar, both uploaded separately inside CRM.
 *
 * So an employee sets their photo in HR and stays a blank circle everywhere else,
 * with nothing to suggest why. These tests close the seam for the other two
 * fields, and before the fix they fail.
 *
 * The photo is a path, not a file: HR and CRM write to the SAME private disk
 * (`local` → storage/app/private), and `ProfilePhotoService::owns()` only claims
 * `crm/profile-photos/`, so an HR path is served correctly by its url() and can
 * never be unlinked by the CRM's cleanup.
 */
class AProfileIsOnePersonNotTwoTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{org: \App\Models\Org, employee: Employee} */
    private function orgWithOneEmployee(string $phone = '0917 000 1111'): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $org = app(OrgOnboardingService::class)->createOrg(
            'Bituin Dental', 'Maria', 'maria@bituin.ph', 'password-123', ['hr' => 'full'],
        )['org'];

        $employee = CurrentOrg::runAs($org->id, function () use ($phone): Employee {
            $department = Department::create(['name' => 'Operations', 'code' => 'OPS']);

            return app(EmployeeService::class)->create([
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'email' => 'juan@bituin.ph',
                'phone' => $phone,
                'department_id' => $department->id,
                'job_title' => 'Staff',
                'employment_status' => 'regular',
                'hire_date' => '2026-01-05',
                'base_salary' => 25000,
            ]);
        });

        return ['org' => $org, 'employee' => $employee];
    }

    public function test_hiring_someone_with_a_phone_puts_it_on_the_account_too(): void
    {
        ['employee' => $employee] = $this->orgWithOneEmployee('0917 555 4444');

        $this->assertSame('0917 555 4444', $employee->fresh()->phone);
        $this->assertSame(
            '0917 555 4444',
            User::whereKey($employee->user_id)->value('phone'),
            'CRM and Projects read users.phone — they have no employee row to read.',
        );
    }

    public function test_changing_the_phone_in_hr_changes_it_everywhere(): void
    {
        ['org' => $org, 'employee' => $employee] = $this->orgWithOneEmployee('0917 000 1111');

        CurrentOrg::runAs($org->id, fn () => app(EmployeeService::class)
            ->update($employee, ['phone' => '0918 222 3333']));

        $this->assertSame('0918 222 3333', User::whereKey($employee->user_id)->value('phone'));
    }

    public function test_a_photo_uploaded_in_hr_is_the_avatar_everywhere(): void
    {
        Storage::fake('local');

        ['org' => $org, 'employee' => $employee] = $this->orgWithOneEmployee();

        CurrentOrg::runAs($org->id, fn () => app(EmployeeService::class)
            ->setPhoto($employee, UploadedFile::fake()->image('juan.jpg')));

        $path = $employee->fresh()->photo_path;

        $this->assertNotNull($path);
        $this->assertSame(
            $path,
            User::whereKey($employee->user_id)->value('avatar'),
            'Same private disk, and ProfilePhotoService::owns() claims only crm/profile-photos/, '
                . 'so an HR path is safe to share and safe from the CRM cleanup.',
        );
    }

    public function test_removing_the_photo_clears_the_avatar_too(): void
    {
        Storage::fake('local');

        ['org' => $org, 'employee' => $employee] = $this->orgWithOneEmployee();

        CurrentOrg::runAs($org->id, function () use ($employee) {
            $service = app(EmployeeService::class);
            $service->setPhoto($employee, UploadedFile::fake()->image('juan.jpg'));
            $service->removePhoto($employee->fresh());
        });

        $this->assertNull($employee->fresh()->photo_path);
        $this->assertNull(
            User::whereKey($employee->user_id)->value('avatar'),
            'A cleared photo must not leave a dangling avatar pointing at a deleted file.',
        );
    }
}
