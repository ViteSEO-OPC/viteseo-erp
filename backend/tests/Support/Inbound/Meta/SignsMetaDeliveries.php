<?php

declare(strict_types=1);

namespace Tests\Support\Inbound\Meta;

use App\Modules\Crm\Models\ProviderAccount;
use Illuminate\Testing\TestResponse;

/**
 * Posts webhook deliveries the way Meta does: a raw body plus an HMAC over those exact bytes.
 *
 * WHY `$this->call()` WITH AN EXPLICIT BODY and not postJson(). The signature covers the raw bytes,
 * so the test has to control them: postJson re-encodes the array it is given, and the re-encoded
 * string is not guaranteed to be the one that was signed. Building the body once and signing that
 * same string is also the only way this test proves the production path reads
 * `$request->getContent()` rather than the decoded input bag.
 *
 * The secrets here are obvious fixtures. Nothing shaped like a real Meta credential appears in the
 * suite.
 */
trait SignsMetaDeliveries
{
    protected const FIXTURE_APP_SECRET = 'fixture-app-secret-for-tests-only';

    protected const FIXTURE_VERIFY_TOKEN = 'fixture-verify-token-for-tests-only';

    protected const WEBHOOK = '/webhooks/meta';

    /** Enables the integration with fixture credentials. Nothing real is involved. */
    protected function enableMetaWebhook(array $overrides = []): void
    {
        config(array_merge([
            'meta.enabled' => true,
            'meta.app_secret' => self::FIXTURE_APP_SECRET,
            'meta.verify_token' => self::FIXTURE_VERIFY_TOKEN,
        ], $overrides));
    }

    protected function signature(string $body, ?string $secret = null): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret ?? self::FIXTURE_APP_SECRET);
    }

    /** A correctly signed delivery. */
    protected function postSigned(array $envelope): TestResponse
    {
        $body = $this->encode($envelope);

        return $this->postRaw($body, $this->signature($body));
    }

    /** A delivery with whatever signature header the test wants — including none. */
    protected function postRaw(string $body, ?string $signature): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($signature !== null) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = $signature;
        }

        return $this->call('POST', self::WEBHOOK, [], [], [], $server, $body);
    }

    /**
     * Connects a Meta resource to an org, the way the provisioning command does.
     *
     * `org_id` is assigned explicitly and not through the fillable array, because the model
     * deliberately keeps it out of $fillable: moving a Page between tenants must never be something
     * a mass assignment can do incidentally. A test that used create(['org_id' => …]) would have
     * silently inserted a row with no owner.
     */
    protected function connectMetaResource(int $orgId, string $externalId, string $resourceType = 'page'): ProviderAccount
    {
        $account = new ProviderAccount([
            'provider' => 'meta',
            'resource_type' => $resourceType,
            'external_id' => $externalId,
        ]);

        $account->org_id = $orgId;
        $account->is_active = true;
        $account->save();

        return $account;
    }

    protected function encode(array $envelope): string
    {
        return json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
