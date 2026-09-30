<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\AiAutoReply;

use App\Models\Subscription;
use App\Models\User;
use App\Modules\Crm\Jobs\AiAutoReplyJob;
use App\Modules\Crm\Models\AutoReply;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Services\AiDraft\ReplyDraftPromptBuilder;
use App\Modules\Crm\Services\AiDraft\ReplyDraftService;
use App\Modules\Crm\Services\CrmAccessService;
use App\Modules\Crm\Services\Outbound\OutboundFailureCode;
use App\Modules\Crm\Services\Outbound\OutboundIdempotency;
use App\Modules\Crm\Services\Outbound\OutboundMessagePersister;
use App\Modules\Crm\Services\Outbound\OutboundMessageRequest;
use App\Modules\Crm\Services\Outbound\OutboundMessageResult;
use App\Modules\Crm\Services\Outbound\OutboundMessageService;
use App\Services\SubscriptionService;
use App\Support\Entitlements;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Phase 3I — guarded AI auto-reply, V1. One inbound turn in, at most one model call and at most
 * one outbound send out.
 *
 *   claim (UNIQUE) → decide (locked) → draft → validate output → recheck (locked) → send once
 *
 * CLAIM FIRST, BEFORE ANYTHING COSTS MONEY. The first write is a row keyed UNIQUE on the inbound
 * message id. A duplicate job, a queue re-delivery or a re-entrant dispatch loses that insert and
 * returns silently, so it can reach neither the model nor Meta. A plain insert whose unique
 * violation is caught — not `insertOrIgnore` — because MariaDB's INSERT IGNORE also swallows
 * foreign-key failures, which would make "the message no longer exists" look like "already done".
 *
 * DECIDE UNDER A LOCK ON THE CONVERSATION. Every guard that compares this trigger with the rest of
 * the thread — newer inbound, an operator's reply, an unresolved send, another auto-reply in
 * flight, cooldown and caps — is read inside one short transaction holding the conversation row
 * FOR UPDATE, and the row moves to `drafting` before the lock is released. Two jobs for the same
 * conversation therefore see each other: the second finds the first `in_flight`. The lock is never
 * held across HTTP.
 *
 * RECHECK BEFORE SENDING. The model call takes seconds, and in that time an operator may have
 * replied, a send may have gone ambiguous, the org may have lost CRM, or the sender may have been
 * deactivated, removed or re-roled. The flags, the allowlist, the org's eligibility, the actor
 * (re-read FRESH, and the one then passed to the send), an operator reply and an unresolved
 * outbound are read again under the lock; any hit skips with a `_before_send` reason.
 * A NEWER inbound during drafting does not stop this reply — it was a reasonable answer to the
 * thread as it stood — and the newer turn's own job then skips as `in_flight` or `cooldown`.
 *
 * SEND EXACTLY ONCE, NEVER RETRY. The send goes through {@see OutboundMessageService} with the
 * client request id `ai-auto:{inbound id}`, so every permission, tenancy, window, kill-switch and
 * idempotency rule of a human reply applies unchanged. An exception around the send is recorded
 * as `ambiguous` — Meta may have accepted it — and nothing here ever calls send a second time.
 * A later re-run cannot either: it loses the claim, and even without the claim `already_attempted`
 * finds the outbound row by its idempotency key (a FAILED outbound row is re-claimable by the
 * persister, so that guard is what stops a definitive failure turning into a second send).
 *
 * AUDIT, IDENTIFIERS ONLY. Every terminal state except the silent duplicate writes one Activity on
 * the conversation and one WARNING log line (production runs at LOG_LEVEL=warning). Neither ever
 * carries the customer's text, the model output, a token or a key.
 */
final class AiAutoReplyService
{
    public const CLIENT_REQUEST_PREFIX = 'ai-auto:';

    public const ACTIVITY_EVENT = 'conversation.ai_auto_reply';

    public const SKIP_DISABLED = 'auto_reply_disabled';

    public const SKIP_NOT_ALLOWLISTED = 'not_allowlisted';

    public const SKIP_CONVERSATION_MISSING = 'conversation_missing';

    public const SKIP_MESSAGE_MISSING = 'message_missing';

