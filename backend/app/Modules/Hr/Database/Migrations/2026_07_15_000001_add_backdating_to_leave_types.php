<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-leave-type control over filing for PAST dates. By default a type may only be
// filed for today/future (`allow_backdated` = false). When enabled, HR may optionally
// cap how far back it reaches via `backdate_limit_days` (NULL = no limit). Enforced in
// LeaveService::create(); the employee form mirrors it as the date picker's min date.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $table) {
            $table->boolean('allow_backdated')->default(false)->after('is_paid');
            $table->unsignedSmallInteger('backdate_limit_days')->nullable()->after('allow_backdated');
        });
    }

    public function down(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $table) {
            $table->dropColumn(['allow_backdated', 'backdate_limit_days']);
        });
    }
};
