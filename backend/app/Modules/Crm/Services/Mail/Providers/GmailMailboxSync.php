<?php

namespace App\Modules\Crm\Services\Mail\Providers;

use App\Modules\Crm\Services\Mail\Contracts\GoogleCredentialSource;
use App\Modules\Crm\Services\Mail\Contracts\MailboxSyncProvider;
use App\Modules\Crm\Services\Mail\Inbound\InboundMessage;
use App\Modules\Crm\Services\Mail\Inbound\MailboxFetchResult;
use Google\Client as GoogleClient;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail;
use Google\Service\Gmail\ListHistoryResponse;
use Google\Service\Gmail\Message as GmailMessage;
use Google\Service\Gmail\MessagePart;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Gmail implementation of MailboxSyncProvider (Phase-1 inbound provider). Reads the mailbox through
 * the Gmail API and normalizes each message to the provider-agnostic InboundMessage. The business
 * layer (InboundEmailService) never sees anything Gmail-specific.
 *
 * HOW it authenticates is no longer this class's concern — a GoogleCredentialSource is injected and
 * applied to the client. Today that is a Workspace service account with domain-wide delegation
 * (keyless by default); a future per-tenant OAuth grant plugs in at the same seam without touching
 * the delta-sync logic below.
 *
 * PULL, NOT PUSH. This class previously also armed a Gmail `users.watch` against a Pub/Sub topic and
 * decoded the push envelopes that came back. The delta-sync engine below is unchanged from that era —
 * only the trigger moved. What `watch()` uniquely provided was the INITIAL cursor, so that job now
 * belongs to fetchSince(): see the null-cursor branch.
 */
class GmailMailboxSync implements MailboxSyncProvider
{
    private const PROVIDER = 'gmail';

    public function __construct(private readonly GoogleCredentialSource $credentials) {}

    public function fetchSince(string $mailbox, ?string $cursor): MailboxFetchResult
    {
        $gmail = $this->gmail($mailbox);

        // No cursor yet — a mailbox being synced for the first time. Under push, watch() established
        // the baseline and this returned nothing; polling has no such step, so a null cursor would
        // mean the mailbox NEVER syncs. Bootstrapping here is what makes the integration
        // self-starting: no arming command, no Google Cloud resource, just a scheduler tick.
        //
        // How much history a first sync imports is an OPERATIONAL decision, not this class's to make
        // — see `outreach.inbound.initial_sync_days`. It is deliberately a different setting from the
        // recovery window below: importing history on purpose and salvaging it after losing a cursor
        // are different intents that happen to share mechanics.
        if ($cursor === null) {
            return $this->baseline(
                $gmail,
                $mailbox,
                (int) config('outreach.inbound.initial_sync_days', 0),
                'outreach.inbound.cursor_bootstrapped',
                ['mailbox' => $mailbox],
            );
        }

        try {
            [$messageIds, $latestCursor] = $this->collectHistory($gmail, $cursor);
        } catch (GoogleServiceException $e) {
            // Only a 404 means the stored historyId has been pruned by Gmail (stale cursor). Any other
            // status (429/5xx/transient) is a legitimate failure — re-throw so the queue retries it.
            if ($e->getCode() !== 404) {
                throw $e;
            }

            return $this->recoverFromExpiredCursor($gmail, $mailbox, $cursor);
        }

        return new MailboxFetchResult($this->hydrateAll($gmail, $mailbox, $messageIds), $latestCursor);
    }

    /**
     * Walk the Gmail history from $cursor, collecting added message ids and advancing the cursor to the
     * newest history record. Unchanged delta-sync logic — extracted so fetchSince can wrap it in the
     * stale-cursor recovery try/catch.
     *
     * @return array{0: list<string>, 1: string} [messageIds, latestCursor]
     */
    private function collectHistory(Gmail $gmail, string $cursor): array
    {
        $latestCursor = $cursor;
        /** @var array<string, true> $messageIds */
        $messageIds = [];
        $pageToken = null;

        do {
            $history = $this->fetchHistoryPage($gmail, $cursor, $pageToken);

            foreach ($history->getHistory() ?? [] as $record) {
                $recordId = (string) $record->getId();
                if ($this->cursorNewer($recordId, $latestCursor)) {
                    $latestCursor = $recordId;
                }
                foreach ($record->getMessagesAdded() as $added) {
                    $messageIds[$added->getMessage()->getId()] = true;
                }
            }

            $pageToken = $history->getNextPageToken();
        } while ($pageToken !== null && $pageToken !== '');

        return [array_keys($messageIds), $latestCursor];
    }

