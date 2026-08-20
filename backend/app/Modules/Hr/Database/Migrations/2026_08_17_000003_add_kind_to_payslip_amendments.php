<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A correction can now TAKE money as well as give it, and it has to say which. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslip_amendments', function (Blueprint $table) {
            // Every amendment raised before this migration was extra pay: nothing else could be
            // recorded, so the default is the truth for existing rows rather than a guess.
            $table->enum('kind', ['pay', 'pay_reversal', 'deduction'])
                ->default('pay')
                ->after('payslip_id');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslip_amendments', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
