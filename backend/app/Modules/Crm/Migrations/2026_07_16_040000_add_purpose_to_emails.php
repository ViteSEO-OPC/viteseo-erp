<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RC3 Outreach Automation Pack — tag an outbound email with its business `purpose` (nullable).
 *
 * Additive and backfill-free: existing rows stay NULL. Only the automation send path sets it (the
 * frozen user-facing composer is untouched). The frozen `proposal.sent` definition — "outbound email,
 * purpose=proposal, successfully sent" — reads this column, so a proposal is queryable and the
 * ProposalSent domain event fires deterministically. No enum constraint at the DB level (the set of
 * purposes stays an application concern, mirroring status/source elsewhere).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_emails', function (Blueprint $table): void {
            $table->string('purpose')->nullable()->after('body')->index();
        });
    }

    public function down(): void
    {
        Schema::table('crm_emails', function (Blueprint $table): void {
            $table->dropIndex(['purpose']);
            $table->dropColumn('purpose');
        });
    }
};
