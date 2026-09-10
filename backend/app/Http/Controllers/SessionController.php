<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EndSessionsPastTheirCeiling;
use App\Support\AccessLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The devices you are signed in on, and the power to end any of them.
 *
 * Each browser keeps its own session, so signing in on a phone does not sign the
 * laptop out. What replaces "one device only" is this: see every session, and
 * close the one you do not recognise.
 *
 * Rows come from the `sessions` table, which the database session driver already
 * fills with the user, address, browser and last activity. The session id is never
 * returned -- it is the cookie's content, the credential itself -- so each row is
 * named by its SHA-256 instead, and ending one means finding the row of YOURS whose
 * id hashes to that key.
 */
class SessionController extends Controller
{
    /** GET /api/v1/auth/sessions */
    public function index(Request $request): JsonResponse
    {
        $current = $this->currentId($request);

        $sessions = $this->liveSessionsOf($request->user()->id)
            ->map(fn (object $row): array => [
                'key' => $this->keyFor($row->id),
                'device' => self::deviceLabel((string) $row->user_agent),
                'user_agent' => $row->user_agent,
                'ip_address' => $row->ip_address,
                'last_active_at' => Carbon::createFromTimestamp($row->last_activity)->toIso8601String(),
                'current' => $row->id === $current,
            ])
            ->sortBy([['current', 'desc'], ['last_active_at', 'desc']])
            ->values();

        return response()->json(['data' => $sessions]);
    }

    /** DELETE /api/v1/auth/sessions/{key} -- end one of your own sessions. */
    public function destroy(Request $request, string $key): JsonResponse
    {
        $row = DB::table($this->table())
            ->where('user_id', $request->user()->id)
            ->get(['id'])
            ->first(fn (object $row): bool => hash_equals($this->keyFor($row->id), $key));

        // Knowing a key is not enough: it has to name a session of your own.
        abort_if($row === null, 404);

        if ($row->id === $this->currentId($request)) {
            // Ending the session you are using is signing out.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        } else {
            DB::table($this->table())->where('id', $row->id)->delete();
            // Told now, so that browser signs out at once rather than on its next click.
            app(AccessLifecycle::class)->sessionEnded($request->user(), 'session-ended');
        }

        return response()->json(['data' => ['message' => 'Signed out of that device.']]);
    }

    /** DELETE /api/v1/auth/sessions -- end every session but the one asking. */
    public function destroyOthers(Request $request): JsonResponse
    {
        DB::table($this->table())
            ->where('user_id', $request->user()->id)
            ->when($this->currentId($request), fn ($query, string $id) => $query->where('id', '!=', $id))
            ->delete();

        app(AccessLifecycle::class)->sessionEnded($request->user(), 'session-ended');

        return response()->json(['data' => ['message' => 'Signed out of every other device.']]);
    }

    /** "Chrome on Windows" -- enough to recognise a device, not to fingerprint one. */
    public static function deviceLabel(string $userAgent): string
    {
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/'), str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'A browser',
        };

        $platform = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return $platform === null ? $browser : "{$browser} on {$platform}";
    }

    /**
     * Sessions that can still open something: used within the idle window, and not
     * past the thirty-day ceiling. Expired rows linger until the session driver's
     * garbage collection gets to them, but they are not devices to show or to fear.
     */
    private function liveSessionsOf(int $userId): Collection
    {
        $idleSince = now()->subMinutes((int) config('session.lifetime'))->getTimestamp();
        $ceiling = now()->subDays(EndSessionsPastTheirCeiling::CEILING_DAYS)->getTimestamp();

        return DB::table($this->table())
            ->where('user_id', $userId)
            ->where('last_activity', '>=', $idleSince)
            ->get()
            ->filter(fn (object $row): bool => ($this->payloadOf($row)[EndSessionsPastTheirCeiling::SIGNED_IN_AT] ?? PHP_INT_MAX) >= $ceiling);
    }

    /** The session's stored attributes. Empty if encrypted -- the row is then simply shown. */
    private function payloadOf(object $row): array
    {
        $raw = base64_decode((string) $row->payload);
        $data = config('session.serialization') === 'json'
            ? json_decode($raw, true)
            : @unserialize($raw, ['allowed_classes' => false]);

        return is_array($data) ? $data : [];
    }

    private function keyFor(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    private function currentId(Request $request): ?string
    {
        return $request->hasSession() ? $request->session()->getId() : null;
    }

    private function table(): string
    {
        return (string) config('session.table', 'sessions');
    }
}
