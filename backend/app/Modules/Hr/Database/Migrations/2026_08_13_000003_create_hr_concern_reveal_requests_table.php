<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asking an anonymous reporter to identify themselves — and their answer.
 *
 * WHAT CHANGED. Until now HR could always see who filed an anonymous concern; the anonymity
 * was from everyone else. That made "anonymous" a promise the system did not keep, and the
 * person it mattered most to — someone reporting their own manager — was the person it misled.
 * Now HR sees `Anonymous`, and the eye asks rather than reveals: HR states a reason, the
 * reporter decides, and a refusal is a complete answer that costs them nothing.
 *
 * `hr_concerns.identity_revealed_at` is the flag ConcernResource actually reads. It is stamped
 * here on acceptance and nowhere else. Deriving the answer from these rows instead would mean
 * a resource that forgot to eager-load them silently hid an identity HR was entitled to — the
 * failure would be invisible and blamed on the feature rather than the query.
 *
 * A declined request does NOT bar another: circumstances change, and an investigation can
 * reach a point where asking again is reasonable. Every ask is a row and an audit line, so a
 * pattern of asking is visible to the person being asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_concern_reveal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('concern_id')->constrained('hr_concerns')->cascadeOnDelete();
            // The HR officer asking. Named to the reporter on purpose — being asked to give up
            // anonymity by someone unnamed is the imbalance this whole flow exists to correct.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            // Required. "HR would like to know who you are" is not a reason, and the reporter
            // cannot weigh a request whose purpose is not stated.
            $table->text('reason');
            $table->string('status')->default('pending'); // pending | accepted | declined
            $table->dateTime('responded_at')->nullable();
            // Why they said no. Required on a decline, so the answer is an answer and not a
            // silence HR has to interpret.
            $table->text('decline_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['concern_id', 'status']);
        });

        Schema::table('hr_concerns', function (Blueprint $table) {
            // Null = HR sees `Anonymous`. Stamped only by an accepted reveal request, and never
            // unset — a reporter who agreed to be named cannot be un-named after the fact, and
            // pretending otherwise would be a promise the audit trail already contradicts.
            $table->dateTime('identity_revealed_at')->nullable()->after('is_anonymous');
        });
    }

    public function down(): void
    {
        Schema::table('hr_concerns', function (Blueprint $table) {
            $table->dropColumn('identity_revealed_at');
        });

        Schema::dropIfExists('hr_concern_reveal_requests');
    }
};
