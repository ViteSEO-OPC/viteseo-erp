<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase-2 Slice 25. Report export history — every export is logged so each report
// shows a "who exported this, when" trail.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_report_exports', function (Blueprint $table) {
            $table->id();
            $table->string('report_key');
            $table->json('params')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('report_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_report_exports');
    }
};
