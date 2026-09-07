<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a provider-side billing contact to the customer ORG it represents, so
 * two customer orgs with the same name don't collapse into one billing contact
 * in the provider's books. Null for ordinary contacts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_contacts', function (Blueprint $table) {
            $table->unsignedBigInteger('source_org_id')->nullable()->after('org_id');
            $table->index(['org_id', 'source_org_id']);
        });
    }

    public function down(): void
    {
        Schema::table('acc_contacts', function (Blueprint $table) {
            $table->dropIndex(['org_id', 'source_org_id']);
            $table->dropColumn('source_org_id');
        });
    }
};
