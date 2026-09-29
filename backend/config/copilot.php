<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Copilot AI fallback
    |--------------------------------------------------------------------------
    |
    | An OPTIONAL, additive layer under the rule-based CRM Copilot. The Copilot answers every question
    | it can on its own; only a question that reaches its `help` fallback may be offered to AI, and
    | only when this switch is on. OFF by default: with it off the endpoint answers "not answered"
    | without reading any credential or contacting any provider.
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
