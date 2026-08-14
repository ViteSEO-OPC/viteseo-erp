<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-Module Sender Identities
    |--------------------------------------------------------------------------
    |
    | The platform is one application sending as several businesses. The TRANSPORT
    | above stays shared and centralized — one relay, one set of credentials — while
    | each module sends under its own identity. Nothing here configures a mailer;
    | adding a module never means adding SMTP.
    |
    | Read through App\Support\Mail\ModuleSenderResolver, never directly. Anything
    | left unset falls back to the global `from` above, so a deployment that has not
    | set these keeps sending exactly as it did before.
    |
    | The nested env chains are the backward-compatibility contract: CRM shipped with
    | OUTREACH_FROM_* (see config/outreach.php), so those keep winning over the global
    | default and a production deployment needs no env change to preserve behaviour.
    |
    | ── CRM INVARIANT ────────────────────────────────────────────────────────────
    | `crm.address` MUST resolve to the same mailbox as `outreach.mailbox` — that is
    | the inbox Gmail sync polls for prospect replies. Point them at different
    | addresses and inbound reply threading stops silently. Guarded by
    | tests/Feature/Mail/ModuleSenderIdentityTest.
    |
    */

    'module_senders' => [

        // Zerem CRM — customer outreach and internal staff alerts.
        'crm' => [
            'address' => env('CRM_MAIL_FROM_ADDRESS', env('OUTREACH_FROM_ADDRESS', env('MAIL_FROM_ADDRESS'))),
            'name' => env('CRM_MAIL_FROM_NAME', env('OUTREACH_FROM_NAME', env('MAIL_FROM_NAME'))),
            'reply_to' => env('CRM_MAIL_REPLY_TO_ADDRESS'),
        ],

        // HRIS — candidate recruitment mail.
        'hr' => [
            'address' => env('HR_MAIL_FROM_ADDRESS', env('MAIL_FROM_ADDRESS')),
            'name' => env('HR_MAIL_FROM_NAME', env('MAIL_FROM_NAME')),
            'reply_to' => env('HR_MAIL_REPLY_TO_ADDRESS'),
        ],

        // PMS/Projects — registered ahead of its first use; the module sends no mail yet.
        'pms' => [
            'address' => env('PMS_MAIL_FROM_ADDRESS', env('MAIL_FROM_ADDRESS')),
            'name' => env('PMS_MAIL_FROM_NAME', env('MAIL_FROM_NAME')),
            'reply_to' => env('PMS_MAIL_REPLY_TO_ADDRESS'),
        ],

    ],

];
