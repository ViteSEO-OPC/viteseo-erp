<?php

namespace App\Modules\Crm\Exceptions;

use RuntimeException;

/**
 * A mailbox cannot be authenticated until a human reconnects it: no grant was ever stored, the
 * stored grant has no refresh token, or Google has told us the grant is dead (`invalid_grant`).
 *
 * WHY THIS IS ITS OWN TYPE. Every OAuth failure is a RuntimeException as far as the queue is
 * concerned, but they split cleanly into two operational outcomes: "retrying will eventually work"
 * (Google is down, we are rate limited, the network blipped) and "retrying will NEVER work until
 * someone clicks Connect again". Only the second is worth surfacing to a person, and it is the only
 * one where dropping the stored credential is correct. Distinguishing them by exception type keeps
 * callers from having to match on message strings — the classic way that taxonomy rots.
 *
 * Extends RuntimeException deliberately: GoogleCredentialSource documents RuntimeException, and
 * SyncMailboxJob already marks the mailbox and lets the queue retry on one. Introducing this type
 * therefore changes nothing about existing handling until a caller opts into catching it.
 *
 * Carries the mailbox rather than only naming it in the message, so a future notification or
 * "reconnect this mailbox" banner has the address as data. It carries NOTHING else — no token, no
 * response body, no client secret. See OAuthUserCredentials for the rule.
 */
class MailboxReauthorizationRequiredException extends RuntimeException
{
    public function __construct(
        public readonly string $mailbox,
        string $message,
    ) {
        parent::__construct($message);
    }
}
