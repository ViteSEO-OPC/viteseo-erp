<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('department_id')->constrained('hr_departments');
            $table->foreignId('hiring_manager_id')->nullable()->constrained('hr_employees');
            $table->integer('openings')->default(1);
            $table->string('stage')->default('draft'); // draft | active | offer_out | closed
            $table->date('opened_at');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_requisitions');
    }
};
