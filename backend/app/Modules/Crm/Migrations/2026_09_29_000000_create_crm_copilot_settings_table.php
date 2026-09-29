<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the CRM Copilot may use AI — the product toggle under Settings → Integrations.
 *
 * ONE ROW, NO org_id. The AI it switches on spends Vite SEO's own OpenAI/OpenRouter credit (the NERBY
 * `openai` credential, read and never written), so it is a platform decision exactly like the NERBY
 * table beside it, and sits behind the same super-admin-only gate. If organisations are ever meant to
 * decide for themselves, this table needs org_id and its route group goes back to crm_admin.
 *
 * A SEPARATE TABLE, NOT A NERBY OPTION. Turning NERBY's AI on must never turn the Copilot's on, and
 * this toggle must never write to the provider row whose credential it borrows.
 *
 * NO ROWS IS A VALID STATE, AND IT IS THE DEFAULT: an absent row reads as OFF, so nothing is seeded and
 * a fresh install keeps the deterministic Copilot. `COPILOT_AI_ENABLED` remains the ops kill switch
 * above this toggle — AI runs only when both allow it and the credential is available.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_copilot_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('ai_enabled')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_copilot_settings');
    }
};
