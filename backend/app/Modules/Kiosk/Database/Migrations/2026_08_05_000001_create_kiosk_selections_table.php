<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A saved website selection from the Kiosk configurator = a captured lead. Org-
 * scoped (BelongsToOrg) and stamped with the BD/client who built it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kiosk_selections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('prospect_name');
            $table->string('prospect_contact')->nullable();
            $table->text('notes')->nullable();
            $table->string('business_type');
            $table->string('website_type');
            $table->json('sections');       // { header, hero, footer, pages: [...] }
            $table->string('brand_kit');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['org_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosk_selections');
    }
};
