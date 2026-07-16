<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase-2 Slice 15. A holiday set = one country + one year (so multiple countries
// can be maintained side by side). Populated from Nager.Date or added manually.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_holiday_sets', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 2);
            $table->unsignedSmallInteger('year');
            $table->string('source')->default('nager'); // nager | manual
            $table->dateTime('refreshed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['country_code', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_holiday_sets');
    }
};
