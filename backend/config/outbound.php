<?php

declare(strict_types=1);

use App\Modules\Crm\Services\Outbound\Meta\Adapters\MetaOutboundInstagramAdapter;
use App\Modules\Crm\Services\Outbound\Meta\Adapters\MetaOutboundMessengerAdapter;

return [

    /*
    |--------------------------------------------------------------------------
    | Outbound Messaging Foundation (Phase 3F.1)
    |--------------------------------------------------------------------------
    |
    | Replying to a conversation. Separate from config/inbound.php because the
    | two answer different questions: inbound enumerates what we will ACCEPT,
    | outbound enumerates what we may SAY and through which integration. One
    | file would make a change to either side a review of both.
    |
    | NO SECRETS LIVE HERE, and no flag in this file can enable sending. The
    | only thing that could is a credential resolver with credentials to give,
    | and the one wired in this phase has none by construction.
    |
    */

    /*
    | Adapter classes. Every class must implement OutboundProviderAdapter, and
    | exactly one may claim any provider/channel pair — OutboundAdapterRegistry
    | refuses both zero and two. This list is the ONLY source of adapter
    | classes: nothing is discovered by scanning, and no name that arrived in a
    | request is ever turned into a class.
    |
    | Both Meta adapters are listed and both are inert: each refuses in send()
    | because Phase 3F.1 writes no transport. Listing them now means adapter
    | selection, resource resolution and validation are all exercised by the
    | real classes rather than by a fixture that could drift from them.
    |
    | The fixture adapter used by the test suite lives under tests/ and is
    | registered by setting this key inside a test. It must never be listed
    | here, and a test asserts that it is not.
    */
    'adapters' => [
        MetaOutboundMessengerAdapter::class,
        MetaOutboundInstagramAdapter::class,
    ],

    /*
    | Which message types the outbound path will consider at all. Adapters
    | narrow this further — both Meta adapters take text only — but a type
    | absent here can never reach one.
    */
    'message_types' => [
        'text',
    ],

];
