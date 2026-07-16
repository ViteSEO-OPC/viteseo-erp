<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// New applicants now land in the candidates table "unassigned" (no pipeline stage)
// until HR drags them onto a kanban column — so `stage` becomes nullable with no
// default. A null stage means "not in the pipeline yet".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->string('stage')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->string('stage')->nullable(false)->default('applied')->change();
        });
    }
};
