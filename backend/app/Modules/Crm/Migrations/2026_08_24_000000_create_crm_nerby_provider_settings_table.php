<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nearby lead-generation provider configuration — the production home for what has until now lived
 * only in the scraper's `.env`.
 *
 * ONE ROW PER PROVIDER, not one row of settings. This shape is the whole point: Nearby is meant to
 * become a multi-provider discovery system (OpenStreetMap today, OpenAI next, a structured Places
 * provider under evaluation), and an admin must be able to hold credentials for several at once and
 * switch between them without re-entering anything. A single settings row with `openai_*` columns
 * would make every new provider a migration AND would force one provider's configuration to
 * overwrite another's.
 *
 * Adding a provider is therefore an enum case and nothing else — no schema change. `provider` is a
 * varchar rather than an enum column for exactly that reason.
 *
 * `is_active` selects the provider Nearby uses. At most one row may hold it, an invariant enforced in
 * {@see \App\Modules\Crm\Services\Discovery\NerbyProviderSettingService} (the sole writer) inside a
 * transaction, rather than by a partial unique index — MySQL has no filtered indexes, and a
 * CHECK-based emulation would be a constraint no other table here relies on.
 *
 * NO ROWS IS A VALID STATE, AND IT IS THE DEFAULT. A fresh install has an empty table and must behave
 * exactly as it does today: OpenStreetMap, no AI, no credential. The service reads "no active row" as
 * the OpenStreetMap baseline, so nothing has to be seeded for the CRM to work.
 *
 * `api_key` is ENCRYPTED at rest by the model cast (Laravel APP_KEY), so it holds ciphertext and can
 * never be matched with a WHERE clause — each row carries a distinct IV, so equality silently fails.
 * `key_last4` exists as a separate PLAIN column because the UI needs something to display and
 * diagnostics need something to compare, and neither may touch the ciphertext. The same trap is
 * documented on {@see \App\Modules\Crm\Models\MailboxOauthToken}.
 *
 * `options` carries whatever a given provider needs and nothing else does — the OpenAI model name
 * today; a region bias or language for a Places provider tomorrow. Provider-specific columns would
 * have accumulated one per provider, mostly NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_nerby_provider_settings', function (Blueprint $table) {
            $table->id();

            // The provider this row configures. Unique: one configuration per provider. Plain and
            // indexed precisely because the credential column beside it is neither.
            $table->string('provider')->unique();

            // Which provider Nearby actually uses. At most one row true (service-enforced).
            $table->boolean('is_active')->default(false);

            // Whether this provider is permitted to run. Separate from `is_active` so an admin can
            // keep a provider fully configured while switched off, and turn it back on without
            // re-entering a credential. Meaningless for providers that need no credentials
            // (OpenStreetMap), which are always runnable.
            $table->boolean('enabled')->default(false);

            // Ciphertext at rest. NEVER returned by any API, never sent to a frontend client.
            $table->text('api_key')->nullable();
            // Display/diagnostic hint only — the last 4 characters.
            $table->string('key_last4', 8)->nullable();

            // Provider-specific configuration (e.g. {"model": "gpt-4o-mini"}).
            $table->json('options')->nullable();

            // Outcome of the last explicit connection test.
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_nerby_provider_settings');
    }
};
