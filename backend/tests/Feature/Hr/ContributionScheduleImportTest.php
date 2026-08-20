<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\ContributionVersion;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\ContributionCalculator;
use App\Modules\Hr\Services\ContributionScheduleImporter;
use App\Modules\Hr\Services\ContributionService;
use App\Modules\Hr\Services\SpreadsheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

/** The published schedule is DATA the system carries, not something someone retypes. */
class ContributionScheduleImportTest extends HrTestCase
{
    use RefreshDatabase;

    /**
     * A miniature of the published layout: title, circular line, blank, header, then bands.
     * Column D is the MSC TOTAL and G the EC — deliberately not the first numeric columns, so a
     * positional reader would pick the wrong ones and fail this test.
     *
     * @return array<int, array<string, string>>
     */
    private function sheet(): array
    {
        return [
            ['A' => 'SCHEDULE OF SSS CONTRIBUTIONS — BUSINESS EMPLOYERS AND EMPLOYEES'],
            ['A' => 'Circular No. 2024-006 | Effective January 2025'],
            [],
            [
                'A' => 'Range of Compensation', 'B' => 'MSC Regular SS', 'C' => 'MSC MPF',
                'D' => 'MSC Total', 'E' => 'ER Regular SS', 'F' => 'ER MPF', 'G' => 'ER EC',
                'H' => 'ER Total', 'I' => 'EE Regular SS', 'J' => 'EE MPF', 'K' => 'EE Total',
            ],
            ['A' => 'BELOW 5,250', 'B' => '5000', 'C' => '-', 'D' => '5000', 'E' => '500', 'F' => '-', 'G' => '10', 'H' => '510', 'I' => '250', 'J' => '-', 'K' => '250'],
            ['A' => '14,750 - 15,249.99', 'B' => '15000', 'C' => '-', 'D' => '15000', 'E' => '1500', 'F' => '-', 'G' => '30', 'H' => '1530', 'I' => '750', 'J' => '-', 'K' => '750'],
            ['A' => '19,750 - 20,249.99', 'B' => '20000', 'C' => '-', 'D' => '20000', 'E' => '2000', 'F' => '-', 'G' => '30', 'H' => '2030', 'I' => '1000', 'J' => '-', 'K' => '1000'],
            ['A' => '34,750 - Over', 'B' => '20000', 'C' => '15000', 'D' => '35000', 'E' => '2000', 'F' => '1500', 'G' => '30', 'H' => '3530', 'I' => '1000', 'J' => '750', 'K' => '1750'],
        ];
    }

    // --- reading the published table --------------------------------------------------

    public function test_it_finds_the_columns_by_their_headings(): void
    {
        $out = app(ContributionScheduleImporter::class)->parse($this->sheet());

        $this->assertSame('A', $out['columns']['range']);
        $this->assertSame('D', $out['columns']['msc'], 'MSC Total, not MSC Regular SS');
        $this->assertSame('G', $out['columns']['employer_add_on']);
        $this->assertSame(4, $out['header_row'], 'the table starts below the title lines');
    }

    /**
     * "BELOW 5,250" is the floor of the schedule, not a band starting at 5,250 — read it the
     * literal way and everyone under ₱5,250 falls into no band and contributes nothing.
     */
    public function test_the_lowest_band_starts_at_zero(): void
    {
        $bands = app(ContributionScheduleImporter::class)->parse($this->sheet())['bands'];

        $this->assertSame(0.0, $bands[0]['min_salary']);
        $this->assertSame(5000.0, $bands[0]['msc']);
        $this->assertSame(34750.0, end($bands)['min_salary'], '"34,750 - Over" is a lower bound');
    }

    public function test_it_infers_the_rates_and_the_ceiling_from_the_table(): void
    {
        $out = app(ContributionScheduleImporter::class)->parse($this->sheet());

        $this->assertSame(0.05, $out['suggested']['employee_rate']);
        // The employer total includes the EC, which is not a percentage — counted in, the rate
        // reads high and every employer figure would then be over-charged.
        $this->assertSame(0.1, $out['suggested']['employer_rate']);
        $this->assertSame(35000.0, $out['suggested']['salary_cap']);
        $this->assertTrue($out['suggested']['amounts_match']);
    }

    /** The whole point of reading the printed amounts: to catch a rate that does not reproduce them. */
    public function test_it_warns_when_our_arithmetic_disagrees_with_the_printed_amount(): void
    {
        $sheet = $this->sheet();
        $sheet[6]['K'] = '1234'; // ₱20,000 band, printed employee amount tampered with

        $out = app(ContributionScheduleImporter::class)->parse($sheet);

        $this->assertFalse($out['suggested']['amounts_match']);
        $this->assertNotEmpty($out['warnings']);
    }

