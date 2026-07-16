<?php

namespace App\Modules\Crm\Services\Mail\Providers;

use App\Modules\Crm\Services\Mail\Contracts\MailboxSyncProvider;
use App\Modules\Crm\Services\Mail\Inbound\InboundMessage;
use App\Modules\Crm\Services\Mail\Inbound\MailboxFetchResult;
use App\Modules\Crm\Services\Mail\Inbound\NotificationCursor;
use App\Modules\Crm\Services\Mail\Inbound\WatchRegistration;
use Google\Auth\ApplicationDefaultCredentials;
use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Google\Service\Gmail\Message as GmailMessage;
use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\WatchRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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

    /**
     * Build a Gmail client authenticated to impersonate $mailbox via domain-wide delegation.
     *
     * KEYLESS by default (org policy: no downloadable service-account key) — the delegation assertion
     * is signed via the IAM Credentials signJwt API using the runtime identity (ADC / Workload Identity
     * Federation), never a local private key. The legacy JSON-key path is retained only as an optional
     * `auth=key` fallback for environments where key creation is permitted. Only the authentication
     * mechanism differs; every caller (watch/unwatch/fetchSince) is unchanged.
     */
    private function gmail(string $mailbox): Gmail
    {
        $client = new GoogleClient;
        $client->setScopes((array) ($this->config['scopes'] ?? []));

        if (($this->config['auth'] ?? 'keyless') === 'key') {
            // Legacy fallback: downloadable service-account JSON key signs the delegation locally.
            $client->setAuthConfig((string) $this->config['credentials']);
            $client->setSubject($mailbox); // domain-wide delegation: impersonate the mailbox
        } else {
            // Preferred: a keyless, domain-wide-delegated Gmail access token (IAM signJwt → jwt-bearer).
            $client->setAccessToken($this->keylessDelegatedToken($mailbox));
        }

        return new Gmail($client);
    }

    /**
     * Mint a domain-wide-delegated Gmail access token for $mailbox WITHOUT a service-account key
     * (honors iam.disableServiceAccountKeyCreation). Cached (~50 min) to avoid re-minting each sync.
     *
     * @return array{access_token: string, expires_in: int}
     */
    private function keylessDelegatedToken(string $mailbox): array
    {
        $sa = (string) ($this->config['service_account'] ?? '');
        if ($sa === '') {
            throw new RuntimeException(
                'Keyless Gmail auth requires outreach.gmail.service_account (env GMAIL_SYNC_SERVICE_ACCOUNT).'
            );
        }

        $scopes = implode(' ', (array) ($this->config['scopes'] ?? []));

        /** @var array{access_token: string, expires_in: int} $token */
        $token = Cache::remember(
            "outreach:gmail:dwd-token:{$sa}:{$mailbox}",
            3000, // access tokens live ~1h; refresh a little early
            function () use ($sa, $mailbox, $scopes): array {
                $now = time();
                $assertion = $this->signDelegatedJwt($sa, [
                    'iss' => $sa,
                    'sub' => $mailbox, // the impersonated mailbox — domain-wide delegation
                    'scope' => $scopes,
                    'aud' => 'https://oauth2.googleapis.com/token',
                    'iat' => $now,
                    'exp' => $now + 3600,
                ]);

                $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]);

                if ($response->failed()) {
                    throw new RuntimeException('Gmail keyless token exchange failed: '.$response->body());
                }

                return [
                    'access_token' => (string) $response->json('access_token'),
                    'expires_in' => (int) $response->json('expires_in', 3600),
                ];
            },
        );

        return $token;
    }

    /**
     * Sign a JWT AS the service account via the IAM Credentials signJwt API — no private key. The source
     * identity comes from ADC (Workload Identity Federation off-GCP, or an attached SA on-GCP) and must
     * hold roles/iam.serviceAccountTokenCreator on $sa. This is the single keyed→keyless substitution.
     *
     * @param  array<string, mixed>  $claims
     */
    private function signDelegatedJwt(string $sa, array $claims): string
    {
        $source = ApplicationDefaultCredentials::getCredentials('https://www.googleapis.com/auth/cloud-platform');
        $bearer = (string) ($source->fetchAuthToken()['access_token'] ?? '');
        if ($bearer === '') {
            throw new RuntimeException(
                'No ADC source token for IAM signJwt — check Workload Identity Federation / GOOGLE_APPLICATION_CREDENTIALS.'
            );
        }

        $response = Http::withToken($bearer)->post(
            "https://iamcredentials.googleapis.com/v1/projects/-/serviceAccounts/{$sa}:signJwt",
            ['payload' => (string) json_encode($claims)],
        );

        if ($response->failed()) {
            throw new RuntimeException("IAM signJwt failed for {$sa}: ".$response->body());
        }

        return (string) $response->json('signedJwt');
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
