<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase-2. One holiday within a set. `type` is the PH classification
// (Nager can't supply it — set by our classifier for PH, defaulted + flagged for
// other countries, always editable by HR). Only regular / special_non_working are
// non-working days; special_working is a normal working day.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holiday_set_id')->constrained('hr_holiday_sets')->cascadeOnDelete();
            $table->string('name');
            $table->date('date');
            // NULL = unclassified: we only auto-set the type when 100% sure (the
            // legally-fixed Regular Holidays); everything else HR classifies.
            $table->string('type')->nullable(); // regular | special_non_working | special_working | null
            $table->string('source')->default('nager'); // nager | manual
            $table->boolean('needs_review')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_holidays');
    }
};
