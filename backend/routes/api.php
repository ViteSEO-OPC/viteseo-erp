<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\ConnectionController;
use App\Http\Controllers\CustomRoleController;
use App\Http\Controllers\EntitlementController;
use App\Http\Controllers\OrgController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

// This file is auto-mounted under the `api` prefix (see bootstrap/app.php),
// so the `v1` group below produces URLs like /api/v1/auth/login.
Route::prefix('v1')->group(function () {

    /*
     * Self-signup was removed on 2026-09-04.
     *
     * It provisioned a tenant on the ACCOUNTING plan catalogue, which left this repo
     * the day before — so it was broken. But it was also contradicting the operating
     * model rather than merely lagging it: the platform admin is the one who creates
     * a client's org and mints that org's first super admin, and hands the account
     * over. A public route that lets a stranger provision their own tenant is a
     * second, unsupervised door into the same thing.
     */

    // Core (shared) auth — not HR-specific. Public login; the rest need a token.
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
        Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:5,1');
        Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1');

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('change-password', [AuthController::class, 'changePassword'])->middleware('throttle:10,1');

            // The devices you are signed in on, and ending any of them. Keys are SHA-256
            // of the session id -- never the id, which is the cookie's content.
            Route::get('sessions', [SessionController::class, 'index']);
            Route::delete('sessions', [SessionController::class, 'destroyOthers']);
            Route::delete('sessions/{key}', [SessionController::class, 'destroy'])->where('key', '[0-9a-f]{64}');
        });
    });

    // Entitlements — which functions the current org has availed (launcher gating)
    // + super-admin "pick functions" panel per org.
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me/features', [EntitlementController::class, 'mine']);

        // Org team management (Finance Admin) — current org pinned.
        Route::middleware('set-current-org')->group(function () {
            Route::get('team', [TeamController::class, 'index']);
            Route::post('team', [TeamController::class, 'store']);
            Route::put('team/{user}', [TeamController::class, 'update']);
            Route::delete('team/{user}', [TeamController::class, 'destroy']);

            // Custom Roles (v2) — org defines its own roles + capabilities.
            Route::get('capabilities', [CustomRoleController::class, 'capabilities']);
            Route::get('roles/presets', [CustomRoleController::class, 'presets']);
            Route::get('roles', [CustomRoleController::class, 'index']);
            Route::post('roles', [CustomRoleController::class, 'store']);
            Route::put('roles/{customRole}', [CustomRoleController::class, 'update']);
            Route::delete('roles/{customRole}', [CustomRoleController::class, 'destroy']);

            // Cross-module connections ("Integrations") — read is any org member;
            // the toggle is org-admin-gated inside the controller.
            Route::get('connections', [ConnectionController::class, 'index']);
            Route::put('connections/{key}', [ConnectionController::class, 'update']);
        });

        Route::middleware('role:super_admin')->group(function () {
            Route::get('admin/systems', [OrgController::class, 'systems']);
            Route::get('admin/orgs', [OrgController::class, 'index']);
            Route::get('admin/revenue-schedules', [OrgController::class, 'revenueSchedules']);
            Route::post('admin/orgs', [OrgController::class, 'store']);
            Route::post('admin/orgs/{org}/seats', [OrgController::class, 'setSeats']);
            Route::post('admin/orgs/{org}/activate', [OrgController::class, 'activate']);
            Route::post('admin/orgs/{org}/suspend', [OrgController::class, 'suspend']);
            Route::post('admin/orgs/{org}/resume', [OrgController::class, 'resume']);
            Route::post('admin/orgs/{org}/adjust', [OrgController::class, 'adjust']);
            Route::get('admin/orgs/{org}/payments', [OrgController::class, 'payments']);
            Route::post('admin/payments/{payment}/reverse', [OrgController::class, 'reversePayment']);
            Route::get('admin/features', [EntitlementController::class, 'catalog']);
            Route::get('admin/orgs/{org}/features', [EntitlementController::class, 'show']);
            Route::put('admin/orgs/{org}/features', [EntitlementController::class, 'update']);
        });
    });

});
