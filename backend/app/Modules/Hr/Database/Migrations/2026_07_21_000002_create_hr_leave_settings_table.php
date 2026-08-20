<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Singleton company setting for the leave allotment model:
// not_shared = each leave type carries its own per-status credits (default)
// shared     = one pool per employment status, spent across any leave type
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_leave_settings', function (Blueprint $table) {
            $table->id();
            $table->string('allotment_mode')->default('not_shared');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_leave_settings');
    }
};
