<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Contact;
use App\Modules\Accounting\Models\Invoice;
use App\Support\CurrentOrg;
use App\Support\Entitlements;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Global top-bar search for the Accounting portal — invoices, bills, contacts
 * and the chart of accounts, grouped for the shared `SearchGroup[]` UI.
 *
 * Three gates apply, and a group is only built when ALL of them pass:
 *   1. Tenancy — every model uses BelongsToOrg, so the org global scope keeps
 *      another tenant's rows out without a single explicit where().
 *   2. Entitlements — invoices sit behind `accounting.invoicing` and bills
 *      behind `accounting.bills` (the same keys the `feature:` middleware uses
 *      on Routes/api.php). The endpoint itself is NOT feature-gated beyond
 *      `accounting`, so an org on the Custom tier still searches contacts and
 *      accounts instead of getting a blanket 403.
 *   3. Permissions — mirrored one-for-one from the list endpoints these results
 *      link to: `accounting.journal.view` guards invoices / bills / contacts
 *      (see Invoice/Bill/ContactController@index) and `accounting.accounts.view`
 *      guards the chart of accounts (see AccountPolicy::viewAny). Search must
 *      never surface a row its own list endpoint would refuse.
 *
 * SQL portability: tests run on in-memory sqlite, production on MySQL 8.4, so
 * matching is LOWER() + LIKE only — no REGEXP_REPLACE, no MATCH ... AGAINST.
 * LIKE wildcards inside the user's term are neutralised with `ESCAPE '!'`
 * (a single-character literal that needs no backslash, which the two engines
 * disagree about).
 */
class SearchService
{
    /** Max rows per group — the dropdown is a shortcut, not a report. */
    private const LIMIT = 5;

    /** Shortest term worth a round trip to the database. */
    private const MIN_LENGTH = 2;

    /**
     * @return array{groups: list<array{label: string, items: list<array{title: string, subtitle: string, href: string}>}>}
     */
    public function search(?User $user, string $q): array
    {
        $q = trim($q);
        $orgId = CurrentOrg::id();

        if ($user === null || $orgId === null || mb_strlen($q) < self::MIN_LENGTH) {
            return ['groups' => []];
        }

        $term = mb_strtolower($q);
        $contains = '%'.$this->escapeLike($term).'%';
        $exact = $this->escapeLike($term);

        $canLedger = $user->can('accounting.journal.view');
        $canAccounts = $user->can('accounting.accounts.view');

        $hasInvoicing = Entitlements::has($orgId, 'accounting.invoicing');
        $hasBills = Entitlements::has($orgId, 'accounting.bills');

        $groups = [];

        // Invoices — the most specific documents a finance user hunts for, so
        // they lead. Matched on invoice number or the customer's name (either
        // the denormalised copy on the invoice or the linked contact).
        if ($canLedger && $hasInvoicing) {
            $this->push($groups, 'Invoices', Invoice::query()
                ->with('contact')
                ->where(function (Builder $w) use ($contains): void {
                    $this->like($w, 'invoice_no', $contains);
                    $this->orLike($w, 'customer_name', $contains);
                    $w->orWhereHas('contact', fn (Builder $c) => $this->like($c, 'name', $contains));
                })
                // Exact document-number hits first ("INV-0007"), then the newest.
                ->orderByRaw('CASE WHEN LOWER(invoice_no) LIKE ? ESCAPE \'!\' THEN 0 ELSE 1 END', [$exact])
                ->orderByDesc('issue_date')
                ->orderByDesc('id')
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (Invoice $i): array => [
                    'title' => (string) $i->invoice_no,
                    'subtitle' => $this->documentSubtitle(
                        $i->contact?->name ?? $i->customer_name,
                        (float) $i->amount,
                        (string) $i->status,
                        $i->due_date?->format('M j, Y'),
                    ),
                    'href' => '/accounting/invoices',
                ]));
        }

