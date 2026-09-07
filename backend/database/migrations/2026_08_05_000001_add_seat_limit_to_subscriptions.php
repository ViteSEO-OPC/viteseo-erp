<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-org user seat limit. Every plan includes a base of 10 seats; more are
 * bought in blocks of 10 (each block adds to the monthly price). Enforced when
 * adding a team member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->unsignedInteger('seat_limit')->default(10)->after('monthly_price');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('seat_limit');
        });
    }
};
