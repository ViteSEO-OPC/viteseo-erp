<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A contribution says WHICH salary figure it is charged on, not just how. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_contribution_versions', function (Blueprint $table) {
            $table->string('salary_basis', 20)->default('basic')->after('basis');
        });
    }

    public function down(): void
    {
        Schema::table('hr_contribution_versions', function (Blueprint $table) {
            $table->dropColumn('salary_basis');
        });
    }
};
