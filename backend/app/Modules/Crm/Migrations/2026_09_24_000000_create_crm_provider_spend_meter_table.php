<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a paid discovery provider has been asked for this period, and what it modelled out at — the
 * meter {@see \App\Modules\Crm\Services\Discovery\GooglePlacesSpendGate} enforces a configured
 * budget against.
 *
 * ONE ROW PER (PROVIDER, PERIOD), AND THAT SHAPE *IS* THE CONCURRENCY DESIGN. Reserving spend is a
 * single conditional UPDATE against one row, so MySQL's row lock — not an application lock, not a
 * read-then-write — is what makes concurrent discovery runs share one budget. Two runs, four queue
 * slices and a retry loop all serialise on this row, and the check-then-spend race cannot exist
 * because there is no gap between the check and the spend: they are the same statement.
 *
 * GLOBAL, WITH NO `org_id`, DELIBERATELY — the same decision `crm_nerby_provider_settings` already
 * makes and for the identical reason: the credential this meters is Vite SEO's own, one Google Cloud
 * project, one bill, shared by every tenant. A per-org meter would multiply the bill by the number
 * of orgs while every org believed it was inside the limit. If a client is ever meant to bring their
 * own Google key, this table grows `org_id` at the same time that one does, and not before.
 *
 * `micros_used` IS A CONSUMPTION COUNTER IN USD MICROS (USD x 1,000,000), NOT PLATFORM MONEY. It is
 * never invoiced, never paid, never reported to the BIR and never displayed in pesos; the platform's
 * `decimal(12,2)` rule governs transactable amounts, and every such column in this codebase
 * (`amount`, `subtotal`, `base_salary`, `sss_contribution`) is one. This is not. Three reasons the
 * distinction is load-bearing rather than pedantic:
 *
 *   - ONE REQUEST COSTS LESS THAN A CENT. Google's published Places Text Search Enterprise rate is
 *     $35.00/1,000 = $0.035 per request, and at its highest volume band $2.63/1,000 = $0.00263.
 *     In `decimal(12,2)` the first rounds to $0.03 or $0.04 — a systematic +/-14% error on EVERY
 *     request, compounding linearly — and the second rounds to $0.00, at which point the counter
 *     stops advancing and the gate never fires. A spend guard that silently stops counting is worse
 *     than no spend guard, because it is believed.
 *   - PHP HAS NO DECIMAL TYPE. Eloquent's `decimal` cast returns a *string*, and arithmetic on it
 *     coerces to float — the one representation money must never use. An integer column with an
 *     integer cast makes float arithmetic structurally unreachable on this path.
 *   - GOOGLE SPEAKS INTEGERS. Cloud Billing returns money as `google.type.Money{units, nanos}`.
 *     Micros convert to nanos by x1000, exactly, so the Phase 3 reconciliation compares integers to
 *     integers with no parse and no rounding step.
 *
 * `micros_allowed` IS OBSERVABILITY, NOT THE LIMIT. The budget enforced on any given request is read
 * LIVE from `crm_nerby_provider_settings.options.spend_limit_usd` at reservation time, so raising
 * $10 to $50 in Settings takes effect on the very next request rather than next month. This column
 * records what that live value was when the row was last touched, so an operator reading the meter
 * can see the budget it was being held to. Nothing enforces against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_provider_spend_meter', function (Blueprint $table) {
            $table->id();

            // Which paid provider this meters. A string rather than an enum column for the same
            // reason `crm_nerby_provider_settings.provider` is: adding a metered provider must not
            // be a migration.
            $table->string('provider', 30);

            // The window the budget applies to. `Y-m` today. Sized for `Y-m-d` so a daily sub-limit
            // — deliberately NOT built here, because it would turn one atomic statement into a
            // two-statement transaction — needs no schema change if it is ever approved.
            $table->string('period', 10);

            // Every request that reached the reservation, billable or not. Counted even when the
            // budget is unlimited, because "how much would we have spent" is the question an
            // operator asks BEFORE setting a limit for the first time.
            $table->unsignedBigInteger('requests_used')->default(0);

            // Modelled gross spend in USD micros. Gross, not net: the provider's free monthly
            // allowance is folded into the effective limit at reservation time rather than
            // subtracted here, which keeps this column a plain monotonic total and keeps the
            // reservation a single statement.
            $table->unsignedBigInteger('micros_used')->default(0);

            // The live budget this row was last held to, in micros. NULL means no limit was
            // configured. Diagnostic only — see the class docblock.
            $table->unsignedBigInteger('micros_allowed')->nullable();

            // Phase 3: what Google's own billing export said this period actually cost, and when we
            // last asked. Nullable forever if reconciliation is never built; enforcement does not
            // read them, so a stale or absent reconciliation can never open the gate.
            $table->timestamp('reconciled_at')->nullable();
            $table->unsignedBigInteger('reconciled_micros')->nullable();

            $table->timestamps();

            // THE INVARIANT THE WHOLE DESIGN RESTS ON: exactly one meter per provider per period.
            // Without it, two concurrent runs could each create a row and each spend a full budget.
            $table->unique(['provider', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_provider_spend_meter');
    }
};
