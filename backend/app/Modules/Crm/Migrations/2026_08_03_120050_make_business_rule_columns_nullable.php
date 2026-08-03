<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen the two lead-scoring-specific columns on `crm_business_rules` — `point_value` and
 * `category` — to nullable, so a module that has neither can store rules without inventing values.
 *
 * WHY. A `lead_scoring` rule carries a scoring magnitude and a category; a `kpi` definition has
 * neither. A KPI is not worth "points", and `BusinessRuleCategory` is documented as "the SOLE
 * carrier of a rule's arithmetic sign" (fit/engagement added, negative subtracted) — a concept with
 * no meaning for a metric. Storing 0 would assert a KPI is worth zero points and be
 * indistinguishable from a rule deliberately set to zero; adding KPI groupings as enum cases would
 * leave the sign undefined for them and weaken a frozen scoring guarantee. NULL says the correct
 * thing: not applicable to this module.
 *
 * WIDENING ONLY. Every existing row keeps its values untouched and lead_scoring writes continue to
 * supply both. Nothing about scoring semantics, the frozen calculator, or the baseline Version 1
 * changes — this only permits an absence that could not previously be expressed. It runs BEFORE the
 * KPI catalog seed, which depends on both columns accepting NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_business_rules', function (Blueprint $table) {
            $table->integer('point_value')->nullable()->change();
            $table->string('category')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reversible only while no NULL rows exist; KPI definitions must be removed first (their own
        // seed migration's down() does exactly that).
        Schema::table('crm_business_rules', function (Blueprint $table) {
            $table->integer('point_value')->nullable(false)->change();
            $table->string('category')->nullable(false)->change();
        });
    }
};
