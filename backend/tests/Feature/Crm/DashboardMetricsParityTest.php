<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Services\Dashboard\DashboardMetricsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BYTE-IDENTICAL dashboard responses across the DashboardMetricsService extraction.
 *
 * The snapshots under __snapshots__/dashboard-metrics-parity were RECORDED from the controller
 * BEFORE the KPI and follow-up logic moved into {@see DashboardMetricsService},
 * so this proves the move changed nothing a client can observe — every key, value, order and byte.
 *
 * The dataset is fixed: faker is seeded, time is frozen at a Manila afternoon, and the follow-ups
 * straddle the Manila midnight boundary on both sides.
 *
 * Re-recording is deliberate and manual: DASHBOARD_PARITY_RECORD=1. Never re-record to make a
 * failing run pass — a diff here is a behaviour change.
 */
class DashboardMetricsParityTest extends TestCase
{
    use RefreshDatabase;

    /** 07:30 UTC = 15:30 Manila. Manila's day began at 2026-09-28 16:00 UTC and ends 2026-09-29 16:00 UTC. */
    private const NOW_UTC = '2026-09-29 07:30:00';

    private const SNAPSHOTS = __DIR__.'/__snapshots__/dashboard-metrics-parity';

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(Carbon::parse(self::NOW_UTC));
        fake()->seed(20260929);
        $this->dataset();
    }

    private function user(string $key, string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $this->users[$key] = $user;
    }

    private function dataset(): void
    {
        $bd1 = $this->user('bd1', 'crm_bd');
        $bd2 = $this->user('bd2', 'crm_bd');
        $this->user('admin', 'crm_admin');
        $this->user('super', 'super_admin');

        $matrix = [
            [$bd1, 'new', 'high', 'no-website', 'none', 'not-called'],
            [$bd1, 'new', 'medium', 'has-website', 'sent', 'called'],
            [$bd1, 'interested', 'high', 'needs-redesign', 'replied', 'scheduled'],
            [$bd1, 'awaiting-reply', 'low', 'website-unknown', 'opened', 'called'],
            [$bd1, 'declined', 'medium', 'website-unreachable', 'replied', 'called'],
            [$bd2, 'new', 'high', 'no-website', 'none', 'not-called'],
            [$bd2, 'call-back-req', 'medium', 'has-website', 'sent', 'scheduled'],
            [$bd2, 'interested', 'low', 'no-website', 'replied', 'called'],
            [null, 'new', 'low', 'website-unknown', 'none', 'not-called'],
        ];

        $leads = [];
        foreach ($matrix as $i => [$owner, $status, $priority, $website, $outreach, $calling]) {
            $leads[$i] = Lead::factory()->create([
                'assigned_to' => $owner?->id,
                'created_by' => $bd1->id,
                'status' => $status,
                'priority' => $priority,
                'website_status' => $website,
                'email_outreach' => $outreach,
                'cold_calling' => $calling,
            ]);
        }

        // Won business on one lead of each BD.
        Deal::factory()->create(['lead_id' => $leads[2]->id, 'amount' => 45000, 'converted_at' => now()->subDays(3)]);
        Deal::factory()->create(['lead_id' => $leads[7]->id, 'amount' => 90000, 'converted_at' => now()->subDays(10)]);

        // Outreach: sent (counted), plus draft/failed/inbound (never counted).
        foreach ([[1, 'outbound', 'sent'], [2, 'outbound', 'sent'], [6, 'outbound', 'sent'], [3, 'outbound', 'draft'], [4, 'outbound', 'failed'], [2, 'inbound', 'received']] as [$i, $direction, $status]) {
            Email::create([
                'lead_id' => $leads[$i]->id,
                'direction' => $direction,
                'status' => $status,
                'subject' => 'Outreach',
                'body' => 'Body',
                'from_email' => 'crm@viteseo.test',
                'to_email' => 'prospect@example.test',
            ]);
        }

        // Follow-ups straddling the Manila day boundary on both sides, plus excluded rows.
        $events = [
            [$bd1, 0, '2026-09-28 15:59:59', 'todo', 'follow-up'],   // overdue (1s before Manila midnight)
            [$bd1, 1, '2026-09-28 16:00:00', 'todo', 'follow-up'],   // today (exactly Manila midnight)
            [$bd1, 2, '2026-09-29 15:59:59', 'in-progress', 'call'], // today (last second)
            [$bd1, 3, '2026-09-29 16:00:00', 'todo', 'email'],       // upcoming (next Manila midnight)
            [$bd1, 4, '2026-09-20 09:00:00', 'completed', 'follow-up'], // completed: excluded
            [$bd2, 5, '2026-09-25 01:00:00', 'todo', 'follow-up'],   // overdue
            [$bd2, 6, '2026-10-02 03:00:00', 'todo', 'meeting'],     // upcoming
            [$bd2, 7, null, 'todo', 'follow-up'],                    // undated: excluded
        ];
        foreach ($events as [$owner, $leadIndex, $startsAt, $status, $type]) {
            PlannerEvent::create([
                'title' => "Follow-up {$leadIndex}",
                'event_type' => $type,
                'status' => $status,
                'starts_at' => $startsAt,
                'lead_id' => $leads[$leadIndex]->id,
                'assigned_to' => $owner->id,
                'created_by' => $owner->id,
            ]);
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function responses(): array
    {
        return [
            'kpis as bd1' => ['bd1', '/api/v1/crm/dashboard/kpis'],
            'kpis as bd2' => ['bd2', '/api/v1/crm/dashboard/kpis'],
            'kpis as admin' => ['admin', '/api/v1/crm/dashboard/kpis'],
            'kpis as super admin' => ['super', '/api/v1/crm/dashboard/kpis'],
            'reports as bd1' => ['bd1', '/api/v1/crm/dashboard/reports-analytics'],
            'reports as admin (all)' => ['admin', '/api/v1/crm/dashboard/reports-analytics'],
            'reports as super admin (all)' => ['super', '/api/v1/crm/dashboard/reports-analytics'],
            'reports as admin (drilled bd2)' => ['admin', '/api/v1/crm/dashboard/reports-analytics?user_id=__bd2__'],
        ];
    }

    #[DataProvider('responses')]
    public function test_the_response_is_byte_identical_to_the_recorded_snapshot(string $as, string $uri): void
    {
        Sanctum::actingAs($this->users[$as]);
        $response = $this->getJson(str_replace('__bd2__', (string) $this->users['bd2']->id, $uri))->assertOk();
        $body = $response->getContent();

        $file = self::SNAPSHOTS.'/'.md5($as.' '.$uri).'.json';
        if (getenv('DASHBOARD_PARITY_RECORD') === '1') {
            @mkdir(self::SNAPSHOTS, 0775, true);
            file_put_contents($file, $body);
        }

        $this->assertFileExists($file, 'No recorded snapshot. Record against the PRE-extraction controller only.');
        $this->assertSame(file_get_contents($file), $body);
    }
}
