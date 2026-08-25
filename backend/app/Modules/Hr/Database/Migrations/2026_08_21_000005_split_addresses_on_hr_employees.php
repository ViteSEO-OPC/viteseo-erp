<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two addresses, as parts rather than one free-text line.
 *
 * A single box asked for "City, Province" and got everything from "Makati" to a full
 * house-number-and-barangay line, which is unusable for the government forms (SSS, PhilHealth,
 * BIR) that ask for each part separately.
 *
 * The original `address` / `current_address` columns are DELIBERATELY LEFT IN PLACE and are not
 * backfilled. Splitting "42 Jasmine St, Quezon City" correctly is a judgement no migration can
 * make — guessing would file a barangay under Street and call it data. The old text is instead
 * shown beneath the new fields as what was previously recorded, until a human re-enters the parts.
 */
return new class extends Migration
{
    /** house_no and zip are short by nature; the rest are ordinary name-length strings. */
    private const PARTS = [
        'house_no' => 50,
        'street' => 150,
        'barangay' => 150,
        'city' => 150,
        'province' => 150,
        'zip' => 20,
    ];

    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $after = 'current_address';

            foreach (['address', 'current_address'] as $prefix) {
                foreach (self::PARTS as $part => $length) {
                    $column = "{$prefix}_{$part}";
                    $table->string($column, $length)->nullable()->after($after);
                    $after = $column;
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $columns = [];

            foreach (['address', 'current_address'] as $prefix) {
                foreach (array_keys(self::PARTS) as $part) {
                    $columns[] = "{$prefix}_{$part}";
                }
            }

            $table->dropColumn($columns);
        });
    }
};
