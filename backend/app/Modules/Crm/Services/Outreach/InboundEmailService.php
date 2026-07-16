<?php

namespace App\Modules\Crm\Services\Outreach;

use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Mail\Inbound\InboundMessage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ingests a normalized inbound email (from any MailboxSyncProvider): idempotent-persists it
 * into the existing `emails` history and, for a matched human reply, writes an `email.received`
 * Activity via the EXISTING centralized Activity log (`$lead->activities()->create` — same
 * mechanism as LeadObserver / OutreachEmailService). No new activity system; the Activity flows
 * to `/activities`, Dashboard Recent Activity, and future lead timelines automatically.
 */
class InboundEmailService
{
    /**
     * @return Email|null the persisted (or pre-existing) inbound row; null only if it could not persist
     */
    public function ingest(InboundMessage $message): ?Email
    {
        // Idempotency (layer 1): already-seen Message-ID → return the existing row, no duplicate.
        if ($message->messageId !== null) {
            $existing = Email::query()
                ->where('provider', $message->provider)
                ->where('message_id', $message->messageId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        [$leadId, $threadId, $strategy] = $this->match($message);

        try {
            $email = DB::transaction(function () use ($message, $leadId, $threadId): Email {
                $email = Email::create([
                    'lead_id' => $leadId,
                    'user_id' => null,
                    'direction' => 'inbound',
                    'from_email' => $message->fromEmail,
                    'from_name' => $message->fromName,
                    'to_email' => $message->toEmail,
                    'subject' => $message->subject,
                    'body' => $message->bodyText,
                    'provider' => $message->provider,
                    'provider_message_id' => $message->providerMessageId,
                    'message_id' => $message->messageId,
                    'in_reply_to' => $message->inReplyTo,
                    'references' => $message->references === [] ? null : implode(' ', $message->references),
                    'thread_id' => $threadId,
                    'status' => 'received',
                    'received_at' => $message->receivedAt,
                    'raw_headers' => $message->headers,
                ]);

                // Only a matched, human reply becomes a lead-visible Activity. Automated traffic
                // (auto-replies, vacation responders, bounces) is persisted but not surfaced.
                if ($leadId !== null && ! $this->isAutomated($message)) {
                    /** @var Lead|null $lead */
                    $lead = Lead::find($leadId);
                    $lead?->activities()->create([
                        'causer_id' => null, // inbound has no CRM user → renders as "System"
                        'event' => 'email.received',
                        'description' => "New reply from {$this->senderLabel($message)}",
                        'properties' => [
                            'email_id' => $email->id,
                            'from' => $message->fromEmail,
                            'from_name' => $message->fromName,
                            'subject' => $message->subject,
                            'message_id' => $message->messageId,
                            'thread_id' => $threadId,
                        ],
                    ]);
                }

                return $email;
            });
        } catch (QueryException $e) {
            // Idempotency (layer 2): lost a race on the unique (provider, message_id) index →
            // treat the winning row as the result rather than erroring the whole notification.
            if ($message->messageId !== null) {
                $winner = Email::query()
                    ->where('provider', $message->provider)
                    ->where('message_id', $message->messageId)
                    ->first();

                if ($winner !== null) {
                    return $winner;
                }
            }

            throw $e;
        }

        // Observability (design §8): record how each inbound message was matched. A rising
        // `unmatched` rate is the signal that threading/matching is degrading.
        Log::info('outreach.inbound.ingested', [
            'provider' => $message->provider,
            'message_id' => $message->messageId,
            'lead_id' => $leadId,
            'match_strategy' => $strategy,
        ]);

        return $email;
    }

    /**
     * Match an inbound message to a lead + thread. Ordered by confidence.
     *
     * @return array{0: int|null, 1: string|null, 2: string} [leadId, threadId, strategy]
     */
    private function match(InboundMessage $message): array
    {
        // 1. Header threading: In-Reply-To / References → a prior outbound Message-ID.
        $candidateIds = array_values(array_filter(
            array_merge([$message->inReplyTo], $message->references),
            static fn (?string $id): bool => $id !== null && $id !== '',
        ));

        if ($candidateIds !== []) {
            $outbound = Email::query()
                ->whereIn('message_id', $candidateIds)
                ->orderByDesc('id')
                ->first();

            if ($outbound !== null) {
                return [$outbound->lead_id, $outbound->thread_id ?? $outbound->message_id, 'header-thread'];
            }
        }

        // 2. Provider-native thread id (e.g. Gmail threadId) against a known outbound thread.
        if ($message->providerThreadId !== null) {
            $outbound = Email::query()
                ->where('thread_id', $message->providerThreadId)
                ->orderByDesc('id')
                ->first();

            if ($outbound !== null) {
                return [$outbound->lead_id, $message->providerThreadId, 'provider-thread'];
            }
        }

        // 3. Sender address → lead email (case-insensitive).
        $lead = Lead::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($message->fromEmail)])
            ->orderByDesc('id')
            ->first();

        if ($lead !== null) {
            return [$lead->id, $message->providerThreadId ?? $message->inReplyTo ?? $message->messageId, 'sender-match'];
        }

        // 4. Unmatched — persisted with a null lead for later manual association; never dropped.
        return [null, $message->providerThreadId ?? $message->messageId, 'unmatched'];
    }

    private function isAutomated(InboundMessage $message): bool
    {
        $autoSubmitted = strtolower($message->headers['auto-submitted'] ?? 'no');
        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return true;
        }

        if (in_array(strtolower($message->headers['precedence'] ?? ''), ['bulk', 'list', 'junk'], true)) {
            return true;
        }

        return str_contains(strtolower($message->fromEmail), 'mailer-daemon')
            || str_contains(strtolower($message->fromEmail), 'postmaster@');
    }

    private function senderLabel(InboundMessage $message): string
    {
        return $message->fromName !== null && $message->fromName !== ''
            ? $message->fromName
            : $message->fromEmail;
    }
}
