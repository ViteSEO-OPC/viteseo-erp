<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-org on/off store for cross-module "connections" (integrations). A row here
 * is the OVERRIDE, not the source of truth: when a connection is AVAILABLE (both
 * systems availed) it defaults ON, and a stored row with enabled=false is what
 * turns it OFF. Availability itself comes from entitlements + ConnectionRegistry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('org_id')->index();
            $table->string('connection_key');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['org_id', 'connection_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_connections');
    }
};