        // Bills — same shape on the payables side.
        if ($canLedger && $hasBills) {
            $this->push($groups, 'Bills', Bill::query()
                ->with('contact')
                ->where(function (Builder $w) use ($contains): void {
                    $this->like($w, 'bill_no', $contains);
                    $this->orLike($w, 'vendor_name', $contains);
                    $w->orWhereHas('contact', fn (Builder $c) => $this->like($c, 'name', $contains));
                })
                ->orderByRaw('CASE WHEN LOWER(bill_no) LIKE ? ESCAPE \'!\' THEN 0 ELSE 1 END', [$exact])
                ->orderByDesc('issue_date')
                ->orderByDesc('id')
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (Bill $b): array => [
                    'title' => (string) $b->bill_no,
                    'subtitle' => $this->documentSubtitle(
                        $b->contact?->name ?? $b->vendor_name,
                        (float) $b->amount,
                        (string) $b->status,
                        $b->due_date?->format('M j, Y'),
                    ),
                    'href' => '/accounting/bills',
                ]));
        }

        // Contacts — customers and vendors, by name, email or TIN.
        if ($canLedger) {
            $this->push($groups, 'Contacts', Contact::query()
                ->where(function (Builder $w) use ($contains): void {
                    $this->like($w, 'name', $contains);
                    $this->orLike($w, 'email', $contains);
                    $this->orLike($w, 'tin', $contains);
                })
                ->orderByRaw('CASE WHEN LOWER(name) LIKE ? ESCAPE \'!\' THEN 0 ELSE 1 END', [$exact])
                ->orderBy('name')
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (Contact $c): array => [
                    'title' => (string) $c->name,
                    'subtitle' => $this->join([
                        ucfirst((string) $c->type),
                        $c->email,
                        $c->tin ? "TIN {$c->tin}" : null,
                    ]),
                    'href' => '/accounting/contacts',
                ]));
        }

        // Chart of accounts — the highest-value lookup in day-to-day booking:
        // "1200" or "receivable" both have to land on the same row.
        if ($canAccounts) {
            $this->push($groups, 'Chart of Accounts', Account::query()
                ->where(function (Builder $w) use ($contains): void {
                    $this->like($w, 'code', $contains);
                    $this->orLike($w, 'name', $contains);
                })
                ->orderByRaw('CASE WHEN LOWER(code) LIKE ? ESCAPE \'!\' THEN 0 ELSE 1 END', [$exact])
                ->orderBy('code')
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (Account $a): array => [
                    'title' => trim("{$a->code} {$a->name}"),
                    'subtitle' => $this->join([
                        ucfirst($a->type?->value ?? ''),
                        $a->is_active ? null : 'Inactive',
                    ]) ?: 'Account',
                    'href' => '/accounting',
                ]));
        }

        return ['groups' => $groups];
    }

    /**
     * @param  list<array{label: string, items: list<array<string, string>>}>  $groups
     * @param  Collection<int, array<string, string>>  $items
     */
    private function push(array &$groups, string $label, Collection $items): void
    {
        if ($items->isNotEmpty()) {
            $groups[] = ['label' => $label, 'items' => $items->values()->all()];
        }
    }

    /** `LOWER(col) LIKE ?` — the only matcher both sqlite and MySQL agree on. */
    private function like(Builder $q, string $column, string $pattern): Builder
    {
        return $q->whereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
    }

    private function orLike(Builder $q, string $column, string $pattern): Builder
    {
        return $q->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
    }

    /**
     * Neutralise LIKE metacharacters typed by the user so "100%" searches for
     * the literal text rather than every row in the table.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }

    /** "Buyer Inc · ₱1,120.00 · Sent · Due Aug 31, 2026" */
    private function documentSubtitle(?string $party, float $amount, string $status, ?string $due): string
    {
        return $this->join([
            $party,
            '₱'.number_format($amount, 2),
            ucfirst($status),
            $due ? "Due {$due}" : null,
        ]);
    }

    /** @param  list<string|null>  $parts */
    private function join(array $parts): string
    {
        return implode(' · ', array_filter(array_map('trim', array_filter($parts, 'is_string'))));
    }
}
