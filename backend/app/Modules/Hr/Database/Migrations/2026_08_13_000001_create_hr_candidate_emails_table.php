<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Every candidate email HR sends, as it was actually sent. */
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
