<?php

namespace Tests\Feature\Outreach;

use App\Modules\Crm\Services\Mail\Contracts\GoogleCredentialSource;
use App\Modules\Crm\Services\Mail\Inbound\InboundMessage;
use App\Modules\Crm\Services\Mail\Providers\GmailMailboxSync;
use Google\Client as GoogleClient;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail;
use Google\Service\Gmail\History;
use Google\Service\Gmail\HistoryMessageAdded;
use Google\Service\Gmail\ListHistoryResponse;
use Google\Service\Gmail\Message as GmailMessage;
use Tests\TestCase;

/**
 * Item #2 (R5) — cursor staleness / history-expiry recovery. Exercises GmailMailboxSync::fetchSince through
 * the thin internal seams (fetchHistoryPage / listRecentMessageIds / hydrate / currentHistoryId), so the
 * recovery + delta orchestration is tested without a live Gmail client. A 404 on the history delta means the
 * stored historyId was pruned → bounded re-sync + cursor re-baseline; any other error stays a legitimate retry.
 */
class GmailMailboxSyncRecoveryTest extends TestCase
{
    /**
     * A GmailMailboxSync whose raw Gmail seams are stubbed. Configure per test via the public props.
     */
    private function makeProvider(): GmailMailboxSync
    {
        // Credentials are never exercised — gmail() is stubbed below — but the constructor now names
        // the dependency, which is the point: authentication is no longer this class's business.
        $credentials = new class implements GoogleCredentialSource
        {
            public function authorize(GoogleClient $client, string $mailbox): void {}
        };

        return new class($credentials) extends GmailMailboxSync
        {
            /** @var callable(string, ?string): ListHistoryResponse */
            public $onFetchHistoryPage;

            /** @var list<string> */
            public array $recentIds = [];

            public string $freshCursor = '0';

            /** The window actually handed to Gmail, so tests can assert which setting was used. */
            public ?int $lastWindowDays = null;

            /** @var list<string> */
            public array $throwHydrateIds = [];

            protected function gmail(string $mailbox): Gmail
            {
                return new Gmail(new GoogleClient); // never actually called — the seams below are stubbed
            }

            protected function fetchHistoryPage(Gmail $gmail, string $startHistoryId, ?string $pageToken): ListHistoryResponse
            {
                return ($this->onFetchHistoryPage)($startHistoryId, $pageToken);
            }

            /** @return list<string> */
            protected function listRecentMessageIds(Gmail $gmail, int $windowDays): array
            {
                $this->lastWindowDays = $windowDays;

                return $this->recentIds;
            }

            protected function hydrate(Gmail $gmail, string $mailbox, string $id): InboundMessage
            {
                if (in_array($id, $this->throwHydrateIds, true)) {
                    throw new \RuntimeException('malformed message');
                }

                return new InboundMessage(
                    provider: 'gmail', mailbox: $mailbox, fromEmail: 'x@y.test', fromName: null,
                    toEmail: $mailbox, subject: 's', bodyText: 'b', messageId: $id, inReplyTo: null, references: [],
                );
            }

            protected function currentHistoryId(Gmail $gmail): string
            {
                return $this->freshCursor;
            }
        };
    }

    /** Build a one-page history response with a single messageAdded record. */
    private function historyPage(string $recordId, string $messageId): ListHistoryResponse
    {
        $message = new GmailMessage;
        $message->setId($messageId);
        $added = new HistoryMessageAdded;
        $added->setMessage($message);
        $record = new History;
        $record->setId($recordId);
        $record->setMessagesAdded([$added]);
        $response = new ListHistoryResponse;
        $response->setHistory([$record]);

        return $response; // nextPageToken null → single page
    }

    public function test_expired_cursor_404_triggers_bounded_resync_and_rebaselines_the_cursor(): void
    {
        $provider = $this->makeProvider();
        $provider->onFetchHistoryPage = function (): ListHistoryResponse {
            throw new GoogleServiceException('historyId not found', 404);
        };
        $provider->recentIds = ['m-1', 'm-2'];
        $provider->freshCursor = '5000';

        $result = $provider->fetchSince('crm@company.test', '100'); // stale stored cursor

        // Re-synced the recent window and re-baselined the cursor to "now".
        $this->assertCount(2, $result->messages);
        $this->assertSame(['m-1', 'm-2'], array_map(fn (InboundMessage $m) => $m->messageId, $result->messages));
        $this->assertSame('5000', $result->cursor);
    }

