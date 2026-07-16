<?php

namespace App\Modules\Crm\Services\Mail\Providers;

use App\Modules\Crm\Services\Mail\Contracts\MailboxSyncProvider;
use App\Modules\Crm\Services\Mail\Inbound\InboundMessage;
use App\Modules\Crm\Services\Mail\Inbound\MailboxFetchResult;
use App\Modules\Crm\Services\Mail\Inbound\NotificationCursor;
use App\Modules\Crm\Services\Mail\Inbound\WatchRegistration;
use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Google\Service\Gmail\Message as GmailMessage;
use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\WatchRequest;
use Illuminate\Support\Carbon;

/**
 * Gmail push implementation of MailboxSyncProvider (Phase-1 inbound provider). Uses a
 * Google Workspace service account with domain-wide delegation to read the mailbox, and
 * normalizes each Gmail message to the provider-agnostic InboundMessage. The business layer
 * (InboundEmailService) never sees anything Gmail-specific.
 */
class GmailMailboxSync implements MailboxSyncProvider
{
    private const PROVIDER = 'gmail';

    /**
     * @param  array<string, mixed>  $config  keys: credentials (SA key path), topic (Pub/Sub), scopes
     */
    public function __construct(private readonly array $config) {}

    public function watch(string $mailbox): WatchRegistration
    {
        $response = $this->gmail($mailbox)->users->watch('me', new WatchRequest([
            'topicName' => $this->config['topic'],
            'labelIds' => ['INBOX'],
            'labelFilterBehavior' => 'INCLUDE',
        ]));

        $expiration = $response->getExpiration();

        return new WatchRegistration(
            cursor: (string) $response->getHistoryId(),
            expiresAt: $expiration !== null
                ? Carbon::createFromTimestampMs((int) $expiration)->toIso8601String()
                : null,
        );
    }

    public function unwatch(string $mailbox): void
    {
        $this->gmail($mailbox)->users->stop('me');
    }

    public function fetchSince(string $mailbox, ?string $cursor): MailboxFetchResult
    {
        // Without a cursor there is nothing to replay — watch() must establish one first.
        if ($cursor === null) {
            return new MailboxFetchResult([], null);
        }

        $gmail = $this->gmail($mailbox);
        $latestCursor = $cursor;
        /** @var array<string, true> $messageIds */
        $messageIds = [];
        $pageToken = null;

        do {
            $params = [
                'startHistoryId' => $cursor,
                'historyTypes' => 'messageAdded',
                'maxResults' => 500,
            ];
            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            $history = $gmail->users_history->listUsersHistory('me', $params);

            foreach ($history->getHistory() as $record) {
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

        $messages = [];
        foreach (array_keys($messageIds) as $id) {
            $messages[] = $this->toInboundMessage(
                $mailbox,
                $gmail->users_messages->get('me', $id, ['format' => 'full']),
            );
        }

        return new MailboxFetchResult($messages, $latestCursor);
    }

    public function parseNotification(array $payload): NotificationCursor
    {
        $data = $payload['message']['data'] ?? null;
        $decoded = is_string($data) ? json_decode((string) base64_decode($data, true), true) : null;

        $mailbox = is_array($decoded) ? (string) ($decoded['emailAddress'] ?? '') : '';
        $cursor = is_array($decoded) && isset($decoded['historyId'])
            ? (string) $decoded['historyId']
            : null;

        return new NotificationCursor(mailbox: $mailbox, cursor: $cursor);
    }

    private function gmail(string $mailbox): Gmail
    {
        $client = new GoogleClient;
        $client->setAuthConfig((string) $this->config['credentials']);
        $client->setScopes((array) ($this->config['scopes'] ?? []));
        $client->setSubject($mailbox); // domain-wide delegation: impersonate the mailbox

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
