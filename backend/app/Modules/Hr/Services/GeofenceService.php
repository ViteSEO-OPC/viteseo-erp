<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Attendance;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Decides whether an office clock-in really came from the office. */
class GeofenceService
{
    /**
     * Fixes worse than this are treated as no fix at all, and the IP carries the check
     * alone. 150 m sits above honest indoor GPS/Wi-Fi drift (~20–100 m) and far below the
     * kilometres an IP-derived guess reports, so it separates the two cleanly.
     */
    public const MAX_ACCURACY_M = 150;

    /** Mean Earth radius (metres) — the usual haversine constant. */
    private const EARTH_RADIUS_M = 6_371_000.0;

    public function __construct(private readonly AttendancePolicyService $policy) {}

    /** Is the geofence actually going to check anything? */
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
     * @throws ValidationException when the employee is provably not at the office
     */
    public function verifyOfficeClockIn(?float $lat, ?float $lng, ?float $accuracy, ?string $ip): ?string
    {
        $config = $this->config();

        // Either off — the kill switch — or on but with nothing to check against.
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
