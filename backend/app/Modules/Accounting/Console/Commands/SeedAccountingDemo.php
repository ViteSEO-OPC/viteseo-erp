<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Console\Commands;

use App\Models\Org;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Contact;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Accounting\Services\InvoiceService;
use App\Modules\Accounting\Services\JournalService;
use App\Modules\Accounting\Services\PurchaseOrderService;
use App\Modules\Accounting\Services\RecurringInvoiceService;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Seeds a self-contained DEMO company ("Demo Trading OPC") with three login
 * accounts and realistic fake data, so a 3rd-party accountant can test the
 * Accounting system end-to-end. Idempotent: skips if the demo already exists;
 * `--fresh` deletes and rebuilds it. Safe to run on a throwaway demo database.
 *
 * Logins (all password: Demo1234):
 *   admin@demotrading.ph      Finance Admin
 *   accountant@demotrading.ph Accountant
 *   viewer@demotrading.ph     Viewer / Auditor
 */
class SeedAccountingDemo extends Command
{
    protected $signature = 'accounting:seed-demo {--fresh : Delete an existing demo company first, then rebuild}';

    protected $description = 'Seed the Demo Trading OPC company + fake data for 3rd-party accounting testing';

    private const ORG_NAME = 'Demo Trading OPC';

    public function handle(): int
    {
        // Roles must exist first (RolesAndPermissionsSeeder).
        if (! Role::where('name', 'acc_admin')->exists()) {
            $this->error('Accounting roles are missing. Run `php artisan db:seed` first, then re-run this.');

            return self::FAILURE;
        }

        $existing = Org::where('name', self::ORG_NAME)->first();
        if ($existing) {
            if (! $this->option('fresh')) {
                $this->warn('Demo already exists (org #' . $existing->id . '). Use --fresh to rebuild it.');

                return self::SUCCESS;
            }
            $this->line('Removing existing demo (org #' . $existing->id . ')…');
            $this->teardown($existing);
        }

        $this->line('Creating ' . self::ORG_NAME . '…');
        $orgId = $this->build();

        $this->info("Done. Demo company ready (org #{$orgId}).");
        $this->newLine();
        $this->line('  Logins (password: Demo1234)');
        $this->line('    Finance Admin      admin@demotrading.ph');
        $this->line('    Accountant         accountant@demotrading.ph');
        $this->line('    Viewer / Auditor   viewer@demotrading.ph');

        return self::SUCCESS;
    }

