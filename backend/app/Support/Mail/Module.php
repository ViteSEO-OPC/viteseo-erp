<?php

namespace App\Support\Mail;

/**
 * The platform modules that own an outbound sender identity.
 *
 * A closed enum rather than a bare string key on purpose: a typo in a string
 * (`'hrs'`) would silently fall through to the global `mail.from` and send under the
 * wrong identity — precisely the failure this registry exists to prevent. A typo in a
 * case name is a fatal error at the call site instead.
 *
 * The value is the key under `config('mail.module_senders')`.
 */
enum Module: string
{
    case Crm = 'crm';
    case Hr = 'hr';

    /**
     * PMS/Projects sends no email today — the module has no Mailable, Notification, or
     * Mail:: call anywhere. The identity is registered ahead of its first use so that
     * mail lands on this registry when it arrives, rather than inventing a fourth pattern.
     */
    case Pms = 'pms';
}
