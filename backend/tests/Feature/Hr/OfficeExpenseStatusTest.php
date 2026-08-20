<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\AuditLog;
use App\Modules\Hr\Models\OfficeExpense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/** Setting an office expense's review state from the add/edit form. */
class OfficeExpenseStatusTest extends HrTestCase
{
    use RefreshDatabase;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hr = $this->hrUser();
        Sanctum::actingAs($this->hr);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'category' => 'supplies',
            'amount' => 1200.50,
            'expense_date' => '2026-08-01',
            'description' => 'Printer paper and toner',
            'vendor' => 'Acme Supplies',
        ], $overrides);
    }

    private function recordedExpense(): OfficeExpense
    {
        return OfficeExpense::create($this->payload(['status' => 'recorded']));
    }

    /** @return array<int, string> */
    private function auditActions(): array
    {
        return AuditLog::query()->where('module', 'Office Expenses')->pluck('action')->all();
    }

    public function test_a_new_expense_without_a_status_is_recorded_and_unreviewed(): void
    {
        $this->postJson('/api/v1/hr/office-expenses', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'recorded');

        $this->assertNull(OfficeExpense::sole()->reviewed_by);
    }

    public function test_an_expense_filed_as_already_approved_names_its_reviewer(): void
    {
        $this->postJson('/api/v1/hr/office-expenses', $this->payload(['status' => 'approved']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved');

        $expense = OfficeExpense::sole();

        // Attributed and timestamped — not a bare column default appearing from nowhere.
        $this->assertSame($this->hr->id, $expense->reviewed_by);
        $this->assertNotNull($expense->reviewed_at);

        // And the approval left its own audit line on top of the creation one.
        $this->assertContains('created', $this->auditActions());
        $this->assertContains('approved', $this->auditActions());
    }

    public function test_a_rejection_reason_survives_the_create(): void
    {
        $this->postJson('/api/v1/hr/office-expenses', $this->payload([
            'status' => 'rejected',
            'review_note' => 'Duplicate of expense #12',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.review_note', 'Duplicate of expense #12');
    }

    public function test_the_edit_form_can_approve_an_existing_expense(): void
    {
        $expense = $this->recordedExpense();

        $this->patchJson("/api/v1/hr/office-expenses/{$expense->id}", $this->payload(['status' => 'approved']))
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame($this->hr->id, $expense->fresh()->reviewed_by);
    }

    /**
     * `transition()` refuses to review an expense that is already decided. Editing has to get past
     * that without the guard being weakened, so it reopens first — the same two steps HR would
     * take by hand, each recording its own audit line.
     */
    public function test_reversing_a_decision_reopens_before_deciding_again(): void
    {
        $expense = $this->recordedExpense();

        $this->postJson("/api/v1/hr/office-expenses/{$expense->id}/approve")->assertOk();

        $this->patchJson("/api/v1/hr/office-expenses/{$expense->id}", $this->payload([
            'status' => 'rejected',
            'review_note' => 'Receipt turned out to be unreadable',
        ]))
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertContains('reopened', $this->auditActions());
        $this->assertContains('declined', $this->auditActions());
    }

    public function test_resubmitting_an_unchanged_status_does_not_re_stamp_the_review(): void
    {
        $expense = $this->recordedExpense();

        $this->postJson("/api/v1/hr/office-expenses/{$expense->id}/approve")->assertOk();
        $reviewedAt = $expense->fresh()->reviewed_at;

        // Editing the description while the status stays 'approved' must not churn the review.
        $this->patchJson("/api/v1/hr/office-expenses/{$expense->id}", $this->payload([
            'status' => 'approved',
            'description' => 'Printer paper, toner and staples',
        ]))
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame(
            $reviewedAt->toDateTimeString(),
            $expense->fresh()->reviewed_at->toDateTimeString(),
        );
        $this->assertNotContains('reopened', $this->auditActions());
    }

    public function test_a_status_outside_the_allowed_set_is_refused(): void
    {
        $this->postJson('/api/v1/hr/office-expenses', $this->payload(['status' => 'paid']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }
}
