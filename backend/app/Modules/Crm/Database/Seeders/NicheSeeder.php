<?php

namespace App\Modules\Crm\Database\Seeders;

use App\Modules\Crm\Models\Niche;
use Illuminate\Database\Seeder;

/**
 * Seeds the built-in Business Niches — the canonical vocabulary offered at the top of the shared
 * Business Niche selector, above the user-created custom niches.
 *
 * ⚠️ THE CANONICAL LIST IS NOT YET APPROVED. The Product Owner deferred it for separate review, so
 * {@see BUILT_INS} is intentionally EMPTY and this seeder is a no-op. Nothing is invented here:
 * none of the three vocabularies already in the codebase is canonical — the 5 options hardcoded in
 * the old create-lead wizard, the 13 test values in LeadFactory, and the ~19 OpenStreetMap tags the
 * scraper writes are all incidental, not decisions.
 *
 * When the list is approved: add the names to BUILT_INS, run this seeder, then run
 * `php artisan crm:backfill-niches` to register the niche values already present on existing leads.
 *
 * Idempotent — safe to re-run after the list changes. Existing rows are re-marked as built-in, so a
 * value that was auto-registered as a custom niche by the backfill is promoted (not duplicated) once
 * it is later approved as built-in. Removing a name from BUILT_INS does NOT delete its row; retiring
 * an approved built-in is a deliberate data decision, not a side effect of editing this file.
 */
class NicheSeeder extends Seeder
{
    /**
     * The approved built-in Business Niches, in any order (the API sorts alphabetically).
     *
     * @var list<string>
     */
    private const BUILT_INS = [
        // PENDING PO APPROVAL — see the class docblock. Deliberately empty.
    ];

    public function run(): void
    {
        foreach (self::BUILT_INS as $name) {
            Niche::query()->updateOrCreate(
                ['name' => $name],
                ['is_custom' => false],
            );
        }
    }
}
