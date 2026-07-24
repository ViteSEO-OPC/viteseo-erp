<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business Rules Administration — per-rule snapshot (table 2 of 3; Implementation Design §1.2).
 *
 * One row per rule INSIDE a version — a self-contained snapshot. This is what makes rollback a clean
 * forward-copy: a version carries its complete ruleset, so restoring a prior configuration never depends on
 * reconstructing state from a diff. `version_id` cascades on delete so discarding a draft removes its rules
 * atomically.
 *
 * `rule_key` is IMMUTABLE — the stable identity linking a row to the frozen scoring signal it configures
 * (e.g. `no_website`, `interested_reply`). It is set when the rule is defined and never changed; renaming a
 * rule is not an operation (§1.2).
 *
 * `point_value` is a SIGNED whole number. Negative signals are stored as POSITIVE MAGNITUDES exactly as in
 * the frozen `config/scoring.php` (`has_website` = 15, `no_activity` = 10, `invalid_email` = 15); the sign is
 * carried by `category = negative`, and the frozen calculator subtracts them. Storing magnitudes keeps
 * Version 1 byte-for-byte equal to the file, so the source-switch changes no score.
 *
 * The STRUCTURAL CAPS (fit ≤40, engagement ≤60, score 0–100) and TIME WINDOWS (recent ≤7d, stale >30d) are
 * NOT rows here — they are not per-rule and are not admin-editable in v1, so they remain sourced from the
 * frozen file; the configuration provider (a later slice) merges file caps/windows with these DB point
 * values.
 *
 * `display_order` is PRESENTATION ONLY — the model is additive, so the order rules are shown in never
 * affects any computed score (§1.2). No soft delete (§1.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_business_rules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('version_id')
                ->constrained('crm_business_rule_versions')
                ->cascadeOnDelete();

            $table->string('rule_key');            // IMMUTABLE identity (§1.2).
            $table->text('description');           // business-facing wording (editable).
            $table->string('category');            // category enum (§1.0): fit | engagement | negative.
            $table->integer('point_value');        // signed whole number; negatives stored as magnitudes.
            $table->boolean('is_enabled')->default(true); // disabled → contributes 0.
            $table->integer('display_order')->default(0); // presentation only — never affects the score.

            $table->timestamps(); // No soft delete (§1.6).

            $table->unique(['version_id', 'rule_key']);            // one row per rule per version.
            $table->index(['version_id', 'category', 'display_order']); // ordered display of a version.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_business_rules');
    }
};
