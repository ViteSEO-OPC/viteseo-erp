<?php

namespace App\Modules\Crm\Services\Mail;

use App\Modules\Crm\Models\MailboxSync;
use App\Modules\Crm\Services\Mail\Contracts\GoogleCredentialSource;
use App\Modules\Crm\Services\Mail\Contracts\MailboxSyncProvider;
use App\Modules\Crm\Services\Mail\Providers\GmailMailboxSync;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the MailboxSyncProvider for a PARTICULAR mailbox.
 *
 * Replaces a single global `MailboxSyncProvider` container binding. That binding encoded an
 * assumption that held only while every mailbox in the system belonged to the same Google
 * Workspace: one deployment, one provider. `crm_mailbox_syncs.provider` has always been a
 * per-row column — the manager is what finally makes the column mean something, so a Gmail
 * mailbox and a Microsoft Graph mailbox can be polled by the same scheduler tick.
 *
 * Nothing about polling, cursors, ingest or threading changes. The job still receives a
 * MailboxSyncProvider and still calls fetchSince(); the only difference is that WHICH provider it
 * gets is now a function of the mailbox instead of a constant.
 *
 * Adding a provider means registering a driver here (or via extend()) and implementing
 * MailboxSyncProvider. The business layer is untouched, as it was designed to be.
 */
class MailboxSyncManager
{
    /**
     * Drivers are cached per name, not per mailbox: providers are stateless request-makers and the
     * mailbox is an argument to fetchSince(), so one Gmail driver serves every Gmail mailbox.
     *
     * @var array<string, MailboxSyncProvider>
     */
    private array $drivers = [];

    /** @var array<string, callable(): MailboxSyncProvider> */
    private array $customCreators = [];

    public function __construct(private readonly Container $app) {}

    /**
     * The provider for one mailbox, chosen by its stored `provider` value.
     *
     * Accepts the model when the caller already has it (SyncMailboxJob does, having just
     * firstOrCreate'd the row) to avoid a redundant query. A mailbox with no row yet — or a row
     * with an empty provider — falls back to the configured default, which is what keeps a
     * newly-onboarded mailbox working exactly as it did before this class existed.
     */
    public function for(MailboxSync|string $mailbox): MailboxSyncProvider
    {
        $sync = $mailbox instanceof MailboxSync
            ? $mailbox
            : MailboxSync::query()->where('email', $mailbox)->first();

        $provider = is_string($sync?->provider) ? trim($sync->provider) : '';

        return $this->driver($provider !== '' ? $provider : null);
    }

    /**
     * A driver by name; null means the configured default (`outreach.inbound_provider`).
     */
    public function driver(?string $name = null): MailboxSyncProvider
    {
        $name = $name ?? $this->getDefaultDriver();

        return $this->drivers[$name] ??= $this->resolve($name);
    }

    /**
     * Register a driver at runtime. Used by tests to substitute a fake, and available to a future
     * module that wants to add a provider without editing this class.
     *
     * @param  callable(): MailboxSyncProvider  $factory
     */
    public function extend(string $name, callable $factory): static
    {
        $this->customCreators[$name] = $factory;
        unset($this->drivers[$name]); // a re-registration must win over anything already resolved

        return $this;
    }

    public function getDefaultDriver(): string
    {
        return (string) config('outreach.inbound_provider', 'gmail');
    }

    private function resolve(string $name): MailboxSyncProvider
    {
        if (isset($this->customCreators[$name])) {
            return ($this->customCreators[$name])();
        }

        return match ($name) {
            'gmail' => $this->createGmailDriver(),
            // Loud rather than silently falling back to Gmail: a mailbox configured for a provider
            // we cannot serve must surface as a failed sync (which marks the mailbox and retries),
            // not as Gmail quietly polling someone else's Microsoft account.
            default => throw new InvalidArgumentException("No mailbox sync driver registered for [{$name}]."),
        };
    }

    private function createGmailDriver(): MailboxSyncProvider
    {
        return new GmailMailboxSync($this->app->make(GoogleCredentialSource::class));
    }
}
