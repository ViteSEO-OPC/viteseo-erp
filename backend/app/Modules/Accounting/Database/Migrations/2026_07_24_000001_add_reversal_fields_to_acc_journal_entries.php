<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit + idempotency for reversing entries: when a posted entry is reversed,
 * the original is stamped with reversed_at + the reversal's id, so it can never
 * be reversed twice and you can trace original <-> reversal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_journal_entries', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('posted_at');
            $table->unsignedBigInteger('reversal_entry_id')->nullable()->after('reversed_at');
        });
    }

    public function down(): void
    {
        Schema::table('acc_journal_entries', function (Blueprint $table) {
            $table->dropColumn(['reversed_at', 'reversal_entry_id']);
        });
    }
};
