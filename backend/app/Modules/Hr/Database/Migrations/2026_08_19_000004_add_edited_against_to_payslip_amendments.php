<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT THE ENGINE SAID WHEN HR OVERRULED IT — kept so a re-run can report that it no longer says it.
 *
 * `original_amount` cannot answer this, because it has the other job: it is the figure the payslip
 * prints struck through, so it has to track what the engine computes NOW and is rewritten on every
 * run. Frozen instead, an amended payslip would keep striking through a figure the engine had
 * stopped producing.
 *
 * So the value HR actually disagreed with is recorded separately, once, at the moment they edited.
 * When a re-run computes something different from it, the override has outlived the problem it was
 * fixing — the attendance was corrected, or the rate changed, and HR is now silently suppressing a
 * figure nobody has looked at. THE OVERRIDE STILL WINS: it is an absolute value and a re-run does
 * not get to re-decide it. It is reported instead, which is the difference between a system that
 * quietly resolves the conflict and one that tells the person who owns it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslip_amendments', function (Blueprint $table) {
            $table->decimal('edited_against', 12, 2)->nullable()->after('original_amount');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslip_amendments', function (Blueprint $table) {
            $table->dropColumn('edited_against');
        });
    }
};
