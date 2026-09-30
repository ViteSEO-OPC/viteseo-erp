<?php

declare(strict_types=1);

namespace Tests\Support\Inbound\Meta;

/**
 * Meta webhook envelopes, as fixtures.
 *
 * SHAPE CAVEAT, STATED WHERE IT CANNOT BE MISSED: these follow Meta's published envelope structure
 * (`object`, `entry[]`, `messaging[]`, `changes[].field=leadgen`), built from documentation rather
 * than from captured traffic, because this phase has no Meta developer access. They are good enough
 * to prove the CRM's own behaviour — grouping, tenancy, limits, idempotency, logging — and they
 * must be re-verified against real deliveries before this goes live. Nothing outside the Meta
 * adapter/classifier depends on these shapes, which is the point of the layering.
 */
final class MetaEnvelopes
{
    public const PAGE_A = '100000000000001';

    public const PAGE_B = '100000000000002';

    public const IG_A = '200000000000001';

    public const PSID = '3000000000001';

    public const IGSID = '4000000000001';

    /** Meta timestamps are milliseconds. */
    public const TS = 1758441600000;

    /** @param  list<array<string, mixed>>  $items */
    public static function page(string $pageId, array $items): array
    {
        return ['object' => 'page', 'entry' => [['id' => $pageId, 'time' => self::TS, 'messaging' => $items]]];
    }

    /** @param  list<array<string, mixed>>  $items */
    public static function instagram(string $igId, array $items): array
    {
        return ['object' => 'instagram', 'entry' => [['id' => $igId, 'time' => self::TS, 'messaging' => $items]]];
    }

    // ---------------------------------------------------------------- Messenger items

    public static function textItem(string $mid = 'mid.text1', string $text = 'Magkano po ang SEO package?', ?string $psid = null): array
    {
        return [
            'sender' => ['id' => $psid ?? self::PSID],
            'recipient' => ['id' => self::PAGE_A],
            'timestamp' => self::TS,
            'message' => ['mid' => $mid, 'text' => $text],
        ];
    }

    public static function attachmentItem(string $type = 'image', string $mid = 'mid.att1'): array
    {
        return [
            'sender' => ['id' => self::PSID],
            'recipient' => ['id' => self::PAGE_A],
            'timestamp' => self::TS,
            'message' => [
                'mid' => $mid,
                'attachments' => [['type' => $type, 'payload' => ['url' => 'https://example.test/asset']]],
            ],
        ];
    }

    /** Text AND two attachments under ONE mid — the mixed case the DTO cannot fully name. */
    public static function mixedItem(string $mid = 'mid.mixed1'): array
    {
        return [
            'sender' => ['id' => self::PSID],
            'recipient' => ['id' => self::PAGE_A],
            'timestamp' => self::TS,
            'message' => [
                'mid' => $mid,
                'text' => 'Here are the photos',
                'attachments' => [
                    ['type' => 'image', 'payload' => ['url' => 'https://example.test/one']],
                    ['type' => 'video', 'payload' => ['url' => 'https://example.test/two']],
                ],
            ],
        ];
    }

    public static function postbackItem(): array
    {
        return [
            'sender' => ['id' => self::PSID],
            'recipient' => ['id' => self::PAGE_A],
            'timestamp' => self::TS,
            'postback' => ['title' => 'Get a quote', 'payload' => 'QUOTE_FLOW'],
        ];
    }

    public static function echoItem(): array
    {
        return [
            'sender' => ['id' => self::PAGE_A],
            'recipient' => ['id' => self::PSID],
            'timestamp' => self::TS,
            'message' => ['mid' => 'mid.echo1', 'is_echo' => true, 'text' => 'our own reply'],
        ];
    }

    public static function deliveryItem(): array
    {
        return [
            'sender' => ['id' => self::PSID],
            'recipient' => ['id' => self::PAGE_A],
            'delivery' => ['mids' => ['mid.text1'], 'watermark' => self::TS],
        ];
    }

    public static function readItem(): array
    {
        return [
            'sender' => ['id' => self::PSID],
            'recipient' => ['id' => self::PAGE_A],
            'read' => ['watermark' => self::TS],
        ];
    }

    public static function reactionItem(): array
    {
        return [
            'sender' => ['id' => self::PSID],
            'recipient' => ['id' => self::PAGE_A],
            'reaction' => ['mid' => 'mid.text1', 'action' => 'react', 'emoji' => "\u{2764}"],
        ];
    }

    public static function standbyItem(): array
    {
        return [
            'sender' => ['id' => self::PSID],
            'recipient' => ['id' => self::PAGE_A],
            'standby' => ['message' => ['mid' => 'mid.standby1', 'text' => 'handed to another app']],
        ];
    }

    /** No mid at all — exercises the Phase 3A derived-key regime. */
    public static function midlessItem(string $text): array
    {
        return [
            'sender' => ['id' => self::PSID],
            'recipient' => ['id' => self::PAGE_A],
            'timestamp' => self::TS,
            'message' => ['text' => $text],
        ];
    }

