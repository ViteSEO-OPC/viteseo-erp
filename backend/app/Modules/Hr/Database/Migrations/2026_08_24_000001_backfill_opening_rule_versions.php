<?php

use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Services\AttendancePolicyService;
use Illuminate\Database\Migrations\Migration;

/**
 * The opening rule versions that were never written.
 *
 * Both versioning migrations tried to backfill an opening row from the live singleton and both
 * took their "no row yet, nothing to preserve" early return every time — migrations run before
 * anything reads the settings, and both singletons are created lazily by `firstOrCreate` on first
 * read. So the row appeared a moment AFTER the backfill had already given up.
 *
 * The consequence was silent and expensive: with no version on or before a date, `asOf()` falls
 * back to the LIVE row, so re-running May's payroll priced it with today's rules. This writes the
 * missing rows, and the two services now file their own the moment the singleton is created, so
 * the gap cannot reopen.
 *
 * This one leans on the models' DEFAULTS constants rather than reading the table raw — the usual
 * argument (a migration must not depend on today's $fillable) points the other way here, because
 * the alternative is a frozen copy of fifty-odd policy defaults that nothing keeps honest, and
 * what is wanted IS "whatever a stock install has". Nothing is written to the settings tables
 * themselves, only JSON into the version tables, so there is no schema dependency either.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Deliberately DEFAULTS, not the live row: the live row is whatever it has since been
        // edited to, and filing that as "in force since 2000" is the very error being fixed.
        // Verified against this database's own history — the June run's policy_snapshot holds
        // exactly the stock defaults, while the live row has since gained a minimum wage.
        PayrollSetting::ensureOpeningVersion();

        app(AttendancePolicyService::class)->ensureOpeningVersion();
    }

    public function down(): void
    {
        // Intentionally irreversible. Removing these rows would restore the bug — every payroll
        // run before the first recorded edit would go back to pricing at whatever the settings
        // say today. There is nothing to gain by undoing a repair.
    }
};
