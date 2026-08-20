<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The withholding tax table becomes dated data instead of a `match` in PHP. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_tax_versions', function (Blueprint $table) {
            $table->id();
            // The date this table took effect. Unique: one published table per start date.
            $table->date('effective_from')->unique();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('hr_tax_brackets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_version_id')->constrained('hr_tax_versions')->cascadeOnDelete();
            // Inclusive lower bound of the band, in annual pesos.
            $table->decimal('min_annual', 12, 2);
            // Tax due on everything below this band — the published "plus" figure.
            $table->decimal('base_tax', 12, 2)->default(0);
            // Marginal rate as a decimal (0.15 = 15%), applied to income above min_annual.
            $table->decimal('rate_on_excess', 6, 4)->default(0);
            $table->timestamps();

            // One band per starting point per table, and the index the resolver reads by.
            $table->unique(['tax_version_id', 'min_annual']);
        });

        // The TRAIN schedule in force from 1 January 2023 (RA 10963, second tranche) — the exact
        // figures that were hardcoded in PayrollService::annualWithholdingTax() before this.
        $versionId = DB::table('hr_tax_versions')->insertGetId([
            'effective_from' => '2000-01-01',
            'note' => 'TRAIN graduated rates (RA 10963), in force from 1 January 2023 — seeded as the opening table so no historical period reprices.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('hr_tax_brackets')->insert(array_map(
            fn (array $b) => [
                'tax_version_id' => $versionId,
                'min_annual' => $b[0],
                'base_tax' => $b[1],
                'rate_on_excess' => $b[2],
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                [0, 0, 0],
                [250000, 0, 0.15],
                [400000, 22500, 0.20],
                [800000, 102500, 0.25],
                [2000000, 402500, 0.30],
                [8000000, 2202500, 0.35],
            ],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_tax_brackets');
        Schema::dropIfExists('hr_tax_versions');
    }
};
