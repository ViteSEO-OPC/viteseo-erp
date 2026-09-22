<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Controllers\MetaWebhookController;
use App\Modules\Crm\Http\Middleware\MetaSignatureGuard;
use App\Modules\Crm\Models\ConversationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\Inbound\Meta\MetaEnvelopes;
use Tests\Support\Inbound\Meta\SignsMetaDeliveries;
use Tests\TestCase;

/**
 * Where the callback actually lives — the one property no unit test could have caught.
 *
 * Phase 3D.1 mounted the webhook at the root, which was correct Laravel and useless in production:
 * the host forwards only `/api` (plus `/storage` and two exact paths) to PHP and proxies everything
 * else to the Next.js frontend, so `GET /webhooks/meta` was answered by the frontend with an HTML
 * 404 and Meta's verification could never have succeeded. The code was right and unreachable, which
 * is the kind of defect that only shows up against the real host.
 *
 * These tests pin the path itself, because it is now half of an external contract: once the URL is
 * typed into the Meta dashboard, changing it silently stops every delivery.
 */
class MetaWebhookRoutingTest extends TestCase
{
    use RefreshDatabase;
    use SignsMetaDeliveries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMetaWebhook();
        $this->connectMetaResource($this->tenant()->id, MetaEnvelopes::PAGE_A);
    }

    /** 1 — the handshake lives at the new path and reaches the Meta controller. */
    public function test_the_get_handshake_is_registered_under_the_api_prefix(): void
    {
        $route = Route::getRoutes()->getByAction(MetaWebhookController::class.'@verify');

        $this->assertNotNull($route, 'the verification route must exist');
        $this->assertSame('api/webhooks/meta', $route->uri());
        $this->assertContains('GET', $route->methods());
    }

    /** 2 — so does the delivery, and the signature guard is on it. */
    public function test_the_post_delivery_is_registered_under_the_api_prefix_with_the_guard(): void
    {
        $route = Route::getRoutes()->getByAction(MetaWebhookController::class.'@handle');

        $this->assertNotNull($route);
        $this->assertSame('api/webhooks/meta', $route->uri());
        $this->assertSame(['POST'], array_values(array_diff($route->methods(), ['HEAD'])));
        $this->assertContains(MetaSignatureGuard::class, $route->gatherMiddleware());
    }

    /** The guard is on POST ONLY — the handshake carries no signature to check. */
    public function test_the_signature_guard_is_not_on_the_handshake(): void
    {
        $verify = Route::getRoutes()->getByAction(MetaWebhookController::class.'@verify');

        $this->assertNotContains(MetaSignatureGuard::class, $verify->gatherMiddleware());
    }

    /** No CRM auth stack sneaked onto the public route — Meta has no token, session or membership. */
    public function test_the_public_routes_carry_no_crm_authentication(): void
    {
        foreach (['verify', 'handle'] as $action) {
            $middleware = Route::getRoutes()->getByAction(MetaWebhookController::class.'@'.$action)->gatherMiddleware();
            $flattened = implode('|', array_map(fn ($m): string => is_string($m) ? $m : '', $middleware));

            foreach (['auth:sanctum', 'set-current-org', 'subscription-active', 'feature:crm', 'EnsureCrmAccessIsActive', 'permission:'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $flattened, $action.' must stay reachable by Meta');
            }
        }
    }

    /** 3, 4 — the old root path is gone, not left behind as a second entry point. */
    public function test_the_root_path_is_no_longer_a_webhook_entry_point(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $this->assertNotSame('webhooks/meta', $route->uri(), 'a second public webhook entry point must not exist');
        }

        // And exactly two webhook routes exist in total.
        $webhookRoutes = array_filter(
            Route::getRoutes()->getRoutes(),
            fn ($route): bool => str_contains($route->uri(), 'webhooks/meta'),
        );

        $this->assertCount(2, $webhookRoutes);
    }

    /** Hitting the old path is a 404 from the framework, not a webhook. */
    public function test_the_old_root_path_answers_nothing(): void
    {
        $body = $this->encode(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [MetaEnvelopes::textItem()]));

        $this->get('/webhooks/meta?hub.mode=subscribe&hub.verify_token='.self::FIXTURE_VERIFY_TOKEN.'&hub.challenge=chal')
            ->assertNotFound();

        $this->call('POST', '/webhooks/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $this->signature($body),
        ], $body)->assertNotFound();

        $this->assertSame(0, ConversationMessage::count(), 'and nothing is ingested through it');
    }

    /** 5 — the handshake still works, on the new path. */
    public function test_the_handshake_still_succeeds_on_the_new_path(): void
    {
        $this->get(self::WEBHOOK.'?hub.mode=subscribe&hub.verify_token='.self::FIXTURE_VERIFY_TOKEN.'&hub.challenge=chal_route_1')
            ->assertOk()
            ->assertSee('chal_route_1');
    }

    /** 6 — and a correctly signed delivery still ingests. */
    public function test_a_signed_delivery_still_ingests_on_the_new_path(): void
    {
        $this->postSigned(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [MetaEnvelopes::textItem()]))->assertOk();

        $this->assertSame(1, ConversationMessage::count());
        $this->assertSame('mid.text1', ConversationMessage::firstOrFail()->external_message_id);
    }

    /** 7 — moving the path changed nothing about failing closed. */
    public function test_an_invalid_signature_still_fails_closed_on_the_new_path(): void
    {
        $body = $this->encode(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [MetaEnvelopes::textItem()]));

        $this->postRaw($body, 'sha256='.str_repeat('a', 64))->assertForbidden();
        $this->postRaw($body, null)->assertForbidden();

        $this->assertSame(0, ConversationMessage::count());
    }
}
