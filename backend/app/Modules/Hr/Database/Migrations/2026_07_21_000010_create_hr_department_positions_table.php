<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Roles/positions that exist within a department (e.g. Sales → Business
        // Developer, Account Manager). A lightweight config catalog: the employee
        // form's "role" field is populated from this. Employees still store their
        // role as a string (soft link), so removing a position never orphans data.
        Schema::create('hr_department_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('hr_departments')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['department_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_department_positions');
    }
};
