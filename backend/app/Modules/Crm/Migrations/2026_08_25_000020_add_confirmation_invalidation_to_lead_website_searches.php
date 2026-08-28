<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a confirmed absence turns out to be wrong, record that it was WITHDRAWN — do not erase it.
 *
 * THE STATE THIS FIXES. A human could confirm a business has no website, and a later search could
 * then deterministically verify one. Nothing reconciled the two, so the lead sat at `no-website` —
 * and therefore High Priority, the strongest signal on the Nearby board — while holding a verified
 * website at high confidence. The board advertised a greenfield opportunity that demonstrably was
 * not one.
 *
 * WHY A SEPARATE FLAG RATHER THAN NULLING THE CONFIRMATION. Clearing `confirmed_absent_at` and
 * `confirmed_by` would fix the state and destroy the history: a BD made a judgement on the evidence
 * in front of them, and that judgement was reasonable when they made it. Keeping the original
 * confirmation alongside the withdrawal means the trail reads "confirmed by X on the 3rd, withdrawn
 * on the 9th when a website was verified" instead of silently pretending nobody ever decided
 * anything. `LeadWebsiteSearch::isConfirmedAbsent()` treats a withdrawn confirmation as not
 * confirmed, so every consumer sees the corrected state without reading two columns.
 *
 * THE WITHDRAWAL NEVER CREATES `no-website`; it only ever reverts AWAY from it, to `website-unknown`,
 * which is the neutral state the existing verification flow is designed to resolve. Reverting away
 * from an over-strong claim is safe in a way that asserting one automatically is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_lead_website_searches', function (Blueprint $table) {
            // Set when a verified website contradicts an earlier confirmation of absence. The
            // original confirmation columns are deliberately left intact beside it.
            $table->timestamp('confirmation_invalidated_at')->nullable()->after('confirmation_note');
            // Why it was withdrawn, so the panel can say so in words rather than implying the BD
            // simply changed their mind.
            $table->string('confirmation_invalidated_reason', 200)->nullable()->after('confirmation_invalidated_at');
        });
    }

    public function down(): void
    {
        Schema::table('crm_lead_website_searches', function (Blueprint $table) {
            $table->dropColumn(['confirmation_invalidated_at', 'confirmation_invalidated_reason']);
        });
    }
};
