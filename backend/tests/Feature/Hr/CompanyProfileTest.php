<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\CompanyProfile;
use App\Modules\Hr\Services\CompanyProfileService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

/**
 * WHO THE EMPLOYER IS — the one row every letterhead reads (Company → Settings).
 *
 * The point of this table is that the company's name lives in ONE place. These tests hold
 * that line: who may change it, what a blank name does, and that a rename actually reaches
 * the payslip rather than only the settings form.
 */
class CompanyProfileTest extends HrTestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/hr/company-profile';

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'legal_name' => 'ACME MANPOWER SERVICES INC.',
            'trade_name' => 'Acme',
            'address' => '1 Ayala Avenue, Makati City',
            'tin' => '009-123-456-000',
            'email' => 'hr@acme.test',
        ], $overrides);
    }

    /** An employee — no HR role. Roles are seeded on first use, as in HrTestCase. */
    private function employeeUser(): User
    {
        if (Role::query()->where('name', 'hr_employee')->doesntExist()) {
            $this->seed(RolesAndPermissionsSeeder::class);
        }

        $user = User::factory()->create();
        $user->assignRole('hr_employee');

        return $user;
    }

    public function test_the_opening_row_carries_the_letterhead_the_documents_used_to_hardcode(): void
    {
        $profile = app(CompanyProfileService::class)->get();

        $this->assertSame('VITE SEO DIGITAL ADVERTISING OPC', $profile->legal_name);
        $this->assertStringContainsString('Burgundy Corporate Tower', (string) $profile->address);
    }

    public function test_hr_reads_and_updates_the_profile(): void
    {
        $this->actingAsHr()->getJson(self::URI)
            ->assertOk()
            ->assertJsonPath('data.legal_name', 'VITE SEO DIGITAL ADVERTISING OPC');

        $this->actingAsHr()->patchJson(self::URI, $this->payload())
            ->assertOk()
            ->assertJsonPath('data.legal_name', 'ACME MANPOWER SERVICES INC.')
            ->assertJsonPath('data.tin', '009-123-456-000');

        // One row, updated — never a second one, which would leave the documents guessing.
        $this->assertSame(1, CompanyProfile::query()->count());
    }

    public function test_the_company_name_cannot_be_blanked(): void
    {
        $this->actingAsHr()->patchJson(self::URI, $this->payload(['legal_name' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('legal_name');

        $this->assertSame(
            'VITE SEO DIGITAL ADVERTISING OPC',
            CompanyProfile::query()->value('legal_name'),
        );
    }

    public function test_an_employee_may_read_the_letterhead_but_never_change_it(): void
    {
        // Reading is open: the payslip on screen prints this for employees too.
        Sanctum::actingAs($this->employeeUser());
        $this->getJson(self::URI)->assertOk();

        Sanctum::actingAs($this->employeeUser());
        $this->patchJson(self::URI, $this->payload())->assertForbidden();

        Sanctum::actingAs($this->employeeUser());
        $this->postJson(self::URI.'/logo', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertForbidden();
    }

    public function test_the_logo_is_stored_privately_and_can_be_removed(): void
    {
        Storage::fake('local');

        $this->actingAsHr()->postJson(self::URI.'/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 240, 80),
        ])->assertOk()->assertJsonPath('data.logo_url', fn ($url) => is_string($url));

        $path = CompanyProfile::query()->value('logo_path');
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);

        $this->actingAsHr()->deleteJson(self::URI.'/logo')
            ->assertOk()
            ->assertJsonPath('data.logo_url', null);

        Storage::disk('local')->assertMissing($path);
        $this->assertNull(CompanyProfile::query()->value('logo_path'));
    }

    public function test_a_replaced_logo_discards_the_previous_file(): void
    {
        Storage::fake('local');

        $this->actingAsHr()->postJson(self::URI.'/logo', [
            'logo' => UploadedFile::fake()->image('first.png'),
        ])->assertOk();
        $first = CompanyProfile::query()->value('logo_path');

        $this->actingAsHr()->postJson(self::URI.'/logo', [
            'logo' => UploadedFile::fake()->image('second.png'),
        ])->assertOk();
        $second = CompanyProfile::query()->value('logo_path');

        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
    }

    public function test_a_non_image_is_not_accepted_as_a_logo(): void
    {
        Storage::fake('local');

        // The logo is inlined into the payslip PDF, so anything the server can't decode as a
        // picture has no business being stored — a document, least of all.
        $this->actingAsHr()->postJson(self::URI.'/logo', [
            'logo' => UploadedFile::fake()->create('handbook.pdf', 12, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('logo');

        $this->assertNull(CompanyProfile::query()->value('logo_path'));
    }

    public function test_an_oversized_logo_is_refused(): void
    {
        Storage::fake('local');

        // 3 MB, against the 2 MB cap. Every payslip PDF carries this image inline.
        $logo = UploadedFile::fake()->image('huge.png');
        $logo->sizeToReport = 3 * 1024 * 1024;

        $this->actingAsHr()->postJson(self::URI.'/logo', ['logo' => $logo])
            ->assertStatus(422)
            ->assertJsonValidationErrors('logo');

        $this->assertNull(CompanyProfile::query()->value('logo_path'));
    }

    public function test_the_letterhead_a_document_prints_follows_the_saved_profile(): void
    {
        Storage::fake('local');

        $this->actingAsHr()->patchJson(self::URI, $this->payload())->assertOk();
        $this->actingAsHr()->postJson(self::URI.'/logo', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertOk();

        // A FRESH service instance: the memo must not be what makes this pass.
        $letterhead = app()->make(CompanyProfileService::class)->letterhead();

        $this->assertSame('ACME MANPOWER SERVICES INC.', $letterhead['name']);
        $this->assertSame('1 Ayala Avenue, Makati City', $letterhead['address']);
        // Inlined, not linked — dompdf cannot fetch a signed URL off a private disk.
        $this->assertStringStartsWith('data:image/', (string) $letterhead['logo']);
    }

    public function test_a_missing_logo_file_degrades_to_a_letterhead_without_one(): void
    {
        Storage::fake('local');

        $this->actingAsHr()->postJson(self::URI.'/logo', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertOk();

        // The row still points at a file somebody deleted underneath us. A payslip must
        // still render — a letterhead with no mark, never a failed document.
        Storage::disk('local')->delete((string) CompanyProfile::query()->value('logo_path'));

        $this->assertNull(app()->make(CompanyProfileService::class)->letterhead()['logo']);
    }
}
