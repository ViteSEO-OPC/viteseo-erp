<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Any number of further government IDs — a passport, a driver's licence, a PRC card — kept as a
 * list of {type, number} beside the four named columns.
 *
 * TEXT, and deliberately neither `json` nor a pair of columns. The NUMBERS are the same class of
 * data as the TIN beside them, and a list cannot encrypt just one field of each entry, so the whole
 * list is encrypted together (`encrypted:array` on the model). What lands in the column is
 * therefore a ciphertext string: a `json` column would reject it outright, and a varchar would
 * silently truncate it into something that can never be decrypted again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->text('other_ids')->nullable()->after('pagibig_number');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn('other_ids');
        });
    }
};
