<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHO THE EMPLOYER IS — one row, edited under Company → Settings.
 *
 * Until now the company's name and address were typed into four separate places that had to
 * be kept in step by hand (payslip.blade.php, payslip-document.tsx, PayrollXlsxWriter and
 * report.blade.php) — and had already drifted: the report said "Viteseo Inc." while the
 * payslip said the legal name, and the workbook carried a shorter address than the payslip.
 * This table is the single source those four now read.
 *
 * The opening row is seeded with exactly what those files hardcoded, so a fresh install
 * renders byte-identical documents until HR edits them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_company_profiles', function (Blueprint $table) {
            $table->id();
            // What appears on the payslip letterhead — the registered name.
            $table->string('legal_name');
            // The everyday name, for screens where the legal name is too long. Optional.
            $table->string('trade_name')->nullable();
            // One line, as it prints. Deliberately NOT split into components like the
            // employee address: this is letterhead text, never matched or filtered on.
            $table->string('address', 500)->nullable();
            // Employer registration numbers. Payslips commonly carry them, and payroll
            // remittance files will need them — kept here rather than in .env so HR owns them.
            $table->string('tin', 32)->nullable();
            $table->string('sss_employer_number', 32)->nullable();
            $table->string('philhealth_employer_number', 32)->nullable();
            $table->string('pagibig_employer_number', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('website')->nullable();
            // On the private disk, like employee photos — reached through a signed URL.
            $table->string('logo_path')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('hr_company_profiles')->insert([
            'legal_name' => 'VITE SEO DIGITAL ADVERTISING OPC',
            'trade_name' => 'Viteseo',
            'address' => '26L Burgundy Corporate Tower, 252 Sen. Gil Puyat Avenue, '
                .'Pio Del Pilar 1230 City of Makati NCR, Fourth District Philippines',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_company_profiles');
    }
};
