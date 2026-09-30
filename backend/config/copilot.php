<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Copilot AI fallback
    |--------------------------------------------------------------------------
    |
    | An OPTIONAL, additive layer under the rule-based CRM Copilot. Real intents keep their own
    | deterministic answers; only a genuine fallback or a knowledge-article match may be offered to AI.
    |
    | `enabled` is the OPS KILL SWITCH, OFF by default. AI runs only when this switch AND the Super
    | Admin toggle (Settings → Integrations → Copilot AI, table `crm_copilot_settings`) are both on and
    | the credential is available. With this off the endpoint answers "not answered" without reading
    | the toggle or any credential, and without contacting any provider.
    |
    | The credential is the existing NERBY OpenAI/OpenRouter row, read and never written.
    |
    */

    'ai' => [
        'enabled' => (bool) env('COPILOT_AI_ENABLED', false),

        // Seconds to wait for the model before the Copilot falls back to its own help reply.
        'timeout' => (int) env('COPILOT_AI_TIMEOUT', 15),

        // Bounds a cooperative provider's answer. The body is also size-checked before decoding.
        'max_tokens' => (int) env('COPILOT_AI_MAX_TOKENS', 500),

        // A little variety in wording; the facts and the validator keep it grounded.
        'temperature' => (float) env('COPILOT_AI_TEMPERATURE', 0.2),

        // Ceiling on the JSON sent to the model. Curated article text is trimmed first; CRM figures
        // are never cut.
        'max_context_bytes' => (int) env('COPILOT_AI_MAX_CONTEXT_BYTES', 8192),

        // Longest answer shown; anything longer is rejected, never truncated.
        'max_answer_chars' => (int) env('COPILOT_AI_MAX_ANSWER_CHARS', 1200),

        // AI requests per user per Asia/Manila day, on top of the route's 20 per minute. A missing
        // or non-positive value FAILS CLOSED: no AI at all.
        'daily_limit' => env('COPILOT_AI_DAILY_LIMIT', 100),

        // Circuit breaker: this many consecutive provider failures pause AI for everyone.
        'breaker_failures' => (int) env('COPILOT_AI_BREAKER_FAILURES', 5),
        'breaker_minutes' => (int) env('COPILOT_AI_BREAKER_MINUTES', 5),

        // Metadata audit rows (crm_copilot_ai_logs) older than this are pruned.
        'log_retention_days' => (int) env('COPILOT_AI_LOG_RETENTION_DAYS', 90),
    ],

];
