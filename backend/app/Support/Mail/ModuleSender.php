<?php

namespace App\Support\Mail;

use Illuminate\Mail\Mailables\Address;

/**
 * A module's outbound sender identity — the ONLY thing that varies per module.
 *
 * Transport is deliberately absent: every module sends through the one shared SMTP
 * mailer configured in `config/mail.php`. This object carries identity, never wiring.
 */
final readonly class ModuleSender
{
    public function __construct(
        public string $address,
        public string $name,
        /**
         * Where replies should go when it is not the From address. Nothing sets this
         * today (no `->replyTo()` exists anywhere in the app), but it is modelled here
         * so the first module that needs a distinct reply target adds config rather
         * than threading a new argument through every call site.
         */
        public ?string $replyTo = null,
    ) {}

    /** For `Envelope(from: …)` and anywhere else Laravel wants an Address. */
    public function toAddress(): Address
    {
        return new Address($this->address, $this->name);
    }
}