    public function test_hr_can_re_point_a_column_the_detection_got_wrong(): void
    {
        // Force the MSC onto the "Regular SS" column: at the top band that is ₱20,000, not ₱35,000.
        $out = app(ContributionScheduleImporter::class)->parse($this->sheet(), ['msc' => 'B']);

        $this->assertSame(20000.0, end($out['bands'])['msc']);
    }

    /**
     * A schedule published without an EC column must import without one being invented. An empty
     * override is HR saying "not in this file" and has to beat the detection, not fall back to it.
     */
    public function test_a_column_can_be_declared_absent(): void
    {
        $out = app(ContributionScheduleImporter::class)->parse($this->sheet(), ['employer_add_on' => '']);

        $this->assertArrayNotHasKey('employer_add_on', $out['columns']);
        $this->assertNull($out['bands'][0]['employer_add_on']);
        $this->assertNotEmpty($out['bands'], 'the rest of the table still imports');
    }

    public function test_a_sheet_it_cannot_read_says_so_instead_of_importing_nothing(): void
    {
        $out = app(ContributionScheduleImporter::class)->parse([
            ['A' => 'Some other spreadsheet'],
            ['A' => 'nothing', 'B' => 'useful'],
        ]);

        $this->assertSame([], $out['bands']);
        $this->assertNotEmpty($out['warnings']);
    }

    // --- the file itself --------------------------------------------------------------

    public function test_it_reads_a_real_xlsx(): void
    {
        $path = $this->xlsxFixture();

        $rows = app(SpreadsheetReader::class)->read($path, 'schedule.xlsx');
        $out = app(ContributionScheduleImporter::class)->parse($rows);

        $this->assertSame(0.0, $out['bands'][0]['min_salary']);
        $this->assertSame(35000.0, end($out['bands'])['msc']);
        @unlink($path);
    }

    public function test_the_import_endpoint_saves_nothing(): void
    {
        $path = $this->xlsxFixture();

        // A DELTA, not a count of zero. The published SSS schedule is now installed by migration
        // rather than seeded, so a bracket version exists in every database before this test
        // uploads anything — and "nothing was written" was only ever about what the upload adds.
        $before = ContributionVersion::where('basis', 'bracket')->count();

        $this->actingAsHr()
            ->postJson('/api/v1/hr/contribution-versions/import-schedule', [
                'file' => new UploadedFile($path, 'schedule.xlsx', null, null, true),
            ])
            ->assertOk()
            ->assertJsonPath('data.suggested.employee_rate', 0.05)
            ->assertJsonPath('data.columns.msc', 'D');

        $this->assertSame($before, ContributionVersion::where('basis', 'bracket')->count(),
            'a dry run — HR confirms the reading before anything is written');
    }