    /**
     * Recovery path (R5): the stored historyId has expired (Gmail pruned it). Re-sync the last N days
     * of INBOX and re-baseline, exactly as a first-time bootstrap does — the two situations differ
     * only in how alarming they are, so they share the mechanics and differ in log level.
     *
     * A pruned cursor means a gap we cannot measure; that is an operational problem worth an ERROR.
     */
    private function recoverFromExpiredCursor(Gmail $gmail, string $mailbox, string $staleCursor): MailboxFetchResult
    {
        return $this->baseline(
            $gmail,
            $mailbox,
            // NOT `initial_sync_days`. That setting expresses "how much history do we want when
            // onboarding a mailbox", and an operator who sets it to 0 is saying "start from now" —
            // they are not saying "silently discard mail when a cursor expires". Recovery keeps its
            // own window so tuning one cannot quietly disarm the other.
            (int) config('outreach.inbound.resync_window_days', 7),
            'outreach.inbound.cursor_expired',
            ['mailbox' => $mailbox, 'stale_cursor' => $staleCursor],
            failure: true,
        );
    }

    /**
     * Establish (or re-establish) a cursor: optionally ingest a bounded recent window of INBOX, then
     * baseline to the mailbox's current historyId so incremental sync resumes cleanly from here.
     *
     * Shared by the first-time bootstrap and the expired-cursor recovery; they differ only in the
     * window they pass and how loudly they log. Bounded on purpose — an unbounded backfill would drag
     * a mailbox's entire history into the CRM. Idempotent ingest dedupes the overlap this necessarily
     * re-offers, which is what makes re-baselining safe to do more than once.
     *
     * $windowDays <= 0 means BASELINE ONLY: take a cursor, import nothing. The cursor is still
     * returned, so syncing starts from this moment forward — which is the point, and why zero is a
     * meaningful setting rather than a disabled one.
     *
     * Mail older than the window is NOT imported. That is a deliberate trade, and it is logged rather
     * than silent so the boundary is visible after the fact.
     *
     * @param  array<string, mixed>  $context
     */
    private function baseline(
        Gmail $gmail,
        string $mailbox,
        int $windowDays,
        string $event,
        array $context,
        bool $failure = false,
    ): MailboxFetchResult {
        // Clamped rather than trusted: a negative value would otherwise reach Gmail as the nonsense
        // query `newer_than:-3d`.
        $windowDays = max(0, $windowDays);
        $context['window_days'] = $windowDays;

        // A first sync is expected and routine; a pruned cursor means lost mail. Same code, different
        // severity — an INFO for the normal path keeps the ERROR channel meaningful.
        $failure ? Log::error($event, $context) : Log::info($event, $context);

        // Skip the listing entirely at zero. Not just an optimisation: `newer_than:0d` is not a
        // reliable way to express "nothing", so asking at all could import today's mail.
        $messages = $windowDays === 0
            ? []
            : $this->hydrateAll($gmail, $mailbox, $this->listRecentMessageIds($gmail, $windowDays));

        return new MailboxFetchResult($messages, $this->currentHistoryId($gmail));
    }

