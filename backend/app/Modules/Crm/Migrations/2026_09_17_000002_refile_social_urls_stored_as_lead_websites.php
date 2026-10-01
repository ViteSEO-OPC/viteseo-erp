<?php

use App\Modules\Crm\Services\Website\WebsiteUrlClassifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-file social profiles that were stored in `crm_leads.website` before URLs were classified.
 *
 * WHAT WENT WRONG. Google Places returns one `websiteUri` per place and it is whatever the owner
 * typed there — very often their Facebook page. Discovery stored it verbatim, so the board showed a
 * social profile under WEBSITE and the audit would have offered that page to the identity verifier,
 * where it would have passed on its title and phone and recorded the lead as having a website. On
 * this database that is 79 of 237 discovered URLs.
 *
 * WHAT THIS DOES, AND WHAT IT REFUSES TO DO. It MOVES the URL into the `facebook` / `instagram`
 * column the Lead already has, and clears `website`. Nothing is deleted: a Facebook page is useful
 * contact data, it is simply not a website. A row whose social column is already filled keeps that
 * value and only has `website` cleared — a handle a BD or a spreadsheet import put there is better
 * attested than one inferred from a field being used for the wrong purpose.
 *
 * `website_status` IS NOT TOUCHED. Every affected row is `website-unknown`, which stays exactly
 * right: we still do not know whether the business has a site. Deriving anything else here would be
 * scoring from a migration, and `website_status` is a frozen Lead Score input.
 *
 * Platforms with no column (TikTok, LinkedIn, directory listings, link-in-bio hubs) are deliberately
 * left alone by this migration. Clearing them would drop data with nowhere to put it; they are
 * already handled at the door for new ingests and classified at read time for display.
 *
 * Reversible in shape only: `down()` puts the URL back in `website`, which is where it was, but the
 * original state cannot be distinguished from a lead that legitimately had both. It restores only
 * rows this migration can prove it moved (social column set, website empty).
 */
return new class extends Migration
{
    public function up(): void
    {
        $classifier = new WebsiteUrlClassifier();

        DB::table('crm_leads')
            ->whereNotNull('website')
            ->where('website', '<>', '')
            ->orderBy('id')
            ->chunkById(500, function ($leads) use ($classifier): void {
                foreach ($leads as $lead) {
                    $column = $classifier->socialColumn($lead->website);
                    if ($column === null) {
                        // Either not social, or social with nowhere to put it — a TikTok link, or a
                        // Facebook link-shim too long for the 255-char column. EXISTING DATA IS LEFT
                        // ALONE in that case: a migration clearing a column it cannot re-home is a
                        // migration that destroys records. Display is already honest without it —
                        // the board classifies at read time and will not show these under WEBSITE.
                        continue;
                    }

                    $update = ['website' => null];
                    // Never overwrite a handle the record already carries.
                    if (($lead->{$column} ?? null) === null || $lead->{$column} === '') {
                        $update[$column] = $lead->website;
                    }

                    DB::table('crm_leads')->where('id', $lead->id)->update($update);
                }
            });
    }

    public function down(): void
    {
        $classifier = new WebsiteUrlClassifier();

        foreach (['facebook', 'instagram'] as $column) {
            DB::table('crm_leads')
                ->whereNotNull($column)
                ->where($column, '<>', '')
                ->where(function ($q): void {
                    $q->whereNull('website')->orWhere('website', '');
                })
                ->orderBy('id')
                ->chunkById(500, function ($leads) use ($classifier, $column): void {
                    foreach ($leads as $lead) {
                        if ($classifier->socialColumn($lead->{$column}) !== $column) {
                            continue;
                        }
                        DB::table('crm_leads')->where('id', $lead->id)->update(['website' => $lead->{$column}]);
                    }
                });
        }
    }
};