    /** The org no longer has the `crm` feature or an active subscription (the HTTP path's gates). */
    public const SKIP_ORG_INELIGIBLE = 'org_ineligible';

    public const SKIP_NOT_INBOUND = 'not_inbound';

    public const SKIP_UNSUPPORTED_CHANNEL = 'unsupported_channel';

    public const SKIP_UNSUPPORTED_TYPE = 'unsupported_type';

    public const SKIP_EMPTY_BODY = 'empty_body';

    public const SKIP_TRIGGER_TOO_OLD = 'trigger_too_old';

    public const SKIP_SUPERSEDED = 'superseded';

    public const SKIP_OPERATOR_REPLIED = 'operator_replied';

    public const SKIP_OUTBOUND_UNRESOLVED = 'outbound_unresolved';

    public const SKIP_IN_FLIGHT = 'in_flight';

    public const SKIP_COOLDOWN = 'cooldown';

    public const SKIP_CONVERSATION_HOUR_CAP = 'conversation_hour_cap';

    public const SKIP_GLOBAL_HOUR_CAP = 'global_hour_cap';

    public const SKIP_ACTOR_INVALID = 'actor_invalid';

    public const SKIP_ALREADY_ATTEMPTED = 'already_attempted';

    public const BEFORE_SEND_SUFFIX = '_before_send';

    public const FAILURE_INVALID_OUTPUT = 'invalid_output';

    public const FAILURE_EXCEPTION = 'exception';

    /** Written only by the job's `failed()` hook: the worker gave up on the job itself. */
    public const FAILURE_JOB_FAILED = 'job_failed';

    /**
     * An in-flight row older than this is a crashed run, not a running one (the job is killed at
     * 60 s). It stops blocking the thread; a send it may have stranded is still caught by the
     * `outbound_unresolved` guard, which reads the outbound rows themselves.
     */
    public const STALE_IN_FLIGHT_SECONDS = 600;

    /** Invisible characters that can hide a link or pad an otherwise empty reply. */
    private const ZERO_WIDTH = '/[\x{200B}-\x{200D}\x{FEFF}\x{2060}\x{00AD}\x{180E}]/u';

    /**
     * Run against the NFKC-normalized, zero-width-stripped copy of the output. Any match (or a
     * preg error) rejects. See {@see sanitize()}.
     */
    private const REJECT_PATTERNS = [
        // Schemes, including spaced-out ones ("https : //"), and scheme-like prefixes.
        '~\b(?:https?|ftp)\s*:\s*//~iu',
        '~\bwww\.~iu',
        '~\b(?:tel|mailto|sms|whatsapp|viber)\s*:~iu',
        // Bare domains and scheme-less links with ANY alphabetic TLD: viteseo.ph, example.com/pricing,
        // m.me/x, youtu.be/x, forms.gle/x, example.de, café.com, Cyrillic lookalikes. Labels are any
        // letter/digit (not just ASCII) and the boundaries are explicit, because `\b` under /u counts
        // accented and Cyrillic letters as word characters and would let "évil.com" slip past. This
        // over-matches run-together sentences ("done.Thanks") — that fails closed (reply skipped).
        '~(?<![\p{L}\p{N}_-])[\p{L}\p{N}][\p{L}\p{N}-]{0,62}(?:\.[\p{L}\p{N}-]{1,63})*\.\p{L}{2,63}(?![\p{L}\p{N}_-])~u',
        // Bare IPv4 addresses (192.168.1.1/login).
        '~(?<![\d.])\d{1,3}(?:\.\d{1,3}){3}(?![\d.])~u',
        // Email addresses.
        '~[^\s@]+@[^\s@]+\.[^\s@]+~u',
        // Template artifacts: [Name], {name}, {{name}}, ${name}, <NAME>, %s / %d / %1$s.
        '~\[[^\]]*\]~u',
        '~\{[^{}]*\}~u',
        '~\{\{|\}\}|\$\{~u',
        '~<\s*/?\s*[a-z_][a-z0-9_ -]{0,40}>~iu',
        '~%(?:\d+\$)?[-+0#]*\d*(?:\.\d+)?[sdfuxc]\b~u',
    ];

