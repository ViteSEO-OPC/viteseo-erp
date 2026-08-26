<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Requests\SelectNerbyProviderRequest;
use App\Modules\Crm\Requests\UpdateNerbyProviderSettingRequest;
use App\Modules\Crm\Resources\NerbyProviderSettingResource;
use App\Modules\Crm\Services\Discovery\NerbyProviderSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings → Integrations → Nearby Lead Generation — the control centre for provider configuration.
 *
 * Admin / Super Admin only, enforced by `role:super_admin|crm_admin` on the route group — the same
 * gate the Gmail integration uses, and for the same reason: this configures how the ORGANISATION
 * generates leads, not a personal preference. A Business Development Manager is refused by the route
 * itself, so hiding the UI is presentation, not protection.
 *
 * THIN. Every decision lives in {@see NerbyProviderSettingService} (sole writer, keeper of the
 * single-active invariant, and the only place that resolves which provider actually runs) and every
 * response shape lives in {@see NerbyProviderSettingResource} (the allow-list that makes leaking a
 * credential impossible). This class routes and nothing more.
 *
 * The OpenStreetMap default is not implemented here either. It is the ABSENCE of configuration: with
 * no active row the service answers with the baseline, so a CRM that has never visited this page
 * behaves exactly as it does today.
 */
class NerbyProviderSettingController extends Controller
{
    public function __construct(private readonly NerbyProviderSettingService $settings) {}

    /**
     * The whole control centre in one payload: every provider's configuration plus which one will
     * actually run and why.
     *
     * Returns EVERY provider, including ones never configured, so the UI can render the full switch
     * without inferring the roster — and so a provider added later appears with no frontend change.
     */
    public function show(): JsonResponse
    {
        // Quietly: merely viewing the page must not append a fallback warning to the log on every
        // refresh. The reason is still in the response.
        return $this->overview(quiet: true);
    }

    /** Choose the provider Nearby uses. Does not enable it and does not touch any credential. */
    public function select(SelectNerbyProviderRequest $request): JsonResponse
    {
        $this->settings->selectProvider($request->provider(), $request->user());

        // Not quiet: a selection that lands the system in a fallback state is exactly when the
        // operator should get a log line saying so.
        return $this->overview();
    }

    /** Configure one provider: enablement, credential (write-only), provider-specific options. */
    public function update(UpdateNerbyProviderSettingRequest $request): JsonResponse
    {
        $this->settings->configure($request->provider(), $request->validated(), $request->user());

        return $this->overview();
    }

    /** Remove one provider's credential. Idempotent; selection and enablement are left alone. */
    public function destroyCredential(Request $request, string $provider): JsonResponse
    {
        $this->settings->clearCredential($this->resolveProvider($provider), $request->user());

        return $this->overview();
    }

    /**
     * Verify one provider's stored credential.
     *
     * Returns a status code, never the provider's error text. With no key stored it reports
     * `not_configured` without making a request — a test that cannot run says so rather than
     * reporting a success nothing verified.
     */
    public function test(Request $request, string $provider): JsonResponse
    {
        $tested = $this->settings->test($this->resolveProvider($provider), $request->user());

        // Passed through explicitly. A `not_configured` / `not_applicable` verdict is deliberately
        // NOT persisted — clicking Test on an unconfigured provider must not create a row — so
        // re-reading the overview from the database would silently discard the answer and the admin
        // would see nothing happen.
        return $this->overview(tested: $tested);
    }

    /** 404 for an unknown provider — the route segment is data, not a trusted value. */
    private function resolveProvider(string $provider): NerbyProvider
    {
        return NerbyProvider::tryFrom($provider) ?? abort(404, 'Unknown discovery provider.');
    }

    private function overview(bool $quiet = false, ?NerbyProviderSetting $tested = null): JsonResponse
    {
        $effective = $quiet ? $this->settings->resolveQuietly() : $this->settings->resolve();

        $providers = $this->settings->all();
        if ($tested !== null) {
            // Substitute the in-memory result so an unpersisted verdict still reaches the client.
            $providers[(string) $tested->provider] = $tested;
        }

        return response()->json([
            'data' => [
                // What the configuration asks for, what will actually run, and why they differ.
                'activeProvider' => $effective->configured->value,
                'effectiveProvider' => $effective->provider->value,
                'effectiveProviderLabel' => $effective->provider->label(),
                'reason' => $effective->reason,
                'mode' => $effective->mode,
                'isFallback' => $effective->isFallback(),
                'defaultProvider' => NerbyProvider::default()->value,

                'providers' => NerbyProviderSettingResource::collection(array_values($providers)),
            ],
        ]);
    }
}
