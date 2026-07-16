<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drafts are an Email lifecycle state (status=draft): an outbound email composed but not yet
 * sent, so no transport has been chosen. `provider` must therefore be nullable. Additive and
 * non-destructive — existing sent/received rows keep their provider; the unique
 * (provider, message_id) index is unaffected (drafts leave both null, which MySQL treats as
 * distinct). No new table or column is introduced; the draft reuses the existing `emails` table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_emails', function (Blueprint $table) {
            $table->string('provider')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('crm_emails', function (Blueprint $table) {
            $table->string('provider')->nullable(false)->change();
        });
    }
};
