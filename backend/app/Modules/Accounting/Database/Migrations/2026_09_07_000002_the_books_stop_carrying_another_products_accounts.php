<?php

use App\Models\Org;
use App\Modules\Accounting\Services\ChartOfAccountsProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * `4100 Booking Revenue` and `5410 Inventory Adjustments` stop being provisioned
 * today (see ChartOfAccountsProvisioner::RETIRED_ACCOUNTS). That only helps the
 * next client. Every org onboarded before today still carries them, and still
 * shows them in six pickers and in Budget vs Actual.
 *
 * So sweep what already exists. The prune refuses any account something
 * references, so an org that did use one keeps it and its history intact -- the
 * sweep is allowed to leave work undone, and that is the correct outcome, not a
 * failure.
 *
 * down() is a no-op on purpose: putting an account back into a client's chart of
 * accounts is a change to their books, not a rollback of a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('acc_accounts')) {
            return;
        }

        $provisioner = app(ChartOfAccountsProvisioner::class);

        Org::query()->orderBy('id')->each(function (Org $org) use ($provisioner): void {
            $removed = $provisioner->pruneRetiredFor($org);

            if ($removed !== []) {
                echo "  Org #{$org->id} ({$org->name}): removed ".implode(', ', $removed)."\n";
            }
        });
    }

    public function down(): void
    {
        // Deliberately empty -- see the note above.
    }
};