    /**
     * The polling bootstrap, at the DEFAULT setting. Under Pub/Sub, `watch()` established the first
     * cursor; polling has no such step, so a null cursor must still come back with a usable cursor or
     * a newly registered mailbox would never sync at all.
     *
     * Default is 0 — baseline only. A cursor is taken, nothing historical is imported, and syncing
     * runs from this moment forward.
     */
    public function test_a_null_cursor_baselines_without_importing_history_by_default(): void
    {
        config(['outreach.inbound.initial_sync_days' => 0]);
        $provider = $this->makeProvider();
        // Proves the delta path is not consulted at all: with no cursor there is nothing to delta from.
        $provider->onFetchHistoryPage = function (): ListHistoryResponse {
            throw new \LogicException('history must not be walked without a cursor');
        };
        // Present but must go untouched — at zero the listing is skipped entirely.
        $provider->recentIds = ['must-not-be-imported'];
        $provider->freshCursor = '9100';

        $result = $provider->fetchSince('crm@company.test', null);

        $this->assertSame([], $result->messages, 'Zero means baseline only — import nothing.');
        // The cursor still comes back, so the next poll is incremental rather than re-bootstrapping.
        $this->assertSame('9100', $result->cursor);
    }

    /** A non-zero setting opts INTO importing that many days of history on the first poll. */
    public function test_the_initial_sync_window_is_configurable(): void
    {
        config(['outreach.inbound.initial_sync_days' => 30]);
        $provider = $this->makeProvider();
        $provider->recentIds = ['boot-1', 'boot-2'];
        $provider->freshCursor = '9200';

        $result = $provider->fetchSince('crm@company.test', null);

        $this->assertSame(
            ['boot-1', 'boot-2'],
            array_map(fn (InboundMessage $m) => $m->messageId, $result->messages),
        );
        $this->assertSame(30, $provider->lastWindowDays, 'The configured window must reach Gmail.');
        $this->assertSame('9200', $result->cursor);
    }

    /** A negative value is clamped, not passed through as `newer_than:-3d`. */
    public function test_a_negative_initial_sync_window_is_clamped_to_baseline_only(): void
    {
        config(['outreach.inbound.initial_sync_days' => -3]);
        $provider = $this->makeProvider();
        $provider->recentIds = ['must-not-be-imported'];
        $provider->freshCursor = '9300';

        $result = $provider->fetchSince('crm@company.test', null);

        $this->assertSame([], $result->messages);
        $this->assertSame('9300', $result->cursor);
    }

    /**
     * The two windows are independent. Setting the onboarding window to 0 means "start this mailbox
     * from today" — it must NOT also mean "discard mail whenever Gmail prunes a cursor", which is a
     * data-loss event with its own window.
     */
    public function test_recovery_uses_its_own_window_even_when_initial_sync_is_disabled(): void
    {
        config([
            'outreach.inbound.initial_sync_days' => 0,
            'outreach.inbound.resync_window_days' => 14,
        ]);
        $provider = $this->makeProvider();
        $provider->onFetchHistoryPage = function (): ListHistoryResponse {
            throw new GoogleServiceException('historyId not found', 404);
        };
        $provider->recentIds = ['salvaged-1'];
        $provider->freshCursor = '9400';

        $result = $provider->fetchSince('crm@company.test', '100'); // stale stored cursor

        $this->assertCount(1, $result->messages, 'Recovery must still salvage the window.');
        $this->assertSame(14, $provider->lastWindowDays);
        $this->assertSame('9400', $result->cursor);
    }

    public function test_non_404_history_error_propagates_as_a_legitimate_retry(): void
    {
        $provider = $this->makeProvider();
        $provider->onFetchHistoryPage = function (): ListHistoryResponse {
            throw new GoogleServiceException('backend error', 500);
        };
        $provider->recentIds = ['should-not-be-used'];

        // A 5xx must NOT trigger recovery — it re-throws so the queue retries.
        $this->expectException(GoogleServiceException::class);
        $provider->fetchSince('crm@company.test', '100');
    }

    public function test_valid_cursor_uses_the_normal_delta_path(): void
    {
        $provider = $this->makeProvider();
        $provider->onFetchHistoryPage = fn (): ListHistoryResponse => $this->historyPage('4300', 'h-1');

        $result = $provider->fetchSince('crm@company.test', '100');

        $this->assertCount(1, $result->messages);
        $this->assertSame('h-1', $result->messages[0]->messageId);
        $this->assertSame('4300', $result->cursor); // advanced to the newest history record id
    }

    public function test_resync_isolates_a_poison_message(): void
    {
        $provider = $this->makeProvider();
        $provider->onFetchHistoryPage = function (): ListHistoryResponse {
            throw new GoogleServiceException('historyId not found', 404);
        };
        $provider->recentIds = ['good', 'poison'];
        $provider->throwHydrateIds = ['poison'];
        $provider->freshCursor = '6000';

        $result = $provider->fetchSince('crm@company.test', '100');

        // Poison skipped during re-sync; good one recovered; cursor still re-baselined.
        $this->assertCount(1, $result->messages);
        $this->assertSame('good', $result->messages[0]->messageId);
        $this->assertSame('6000', $result->cursor);
    }
}
