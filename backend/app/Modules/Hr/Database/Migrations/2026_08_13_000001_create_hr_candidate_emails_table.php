<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every candidate email HR sends, as it was actually sent.
 *
 * Until now the only record was `hr_candidates.emails_sent` — a de-duplicated list of type
 * strings. It could not answer any of the questions HR actually asks: what did we say, who
 * else was copied, when did it go, did it arrive. And because the send swallowed transport
 * failures, a bounced email and a delivered one looked identical.
 *
 * One row per send attempt, including the failed ones. `status` distinguishes them, and
 * re-sends append rather than collapse, so the history is a log and not a set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_candidate_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('hr_candidates')->cascadeOnDelete();
            // The stage email this was: online_interview, final_interview, assessment,
            // offer, rejection. Kept as the candidate's board state depends on it.
            $table->string('type', 40);

            $table->string('to_email');
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->string('reply_to')->nullable();
            $table->string('subject');
            // Sanitised HTML — the exact body the candidate received.
            $table->text('body_html');
            // [{path, name, size, mime}] on the private disk.
            $table->json('attachments')->nullable();

            $table->string('status', 20)->default('sent');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // The board reads "has this candidate been sent X yet", and the drawer reads
            // the whole thread newest-first.
            $table->index(['candidate_id', 'type']);
            $table->index(['candidate_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_candidate_emails');
    }
};
