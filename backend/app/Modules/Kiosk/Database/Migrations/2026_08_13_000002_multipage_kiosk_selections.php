<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Multi-page: convert the `sections` JSON from the old single-page shape
 * `{header, hero, footer, pages:[{section,variant}]}` to the multi-page shape
 * `{header, footer, pages:[{name, slug, hero, sections:[{section,variant}]}]}`.
 *
 * Existing selections become a single "Home" page carrying their old hero +
 * content sections. Idempotent — rows already in the new shape are skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('kiosk_selections')->get(['id', 'sections']) as $row) {
            $sec = json_decode((string) ($row->sections ?? ''), true);
            if (! is_array($sec)) {
                continue;
            }

            $pages = $sec['pages'] ?? [];
            // New shape already? (a page carries name/slug, not section/variant.)
            if (isset($pages[0]['slug']) || isset($pages[0]['name'])) {
                continue;
            }

            $home = [
                'name' => 'Home',
                'slug' => 'home',
                'hero' => $sec['hero'] ?? null,
                'sections' => array_values(array_filter(
                    is_array($pages) ? $pages : [],
                    static fn ($p): bool => is_array($p) && isset($p['section'], $p['variant']),
                )),
            ];

            DB::table('kiosk_selections')->where('id', $row->id)->update([
                'sections' => json_encode([
                    'header' => $sec['header'] ?? null,
                    'footer' => $sec['footer'] ?? null,
                    'pages' => [$home],
                ]),
            ]);
        }
    }

    public function down(): void
    {
        // Best-effort reverse: flatten back to the old single-page shape using the
        // first page (the Home page).
        foreach (DB::table('kiosk_selections')->get(['id', 'sections']) as $row) {
            $sec = json_decode((string) ($row->sections ?? ''), true);
            if (! is_array($sec) || ! isset($sec['pages'][0]['slug'])) {
                continue;
            }
            $home = $sec['pages'][0];

            DB::table('kiosk_selections')->where('id', $row->id)->update([
                'sections' => json_encode([
                    'header' => $sec['header'] ?? null,
                    'hero' => $home['hero'] ?? null,
                    'footer' => $sec['footer'] ?? null,
                    'pages' => $home['sections'] ?? [],
                ]),
            ]);
        }
    }
};
