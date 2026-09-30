<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Services\CrmAccessService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Who may reach inbound ingest — the integration-only boundary, tested from the outside.
 *
 * THE REGRESSION THIS FILE EXISTS FOR. The CRM registers a global `Gate::before` that returns true
 * for every ability a `super_admin` is asked about, and Laravel's `permission:` middleware
 * authorizes through `canAny()`, which consults that gate. So before EnsureCrmIntegrationAccount
 * existed, a super_admin — the broadest human credential in the platform — could POST to a
 * machine-only endpoint and have a message ingested. `test_a_super_admin_is_refused...` is that
 * exact request; without the middleware it returns 201.
 *
 * EVERY REJECTION ASSERTS THE `code`, not merely the status. Three different layers answer 403 on
 * this route (Spatie's ability check, CRM suspension, and this boundary), so a bare
 * `assertForbidden()` would pass even with the middleware deleted. `integration_account_required`
 * is what proves which layer spoke.
 */
class InboundConversationIntegrationIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/crm/automation/conversations/ingest';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * The provisioned shape: no role, the ability directly, exactly one org.
     *
     * The membership is re-pointed rather than left to UserFactory, which attaches every user it
     * builds to whichever org already exists — right for ordinary fixtures, wrong where the tenant
     * is the subject.
     */
    private function account(string $email, ?Org $org = null): User
    {
        $org ??= $this->tenant();

        $user = User::factory()->create(['email' => $email]);
        DB::table('org_user')->where('user_id', $user->id)->delete();
        $org->users()->attach($user->id, ['is_default' => true]);

        return $user;
    }

    private function grantIngest(User $user): User
    {
        $user->givePermissionTo(Permission::findOrCreate('crm.conversations.ingest', 'web'));

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'meta',
            'channel' => 'messenger',
            'external_conversation_id' => 't_identity_1',
            'external_message_id' => 'mid_identity_1',
            'sender_external_id' => 'psid_identity_1',
            'message_type' => 'text',
            'text' => 'Magkano po ang SEO package?',
        ], $overrides);
    }

    private function assertNothingIngested(): void
    {
        $this->assertSame(0, ConversationMessage::count(), 'a refused request must write no message');
        $this->assertSame(0, Conversation::count(), 'and no conversation');
    }

    /** 1 — an account the real provisioning command created can ingest. */
    public function test_an_account_provisioned_by_the_command_can_ingest(): void
    {
        $org = $this->tenant();

        $this->artisan('crm:integration-account', ['--org' => (string) $org->id, '--provider' => 'meta'])
            ->assertSuccessful();

        $email = sprintf((string) config('inbound.service_account.email_pattern'), 'meta', $org->id);
        $provisioned = User::query()->where('email', $email)->firstOrFail();

        Sanctum::actingAs($provisioned);

        $this->postJson(self::URL, $this->payload())->assertCreated();

        $this->assertSame(1, ConversationMessage::count());
    }

    /**
     * 2 — a super_admin WITHOUT the ability directly.
     *
     * Note what this proves about the layering: `permission:crm.conversations.ingest` does NOT
     * stop this request — Gate::before satisfies it — so the 403 and the code below can only have
     * come from EnsureCrmIntegrationAccount.
     */
    public function test_a_super_admin_without_the_direct_ability_is_refused(): void
    {
        $user = $this->account('chief@viteseo.ph');
        $user->assignRole('super_admin');

        Sanctum::actingAs($user);

        $this->postJson(self::URL, $this->payload())
            ->assertForbidden()
            ->assertJsonPath('code', 'integration_account_required');

        $this->assertNothingIngested();
    }

    /** 3 — and still refused when the ability IS held directly, because a role disqualifies. */
    public function test_a_super_admin_holding_the_ability_directly_is_still_refused(): void
    {
        $user = $this->grantIngest($this->account('chief-with-grant@viteseo.ph'));
        $user->assignRole('super_admin');

        Sanctum::actingAs($user);

        $this->postJson(self::URL, $this->payload())
            ->assertForbidden()
            ->assertJsonPath('code', 'integration_account_required');

        $this->assertNothingIngested();
    }

    /** 4 — the same for an ordinary human role. */
    public function test_a_human_role_holding_the_ability_directly_is_refused(): void
    {
        $user = $this->grantIngest($this->account('crm-admin@viteseo.ph'));
        $user->assignRole('crm_admin');

        Sanctum::actingAs($user);

        $this->postJson(self::URL, $this->payload())
            ->assertForbidden()
            ->assertJsonPath('code', 'integration_account_required');

        $this->assertNothingIngested();
    }

    /**
     * 5 — roleless but without the ability.
     *
     * This one is answered by Spatie's `permission:` middleware, which throws before this
     * boundary is reached, so only the status is asserted: what matters is that an account
     * carrying no grant cannot ingest, not which of the two layers said so.
     */
    public function test_a_roleless_account_without_the_ability_is_refused(): void
    {
        Sanctum::actingAs($this->account('nobody@viteseo.ph'));

        $this->postJson(self::URL, $this->payload())->assertForbidden();

        $this->assertNothingIngested();
    }

    /** 6 — roleless, ability held directly, one valid org: the intended identity. */
    public function test_a_roleless_account_with_the_direct_ability_and_an_org_succeeds(): void
    {
        Sanctum::actingAs($this->grantIngest($this->account('meta-inbound-org'.$this->tenant()->id.'@crm.local')));

        $this->postJson(self::URL, $this->payload())->assertCreated();

        $this->assertSame(1, ConversationMessage::count());
    }

    /**
     * 8a — the decision does not depend on the address.
     *
     * An account at an address matching none of the administrative conventions
     *
     * (`*@crm.local`, `meta-inbound-org*`, `n8n-inbound-*`) is admitted on state alone.
     */
    public function test_a_valid_identity_at_an_unconventional_address_is_admitted(): void
    {
        Sanctum::actingAs($this->grantIngest($this->account('partner.automation@example.org')));

        $this->postJson(self::URL, $this->payload())->assertCreated();

        $this->assertSame(1, ConversationMessage::count());
    }

    /**
     * 8b — and the converse, which is the half that would actually be exploitable: wearing the
     * service-account address buys nothing when the state is wrong.
     */
    public function test_a_service_account_address_does_not_admit_an_account_with_a_role(): void
    {
        $email = sprintf((string) config('inbound.service_account.email_pattern'), 'meta', $this->tenant()->id);
        $user = $this->grantIngest($this->account($email));
        $user->assignRole('crm_bd');

        Sanctum::actingAs($user);

        $this->postJson(self::URL, $this->payload())
            ->assertForbidden()
            ->assertJsonPath('code', 'integration_account_required');

        $this->assertNothingIngested();
    }

    /**
     * An identity that has DRIFTED since provisioning — someone attached a role to it later —
     * loses ingest on its very next request, without anyone revoking its token.
     */
    public function test_an_identity_that_gains_a_role_after_provisioning_loses_ingest(): void
    {
        $user = $this->grantIngest($this->account('drifting-inbound@crm.local'));

        Sanctum::actingAs($user);
        $this->postJson(self::URL, $this->payload())->assertCreated();

        $user->assignRole('crm_bd');

        $this->postJson(self::URL, $this->payload([
            'external_conversation_id' => 't_identity_2',
            'external_message_id' => 'mid_identity_2',
        ]))
            ->assertForbidden()
            ->assertJsonPath('code', 'integration_account_required');

        $this->assertSame(1, ConversationMessage::count(), 'only the pre-drift message exists');
    }

    /**
     * 9 — an identity with no tenant is rejected before ingest.
     *
     * THE STATUS IS 402, NOT THIS BOUNDARY'S 403, and that is accepted deliberately: the
     * platform's shared `subscription-active` gate is group middleware and therefore runs before
     * any route middleware, and an account with no org has no subscription to find. Forcing a 403
     * would mean reordering a gate shared by every CRM route. What the requirement asks for, and
     * what this pins, is that the request is refused before InboundConversationService is reached,
     * that it is not a server error, and that nothing is written.
     */
    public function test_an_orgless_identity_is_rejected_before_ingest(): void
    {
        $user = $this->grantIngest($this->account('orphan-inbound@crm.local'));
        DB::table('org_user')->where('user_id', $user->id)->delete();

        // Tests\TestCase pins CurrentOrg in setUp() so fixtures can be built outside a request.
        // That pin is an override and would mask the condition under test; in production nothing
        // pins it and an orgless account resolves to null.
        CurrentOrg::clear();

        Sanctum::actingAs($user);

        $response = $this->postJson(self::URL, $this->payload());

        $this->assertSame(402, $response->status(), 'the shared subscription gate answers first');
        $this->assertNothingIngested();
    }

    /**
     * 5 — CRM suspension still governs an integration identity, through the EXISTING mechanism.
     *
     * Deliberately asserting `crm_access_suspended` and not this boundary's own code: the point is
     * that suspension is decided upstream by EnsureCrmAccessIsActive on the CRM group, and that no
     * second suspension system was introduced for integrations. If that ever regressed, this test
     * would still see a 403 — but with the wrong code, which is what makes the assertion useful.
     */
    public function test_a_suspended_integration_identity_is_refused_by_the_existing_crm_gate(): void
    {
        $user = $this->grantIngest($this->account('suspended-inbound@crm.local'));

        Sanctum::actingAs($user);
        $this->postJson(self::URL, $this->payload())->assertCreated();

        app(CrmAccessService::class)->suspend($user);

        $this->postJson(self::URL, $this->payload([
            'external_conversation_id' => 't_identity_4',
            'external_message_id' => 'mid_identity_4',
        ]))
            ->assertForbidden()
            ->assertJsonPath('code', 'crm_access_suspended');

        $this->assertSame(1, ConversationMessage::count(), 'only the pre-suspension message exists');
    }

    /** The middleware reads state, not the token's name or abilities: a fresh token behaves the same. */
    public function test_the_boundary_is_evaluated_per_request_not_at_token_issue(): void
    {
        $user = $this->grantIngest($this->account('per-request@crm.local'));

        Sanctum::actingAs($user);
        $this->postJson(self::URL, $this->payload())->assertCreated();

        // Revoking the grant is enough; the credential itself is untouched.
        $user->revokePermissionTo('crm.conversations.ingest');

        $this->postJson(self::URL, $this->payload([
            'external_conversation_id' => 't_identity_3',
            'external_message_id' => 'mid_identity_3',
        ]))->assertForbidden();

        $this->assertSame(1, ConversationMessage::count());
    }
}
