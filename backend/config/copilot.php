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
        'max_tokens' => (int) env('COPILOT_AI_MAX_TOKENS', 300),
    ],

];
