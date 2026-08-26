<?php

namespace App\Modules\Crm\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The signed-in user's own profile photo: where the bytes live, and how a browser is given one.
 *
 * Files go on the PRIVATE disk (`storage/app/private`), never `public` — a profile photo is a
 * picture of a colleague's face, so it must not be reachable by guessing a URL. Reads are issued as
 * SHORT-LIVED SIGNED URLs rather than through an authorized download endpoint, and that difference
 * is deliberate: an avatar is rendered by an `<img>` tag, which sends no Authorization header, so
 * the CRM's usual bearer-guarded download route (see {@see LeadAttachmentStore}) cannot serve one.
 * A signature carries its own authorization and expires on its own. Same mechanism the HR employee
 * photo already uses in production; reimplemented here rather than imported, because CRM code must
 * not depend on an HR class.
 *
 * ── The shared column ────────────────────────────────────────────────────────────────────────
 * The path is stored in `users.avatar`, which is a SHARED column: it was added by the Projects
 * module (migration `2026_07_15_000001_add_projects_fields_to_users_table`, "Profile picture
 * URL/path") and is READ by three Projects resources. Until now nothing in the platform wrote it.
 *
 * Two rules keep that adoption safe, and both live in this class:
 *  - {@see self::url()} accepts an absolute URL unchanged, so if Projects ever stores one, the CRM
 *    renders it instead of mangling it.
 *  - {@see self::owns()} gates every disk deletion, so the CRM can only ever unlink a file it wrote
 *    itself. A value in an unrecognised format is left completely alone — the row is cleared, but
 *    no foreign file is destroyed on a guess.
 */
final class ProfilePhotoService
{
    /** The private disk (storage/app/private). Deliberately NOT `public` — see the class docblock. */
    public const DISK = 'local';

    /** How long an issued link stays valid. Matches the HR photo TTL. */
    public const LINK_TTL_MINUTES = 30;

    /** Every path this service writes lives under here, which is what makes ownership decidable. */
    public const DIRECTORY = 'crm/profile-photos';

    /**
     * Replace the user's photo. The previous file is discarded — a profile photo is overwritten in
     * place, so there is no history worth keeping.
     *
     * The row is updated BEFORE the old file is unlinked: if the delete fails, the user still has a
     * working new photo and the platform has one orphaned file, which is the harmless direction to
     * fail in. Doing it the other way round can leave a row pointing at bytes that no longer exist.
     */
    public function set(User $user, UploadedFile $photo): User
    {
        $previous = (string) $user->avatar;

        $path = $photo->store(self::DIRECTORY.'/'.$user->getKey(), self::DISK);

        if ($path === false || $path === '') {
            throw new \RuntimeException('Failed to store the uploaded photo.');
        }

        $user->forceFill(['avatar' => $path])->save();

        $this->discard($previous, $path);

        return $user->refresh();
    }

    /** Drop the photo, falling the avatar back to initials. Idempotent. */
    public function remove(User $user): User
    {
        $previous = (string) $user->avatar;

        $user->forceFill(['avatar' => null])->save();

        $this->discard($previous);

        return $user->refresh();
    }

    /**
     * A browser-usable link for a stored avatar, or null when there is nothing to show.
     *
     * Three cases, in order. An absolute URL is returned untouched (a value this module did not
     * write — respected, not reinterpreted). A path this module owns becomes a short-lived signed
     * URL. Anything else yields null: an unrecognised format is not something to guess about, and
     * `null` simply renders initials.
     */
    public static function url(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (! self::owns($path)) {
            return null;
        }

        return Storage::disk(self::DISK)->temporaryUrl(
            $path,
            now()->addMinutes(self::LINK_TTL_MINUTES),
        );
    }

    /** Whether this module wrote the file at `$path` — the guard on every deletion. */
    public static function owns(?string $path): bool
    {
        return str_starts_with(trim((string) $path), self::DIRECTORY.'/');
    }

    /**
     * Unlink a superseded file, but only one this module owns and only when it is genuinely
     * superseded (re-storing to the same path must not delete the file just written).
     */
    private function discard(string $previous, ?string $replacement = null): void
    {
        if ($previous === '' || $previous === $replacement || ! self::owns($previous)) {
            return;
        }

        Storage::disk(self::DISK)->delete($previous);
    }
}
