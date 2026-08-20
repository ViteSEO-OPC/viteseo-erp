<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Whether anyone coming in on a suspended day has to ask first. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_work_suspensions', function (Blueprint $table) {
            $table->boolean('work_requires_request')->default(false)->after('is_paid');
        });
    }

    public function down(): void
    {
        Schema::table('hr_work_suspensions', function (Blueprint $table) {
            $table->dropColumn('work_requires_request');
        });
    }
};
