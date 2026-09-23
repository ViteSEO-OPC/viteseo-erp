<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attaches an existing resource mapping to the connection that granted it.
 *
 * NULLABLE, AND STAYING THAT WAY. The two Meta resources live in production today were provisioned
 * by hand before connections existed; there is no connection for them to point at and inventing one
 * would be fabricating a grant that never happened. They keep a null, which reads correctly as
 * "mapped by an operator, not by an OAuth flow".
 *
 * WEBHOOK RESOLUTION MUST NOT LEARN ABOUT THIS COLUMN. `MetaAccountResolver` answers "whose Page is
 * this?" from (provider, resource_type, external_id, is_active) and nothing else. Adding a join to
 * a connection — or worse, a status check on it — would make inbound delivery fail whenever a
 * tenant's outbound grant lapsed, which is exactly the coupling the separate tables exist to avoid.
 *
 * NULL ON DELETE, not cascade: removing a connection must not delete the resource mappings that
 * historical conversations point at. The mapping survives as an operator-owned row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_provider_accounts', function (Blueprint $table) {
            $table->foreignId('connection_id')
                ->nullable()
                ->after('org_id')
                ->constrained('crm_provider_connections')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_provider_accounts', function (Blueprint $table) {
            $table->dropForeign(['connection_id']);
            $table->dropColumn('connection_id');
        });
    }
};
