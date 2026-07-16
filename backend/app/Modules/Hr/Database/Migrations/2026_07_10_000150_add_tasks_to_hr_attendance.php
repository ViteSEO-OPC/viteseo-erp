<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tasks the employee logs when they time out — an array of { text, done } items,
// captured in the time-out confirmation modal and shown to HR in the attendance
// detail modal.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance', function (Blueprint $table) {
            $table->json('tasks')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance', function (Blueprint $table) {
            $table->dropColumn('tasks');
        });
    }
};
