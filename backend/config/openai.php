<?php

/**
 * OpenAI — the CRM's optional intelligence layer.
 *
 * THE PRODUCT PRINCIPLE THIS CONFIG ENCODES: OpenAI does not replace a deterministic system, it
 * makes one smarter. Every capability wired through here is ADDITIVE — it runs only where a
 * deterministic pipeline has already produced an inconclusive result, and its absence must leave
 * the system behaving exactly as it did before. That is why `enabled` defaults to FALSE: an
 * environment that never sets these variables gets today's behaviour, byte for byte.
 *
 * Nothing here is ever exposed to the frontend. The key is server-side only; the browser neither
 * holds it nor calls OpenAI.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    | OFF by default. Turning this on is a deliberate operational act, and every consumer must
    | still degrade to its deterministic result when the call fails — the flag buys capability,
    | never a dependency.
    */
    'enabled' => (bool) env('OPENAI_ENABLED', false),

    // Server-side only. An empty key is treated exactly like `enabled = false`, so a
    // half-configured environment degrades rather than erroring on every audit.
    'api_key' => env('OPENAI_API_KEY'),

    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),

    /*
    |--------------------------------------------------------------------------
    | Model
    |--------------------------------------------------------------------------
    | Pinned deliberately. The adjudicator records the model that produced each verdict in the
    | audit trail, so a floating alias would make historical verdicts unexplainable — two rows
    | could carry the same model string and have been decided by different models.
    */
    'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),

    /*
    |--------------------------------------------------------------------------
    | Request budget
    |--------------------------------------------------------------------------
    | A tight timeout and NO retries. This layer sits inside the Website Intelligence audit's
    | write path, which processes a queue: a slow or flapping API must cost one lead a few
    | seconds and then fall back, never stall the batch behind it. Retrying a judgement call is
    | not worth a doubled tail latency.
    */
    'timeout' => (float) env('OPENAI_TIMEOUT', 8),

    // Adjudication answers with a short JSON object; this caps a runaway completion.
    'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS', 300),
];
