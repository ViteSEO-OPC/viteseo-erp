<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company → Benefits redesign: a benefit program is now grouped into the "Allowances"
 * or "Company benefits" section (government-mandated contributions stay in
 * hr_contribution_settings and are system-managed). Adds the peso amount an allowance
 * pays, a longer description for company benefits, and the card's dot colour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_benefit_programs', function (Blueprint $table) {
            $table->string('kind', 20)->default('company')->after('category'); // allowance | company
            $table->decimal('amount', 12, 2)->nullable()->after('kind');        // allowance: peso / month
            $table->text('description')->nullable()->after('amount');           // company benefit blurb
            $table->string('color', 20)->nullable()->after('description');      // card dot colour
        });
    }

    public function down(): void
    {
        Schema::table('hr_benefit_programs', function (Blueprint $table) {
            $table->dropColumn(['kind', 'amount', 'description', 'color']);
        });
    }
};