    /** Outcomes that count against cooldown (the customer may have received something). */
    private const COOLDOWN_STATUSES = [AutoReply::SENT, AutoReply::AMBIGUOUS];

    /** Outcomes that count against the hourly caps (each one cost a model call). */
    private const CAPPED_STATUSES = [AutoReply::SENT, AutoReply::AMBIGUOUS, AutoReply::FAILED];

    public function __construct(
        private readonly ReplyDraftService $drafts,
        private readonly OutboundMessageService $outbound,
        private readonly OutboundMessagePersister $persister,
        private readonly OutboundIdempotency $idempotency,
        private readonly CrmAccessService $access,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * Runs one decision. Returns the claim row in its terminal state, or null when this trigger was
     * already claimed (a duplicate — deliberately silent). Expects to run inside
     * `CurrentOrg::runAs($orgId)`; every query below also names the org explicitly.
     */
    public function handle(int $orgId, int $conversationId, int $inboundMessageId): ?AutoReply
    {
        $reply = $this->claim($orgId, $conversationId, $inboundMessageId);

        if ($reply === null) {
            return null;
        }

        try {
            return $this->run($reply, $orgId, $conversationId, $inboundMessageId);
        } catch (Throwable $e) {
            // Never rethrown (a rethrow is a retry on some worker configuration, and a retry is a
            // second send). Recorded on the row THIS run claimed. The CLASS only: an exception
            // message can quote a query, and a query can carry text.
            Log::warning('crm.ai_auto_reply.exception', [
                'org_id' => $orgId,
                'conversation_id' => $conversationId,
                'inbound_message_id' => $inboundMessageId,
                'auto_reply_id' => (int) $reply->id,
                'exception' => $e::class,
            ]);

            $this->terminate((int) $reply->id, self::FAILURE_EXCEPTION);

            return AutoReply::query()->withoutGlobalScopes()->whereKey($reply->id)->first();
        }
    }

    /**
     * The queue's last resort ({@see AiAutoReplyJob::failed()}): the
     * worker gave up on the job — a timeout kill, or a failure outside the job body. Moves a
     * still-open claim to a terminal state and never touches a finished one. The job only knows its
     * trigger id; the claim is UNIQUE on it, so this is exactly the row that job claimed.
     */
    public function recordJobFailure(int $orgId, int $inboundMessageId): void
    {
        $id = AutoReply::query()
            ->withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->where('inbound_message_id', $inboundMessageId)
            ->value('id');

        if ($id !== null) {
            $this->terminate((int) $id, self::FAILURE_JOB_FAILED);
        }
    }

    /**
     * Trim, strip ONE pair of wrapping quotes, then REJECT (null) anything a customer must not
     * receive unreviewed: too short, too long (never truncated — a cut sentence can change its
     * meaning), a link (with or without a scheme, a bare domain, fullwidth or zero-width
     * obfuscated), an email address, a template placeholder, or JSON-looking output. Pure.
     *
     * Detection runs on a NORMALIZED copy (NFKC, zero-width characters removed) so that
     * `ｈｔｔｐｓ：／／` or `exa\u{200B}mple.com` cannot slip past; the text returned — and sent —
     * is the original, and its length is measured on the original.
     */
    public static function sanitize(string $raw, int $maxChars): ?string
    {
        if (preg_match('//u', $raw) !== 1) {
            return null; // not valid UTF-8
        }

        $text = trim($raw);

        foreach ([['"', '"'], ["'", "'"], ["\u{201C}", "\u{201D}"], ["\u{2018}", "\u{2019}"]] as [$open, $close]) {
            if (mb_strlen($text) >= 2 && str_starts_with($text, $open) && str_ends_with($text, $close)) {
                $text = trim(mb_substr($text, 1, -1));

                break;
            }
        }

        $length = mb_strlen($text);

        if ($length < 2 || $length > $maxChars) {
            return null;
        }

        $probe = self::detectionCopy($text);

        // Empty or one visible character once the invisible padding is gone.
        if ($probe === null || mb_strlen($probe) < 2) {
            return null;
        }

        // JSON-looking output is a model/gateway artifact, never a reply.
        if (preg_match('/^[\[{].*[\]}]$/su', $probe) !== 0) {
            return null;
        }

        // preg_match returns false on error; anything but a clean 0 is a rejection.
        foreach (self::REJECT_PATTERNS as $pattern) {
            if (preg_match($pattern, $probe) !== 0) {
                return null;
            }
        }

        return $text;
    }

    /** NFKC (intl) or a fullwidth-ASCII fold (no intl), zero-width removed, trimmed. Null on error. */
    private static function detectionCopy(string $text): ?string
    {
        if (extension_loaded('intl')) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);

            if (! is_string($normalized)) {
                return null;
            }
        } else {
            // U+FF01..U+FF5E are the fullwidth forms of ASCII 0x21..0x7E; U+3000 is a fullwidth space.
            $normalized = preg_replace_callback(
                '/[\x{FF01}-\x{FF5E}\x{3000}]/u',
                static fn (array $m): string => $m[0] === "\u{3000}" ? ' ' : chr(mb_ord($m[0]) - 0xFEE0),
                $text,
            );
        }

