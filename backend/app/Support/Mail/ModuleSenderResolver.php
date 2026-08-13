<?php

namespace App\Support\Mail;

use Illuminate\Contracts\Config\Repository;

/**
 * Resolves a module's outbound sender identity from `config('mail.module_senders')`.
 *
 * This is the single source of truth for "who is this email from". Modules depend on
 * this resolver, never on env() and never on each other's config. The SMTP transport
 * (`MAIL_HOST`/`MAIL_PORT`/credentials) is shared and untouched by any of this — one
 * relay, many identities.
 *
 * A module with no configured address falls back to the global `mail.from`, so a
 * deployment that has not set the new vars keeps sending exactly as it did before.
 *
 * ── CRM INVARIANT ────────────────────────────────────────────────────────────────
 * The CRM sender MUST resolve to the same mailbox as `config('outreach.mailbox')`.
 * That mailbox is the inbox Gmail sync polls for prospect replies; if the From address
 * diverges from it, replies land somewhere nobody polls and inbound reply threading
 * stops silently. Enforced by a guard test rather than a runtime exception — a
 * misconfigured deployment should be caught in CI, not by refusing to send mail in
 * production. See tests/Feature/Mail/ModuleSenderIdentityTest.
 */
final readonly class ModuleSenderResolver
{
    public function __construct(private Repository $config) {}

    public function for(Module $module): ModuleSender
    {
        /** @var array<string, mixed> $configured */
        $configured = (array) $this->config->get("mail.module_senders.{$module->value}", []);

        return new ModuleSender(
            address: $this->value($configured, 'address') ?? (string) $this->config->get('mail.from.address'),
            name: $this->value($configured, 'name') ?? (string) $this->config->get('mail.from.name'),
            replyTo: $this->value($configured, 'reply_to'),
        );
    }

    /**
     * Reads one key, treating blank as absent. An env var that is present but empty
     * (`HR_MAIL_FROM_ADDRESS=`) is a configuration mistake, not an instruction to send
     * from nobody — it falls back like the unset case rather than producing an
     * unsendable empty From header.
     */
    private function value(array $configured, string $key): ?string
    {
        $value = trim((string) ($configured[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
