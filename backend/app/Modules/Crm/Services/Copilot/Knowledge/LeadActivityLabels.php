<?php

namespace App\Modules\Crm\Services\Copilot\Knowledge;

/**
 * THE CODE-OWNED DECISION of which `crm_activities` rows the Copilot's model may hear about, and in
 * what words (Phase 2 Slice 4, PO decisions 2026-10-01).
 *
 * Only sales-relevant work the lead actually received is listed. Each entry maps to a KIND (what is
 * counted) and a fixed LABEL (what the model reads). The row's own description and properties are
 * never supplied: they carry names, email subjects, planner titles, loss reasons and deal amounts.
 * Two single property values are read server-side to pick a label — a call's `outcome` and a planner
 * event's `event_type` — and only a value listed here yields one.
 *
 * Fail-closed: an event, call outcome or planner type that is not listed is excluded. Excluded on
 * purpose:
 *   - admin and system changes: `created`, `lead.created`, `updated`, `deleted`, `lead.restored`,
 *     `lead.assigned`, `lead.qualified`, `planner-event.reassigned|updated|deleted`,
 *     `note.updated|deleted`;
 *   - planned, not performed: `planner-event.created`, `call.started`;
 *   - a discarded (`cancelled`) or technically `failed` call;
 *   - a completed planner `call` or `email`, which the real `call.completed` / `email.sent` already
 *     record, so one touch is never counted twice;
 *   - `conversation.*` (the messaging inbox), until its migrations and QA are available.
 */
final class LeadActivityLabels
{
    public const CONNECTED_CALL = 'connected_call';

    public const UNCONNECTED_CALL = 'unconnected_call';

    public const EMAIL_SENT = 'email_sent';

    public const EMAIL_REPLY = 'email_reply';

    public const NOTE = 'note';

    public const FOLLOW_UP = 'follow_up';

    public const MEETING = 'meeting';

    public const CONSULTATION = 'consultation';

    public const TASK = 'task';

    public const WON = 'won';

    public const LOST = 'lost';

    /** Every event that can yield a label. The retriever reads no other rows. */
    public const EVENTS = [
        'call.completed', 'email.sent', 'email.received', 'note.created',
        'planner-event.completed', 'lead.won', 'lead.lost',
    ];

    /** Events with one label regardless of their properties. */
    private const SIMPLE = [
        'email.sent' => [self::EMAIL_SENT, 'Email sent'],
        'email.received' => [self::EMAIL_REPLY, 'Email reply received'],
        'note.created' => [self::NOTE, 'Note added'],
        'lead.won' => [self::WON, 'Marked won'],
        'lead.lost' => [self::LOST, 'Marked lost'],
    ];

    /** `call.completed` by its `outcome` (CallOutcome). Cancelled and failed calls are not listed. */
    private const CALL_OUTCOMES = [
        'connected' => [self::CONNECTED_CALL, 'Connected call'],
        'no_answer' => [self::UNCONNECTED_CALL, 'Call attempt (no answer)'],
        'busy' => [self::UNCONNECTED_CALL, 'Call attempt (busy)'],
        'voicemail' => [self::UNCONNECTED_CALL, 'Call attempt (voicemail)'],
    ];

    /** `planner-event.completed` by its `event_type`. `call` and `email` are not listed (see above). */
    private const PLANNER_TYPES = [
        'follow-up' => [self::FOLLOW_UP, 'Follow-up completed'],
        'meeting' => [self::MEETING, 'Meeting completed'],
        'consultation' => [self::CONSULTATION, 'Consultation completed'],
        'task' => [self::TASK, 'Task completed'],
    ];

    /** The kinds, in the order their counts are supplied. */
    public const KINDS = [
        self::CONNECTED_CALL, self::UNCONNECTED_CALL, self::EMAIL_SENT, self::EMAIL_REPLY, self::NOTE,
        self::FOLLOW_UP, self::MEETING, self::CONSULTATION, self::TASK, self::WON, self::LOST,
    ];

    /**
     * The kind and fixed label for one activity row, or null when it is not allowlisted.
     *
     * @return array{0: string, 1: string}|null [kind, label]
     */
    public static function classify(string $event, mixed $outcome, mixed $eventType): ?array
    {
        return match ($event) {
            'call.completed' => is_string($outcome) ? (self::CALL_OUTCOMES[$outcome] ?? null) : null,
            'planner-event.completed' => is_string($eventType) ? (self::PLANNER_TYPES[$eventType] ?? null) : null,
            default => self::SIMPLE[$event] ?? null,
        };
    }
}
