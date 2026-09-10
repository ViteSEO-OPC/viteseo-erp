<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank reconciliation: match ledger transactions on a cash/bank account against
 * the bank statement. Completing a reconciliation stamps the cleared journal
 * lines with its id; unstamped lines remain outstanding for next time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->unsignedBigInteger('account_id');
            $table->date('statement_date');
            $table->decimal('statement_balance', 14, 2);
            $table->decimal('beginning_balance', 14, 2)->default(0);
            $table->decimal('cleared_balance', 14, 2)->default(0);
            $table->string('status')->default('completed'); // completed (v1 reconciles in one pass)
            $table->unsignedBigInteger('reconciled_by')->nullable();
            $table->timestamps();

            $table->index(['org_id', 'account_id', 'statement_date']);
        });

        // A journal line is "reconciled" once stamped with a reconciliation id.
        Schema::table('acc_journal_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('bank_reconciliation_id')->nullable()->after('memo');
            $table->index('bank_reconciliation_id');
        });
    }

    public function down(): void
    {
        Schema::table('acc_journal_lines', function (Blueprint $table) {
            $table->dropIndex(['bank_reconciliation_id']);
            $table->dropColumn('bank_reconciliation_id');
        });
        Schema::dropIfExists('acc_bank_reconciliations');
    }
};
