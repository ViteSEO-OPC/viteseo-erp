<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service-Area Registry — the PO-managed set of administrative areas that count as a
 * **target service location** (frozen Target Location +10). This is **configuration data owned by
 * the business**, not a formula: an admin adds/removes target areas without a deploy (the approved
 * M5 registry / M2 admin-territory model). A lead is "in target location" when its region/province/
 * city matches an active area at that `level`. Empty registry → no lead is in target (honest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_service_areas', function (Blueprint $table) {
            $table->id();
            // The admin area's name, matched against the lead's field at `level`
            // (e.g. level=city, name="Makati City" matches leads where city = "Makati City").
            $table->string('name');
            // Which admin level this area targets.
            $table->string('level'); // region | province | city
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_service_areas');
    }
};