        // Ideographic / halfwidth full stops read as "." in a link (NFKC maps U+FF61 to U+3002, not to ".").
        $normalized = $normalized === null ? null : str_replace(["\u{3002}", "\u{FF61}"], '.', $normalized);

        $stripped = $normalized === null ? null : preg_replace(self::ZERO_WIDTH, '', $normalized);

        return $stripped === null ? null : (string) preg_replace('/^\s+|\s+$/u', '', $stripped);
    }

    public static function clientRequestId(int $inboundMessageId): string
    {
        return self::CLIENT_REQUEST_PREFIX.$inboundMessageId;
    }

    // ------------------------------------------------------------------ stages

    private function run(AutoReply $reply, int $orgId, int $conversationId, int $inboundMessageId): AutoReply
    {
        [$skip, $conversation, $actor] = DB::transaction(
            fn (): array => $this->decide($reply, $orgId, $conversationId, $inboundMessageId),
        );

        if ($skip !== null) {
            return $this->finish($reply, AutoReply::SKIPPED, $conversation, $actor, skipReason: $skip);
        }

        $result = $this->drafts->draft($conversation, (int) $actor->id, ReplyDraftPromptBuilder::PURPOSE_AUTO_REPLY);

        $reply->model = mb_substr($result->model, 0, 100);
        $reply->prompt_version = ReplyDraftPromptBuilder::AUTO_REPLY_PROMPT_VERSION;

        if (! $result->succeeded()) {
            return $this->finish($reply, AutoReply::FAILED, $conversation, $actor, failureCode: 'ai_'.$result->failureCode);
        }

        $body = self::sanitize((string) $result->draft, (int) config('ai_auto_reply.max_reply_chars', 1000));

        if ($body === null) {
            return $this->finish($reply, AutoReply::FAILED, $conversation, $actor, failureCode: self::FAILURE_INVALID_OUTPUT);
        }

        // The actor is re-resolved FRESH under the lock: the one read before the model call may
        // have been deactivated, removed from the org, stripped of the grant or made super_admin.
        [$skip, $freshActor] = DB::transaction(
            fn (): array => $this->recheckBeforeSend($reply, $orgId, $conversation, $inboundMessageId),
        );

        if ($skip !== null) {
            // An actor that is no longer valid is not named as the causer.
            $causer = $skip === self::SKIP_ACTOR_INVALID ? null : ($freshActor ?? $actor);

            return $this->finish($reply, AutoReply::SKIPPED, $conversation, $causer, skipReason: $skip.self::BEFORE_SEND_SUFFIX);
        }

        return $this->send($reply, $orgId, $conversation, $freshActor, $body, $inboundMessageId);
    }

    private function claim(int $orgId, int $conversationId, int $inboundMessageId): ?AutoReply
    {
        $reply = new AutoReply;
        $reply->org_id = $orgId;
        $reply->conversation_id = $conversationId;
        $reply->inbound_message_id = $inboundMessageId;
        $reply->status = AutoReply::DECIDED;

        try {
            $reply->save();
        } catch (UniqueConstraintViolationException $duplicate) {
            // Not chained or logged: the bindings are ids only, but the rule is the same everywhere.
            unset($duplicate);

            return null;
        }

        return $reply;
    }

    /**
     * The first matching reason wins, in the documented order. Moves the claim to `drafting` when
     * nothing matches. Runs inside a transaction holding the conversation FOR UPDATE.
     *
     * @return array{0: ?string, 1: ?Conversation, 2: ?User}
     */
    private function decide(AutoReply $reply, int $orgId, int $conversationId, int $inboundMessageId): array
    {
        // Read first (it is only a lock) so that even the first two skips can be audited on the
        // conversation; their ORDER as reasons is unchanged.
        $conversation = $this->lockedConversation($orgId, $conversationId);

        if (! $this->switchesOn()) {
            return [self::SKIP_DISABLED, $conversation, null];
        }

        if (! $this->allowlisted($conversationId)) {
            return [self::SKIP_NOT_ALLOWLISTED, $conversation, null];
        }

        if ($conversation === null) {
            return [self::SKIP_CONVERSATION_MISSING, null, null];
        }

        // Resolved once, early, so every audit row below can name a VALID actor as its causer. Its
        // verdict is only acted on at its own place in the order.
        $actor = $this->validActor($orgId);

        $inbound = ConversationMessage::query()
            ->withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->where('conversation_id', $conversationId)
            ->whereKey($inboundMessageId)
            ->first();

        $skip = match (true) {
            $inbound === null => self::SKIP_MESSAGE_MISSING,
            ! $this->orgEligible($orgId) => self::SKIP_ORG_INELIGIBLE,
            ! $inbound->isInbound() => self::SKIP_NOT_INBOUND,
            ! $this->supportedChannel($conversation, $inbound) => self::SKIP_UNSUPPORTED_CHANNEL,
            ! in_array($inbound->message_type, (array) config('ai_auto_reply.message_types', []), true) => self::SKIP_UNSUPPORTED_TYPE,
            trim((string) $inbound->body) === '' => self::SKIP_EMPTY_BODY,
            $this->tooOld($inbound) => self::SKIP_TRIGGER_TOO_OLD,
            $this->hasNewer($orgId, $conversationId, $inboundMessageId, 'inbound') => self::SKIP_SUPERSEDED,
            $this->hasNewer($orgId, $conversationId, $inboundMessageId, 'outbound') => self::SKIP_OPERATOR_REPLIED,
            $this->persister->unresolvedInConversation($conversation)->isNotEmpty() => self::SKIP_OUTBOUND_UNRESOLVED,
            $this->otherInFlight($reply, $orgId, $conversationId) => self::SKIP_IN_FLIGHT,
            $this->inCooldown($orgId, $conversationId) => self::SKIP_COOLDOWN,
            $this->conversationCapReached($orgId, $conversationId) => self::SKIP_CONVERSATION_HOUR_CAP,
            $this->globalCapReached() => self::SKIP_GLOBAL_HOUR_CAP,
            $actor === null => self::SKIP_ACTOR_INVALID,
            $this->alreadyAttempted($orgId, $conversation, $actor, $inboundMessageId) => self::SKIP_ALREADY_ATTEMPTED,
            default => null,
        };

        if ($skip === null) {
            $reply->status = AutoReply::DRAFTING;
            $reply->save();
        }

        return [$skip, $conversation, $actor];
    }

    /**
     * @return array{0: ?string, 1: ?User} a reason WITHOUT the `_before_send` suffix (or null to
     *                                     proceed) and the freshly re-validated actor
     */
    private function recheckBeforeSend(AutoReply $reply, int $orgId, Conversation $conversation, int $inboundMessageId): array
    {
        $locked = $this->lockedConversation($orgId, (int) $conversation->id);

        $skip = match (true) {
            ! $this->switchesOn() => self::SKIP_DISABLED,
            ! $this->allowlisted((int) $conversation->id) => self::SKIP_NOT_ALLOWLISTED,
            $locked === null => self::SKIP_CONVERSATION_MISSING,
            ! $this->orgEligible($orgId) => self::SKIP_ORG_INELIGIBLE,
            default => null,
        };

        $actor = null;

        if ($skip === null) {
            // Spatie keeps a per-process permission collection; a long-lived worker must not
            // answer from what it loaded before the model call. validActor() also re-reads the
            // user row itself, so its roles and permissions relations are loaded fresh.
            app(PermissionRegistrar::class)->clearPermissionsCollection();
            $actor = $this->validActor($orgId);

            $skip = match (true) {
                $actor === null => self::SKIP_ACTOR_INVALID,
                $this->hasNewer($orgId, (int) $conversation->id, $inboundMessageId, 'outbound') => self::SKIP_OPERATOR_REPLIED,
                $this->persister->unresolvedInConversation($locked)->isNotEmpty() => self::SKIP_OUTBOUND_UNRESOLVED,
                default => null,
            };
        }

        if ($skip === null) {
            $reply->status = AutoReply::SENDING;
            $reply->save();
        }

        return [$skip, $actor];
    }

    private function send(
        AutoReply $reply,
        int $orgId,
        Conversation $conversation,
        User $actor,
        string $body,
        int $inboundMessageId,
    ): AutoReply {
        $request = new OutboundMessageRequest($conversation, $actor, $body, self::clientRequestId($inboundMessageId));

        try {
            $result = $this->outbound->send($request, $orgId);
        } catch (Throwable $e) {
            // The provider may have accepted it. Ambiguous, recorded, and never tried again.
            Log::warning('crm.ai_auto_reply.send_exception', [
                'org_id' => $orgId,
                'conversation_id' => (int) $conversation->id,
                'inbound_message_id' => $inboundMessageId,
                'exception' => $e::class,
            ]);

            $reply->outbound_message_id = $this->outboundRowId($orgId, $request);

            return $this->finish($reply, AutoReply::AMBIGUOUS, $conversation, $actor, failureCode: self::FAILURE_EXCEPTION);
        }

        $rowId = $this->outboundRowId($orgId, $request);
        $reply->outbound_message_id = $rowId;

        [$status, $code] = $this->outcome($orgId, $rowId, $result);

        return $this->finish($reply, $status, $conversation, $actor, failureCode: $code);
    }

    /**
     * The terminal status comes from the OUTBOUND ROW when there is one — the persister's record of
     * what Meta said — not from the result object alone, so "accepted" without a provider id can
     * never be recorded as `sent`. `already_attempted` guarantees the row found is this send's.
     *
     * @return array{0: string, 1: ?string} status, failure code
     */
    private function outcome(int $orgId, ?int $rowId, OutboundMessageResult $result): array
    {
        $row = $rowId === null ? null : ConversationMessage::query()
            ->withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->whereKey($rowId)
            ->first(['id', 'delivery_status', 'failure_code']);

        $resultCode = $result->failure?->value;

        if ($row === null) {
            // Refused before anything durable was written (permission, resource, window, ...).
            if ($result->accepted) {
                return [AutoReply::AMBIGUOUS, 'send_unknown'];
            }

            return [
                $result->failure?->leavesDeliveryUnresolved() ? AutoReply::AMBIGUOUS : AutoReply::FAILED,
                'send_'.($resultCode ?? 'unknown'),
            ];
        }

        return match ($row->delivery_status) {
            OutboundMessagePersister::SENT => [AutoReply::SENT, null],
            OutboundMessagePersister::FAILED => [AutoReply::FAILED, 'send_'.($row->failure_code ?? $resultCode ?? 'unknown')],
            // pending — unresolved, or never settled: the customer may have it.
            default => [AutoReply::AMBIGUOUS, 'send_'.($row->failure_code ?? $resultCode ?? OutboundFailureCode::DeliveryUnknown->value)],
        };
    }

    /** Terminal write + one Activity + one warning line. Identifiers and codes only. */
    private function finish(
        AutoReply $reply,
        string $status,
        ?Conversation $conversation,
        ?User $actor,
        ?string $skipReason = null,
        ?string $failureCode = null,
    ): AutoReply {
        $reply->status = $status;
        $reply->skip_reason = $skipReason;
        $reply->failure_code = $failureCode === null ? null : mb_substr($failureCode, 0, 40);
        $reply->completed_at = now();
        $reply->save();

        $this->audit($reply, $conversation, $actor);

        return $reply;
    }

    /**
     * Last-resort terminal write for a row whose run did not finish normally. Re-reads the row
     * (an exception may have rolled back a transaction the in-memory model had already moved on
     * in), and writes only if it is STILL in the state it was read in — a conditional update, so a
     * concurrent normal finish is never overwritten. `sending` becomes `ambiguous` (Meta may have
     * the message), anything else `failed`. Audited with no causer.
     */
    private function terminate(int $replyId, string $failureCode): void
    {
        $row = AutoReply::query()->withoutGlobalScopes()->whereKey($replyId)->first();

        if ($row === null || $row->isTerminal()) {
            return;
        }

        $status = $row->status === AutoReply::SENDING ? AutoReply::AMBIGUOUS : AutoReply::FAILED;

        $updated = AutoReply::query()
            ->withoutGlobalScopes()
            ->whereKey($replyId)
            ->where('status', $row->status)
            ->update([
                'status' => $status,
                'failure_code' => $failureCode,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            return;
        }

        $row->refresh();

        $conversation = Conversation::query()
            ->withoutGlobalScopes()
            ->where('org_id', (int) $row->org_id)
            ->whereKey($row->conversation_id)
            ->first();

        $this->audit($row, $conversation, null);
    }

    /** One Activity (when the conversation exists) + one warning line. Identifiers and codes only. */
    private function audit(AutoReply $reply, ?Conversation $conversation, ?User $actor): void
    {
        $status = (string) $reply->status;

        $context = [
            'org_id' => (int) $reply->org_id,
            'conversation_id' => (int) $reply->conversation_id,
            'auto_reply_id' => (int) $reply->id,
            'inbound_message_id' => (int) $reply->inbound_message_id,
            'outbound_message_id' => $reply->outbound_message_id,
            'status' => $status,
            'skip_reason' => $reply->skip_reason,
            'failure_code' => $reply->failure_code,
            'model' => $reply->model,
            'prompt_version' => $reply->prompt_version,
        ];

        if ($conversation !== null) {
            $activity = $conversation->activities()->make([
                'event' => self::ACTIVITY_EVENT,
                'description' => 'AI auto-reply '.$status,
                'properties' => [
                    'inbound_message_id' => (int) $reply->inbound_message_id,
                    'outbound_message_id' => $reply->outbound_message_id,
                    'status' => $status,
                    'skip_reason' => $reply->skip_reason,
                    'failure_code' => $reply->failure_code,
                    'model' => $reply->model,
                    'prompt_version' => $reply->prompt_version,
                    'channel' => $conversation->channel,
                ],
                'causer_id' => $actor?->id,
            ]);
            $activity->org_id = (int) $reply->org_id;
            $activity->save();
        }

        Log::warning('crm.ai_auto_reply.'.$status, $context);
    }

    // ------------------------------------------------------------------ guards

    /** All three independent switches: this automation, the model endpoint, the Meta transport. */
    private function switchesOn(): bool
    {
        return (bool) config('ai_auto_reply.enabled')
            && ReplyDraftService::configurationFailure() === null
            && (bool) config('meta.outbound.enabled');
    }

    /**
     * The two org-level gates every human reply passes on its way in — the `feature:crm` and
     * `subscription-active` route middleware. A job has no route, so it asks the same questions
     * itself; an org that lost CRM or lapsed must not keep sending unattended.
     */
    private function orgEligible(int $orgId): bool
    {
        if (! Entitlements::has($orgId, 'crm')) {
            return false;
        }

        $subscription = Subscription::query()->where('org_id', $orgId)->first();

        return $subscription !== null && $this->subscriptions->hasAccess($subscription);
    }

    private function allowlisted(int $conversationId): bool
    {
        return in_array($conversationId, (array) config('ai_auto_reply.conversation_ids', []), true);
    }

    private function lockedConversation(int $orgId, int $conversationId): ?Conversation
    {
        return Conversation::query()
            ->withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->whereKey($conversationId)
            ->lockForUpdate()
            ->first();
    }

    private function supportedChannel(Conversation $conversation, ConversationMessage $inbound): bool
    {
        $channels = (array) config('ai_auto_reply.channels', []);

        return $conversation->provider === 'meta'
            && $inbound->provider === 'meta'
            && $conversation->channel === $inbound->channel
            && in_array($conversation->channel, $channels, true);
    }

    private function tooOld(ConversationMessage $inbound): bool
    {
        $at = $inbound->occurred_at ?? $inbound->received_at ?? $inbound->created_at;

        return $at === null
            || $at->lt(now()->subSeconds(max(0, (int) config('ai_auto_reply.max_trigger_age_seconds', 600))));
    }

    /** Any row of that direction in the thread written after the trigger (ids are monotonic). */
    private function hasNewer(int $orgId, int $conversationId, int $inboundMessageId, string $direction): bool
    {
        return ConversationMessage::query()
            ->withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->where('conversation_id', $conversationId)
            ->where('direction', $direction)
            ->where('id', '>', $inboundMessageId)
            ->exists();
    }

    private function otherInFlight(AutoReply $reply, int $orgId, int $conversationId): bool
    {
        return $this->conversationReplies($orgId, $conversationId)
            ->whereIn('status', AutoReply::IN_FLIGHT)
            ->whereKeyNot($reply->getKey())
            ->where('created_at', '>', now()->subSeconds(self::STALE_IN_FLIGHT_SECONDS))
            ->exists();
    }

    private function inCooldown(int $orgId, int $conversationId): bool
    {
        return $this->conversationReplies($orgId, $conversationId)
            ->whereIn('status', self::COOLDOWN_STATUSES)
            // Strictly after the cutoff, so `min_seconds_between = 0` really means no spacing.
            ->where('created_at', '>', now()->subSeconds(max(0, (int) config('ai_auto_reply.min_seconds_between', 120))))
            ->exists();
    }

    private function conversationCapReached(int $orgId, int $conversationId): bool
    {
        return $this->conversationReplies($orgId, $conversationId)
            ->whereIn('status', self::CAPPED_STATUSES)
            ->where('created_at', '>=', now()->subHour())
            ->count() >= (int) config('ai_auto_reply.max_per_conversation_hour', 3);
    }

    /** ACROSS ALL ORGS on purpose: this is the host's cost ceiling, not a tenant's quota. */
    private function globalCapReached(): bool
    {
        return AutoReply::query()
            ->withoutGlobalScopes()
            ->whereIn('status', self::CAPPED_STATUSES)
            ->where('created_at', '>=', now()->subHour())
            ->count() >= (int) config('ai_auto_reply.max_global_hour', 30);
    }

    /** @return Builder<AutoReply> */
    private function conversationReplies(int $orgId, int $conversationId): Builder
    {
        return AutoReply::query()
            ->withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->where('conversation_id', $conversationId);
    }

    /**
     * The configured sender, or null when it may not send for this org.
     *
     * `checkPermissionTo` and not `can()`: the CRM's Gate::before lets super_admin through every
     * ability, and an unattended sender must hold the grant itself. super_admin is refused outright
     * for the same reason — its access is a bypass, not a grant — and `checkPermissionTo` (unlike
     * `hasPermissionTo`) answers false instead of throwing when the permission row is absent.
     */
    private function validActor(int $orgId): ?User
    {
        $actorId = (int) config('ai_auto_reply.actor_user_id', 0);

        if ($actorId <= 0) {
            return null;
        }

        $actor = User::query()->find($actorId);

        if ($actor === null || ! $actor->isActive()) {
            return null;
        }

        $member = DB::table('org_user')
            ->where('user_id', $actorId)
            ->where('org_id', $orgId)
            ->exists();

        if (! $member
            || $actor->hasRole('super_admin')
            || ! $actor->checkPermissionTo(OutboundMessageService::PERMISSION)
            || $this->access->isSuspended($actor)) {
            return null;
        }

        return $actor;
    }

    /** Belt and braces behind the claim: a crm_messages row already carries this reply's key. */
    private function alreadyAttempted(int $orgId, Conversation $conversation, User $actor, int $inboundMessageId): bool
    {
        $request = new OutboundMessageRequest($conversation, $actor, '', self::clientRequestId($inboundMessageId));

        return $this->outboundRowId($orgId, $request) !== null;
    }

    private function outboundRowId(int $orgId, OutboundMessageRequest $request): ?int
    {
        $key = $this->idempotency->keyFor($request);

        if ($key === null) {
            return null;
        }

        $id = ConversationMessage::query()
            ->withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->where('idempotency_key', $key)
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
