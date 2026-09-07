<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Accounting\Models\Account;
use App\Services\OrgOnboardingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Two things an invoice was claiming that were not so.
 *
 * It was stamped `status = 'sent'` the moment it was created, by a screen whose
 * button said "Create & send" and which never called the send endpoint. Nothing
 * reached the customer; the list said it had. Bills, created by the same module
 * on the same day, correctly start as `awaiting` -- invoices were the outlier.
 *
 * And a recurring schedule carried no VAT treatment at all. The form sent no
 * `tax_rate`, so `InvoiceService` fell to its default -- `$taxRate > 0 ?
 * 'vatable' : 'exempt'` -- and every invoice a retainer generated was booked
 * VAT-EXEMPT. It then flowed into BIR 2550Q as exempt sales and into the SLSP
 * alphalist the same way. A monthly retainer is ViteSEO's own business model,
 * so this is a wrong return filed on the most ordinary transaction the product
 * has, and nothing on any screen disagrees with itself until the BIR does.
 */
class AnInvoiceSaysOnlyWhatIsTrueOfItTest extends TestCase
{
    use RefreshDatabase;

    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $result = app(OrgOnboardingService::class)->createOrg(
            'Bituin Digital Corp', 'Maria', 'maria@bituin.ph', 'password-123', ['accounting' => 'full'],
        );
        Sanctum::actingAs($result['admin']);

        $this->customerId = $this->postJson('/api/v1/accounting/contacts', [
            'name' => 'Aurora Retail Inc', 'type' => 'customer', 'email' => 'ap@aurora.ph',
        ])->assertCreated()->json('data.id');
    }

    private function account(string $code): int
    {
        return Account::query()->where('code', $code)->firstOrFail()->id;
    }

    public function test_a_new_invoice_is_not_described_as_sent(): void
    {
        $id = $this->postJson('/api/v1/accounting/invoices', [
            'contact_id' => $this->customerId,
            'issue_date' => '2026-09-07', 'due_date' => '2026-10-07',
            'tax_rate' => 12, 'vat_class' => 'vatable',
            'lines' => [['quantity' => 1, 'unit_price' => 50000, 'account_id' => $this->account('4000')]],
        ])->assertCreated()->json('data.id');

        $invoice = $this->getJson("/api/v1/accounting/invoices/{$id}")->assertOk();

        $this->assertSame('awaiting', $invoice->json('data.status'), 'raised, not sent');
        $this->assertNull($invoice->json('data.sent_at'));
    }

    public function test_sending_it_is_what_makes_it_sent(): void
    {
        Mail::fake();

        $id = $this->postJson('/api/v1/accounting/invoices', [
            'contact_id' => $this->customerId,
            'issue_date' => '2026-09-07', 'due_date' => '2026-10-07',
            'tax_rate' => 12, 'vat_class' => 'vatable',
            'lines' => [['quantity' => 1, 'unit_price' => 50000, 'account_id' => $this->account('4000')]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/accounting/invoices/{$id}/send")->assertOk();

        $invoice = $this->getJson("/api/v1/accounting/invoices/{$id}")->assertOk();
        $this->assertSame('sent', $invoice->json('data.status'));
        $this->assertNotNull($invoice->json('data.sent_at'));
    }

    public function test_a_retainer_charges_the_vat_the_client_set_on_it(): void
    {
        $this->postJson('/api/v1/accounting/recurring-invoices', [
            'contact_id' => $this->customerId,
            'description' => 'Monthly SEO retainer',
            'frequency' => 'monthly',
            'start_date' => '2026-09-01',
            'due_days' => 30,
            'tax_rate' => 12,
            'vat_class' => 'vatable',
            'lines' => [['quantity' => 1, 'unit_price' => 30000, 'account_id' => $this->account('4000')]],
        ])->assertCreated();

        $this->postJson('/api/v1/accounting/recurring-invoices/run')->assertOk();

        $invoice = collect($this->getJson('/api/v1/accounting/invoices')->json('data'))->first();

        $this->assertSame('vatable', $invoice['vat_class'], 'not exempt by default');
        $this->assertSame(3600.0, (float) $invoice['tax_amount'], '12% of 30,000');
        $this->assertSame(33600.0, (float) $invoice['amount']);

        // And the figure reaches the return the client actually files.
        $q = $this->getJson('/api/v1/accounting/reports/bir-2550q?year=2026&quarter=3')->assertOk();
        $this->assertSame(30000.0, (float) $q->json('data.sales.vatable'));
        $this->assertSame(3600.0, (float) $q->json('data.sales.output_vat'));
        $this->assertSame(0.0, (float) $q->json('data.sales.exempt'), 'nothing was exempt');
    }

    public function test_a_retainer_can_be_zero_rated_which_a_rate_alone_cannot_say(): void
    {
        // Export consulting is 0% and NOT exempt -- the distinction the BIR
        // reports on separately, and one a tax rate of zero cannot express.
        $this->postJson('/api/v1/accounting/recurring-invoices', [
            'contact_id' => $this->customerId,
            'description' => 'Export consulting retainer',
            'frequency' => 'monthly',
            'start_date' => '2026-09-01',
            'tax_rate' => 0,
            'vat_class' => 'zero_rated',
            'lines' => [['quantity' => 1, 'unit_price' => 20000, 'account_id' => $this->account('4000')]],
        ])->assertCreated();

        $this->postJson('/api/v1/accounting/recurring-invoices/run')->assertOk();

        $invoice = collect($this->getJson('/api/v1/accounting/invoices')->json('data'))->first();
        $this->assertSame('zero_rated', $invoice['vat_class']);

        $q = $this->getJson('/api/v1/accounting/reports/bir-2550q?year=2026&quarter=3')->assertOk();
        $this->assertSame(20000.0, (float) $q->json('data.sales.zero_rated'));
        $this->assertSame(0.0, (float) $q->json('data.sales.exempt'), 'zero-rated is not exempt');
    }
}
