<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels (platform)
|--------------------------------------------------------------------------
|
| SHARED channels live here. Module-specific channels live inside their owning
| module and are registered by that module's service provider:
|   app/Modules/Projects/Routes/channels.php  → project.{projectUuid}
|   app/Modules/Crm/Routes/channels.php       → crm.lead.{leadId}
|
| This file is bound by `withBroadcasting()` in bootstrap/app.php, which also
| registers the authenticated `/broadcasting/auth` endpoint (auth:sanctum).
|
*/

// user.{userId} — a user's private notification stream. Only the user themselves.
//
// PLATFORM-LEVEL, not Projects'. A user has ONE notification stream regardless of
// how many modules they work in, so every module broadcasts App\Events\
// NotificationCreated here and each client listener filters on that event's
// `module` field. This authorizer was previously registered by the Projects module,
// which made a shared identity channel look like one product's private property and
// left a second module's notifications depending on Projects being booted.
//
// Identity-scoped, so unlike the ownership-scoped channels there is nothing to
// revoke: the subscriber IS the resource, and that cannot change mid-session.
Broadcast::channel('user.{userId}', function (User $user, string $userId): bool {
    return (int) $user->id === (int) $userId;
});
