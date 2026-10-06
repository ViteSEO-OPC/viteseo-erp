<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The text/html part an outbound email was ACTUALLY sent with, so Lead Details can show the sender
 * what the recipient received.
 *
 * WHY STORE IT, WHEN `email_template_id` ALREADY POINTS AT THE DESIGN. The template id is enough to
 * render a design at SEND time, and that is still where the HTML is produced. It is not enough to show
 * an email AFTERWARDS: the template's content, its design, the lead's token values, the sender's name
 * and the organization's logo can all change once the message has gone, so a re-render answers "what
 * would this look like today", not "what did we send". This column is the snapshot of the one string
 * that reached the transport, written once, on a successful send.
 *
 * MEDIUMTEXT, NOT TEXT. A designed email may be up to EmailTemplateDesignService::MAX_HTML_BYTES
 * (90,000 bytes), past TEXT's 65,535-byte ceiling.
 *
 * NULLABLE, AND NULL IS THE NORMAL CASE: every plain-text send, every edited-template send that fell
 * back to plain text, every failed send, every inbound email, and every row written before this
 * column existed. Nothing is backfilled — re-rendering old rows would claim a history nobody recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_emails', function (Blueprint $table) {
            $table->mediumText('body_html')->nullable()->after('email_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('crm_emails', function (Blueprint $table) {
            $table->dropColumn('body_html');
        });
    }
};
