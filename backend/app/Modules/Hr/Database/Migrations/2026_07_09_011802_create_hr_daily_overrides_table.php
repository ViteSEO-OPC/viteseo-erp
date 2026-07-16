<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_daily_overrides', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedSmallInteger('grace_minutes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_daily_overrides');
    }
};
