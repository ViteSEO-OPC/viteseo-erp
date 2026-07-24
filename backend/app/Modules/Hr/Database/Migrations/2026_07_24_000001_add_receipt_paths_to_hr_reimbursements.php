<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_reimbursements', function (Blueprint $table) {
            // A claim can carry several receipts. The legacy single `receipt_path`
            // column is kept for existing rows; the Resource merges both.
            $table->json('receipt_paths')->nullable()->after('receipt_path');
        });
    }

    public function down(): void
    {
        Schema::table('hr_reimbursements', function (Blueprint $table) {
            $table->dropColumn('receipt_paths');
        });
    }
};
