<?php

namespace Tests\Feature\Outreach;

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
        return new class([]) extends GmailMailboxSync
        {
            /** @var callable(string, ?string): ListHistoryResponse */
            public $onFetchHistoryPage;

            /** @var list<string> */
            public array $recentIds = [];

            public string $freshCursor = '0';

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