    private function build(): int
    {
        $res = app(OrgOnboardingService::class)->createAccountingOrg(
            self::ORG_NAME, 'Maria Santos', 'admin@demotrading.ph', 'Demo1234', 'full'
        );
        $org = $res['org'];
        $admin = $res['admin']; // Finance Admin (acc_admin)
        CurrentOrg::set($org->id);

        // Active for a year so no paywall interrupts testing.
        Subscription::where('org_id', $org->id)->update([
            'status' => 'active',
            'current_period_end' => now()->addYear(),
        ]);

        // Accountant + Viewer login accounts.
        foreach ([
            ['Ana Reyes', 'accountant@demotrading.ph', 'acc_accountant'],
            ['Leo Cruz', 'viewer@demotrading.ph', 'acc_viewer'],
        ] as [$name, $email, $role]) {
            $u = User::create(['name' => $name, 'email' => $email, 'password' => 'Demo1234']);
            $u->assignRole($role);
            $org->users()->attach($u->id, ['is_default' => true]);
        }

        // ---- Fake data ----
        $income = Account::where('code', '4000')->firstOrFail();
        $cash = Account::where('code', '1000')->firstOrFail();
        $equity = Account::where('code', '3000')->firstOrFail();
        // Office Expense by name, not "the first expense account" -- which is
        // Salaries Expense, and a demo that books printer paper to salaries is a
        // demo an accountant stops trusting on the first screen.
        $expense = Account::where('code', '5200')->firstOrFail();

        // Opening capital.
        $j = app(JournalService::class);
        $j->post($j->create(['entry_date' => now()->subMonths(2)->startOfMonth()->toDateString(), 'reference' => 'OB',
            'memo' => 'Owner capital injection',
            'lines' => [['account_id' => $cash->id, 'debit' => 200000], ['account_id' => $equity->id, 'credit' => 200000]]]), $admin);

        // Customers + vendors (with TIN).
        $abc = Contact::create(['name' => 'ABC Retail Corp', 'type' => 'customer', 'tin' => '001-234-567-000', 'email' => 'ap@abcretail.ph', 'address' => 'Makati City']);
        $metro = Contact::create(['name' => 'Metro Supplies Inc', 'type' => 'customer', 'tin' => '002-345-678-000', 'email' => 'billing@metro.ph', 'address' => 'Quezon City']);
        $juan = Contact::create(['name' => 'Juan Dela Cruz', 'type' => 'customer', 'tin' => '003-456-789-000', 'address' => 'Pasig City']);
        $depot = Contact::create(['name' => 'Office Depot PH', 'type' => 'vendor', 'tin' => '100-111-222-000', 'address' => 'Taguig City']);
        $prime = Contact::create(['name' => 'Prime Logistics', 'type' => 'vendor', 'tin' => '200-222-333-000', 'address' => 'Manila']);

        // Dates relative to "now" so the demo stays current whenever it's seeded.
        $lastMonth = now()->subMonth();
        $thisMonth = now();
        $inv = app(InvoiceService::class);
        $mkInv = fn ($c, $issue, $due, $amt, $class, $desc) => $inv->create([
            'contact_id' => $c, 'issue_date' => $issue, 'due_date' => $due, 'description' => $desc,
            'tax_rate' => $class === 'vatable' ? 12 : 0, 'vat_class' => $class,
            'lines' => [['description' => $desc, 'quantity' => 1, 'unit_price' => $amt, 'account_id' => $income->id]],
        ], $admin);

        $i1 = $mkInv($abc->id, $lastMonth->copy()->day(15)->toDateString(), $lastMonth->copy()->day(15)->addMonth()->toDateString(), 50000, 'vatable', 'SEO services');
        $inv->recordPayment($i1, (float) $i1->amount, $admin); // paid
        $i2 = $mkInv($abc->id, $thisMonth->copy()->day(5)->toDateString(), $thisMonth->copy()->day(5)->addMonth()->toDateString(), 30000, 'vatable', 'Web maintenance');
        $inv->recordPayment($i2, 10000, $admin); // partial
        $mkInv($metro->id, $thisMonth->copy()->day(10)->toDateString(), $thisMonth->copy()->day(10)->addMonth()->toDateString(), 20000, 'vatable', 'Ad campaign');
        $mkInv($metro->id, $thisMonth->copy()->day(15)->toDateString(), $thisMonth->copy()->day(15)->addMonth()->toDateString(), 8000, 'exempt', 'Training (VAT-exempt)');
        $mkInv($juan->id, $thisMonth->copy()->day(12)->toDateString(), $thisMonth->copy()->day(12)->addMonth()->toDateString(), 15000, 'zero_rated', 'Export consulting (zero-rated)');

        $bill = app(BillService::class);
        $mkBill = fn ($c, $issue, $due, $amt, $class, $desc) => $bill->create([
            'contact_id' => $c, 'issue_date' => $issue, 'due_date' => $due, 'description' => $desc,
            'tax_rate' => $class === 'vatable' ? 12 : 0, 'vat_class' => $class,
            'lines' => [['description' => $desc, 'quantity' => 1, 'unit_price' => $amt, 'account_id' => $expense->id]],
        ], $admin);
        $b1 = $mkBill($depot->id, $thisMonth->copy()->day(3)->toDateString(), $thisMonth->copy()->day(3)->addMonth()->toDateString(), 12000, 'vatable', 'Office supplies');
        $bill->recordPayment($b1, (float) $b1->amount, $admin); // paid
        $mkBill($prime->id, $thisMonth->copy()->day(8)->toDateString(), $thisMonth->copy()->day(8)->addMonth()->toDateString(), 25000, 'vatable', 'Delivery services'); // unpaid
        $mkBill($depot->id, $thisMonth->copy()->day(18)->toDateString(), $thisMonth->copy()->day(18)->addMonth()->toDateString(), 5000, 'exempt', 'Books (VAT-exempt)'); // unpaid

        // Recurring invoice + open purchase order.
        app(RecurringInvoiceService::class)->create([
            'contact_id' => $abc->id, 'description' => 'Monthly SEO retainer', 'frequency' => 'monthly',
            'tax_rate' => 12, 'due_days' => 15, 'start_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'lines' => [['description' => 'Retainer', 'quantity' => 1, 'unit_price' => 10000, 'account_id' => $income->id]],
        ]);
        app(PurchaseOrderService::class)->create([
            'contact_id' => $depot->id, 'order_date' => $thisMonth->copy()->day(20)->toDateString(),
            'expected_date' => $thisMonth->copy()->day(30)->toDateString(),
            'description' => 'Printer + supplies', 'tax_rate' => 12,
            'lines' => [['description' => 'Printer', 'quantity' => 1, 'unit_price' => 8000, 'account_id' => $expense->id]],
        ]);

        return $org->id;
    }

