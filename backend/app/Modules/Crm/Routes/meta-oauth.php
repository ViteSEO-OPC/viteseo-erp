<?php

declare(strict_types=1);

use App\Modules\Crm\Controllers\MetaConnectController;
use Illuminate\Support\Facades\Route;

/*
 * Meta's OAuth callback — public, and deliberately UNDER /api rather than at the root.
 *
 * WHY NOT /auth/meta/callback, beside the Google one. Host nginx serves this domain's root with
 * Next.js and routes only specific paths to PHP: `location ^~ /api`, `/storage`, and two exact
 * matches — `/broadcasting/auth` and `/auth/google/callback`. A Meta callback at the root would
 * therefore be answered by the SPA, which is exactly the failure Phase 3D.2A diagnosed when the
 * Meta webhook was first mounted at /webhooks/meta and Next.js replied to Meta's verification.
 *
 * The fix there was the same as here: put the path under the prefix that already reaches Laravel,
 * so the server needs no new nginx location and no deployment step outside the application. The
 * registered redirect URI must then be .../api/auth/meta/callback, and Meta compares it literally.
 *
 * PUBLIC BY NECESSITY. Facebook Login for Business sends the browser here as a top-level
 * navigation with no Authorization header. Authentication is the single-use, short-lived state
 * nonce issued by the authenticated endpoint in api.php and verified in MetaConnectState. Nothing
 * is written before that nonce is validated and spent, so an unsolicited request costs one cache
 * read and a redirect.
 */
Route::get('/auth/meta/callback', [MetaConnectController::class, 'callback']);
