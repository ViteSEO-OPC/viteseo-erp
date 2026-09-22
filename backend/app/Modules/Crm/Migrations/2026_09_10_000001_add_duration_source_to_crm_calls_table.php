<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHERE a call's duration came from — the CRM's own clock, or a carrier's.
 *
 * `provider` cannot answer this, which is the entire reason for the column. It names the TRANSPORT a
 * call used; it says nothing about who measured the seconds. A Twilio call whose completion webhook
 * never arrived (a tunnel down in development, a Twilio outage, a BD who concluded the call by hand
 * before the callback landed) is `provider = twilio` and yet carries the CRM's start→end measurement.
 * Reading `provider !== 'manual'` as "carrier-verified" would present that number as talk time.
 *
 *   duration_source = 'manual'    the CRM measured the gap between opening and concluding the record.
 *                                 NOT talk time. Every historical row, every manual call, and any
 *                                 provider call concluded without a carrier report.
 *   duration_source = 'provider'  the carrier reported the dialed leg's real duration.
 *
 * Default 'manual' with no backfill, because 'manual' is the truth about every row that already
 * exists: before this feature, every duration in `crm_calls` was the CRM's own measurement.
 *
 * ── This is NOT a scoring input ──
 * Nothing in the scoring engine reads duration today: `LeadScoreCalculator` scores calls from the
 * lead's `cold_calling` status ('called' / 'scheduled'), not from seconds. This column adds a fact,
 * not a rule. If verified talk time is ever to influence a score, that decision belongs in the
 * Business Rules configuration the scorer already reads — never in the calling code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_calls', function (Blueprint $table): void {
            $table->string('duration_source', 16)
                ->default('manual')
                ->after('duration_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('crm_calls', function (Blueprint $table): void {
            $table->dropColumn('duration_source');
        });
    }
};
