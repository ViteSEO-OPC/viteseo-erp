<?php

namespace Tests\Unit\Support\Mail;

use App\Support\Mail\Module;
use App\Support\Mail\ModuleSenderResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The shared per-module sender registry. Pins the two things the rest of the platform
 * relies on: each module resolves its OWN identity, and anything unconfigured falls back
 * to the global MAIL_FROM_* so an un-migrated deployment behaves exactly as before.
 *
 * Transport is out of scope by construction — the resolver returns identity only and
 * cannot influence which mailer sends.
 */
class ModuleSenderResolverTest extends TestCase
{
    private function resolver(): ModuleSenderResolver
    {
        return app(ModuleSenderResolver::class);
    }

    public function test_each_module_resolves_its_own_configured_identity(): void
    {
        config()->set('mail.module_senders', [
            'crm' => ['address' => 'business@viteseo.test', 'name' => 'Zerem CRM'],
            'hr' => ['address' => 'hris@viteseo.test', 'name' => 'Vite SEO HR'],
            'pms' => ['address' => 'pms@viteseo.test', 'name' => 'Vite SEO Projects'],
        ]);

        $this->assertSame('business@viteseo.test', $this->resolver()->for(Module::Crm)->address);
        $this->assertSame('Zerem CRM', $this->resolver()->for(Module::Crm)->name);
        $this->assertSame('hris@viteseo.test', $this->resolver()->for(Module::Hr)->address);
        $this->assertSame('Vite SEO HR', $this->resolver()->for(Module::Hr)->name);
        $this->assertSame('pms@viteseo.test', $this->resolver()->for(Module::Pms)->address);
    }

    public function test_a_module_with_no_configuration_falls_back_to_the_global_from(): void
    {
        config()->set('mail.from', ['address' => 'platform@viteseo.test', 'name' => 'Vite SEO']);
        config()->set('mail.module_senders', []);

        foreach ([Module::Crm, Module::Hr, Module::Pms] as $module) {
            $sender = $this->resolver()->for($module);

            $this->assertSame('platform@viteseo.test', $sender->address, "{$module->value} should fall back");
            $this->assertSame('Vite SEO', $sender->name);
        }
    }

    /**
     * A present-but-empty env var (`HR_MAIL_FROM_ADDRESS=`) is a configuration mistake, not
     * an instruction to send from nobody. It must fall back rather than produce an
     * unsendable empty From header.
     */
    public function test_a_blank_configured_address_falls_back_instead_of_sending_from_nobody(): void
    {
        config()->set('mail.from', ['address' => 'platform@viteseo.test', 'name' => 'Vite SEO']);
        config()->set('mail.module_senders.hr', ['address' => '   ', 'name' => '']);

        $sender = $this->resolver()->for(Module::Hr);

        $this->assertSame('platform@viteseo.test', $sender->address);
        $this->assertSame('Vite SEO', $sender->name);
    }

    public function test_reply_to_is_null_unless_configured(): void
    {
        config()->set('mail.module_senders.crm', ['address' => 'business@viteseo.test', 'name' => 'Zerem']);
        $this->assertNull($this->resolver()->for(Module::Crm)->replyTo);

        config()->set('mail.module_senders.crm.reply_to', 'replies@viteseo.test');
        $this->assertSame('replies@viteseo.test', $this->resolver()->for(Module::Crm)->replyTo);
    }

    public function test_it_produces_a_mail_address_for_envelopes(): void
    {
        config()->set('mail.module_senders.hr', ['address' => 'hris@viteseo.test', 'name' => 'Vite SEO HR']);

        $address = $this->resolver()->for(Module::Hr)->toAddress();

        $this->assertSame('hris@viteseo.test', $address->address);
        $this->assertSame('Vite SEO HR', $address->name);
    }

    /**
     * BACKWARD COMPATIBILITY. CRM shipped with OUTREACH_FROM_*; those deployments must keep
     * their exact sender with no env change. Asserted against the real config file under
     * different env shapes rather than against hand-set config values, because the fallback
     * chain being tested lives in config/mail.php itself.
     */
    #[DataProvider('crmSenderEnvShapes')]
    public function test_the_crm_sender_env_chain_preserves_existing_deployments(
        array $env,
        string $expected,
        string $because,
    ): void {
        $this->assertSame($expected, $this->crmAddressUnderEnv($env), $because);
    }

    /** @return array<string, array{0: array<string, string>, 1: string, 2: string}> */
    public static function crmSenderEnvShapes(): array
    {
        return [
            'legacy: only the framework default is set' => [
                ['MAIL_FROM_ADDRESS' => 'platform@viteseo.test'],
                'platform@viteseo.test',
                'A deployment with no CRM-specific vars keeps the global sender.',
            ],
            'shipped: OUTREACH_FROM_ADDRESS wins over the framework default' => [
                ['MAIL_FROM_ADDRESS' => 'platform@viteseo.test', 'OUTREACH_FROM_ADDRESS' => 'business@viteseo.test'],
                'business@viteseo.test',
                'Production today sets OUTREACH_FROM_ADDRESS and must not change sender.',
            ],
            'new: CRM_MAIL_FROM_ADDRESS supersedes the legacy var' => [
                [
                    'MAIL_FROM_ADDRESS' => 'platform@viteseo.test',
                    'OUTREACH_FROM_ADDRESS' => 'legacy@viteseo.test',
                    'CRM_MAIL_FROM_ADDRESS' => 'business@viteseo.test',
                ],
                'business@viteseo.test',
                'The new per-module var is the one to set going forward.',
            ],
        ];
    }

    /** Loads the real config/mail.php with a given env shape, isolated from the test env. */
    private function crmAddressUnderEnv(array $env): string
    {
        $keys = ['MAIL_FROM_ADDRESS', 'OUTREACH_FROM_ADDRESS', 'CRM_MAIL_FROM_ADDRESS'];
        $restore = [];

        foreach ($keys as $key) {
            $restore[$key] = $_SERVER[$key] ?? null;
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        foreach ($env as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        try {
            $config = require base_path('config/mail.php');

            return (string) $config['module_senders']['crm']['address'];
        } finally {
            foreach ($restore as $key => $value) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);

                if ($value !== null) {
                    putenv("{$key}={$value}");
                    $_ENV[$key] = $_SERVER[$key] = $value;
                }
            }
        }
    }
}