    /** Delete the demo org and every record tied to it (safe: only this org). */
    private function teardown(Org $org): void
    {
        $o = $org->id;
        DB::transaction(function () use ($o) {
            $poIds = DB::table('acc_purchase_orders')->where('org_id', $o)->pluck('id');
            DB::table('acc_purchase_order_lines')->whereIn('purchase_order_id', $poIds)->delete();
            DB::table('acc_purchase_orders')->where('org_id', $o)->delete();
            $recIds = DB::table('acc_recurring_invoices')->where('org_id', $o)->pluck('id');
            DB::table('acc_recurring_invoice_lines')->whereIn('recurring_invoice_id', $recIds)->delete();
            DB::table('acc_recurring_invoices')->where('org_id', $o)->delete();
            DB::table('acc_bank_reconciliations')->where('org_id', $o)->delete();
            DB::table('acc_revenue_schedules')->where('org_id', $o)->delete();
            $invIds = DB::table('acc_invoices')->where('org_id', $o)->pluck('id');
            DB::table('acc_invoice_lines')->whereIn('invoice_id', $invIds)->delete();
            DB::table('acc_invoices')->where('org_id', $o)->delete();
            $billIds = DB::table('acc_bills')->where('org_id', $o)->pluck('id');
            DB::table('acc_bill_lines')->whereIn('bill_id', $billIds)->delete();
            DB::table('acc_bills')->where('org_id', $o)->delete();
            $jeIds = DB::table('acc_journal_entries')->where('org_id', $o)->pluck('id');
            DB::table('acc_journal_lines')->whereIn('journal_entry_id', $jeIds)->delete();
            DB::table('acc_journal_entries')->where('org_id', $o)->delete();
            DB::table('acc_contacts')->where('org_id', $o)->delete();
            DB::table('acc_budgets')->where('org_id', $o)->delete();
            DB::table('acc_accounts')->where('org_id', $o)->delete();
            DB::table('acc_subscription_payments')->where('customer_org_id', $o)->delete();

            $uids = DB::table('org_user')->where('org_id', $o)->pluck('user_id');
            DB::table('model_has_roles')->whereIn('model_id', $uids)->delete();
            DB::table('model_has_permissions')->whereIn('model_id', $uids)->delete();
            DB::table('personal_access_tokens')->whereIn('tokenable_id', $uids)->where('tokenable_type', User::class)->delete();
            DB::table('custom_roles')->where('org_id', $o)->delete();
            DB::table('org_features')->where('org_id', $o)->delete();
            DB::table('subscriptions')->where('org_id', $o)->delete();
            DB::table('org_user')->where('org_id', $o)->delete();
            DB::table('users')->whereIn('id', $uids)->delete();
            DB::table('orgs')->where('id', $o)->delete();
        });
    }
}