    /**
     * Hydrate each provider message id to an InboundMessage, with per-message isolation (Item #1): a single
     * inaccessible or malformed message is skipped + logged, never fatal, so one poison message can never
     * abort the batch or stall the cursor.
     *
     * @param  list<string>  $ids
     * @return list<InboundMessage>
     */
    private function hydrateAll(Gmail $gmail, string $mailbox, array $ids): array
    {
        $messages = [];
        foreach ($ids as $id) {
            try {
                $messages[] = $this->hydrate($gmail, $mailbox, $id);
            } catch (\Throwable $e) {
                Log::error('outreach.inbound.hydrate_failed', [
                    'mailbox' => $mailbox,
                    'provider_message_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $messages;
    }

    // ---------------------------------------------------------------------------------------------
    // Thin seams over the raw Gmail SDK calls (internal to this class, testability only). Overriding
    // these in a test subclass lets the recovery/delta orchestration above be exercised without the
    // Google client — no public contract, interface, or behaviour change outside GmailMailboxSync.
    // ---------------------------------------------------------------------------------------------

    /** One page of the history delta from $startHistoryId. */
    protected function fetchHistoryPage(Gmail $gmail, string $startHistoryId, ?string $pageToken): ListHistoryResponse
    {
        $params = [
            'startHistoryId' => $startHistoryId,
            'historyTypes' => 'messageAdded',
            'maxResults' => 500,
        ];
        if ($pageToken !== null) {
            $params['pageToken'] = $pageToken;
        }

        return $gmail->users_history->listUsersHistory('me', $params);
    }

    /**
     * Bounded INBOX listing (`newer_than:{N}d`) for the stale-cursor re-sync, deduped + paginated.
     *
     * @return list<string> provider message ids
     */
    protected function listRecentMessageIds(Gmail $gmail, int $windowDays): array
    {
        /** @var array<string, true> $ids */
        $ids = [];
        $pageToken = null;

        do {
            $params = [
                'q' => "newer_than:{$windowDays}d",
                'labelIds' => ['INBOX'],
                'maxResults' => 500,
            ];
            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            $list = $gmail->users_messages->listUsersMessages('me', $params);

            foreach ($list->getMessages() ?? [] as $message) {
                $ids[$message->getId()] = true;
            }

            $pageToken = $list->getNextPageToken();
        } while ($pageToken !== null && $pageToken !== '');

        return array_keys($ids);
    }

    /** Fetch + normalize a single message. */
    protected function hydrate(Gmail $gmail, string $mailbox, string $id): InboundMessage
    {
        return $this->toInboundMessage(
            $mailbox,
            $gmail->users_messages->get('me', $id, ['format' => 'full']),
        );
    }

    /** The mailbox's current historyId — the fresh cursor baseline after a re-sync. */
    protected function currentHistoryId(Gmail $gmail): string
    {
        return (string) $gmail->users->getProfile('me')->getHistoryId();
    }

    /**
     * Build a Gmail client authorized to act as $mailbox.
     *
     * The credential source decides what "authorized" means — keyless domain-wide delegation, a
     * legacy JSON key, or (later) a per-tenant OAuth grant. This method only knows that the client
     * comes back ready to call the Gmail API, so fetchSince is unaffected by any of those choices.
     */
    protected function gmail(string $mailbox): Gmail
    {
        $client = new GoogleClient;
        $this->credentials->authorize($client, $mailbox);

        return new Gmail($client);
    }

    private function toInboundMessage(string $mailbox, GmailMessage $message): InboundMessage
    {
        $payload = $message->getPayload();

        /** @var array<string, string> $headers */
        $headers = [];
        foreach ($payload->getHeaders() as $header) {
            $headers[strtolower($header->getName())] = $header->getValue();
        }

        $from = $this->parseAddress($headers['from'] ?? '');
        $to = $this->parseAddress($headers['to'] ?? $mailbox);

        return new InboundMessage(
            provider: self::PROVIDER,
            mailbox: $mailbox,
            fromEmail: $from['email'],
            fromName: $from['name'],
            toEmail: $to['email'] !== '' ? $to['email'] : $mailbox,
            subject: $headers['subject'] ?? '(no subject)',
            bodyText: $this->extractBody($payload),
            messageId: $this->bareId($headers['message-id'] ?? null),
            inReplyTo: $this->bareId($headers['in-reply-to'] ?? null),
            references: $this->parseReferences($headers['references'] ?? ''),
            providerMessageId: $message->getId(),
            providerThreadId: $message->getThreadId(),
            receivedAt: isset($headers['date']) ? Carbon::parse($headers['date'])->toIso8601String() : null,
            headers: $headers,
        );
    }

    /**
     * Depth-first search for the first text/plain part; falls back to the top-level body.
     * getData() is cast defensively — the SDK types it non-null, but empty parts yield null
     * at runtime, so the cast + empty-string guard keep it safe.
     */
    private function extractBody(MessagePart $part): string
    {
        if (str_starts_with((string) $part->getMimeType(), 'text/plain')) {
            $data = (string) $part->getBody()->getData();
            if ($data !== '') {
                return $this->decodeBase64Url($data);
            }
        }

        foreach ($part->getParts() as $child) {
            $text = $this->extractBody($child);
            if ($text !== '') {
                return $text;
            }
        }

        $data = (string) $part->getBody()->getData();

        return $data !== '' ? $this->decodeBase64Url($data) : '';
    }

    private function decodeBase64Url(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }

    /**
     * @return array{email: string, name: ?string}
     */
    private function parseAddress(string $raw): array
    {
        $raw = trim($raw);
        if (preg_match('/^(.*)<([^>]+)>\s*$/', $raw, $m) === 1) {
            $name = trim($m[1], " \t\"");

            return ['email' => trim($m[2]), 'name' => $name === '' ? null : $name];
        }

        return ['email' => $raw, 'name' => null];
    }

    private function bareId(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $bare = trim($value, " \t<>");

        return $bare === '' ? null : $bare;
    }

    /**
     * @return list<string>
     */
    private function parseReferences(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $ids = [];
        foreach (preg_split('/\s+/', trim($raw)) ?: [] as $token) {
            $bare = $this->bareId($token);
            if ($bare !== null) {
                $ids[] = $bare;
            }
        }

        return $ids;
    }

    /** History ids are monotonically increasing but can exceed PHP_INT — compare as decimal strings. */
    private function cursorNewer(string $candidate, string $current): bool
    {
        return strlen($candidate) > strlen($current)
            || (strlen($candidate) === strlen($current) && $candidate > $current);
    }
}
