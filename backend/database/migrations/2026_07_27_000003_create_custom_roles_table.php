<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom Roles (v2): an org defines its own roles — a name + a set of accounting
 * capabilities (permissions). Members assigned a custom role get exactly those
 * permissions. `org_user.custom_role_id` records the assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->string('name');
            $table->json('permissions');
            $table->timestamps();

            $table->unique(['org_id', 'name']);
        });

        Schema::table('org_user', function (Blueprint $table) {
            $table->unsignedBigInteger('custom_role_id')->nullable()->after('is_default');
        });
    }

    public function down(): void
    {
        Schema::table('org_user', function (Blueprint $table) {
            $table->dropColumn('custom_role_id');
        });
        Schema::dropIfExists('custom_roles');
    }
};
