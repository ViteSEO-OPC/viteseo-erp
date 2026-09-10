<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every token carries its own expiry; the hidden created_at rule goes.
 *
 * Until now the guard expired a token once created_at was more than
 * sanctum.expiration (30 days) ago, and expires_at stayed NULL. That rule is
 * switched off in config/sanctum.php, and without it NULL would mean "never" --
 * reviving every token the rule had already killed. So before it goes, each row
 * gets the expiry the rule implied: created_at + 30 days. Nothing dead today comes
 * back; nothing alive today dies early.
 *
 * Browser tokens (name "web") are deleted outright. Login stopped issuing them
 * when browsers moved to httpOnly session cookies, and every copy was kept in
 * localStorage, where any script on the page could read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('personal_access_tokens')->where('name', 'web')->delete();

        // get(), not each(): each() pages by offset, and every row updated here
        // drops out of the whereNull() it is paging through, so it would skip rows.
        DB::table('personal_access_tokens')->whereNull('expires_at')->get(['id', 'created_at'])
            ->each(function (object $token): void {
                DB::table('personal_access_tokens')->where('id', $token->id)->update([
                    // No created_at means nothing to age it by: treated as spent.
                    'expires_at' => $token->created_at === null
                        ? now()
                        : Carbon::parse($token->created_at)->addDays(30),
                ]);
            });
    }

    /**
     * Rows stay as written. Deleted browser tokens cannot be restored, and putting
     * 'expiration' back in config/sanctum.php brings the old rule back on its own.
     */
    public function down(): void
    {
    }
};
