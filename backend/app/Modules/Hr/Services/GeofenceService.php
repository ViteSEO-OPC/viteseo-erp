<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Attendance;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Decides whether an office clock-in really came from the office.
 *
 * Two independent signals, and they are NOT interchangeable:
 *
 *   • The office **IP** is mandatory. To hold the office's public address you must be
 *     physically on its network — that is what NAT means. Nothing verifies without it.
 *   • **GPS** is corroboration, used only when the browser hands back a reading good
 *     enough to be worth believing.
 *
 * WHY GPS can't be mandatory (the desktop problem): `navigator.geolocation` answers on
 * every machine, GPS hardware or not. A phone or a Wi-Fi laptop trilaterates to tens of
 * metres; an ethernet desktop with no Wi-Fi radio falls back to resolving its own IP and
 * returns a CITY-level guess — kilometres out, and not as an error but as confident-looking
 * coordinates. Requiring both signals unconditionally would therefore reject the office's
 * own desk staff every morning and dump them all in HR's selfie queue.
 *
 * The browser tells us how much to trust each fix via `coords.accuracy` (metres, 95%
 * confidence), so that — not user-agent sniffing — is what decides. A laptop reports a
 * desktop-class user agent and geolocates perfectly well; the accuracy number doesn't lie.
 *
 * Documented in HRIS-Geofenced-Attendance-Plan.md §67/§70 as the "office-IP shortcut for
 * desktops"; the MVP plan lost it in the trim.
 */
class GeofenceService
{
    /**
     * Fixes worse than this are treated as no fix at all, and the IP carries the check
     * alone. 150 m sits above honest indoor GPS/Wi-Fi drift (~20–100 m) and far below the
     * kilometres an IP-derived guess reports, so it separates the two cleanly.
     *
     * A constant, not a policy column, on purpose: HR has no way to judge a good value, and
     * a wrong one silently changes who can clock in. The radius IS theirs to set — that is
     * the knob with a real-world meaning.
     */
    public const MAX_ACCURACY_M = 150;

    /** Mean Earth radius (metres) — the usual haversine constant. */
    private const EARTH_RADIUS_M = 6_371_000.0;

    public function __construct(private readonly AttendancePolicyService $policy) {}

    /**
     * Is the geofence actually going to check anything?
     *
     * True only when the toggle is on AND the policy is complete enough to enforce. A
     * half-configured policy answers FALSE, which is the same answer employees get when the
     * feature is off — the fail-open path (§2.5) has to be invisible in the UI too, or the
     * time clock would demand a location it is about to ignore.
     */
    public function enforcing(): bool
    {
        return $this->config() !== null;
    }

    /**
     * The enforceable office config, or null when there is nothing to enforce.
     *
     * @return ?array{ips: array<int, string>, lat: float, lng: float, radius: int}
     */
    private function config(): ?array
    {
        $policy = $this->policy->get();

        if (! $policy->geofence_enabled) {
            return null;
        }

        // Blank rows are HR mid-edit, not addresses — an all-blank list is an empty list.
        $ips = array_values(array_filter(array_map('trim', $policy->office_ips ?? [])));

        if ($ips === [] || $policy->office_lat === null || $policy->office_lng === null) {
            return null;
        }

        return [
            'ips' => $ips,
            'lat' => (float) $policy->office_lat,
            'lng' => (float) $policy->office_lng,
            'radius' => (int) $policy->office_radius_m,
        ];
    }

    /**
     * Verify an office clock-in, returning the method that proved it — or null when no
     * check applied and the punch should simply go through.
     *
     * @param  ?float  $accuracy  browser-reported radius of confidence, in metres
     * @param  ?string  $ip  the address the SERVER sees; never a client-supplied value
     * @return ?string  one of Attendance::LOCATION_IP_GPS | LOCATION_IP_ONLY, or null
     *
     * @throws ValidationException when the employee is provably not at the office
     */
    public function verifyOfficeClockIn(?float $lat, ?float $lng, ?float $accuracy, ?string $ip): ?string
    {
        $config = $this->config();

        // Either off — the kill switch — or on but with nothing to check against.
        //
        // FAIL OPEN (plan §2.5): a half-finished setting must never lock the whole company
        // out of its own time clock. Loud in the log for the misconfigured case, silent for
        // the deliberate off case, and invisible to the employee in both.
        if ($config === null) {
            $policy = $this->policy->get();

            if ($policy->geofence_enabled) {
                Log::warning('Geofenced clock-in is enabled but not configured — allowing this clock-in unchecked.', [
                    'has_office_ips' => array_filter(array_map('trim', $policy->office_ips ?? [])) !== [],
                    'has_office_pin' => $policy->office_lat !== null && $policy->office_lng !== null,
                ]);
            }

            return null;
        }

        // 1 — The network. Mandatory, and checked first: being off it is the one failure we
        // can state with certainty, whatever the GPS claims.
        if ($ip === null || ! in_array($ip, $config['ips'], true)) {
            throw ValidationException::withMessages([
                'location' => [
                    "You don't appear to be on the office network. If you're working off-site, use the Outside option instead.",
                ],
            ]);
        }

        // 2 — No fix, or one too vague to mean anything. The employee is on the office
        // network, which is presence enough; record that GPS played no part.
        if ($lat === null || $lng === null || $accuracy === null || $accuracy > self::MAX_ACCURACY_M) {
            return Attendance::LOCATION_IP_ONLY;
        }

        // 3 — Both signals. The reading's own margin of error is forgiven, so someone
        // genuinely inside isn't rejected by a fix that happens to be drifting outward.
        $distance = $this->distanceMeters($lat, $lng, $config['lat'], $config['lng']);

        if ($distance - $accuracy > $config['radius']) {
            throw ValidationException::withMessages([
                'location' => [
                    'You appear to be about ' . $this->roundedDistance($distance)
                        . " from the office. If you're working off-site, use the Outside option instead.",
                ],
            ]);
        }

        return Attendance::LOCATION_IP_GPS;
    }

    /**
     * Great-circle distance between two points, in metres. Haversine — no external service,
     * accurate to well under a metre at the scales a geofence cares about.
     */
    public function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** "350 m" / "1.4 km" — a distance an employee can act on, not a decimal. */
    private function roundedDistance(float $metres): string
    {
        return $metres >= 1000
            ? round($metres / 1000, 1) . ' km'
            : round($metres / 10) * 10 . ' m';
    }
}