    public function test_a_file_that_is_not_a_spreadsheet_is_rejected(): void
    {
        $this->actingAsHr()
            ->postJson('/api/v1/hr/contribution-versions/import-schedule', [
                'file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    // --- what the seeder leaves behind ------------------------------------------------

    /** The B8 promise: rebuild from scratch and the rates are right, with nothing typed in. */
    public function test_a_fresh_install_carries_the_published_schedule(): void
    {
        $this->seed(ContributionSettingSeeder::class);

        $sss = ContributionVersion::where('type', 'sss')->orderByDesc('effective_from')->first();

        $this->assertSame('bracket', $sss->basis);
        $this->assertSame(61, $sss->brackets()->count());
        $this->assertSame('0.05000', $sss->employee_rate);
        // The pre-2025 version must survive, or an old cycle would reprice at today's rates.
        $this->assertSame(2, ContributionVersion::where('type', 'sss')->count());
    }

    public function test_the_seeder_can_be_run_twice(): void
    {
        $this->seed(ContributionSettingSeeder::class);
        $this->seed(ContributionSettingSeeder::class);

        $sss = ContributionVersion::where('type', 'sss')->orderByDesc('effective_from')->first();
        $this->assertSame(61, $sss->brackets()->count(), 'bands are not duplicated');
    }

    // --- what payroll then charges ----------------------------------------------------

    /** ₱20,100 is charged on the ₱20,000 band, not on ₱20,100. */
    public function test_a_salary_is_charged_on_its_band(): void
    {
        $this->seed(ContributionSettingSeeder::class);
        $config = $this->sssConfig();

        $this->assertSame(1000.0, ContributionCalculator::employee($config, 20100));
        $this->assertSame(2030.0, ContributionCalculator::employer($config, 20100));
    }

    /**
     * The EC is ₱10 below and ₱30 above, and it follows the BAND. Someone on ₱14,900 sits in the
     * ₱15,000 band and owes ₱30 — the old "₱30 above a ₱15,000 salary" rule gave them ₱0.
     */
    public function test_the_employer_ec_follows_the_band_not_the_salary(): void
    {
        $this->seed(ContributionSettingSeeder::class);
        $config = $this->sssConfig();

        // ₱5,000 band → ₱10 EC: 5% of 5,000 = 250 employee, 10% + 10 = 510 employer.
        $this->assertSame(510.0, ContributionCalculator::employer($config, 4000));
        // ₱14,900 falls in the ₱14,750 band, whose MSC is ₱15,000 and whose EC is ₱30.
        $this->assertSame(1530.0, ContributionCalculator::employer($config, 14900));
    }

    /** Above the top band the MSC is the ceiling — ₱1,000 / ₱3,530 however much they earn. */
    public function test_a_high_earner_is_capped_at_the_top_band(): void
    {
        $this->seed(ContributionSettingSeeder::class);
        $config = $this->sssConfig();

        $this->assertSame(1750.0, ContributionCalculator::employee($config, 95000));
        $this->assertSame(3530.0, ContributionCalculator::employer($config, 95000));
    }

    /** An old cycle still prices on the version that applied then. */
    public function test_a_2024_cycle_still_uses_the_opening_rates(): void
    {
        $this->seed(ContributionSettingSeeder::class);
        $employee = $this->employee();

        $config = app(ContributionService::class)->effectiveFor($employee, '2024-06-30');

        $this->assertSame('percentage', $config['sss']['basis']);
        $this->assertSame(0.045, $config['sss']['rate']);
    }

    // --- removing a version -----------------------------------------------------------

    public function test_a_version_can_be_removed(): void
    {
        $this->seed(ContributionSettingSeeder::class);
        $version = ContributionVersion::where('type', 'sss')->orderByDesc('effective_from')->first();

        $this->actingAsHr()
            ->deleteJson("/api/v1/hr/contribution-versions/{$version->id}")
            ->assertOk();

        $this->assertSame(1, ContributionVersion::where('type', 'sss')->count());
        $this->assertSame(0, $version->brackets()->count(), 'its bands go with it');
    }

    /** Deleting the last one would deduct ₱0 on every payslip while looking like a working run. */
    public function test_the_last_version_of_a_type_cannot_be_removed(): void
    {
        $this->seed(ContributionSettingSeeder::class);
        ContributionVersion::where('type', 'sss')->orderByDesc('effective_from')->first()->delete();
        $last = ContributionVersion::where('type', 'sss')->firstOrFail();

        $this->actingAsHr()
            ->deleteJson("/api/v1/hr/contribution-versions/{$last->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('version');
    }

    // --- helpers ----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function sssConfig(): array
    {
        return ContributionVersion::with('brackets')
            ->where('type', 'sss')->orderByDesc('effective_from')->firstOrFail()
            ->toConfig();
    }

    private function employee(): Employee
    {
        WorkSchedule::firstOrCreate(['name' => 'Office'], [
            'workdays' => [1, 2, 3, 4, 5], 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_minutes' => 15, 'unpaid_break_minutes' => 60, 'paid_break_minutes' => 20,
            'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        return Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => Department::create(['name' => 'Ops', 'code' => 'OPS'])->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 20100, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    /**
     * A real .xlsx, built here rather than committed as a binary fixture: the reader's whole job is
     * unzipping one, so the test has to exercise the zip + shared-strings path, and a checked-in
     * binary is something nobody can review in a diff.
     */
    private function xlsxFixture(): string
    {
        $rows = $this->sheet();
        $shared = [];
        $sheetXml = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($rows as $i => $cells) {
            $sheetXml .= '<row r="' . ($i + 1) . '">';
            foreach ($cells as $column => $value) {
                if (is_numeric($value)) {
                    $sheetXml .= '<c r="' . $column . ($i + 1) . '"><v>' . $value . '</v></c>';
                } else {
                    $index = array_search($value, $shared, true);
                    if ($index === false) {
                        $shared[] = $value;
                        $index = count($shared) - 1;
                    }
                    $sheetXml .= '<c r="' . $column . ($i + 1) . '" t="s"><v>' . $index . '</v></c>';
                }
            }
            $sheetXml .= '</row>';
        }
        $sheetXml .= '</sheetData></worksheet>';

        $sharedXml = '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        foreach ($shared as $text) {
            $sharedXml .= '<si><t>' . htmlspecialchars($text, ENT_XML1) . '</t></si>';
        }
        $sharedXml .= '</sst>';

        $path = tempnam(sys_get_temp_dir(), 'sss') . '.xlsx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Business ER-EE" sheetId="1" r:id="rId3"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            // Deliberately NOT sheet1.xml: Excel keeps the original file name when sheets are
            // reordered, and a reader that assumes sheet1 imports the wrong table.
            . '<Relationship Id="rId3" Target="worksheets/sheet7.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet7.xml', $sheetXml);
        $zip->addFromString('xl/sharedStrings.xml', $sharedXml);
        $zip->close();

        return $path;
    }
}
