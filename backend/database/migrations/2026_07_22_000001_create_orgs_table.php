<?php

/*
 * MOVED HERE 2026-09-03, from app/Modules/Accounting/Database/Migrations/.
 *
 * `orgs` is the tenant table -- the thing every org_id in this schema points at,
 * the thing BelongsToOrg fences on, the root of the whole multi-tenancy design.
 * It was created inside the ACCOUNTING module because that is where tenancy was
 * first needed, and it stayed there.
 *
 * Removing Accounting removed it, and the very next `migrate:fresh` failed on
 * `no such table: orgs` -- the platform's foundation deleted as a side effect of
 * dropping one product. Which is the argument for the move, not just the cause of
 * it: a table three other modules depend on does not belong to any one of them.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orgs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orgs');
    }
};
