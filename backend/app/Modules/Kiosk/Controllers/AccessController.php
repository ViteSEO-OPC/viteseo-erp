<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Org;
use App\Models\User;
use App\Modules\Kiosk\Models\KioskAccessGrant;
use App\Support\CurrentOrg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues + manages 24h temp kiosk logins for long-distance client demos. A BD
 * (kiosk.selections.manage) generates a throwaway account (role kiosk_client);
 * the plaintext password is returned ONCE so the BD can share it. Access self-
 * expires after 24h (EnsureKioskAccess), or the BD can revoke early.
 */
class AccessController extends Controller
{
    private const HOURS = 24;

    /** GET /api/v1/kiosk/access — temp client grants for the current org. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('kiosk.selections.manage'), 403);

        $grants = KioskAccessGrant::query()->with('user:id,email')->latest()->get();

        return response()->json([
            'data' => $grants->map(fn (KioskAccessGrant $g): array => [
                'id' => $g->id,
                'label' => $g->label,
                'email' => $g->user?->email,
                'status' => $g->status(),
                'expires_at' => $g->expires_at?->toIso8601String(),
                'created_at' => $g->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /** POST /api/v1/kiosk/access — create a 24h temp client login. */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('kiosk.selections.manage'), 403);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
        ]);

        $token = Str::lower(Str::random(8));
        $email = "kiosk-{$token}@demo.viteseo.ph";
        $password = Str::random(10);
        $orgId = CurrentOrg::id();

        // Kiosk temp clients occupy an org seat like everyone else.
        abort_unless(\App\Support\Seats::canAdd($orgId), 422, 'Seat limit reached — remove expired kiosk grants or add seats.');

        $grant = DB::transaction(function () use ($data, $email, $password, $orgId, $request): KioskAccessGrant {
            $user = User::create([
                'name' => $data['label'],
                'email' => $email,
                'password' => $password, // hashed by the User cast
            ]);
            $user->assignRole('kiosk_client');
            Org::find($orgId)?->users()->attach($user->id, ['is_default' => true]);

            return KioskAccessGrant::create([
                'user_id' => $user->id,
                'created_by_user_id' => $request->user()->id,
                'label' => $data['label'],
                'expires_at' => now()->addHours(self::HOURS),
            ]);
        });

        return response()->json([
            'data' => [
                'id' => $grant->id,
                'label' => $grant->label,
                'email' => $email,
                'password' => $password, // shown ONCE — the BD shares this with the client
                'expires_at' => $grant->expires_at?->toIso8601String(),
            ],
        ], 201);
    }

    /** DELETE /api/v1/kiosk/access/{grant} — revoke a temp login early. */
    public function destroy(Request $request, KioskAccessGrant $grant): JsonResponse
    {
        abort_unless($request->user()->can('kiosk.selections.manage'), 403);

        if ($grant->revoked_at === null) {
            $grant->update(['revoked_at' => now()]);
        }

        return response()->json(['data' => ['revoked' => true]]);
    }
}