    public static function unsupportedAttachmentItem(): array
    {
        return [
            'sender' => ['id' => self::PSID],
            'recipient' => ['id' => self::PAGE_A],
            'timestamp' => self::TS,
            'message' => ['mid' => 'mid.loc1', 'attachments' => [['type' => 'location', 'payload' => []]]],
        ];
    }

    public static function identitylessItem(): array
    {
        return ['timestamp' => self::TS, 'message' => ['mid' => 'mid.orphan', 'text' => 'no sender']];
    }

    // ---------------------------------------------------------------- Instagram items

    public static function igTextItem(string $mid = 'ig.text1', string $text = 'Available pa po?'): array
    {
        return [
            'sender' => ['id' => self::IGSID],
            'recipient' => ['id' => self::IG_A],
            'timestamp' => self::TS,
            'message' => ['mid' => $mid, 'text' => $text],
        ];
    }

    public static function igMediaItem(string $mid = 'ig.media1'): array
    {
        return [
            'sender' => ['id' => self::IGSID],
            'recipient' => ['id' => self::IG_A],
            'timestamp' => self::TS,
            'message' => ['mid' => $mid, 'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://example.test/ig']]]],
        ];
    }

    public static function igStoryReplyItem(string $mid = 'ig.story1'): array
    {
        return [
            'sender' => ['id' => self::IGSID],
            'recipient' => ['id' => self::IG_A],
            'timestamp' => self::TS,
            'message' => [
                'mid' => $mid,
                'text' => 'Love this!',
                'reply_to' => ['story' => ['id' => 'story_1', 'url' => 'https://example.test/story']],
            ],
        ];
    }

    // ---------------------------------------------------------------- Leadgen

    public static function leadgen(string $pageId = self::PAGE_A, string $leadgenId = 'lead_1', string $formId = 'form_1'): array
    {
        return ['object' => 'page', 'entry' => [[
            'id' => $pageId,
            'time' => self::TS,
            'changes' => [[
                'field' => 'leadgen',
                'value' => [
                    'leadgen_id' => $leadgenId,
                    'page_id' => $pageId,
                    'form_id' => $formId,
                    'adgroup_id' => 'ad_1',
                    'created_time' => 1758441600,
                ],
            ]],
        ]]];
    }

    public static function malformedLeadgen(string $pageId = self::PAGE_A): array
    {
        return ['object' => 'page', 'entry' => [[
            'id' => $pageId,
            'changes' => [['field' => 'leadgen', 'value' => ['form_id' => 'form_1']]],
        ]]];
    }

    /** One Page carrying a Messenger message AND a leadgen change. */
    public static function messengerAndLeadgen(): array
    {
        return ['object' => 'page', 'entry' => [[
            'id' => self::PAGE_A,
            'time' => self::TS,
            'messaging' => [self::textItem()],
            'changes' => [[
                'field' => 'leadgen',
                'value' => ['leadgen_id' => 'lead_2', 'page_id' => self::PAGE_A, 'form_id' => 'form_2'],
            ]],
        ]]];
    }

    /** Two Pages in ONE signed delivery — the cross-tenant case. */
    public static function twoPages(): array
    {
        return ['object' => 'page', 'entry' => [
            ['id' => self::PAGE_A, 'time' => self::TS, 'messaging' => [self::textItem('mid.pageA', 'from page A')]],
            ['id' => self::PAGE_B, 'time' => self::TS, 'messaging' => [[
                'sender' => ['id' => self::PSID],
                'recipient' => ['id' => self::PAGE_B],
                'timestamp' => self::TS,
                'message' => ['mid' => 'mid.pageB', 'text' => 'from page B'],
            ]]],
        ]];
    }

    // ---------------------------------------------------------------- Malformed / hostile

    public static function unknownObject(): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'x', 'changes' => []]]];
    }

    public static function entryWithoutId(): array
    {
        return ['object' => 'page', 'entry' => [['time' => self::TS, 'messaging' => [self::textItem()]]]];
    }

    /** A valid sibling beside a malformed one. */
    public static function malformedSibling(): array
    {
        return self::page(self::PAGE_A, [
            self::textItem('mid.good1', 'first'),
            'not-an-array',
            self::textItem('mid.good2', 'second'),
        ]);
    }

    public static function deepItem(int $depth): array
    {
        $leaf = ['end' => true];
        for ($i = 0; $i < $depth; $i++) {
            $leaf = ['nest' => $leaf];
        }

        $item = self::textItem('mid.deep1');
        $item['message']['deep'] = $leaf;

        return self::page(self::PAGE_A, [$item]);
    }

    public static function oversizedItem(int $bytes): array
    {
        $item = self::textItem('mid.big1');
        $item['message']['blob'] = str_repeat('x', $bytes);

        return self::page(self::PAGE_A, [$item]);
    }

    public static function longTextItem(int $length): array
    {
        return self::page(self::PAGE_A, [self::textItem('mid.long1', str_repeat('a', $length))]);
    }

    public static function manyItems(int $count): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = self::textItem('mid.many'.$i, 'message '.$i);
        }

        return self::page(self::PAGE_A, $items);
    }
}
