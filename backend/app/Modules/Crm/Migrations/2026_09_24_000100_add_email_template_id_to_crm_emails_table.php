<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which designed template an outbound email was composed from — the one thing the send path needed to
 * render the design a person actually previewed.
 *
 * WHY AN ID AND NOT THE HTML. The obvious alternative is a `body_html` column holding the rendered
 * email. It was rejected deliberately: it would store 10–90 KB per row for something derivable, it
 * would freeze the design at compose time, and it would break the pattern this codebase already
 * follows — {@see \App\Modules\Crm\Services\Outreach\OutreachEmailService::htmlFor()} renders the
 * formatted-reply HTML at SEND time from the stored `body_doc`, which is exactly what lets a
 * scheduled email keep its formatting. A foreign key costs eight bytes and reaches the same place.
 *
 * NULLABLE, AND NULL IS THE NORMAL CASE. Every email written before this column existed, every
 * plain-text send, every formatted reply and every automation send carries NULL and behaves exactly
 * as it always has. Only a send that names an unedited designed template sets it. Nothing is
 * backfilled: an old row has no template to point at, and inventing one would be a claim about
 * history that nobody made.
 *
 * `nullOnDelete` rather than cascade: deleting a template must never delete the record that an email
 * was sent. The email keeps its `body` — the plain-text form it was always going to fall back to —
 * and simply stops being able to re-render its design, which is the correct degradation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_emails', function (Blueprint $table) {
            $table->foreignId('email_template_id')
                ->nullable()
                ->after('body_doc')
                ->constrained('crm_email_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_emails', function (Blueprint $table) {
            $table->dropConstrainedForeignId('email_template_id');
        });
    }
};
