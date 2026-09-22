<?php

use App\Modules\Crm\Controllers\MetaWebhookController;
use App\Modules\Crm\Http\Middleware\MetaSignatureGuard;
use Illuminate\Support\Facades\Route;

/*
 * PUBLIC Meta webhook routes — mounted by CrmServiceProvider with the `api` middleware group and
 * no prefix, so the paths are exactly /webhooks/meta. Meta registers a literal callback URL, so
 * these paths are not ours to tidy later.
 *
 * WHY THEY CARRY NONE OF THE CRM MIDDLEWARE. Everything else in this module sits behind
 * auth:sanctum → set-current-org → subscription-active → feature:crm → EnsureCrmAccessIsActive,
 * and every one of those would be wrong here. There is no user to authenticate, no membership to
 * pin (the tenant is resolved per Meta resource, inside the dispatcher), and no subscription to
 * check before we even know whose delivery this is. Inheriting them would not add security; it
 * would answer 401/402 to Meta and get the subscription disabled.
 *
 * The boundary is Meta-specific instead:
 *   GET  — the verify-token handshake, which Meta sends unsigned. Constant-time comparison, and
 *          the challenge is echoed only after it succeeds.
 *   POST — MetaSignatureGuard: `meta.enabled` must be on, the body must be within the byte ceiling,
 *          and X-Hub-Signature-256 must match an HMAC over the RAW body. Disabled means REFUSE, not
 *          "accept unverified": the flag turns the feature off, never the authentication.
 *
 * The guard is route middleware rather than a check inside the controller so that an unsigned body
 * never reaches code that decodes it.
 *
 * Deliberately absent: throttling. Meta is the only legitimate caller and it backs off on its own;
 * a rate limiter here would drop real deliveries during a burst, and the abuse it would mitigate is
 * already bounded by the byte ceiling, the event ceiling and the signature.
 */
Route::get('/webhooks/meta', [MetaWebhookController::class, 'verify']);

Route::post('/webhooks/meta', [MetaWebhookController::class, 'handle'])
    ->middleware(MetaSignatureGuard::class);
