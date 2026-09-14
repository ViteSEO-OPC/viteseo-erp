<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an OJT applicant is asked for and a standard one is not: the school sending them,
 * and the practicum hours that school requires them to render. Both stay null on a
 * standard candidate rather than being asked for and left blank -- the fields appear on
 * the form only when the requisition they are applying to is an OJT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->string('school')->nullable()->after('location');
            $table->unsignedInteger('required_hours')->nullable()->after('school');
        });
    }

    public function down(): void
    {
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->dropColumn(['school', 'required_hours']);
        });
    }
};
