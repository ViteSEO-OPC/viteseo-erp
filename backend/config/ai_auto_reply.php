<?php

/**
 * Guarded AI auto-reply — Zerem CRM Phase 3I (V1).
 *
 * A model writes a reply to a NEW inbound Messenger / Instagram text and it is SENT with no human
 * review, through the ordinary outbound service. Because nobody reads the text first, every knob
 * here fails CLOSED: off by default, an empty allowlist allows nothing, and an unset actor refuses.
 *
 * THREE SWITCHES MUST ALL BE ON, and each is owned by a different review:
 *   - `ai_auto_reply.enabled` (this file) — the automation itself;
 *   - `ai_drafts.enabled` + a key — the model endpoint (config/ai_drafts.php);
 *   - `meta.outbound.enabled` — the Meta transport (config/meta.php).
 * Turning off ANY one stops auto-replies without touching the others.
 *
 * NO ALLOW-ALL. `conversation_ids` names individual conversations. There is deliberately no "*"
 * or "all" value: widening the blast radius must be a list someone wrote down, not a wildcard.
 */

// Positive integers only. Anything else in the list ("12abc", "-3", "*") is dropped rather than
// coerced, so a typo narrows the allowlist instead of widening it.
$conversationIds = array_values(array_unique(array_map(
    'intval',
    array_filter(
        array_map('trim', explode(',', (string) env('AI_AUTO_REPLY_CONVERSATION_IDS', ''))),
        static fn (string $id): bool => $id !== '' && ctype_digit($id) && (int) $id > 0,
    ),
)));

return [

    // Master switch. Turning this on is a deliberate operational act. Strict boolean parsing: only
    // true/1/on/yes enable it; "off", "no", "disabled" or a typo stay OFF (a plain (bool) cast would
    // read any non-empty string such as "off" as true — fail-open).
    'enabled' => filter_var(env('AI_AUTO_REPLY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Allowlisted crm_conversations ids. EMPTY = no conversation is auto-replied.
    'conversation_ids' => $conversationIds,

    /*
    | The CRM user the reply is sent AS (causer on the Activity, actor for the outbound permission
    | check). Must be an active, non-suspended member of the conversation's org holding
    | crm.conversations.reply through a real grant (direct or via a role such as crm_admin), never
    | via the Gate::before bypass; super_admin is refused explicitly. 0 = refuse every auto-reply.
    */
    'actor_user_id' => (int) env('AI_AUTO_REPLY_ACTOR_USER_ID', 0),

    // Queue delay after the inbound turn, so a burst of customer messages yields ONE reply (to the
    // newest); earlier turns' jobs skip as `superseded`.
    'debounce_seconds' => (int) env('AI_AUTO_REPLY_DEBOUNCE_SECONDS', 15),

    // Per conversation: minimum spacing between replies, and a rolling hourly ceiling.
    'min_seconds_between' => (int) env('AI_AUTO_REPLY_MIN_SECONDS_BETWEEN', 120),
    'max_per_conversation_hour' => (int) env('AI_AUTO_REPLY_MAX_PER_CONVERSATION_HOUR', 3),

    // Across ALL orgs: a cost and blast-radius ceiling for the whole host.
    'max_global_hour' => (int) env('AI_AUTO_REPLY_MAX_GLOBAL_HOUR', 30),

    // A trigger older than this (a backed-up queue, a replayed backlog) is never answered.
    'max_trigger_age_seconds' => (int) env('AI_AUTO_REPLY_MAX_TRIGGER_AGE_SECONDS', 600),

    // A longer model output is REJECTED, never truncated: a cut sentence can change its meaning.
    'max_reply_chars' => 1000,

    // Fixed in code, not env: widening either is a code review, not a config change.
    'channels' => ['messenger', 'instagram_dm'],
    'message_types' => ['text'],
];
