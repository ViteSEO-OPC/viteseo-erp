<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The BD Dashboard's "Completed Outreach" KPI (`GET /dashboard/kpis` → `completedOutreach`).
 *
 * PO decision: outbound emails the system actually SENT — `crm_emails.direction = 'outbound' AND
 * status = 'sent'`, scoped by the lead's visibility.
 *
 * Two boundaries carry the business meaning and are pinned hardest:
 *
 *  - It counts ACTIONS, not leads. Emailing one lead three times is three completed outreach
 *    actions; that is what makes this an effort metric rather than the coverage metric M-04
 *    already provides.
 *  - `crm_leads.email_outreach` is NOT the source. Nothing writes its `sent` value — only
 *    `replied`, from the inbound processor — so it records what happened TO us. Sourcing the KPI
 *    there would let an inbound reply credit outreach that was never performed, which the last
 *    test reproduces directly.
 */
class DashboardCompletedOutreachTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    private function leadFor(User $owner): Lead
    {
        return Lead::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);
    }

    private function email(Lead $lead, string $direction, string $status): Email
    {
        return Email::create([
            'lead_id' => $lead->id,
            'direction' => $direction,
            'status' => $status,
            'subject' => 'Outreach',
            'body' => 'Body',
            'from_email' => 'crm@viteseo.test',
            'to_email' => 'prospect@example.test',
        ]);
    }

    private function completedOutreach(): int
    {
        return (int) $this->getJson('/api/v1/crm/dashboard/kpis')->assertOk()->json('data.completedOutreach');
    }

    // ── Shape ───────────────────────────────────────────────────────────────────────────────────

    public function test_the_kpi_response_exposes_the_completed_outreach_field(): void
    {
        Sanctum::actingAs($this->user('crm_bd'));

        $this->getJson('/api/v1/crm/dashboard/kpis')
            ->assertOk()
            ->assertJsonStructure(['data' => ['totalLeads', 'highPriority', 'interested', 'followUpsDue', 'completedOutreach', 'statusCounts']]);
    }

    /** No hardcoded value survives: an empty dataset reports a real 0, not the old placeholder. */
    public function test_an_empty_dataset_returns_zero(): void
    {
        Sanctum::actingAs($this->user('crm_bd'));

        $this->assertSame(0, $this->completedOutreach());
    }

    // ── What counts ─────────────────────────────────────────────────────────────────────────────

    public function test_a_sent_outbound_email_counts(): void
    {
        $bd = $this->user('crm_bd');
        $this->email($this->leadFor($bd), 'outbound', 'sent');
        Sanctum::actingAs($bd);

        $this->assertSame(1, $this->completedOutreach());
    }

    /**
     * ACTIONS, not leads — the distinction that separates this from coverage. One lead emailed
     * three times contributes three.
     */
    public function test_multiple_sent_emails_to_one_lead_count_as_multiple_actions(): void
    {
        $bd = $this->user('crm_bd');
        $lead = $this->leadFor($bd);
        $this->email($lead, 'outbound', 'sent');
        $this->email($lead, 'outbound', 'sent');
        $this->email($lead, 'outbound', 'sent');
        Sanctum::actingAs($bd);

        $this->assertSame(3, $this->completedOutreach());
    }

    // ── What does not count ─────────────────────────────────────────────────────────────────────

    /** Every non-sent outbound state: the email was never actually delivered to a prospect. */
    public function test_draft_scheduled_queued_failed_and_cancelled_do_not_count(): void
    {
        $bd = $this->user('crm_bd');
        $lead = $this->leadFor($bd);
        foreach (['draft', 'scheduled', 'queued', 'failed', 'cancelled'] as $status) {
            $this->email($lead, 'outbound', $status);
        }
        Sanctum::actingAs($bd);

        $this->assertSame(0, $this->completedOutreach());
    }

    /** Inbound mail is not outreach — it is what the prospect did. */
    public function test_inbound_email_does_not_count(): void
    {
        $bd = $this->user('crm_bd');
        $this->email($this->leadFor($bd), 'inbound', 'received');
        Sanctum::actingAs($bd);

        $this->assertSame(0, $this->completedOutreach());
    }

    /** A realistic mailbox: only the sent outbound rows are outreach. */
    public function test_only_sent_outbound_rows_are_counted_in_a_mixed_dataset(): void
    {
        $bd = $this->user('crm_bd');
        $lead = $this->leadFor($bd);
        $this->email($lead, 'outbound', 'sent');
        $this->email($lead, 'outbound', 'sent');
        $this->email($lead, 'outbound', 'failed');
        $this->email($lead, 'outbound', 'draft');
        $this->email($lead, 'inbound', 'received');
        Sanctum::actingAs($bd);

        $this->assertSame(2, $this->completedOutreach());
    }

    // ── Ownership / RBAC ────────────────────────────────────────────────────────────────────────

    public function test_a_bd_sees_only_outreach_on_their_own_leads(): void
    {
        $mine = $this->user('crm_bd');
        $theirs = $this->user('crm_bd');
        $this->email($this->leadFor($mine), 'outbound', 'sent');
        $this->email($this->leadFor($theirs), 'outbound', 'sent');
        $this->email($this->leadFor($theirs), 'outbound', 'sent');
        Sanctum::actingAs($mine);

        $this->assertSame(1, $this->completedOutreach());
    }

    public function test_admin_and_super_admin_see_the_crm_wide_total(): void
    {
        $a = $this->user('crm_bd');
        $b = $this->user('crm_bd');
        $this->email($this->leadFor($a), 'outbound', 'sent');
        $this->email($this->leadFor($b), 'outbound', 'sent');
        $this->email($this->leadFor($b), 'outbound', 'sent');

        Sanctum::actingAs($this->user('crm_admin'));
        $this->assertSame(3, $this->completedOutreach());

        Sanctum::actingAs($this->user('super_admin'));
        $this->assertSame(3, $this->completedOutreach());
    }

    // ── The defect this definition was chosen to avoid ──────────────────────────────────────────

    /**
     * Reproduces the live Lead #148 case that decided the PO choice: a lead whose `email_outreach`
     * reached `replied` purely because a prospect wrote to us, with no outbound email ever sent.
     *
     * Under the rejected `email_outreach`-based definitions this lead scored 1 — crediting outreach
     * the CRM never performed. Sourcing from actually-sent outbound mail makes that impossible.
     */
    public function test_an_inbound_reply_cannot_inflate_the_kpi(): void
    {
        $bd = $this->user('crm_bd');
        $lead = $this->leadFor($bd);
        $lead->update(['email_outreach' => 'replied']);
        $this->email($lead, 'inbound', 'received');
        Sanctum::actingAs($bd);

        $this->assertSame('replied', $lead->fresh()->email_outreach);
        $this->assertSame(0, $this->completedOutreach());
    }

    /** Follow-ups Due is a separate metric and must not move when outreach does. */
    public function test_completed_outreach_does_not_affect_follow_ups_due(): void
    {
        $bd = $this->user('crm_bd');
        $this->email($this->leadFor($bd), 'outbound', 'sent');
        Sanctum::actingAs($bd);

        $data = $this->getJson('/api/v1/crm/dashboard/kpis')->assertOk()->json('data');

        $this->assertSame(1, $data['completedOutreach']);
        $this->assertSame(0, $data['followUpsDue']);
    }
}
