<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** An allowance remembers what it used to be worth. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_benefit_program_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('benefit_program_id')->constrained('hr_benefit_programs')->cascadeOnDelete();
            $table->date('effective_from');
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('frequency', 20)->default('monthly');
            $table->string('tax_treatment', 20)->default('de_minimis');
            $table->decimal('deminimis_ceiling', 12, 2)->nullable();
            $table->string('ceiling_period', 20)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Named explicitly: the generated name would be 68 characters and MySQL caps an
            // identifier at 64.
            $table->unique(['benefit_program_id', 'effective_from'], 'hr_benefit_prog_versions_program_date_unique');
        });

        // The opening version per allowance — dated far enough back that no cycle predates it.
        $now = now();
        $rows = DB::table('hr_benefit_programs')
            ->where('kind', 'allowance')
            ->get(['id', 'amount', 'frequency', 'tax_treatment', 'deminimis_ceiling', 'ceiling_period']);

        foreach ($rows as $row) {
            DB::table('hr_benefit_program_versions')->insert([
                'benefit_program_id' => $row->id,
                'effective_from' => '2000-01-01',
                'amount' => $row->amount,
                'frequency' => $row->frequency,
                'tax_treatment' => $row->tax_treatment,
                'deminimis_ceiling' => $row->deminimis_ceiling,
                'ceiling_period' => $row->ceiling_period,
                'created_by' => null, // written by this migration, not by a person
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_benefit_program_versions');
    }
};
