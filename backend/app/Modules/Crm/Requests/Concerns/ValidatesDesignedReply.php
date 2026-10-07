<?php

namespace App\Modules\Crm\Requests\Concerns;

use App\Modules\Crm\Services\Mail\EmailDocument;
use App\Modules\Crm\Services\Outreach\EmailTemplateDesignService;
use App\Modules\Crm\Support\EmailDesign\EmailDesignSchema;
use App\Modules\Crm\Support\EmailDesign\EmailDesignValidator;
use App\Support\CurrentOrg;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A Lead Details reply sent in an existing Email Design — the rules its send and its preview share, so the
 * two can never disagree about what is sendable.
 *
 * The design is an id, never markup, and it must be one of THIS organization's live designs: `exists` reads
 * the table directly, past the BelongsToOrg and SoftDeletes scopes, so without the two constraints another
 * organization's id (or a deleted one) would validate.
 *
 * The reply's text goes into the design as-is, so it is held to the design's own rules before anything is
 * stored or sent — and a BD who chose a design is told why it cannot carry this reply, never sent plain text
 * in its place.
 */
trait ValidatesDesignedReply
{
    /** @return array<int, mixed> */
    protected function emailDesignIdRules(): array
    {
        return [
            'integer',
            Rule::exists('crm_email_designs', 'id')
                ->where(fn ($query) => $query->where('org_id', CurrentOrg::id())->whereNull('deleted_at')),
        ];
    }

    /**
     * The checks a designed reply's document must pass, in order, each with a message a BD can act on.
     * Call only once the design id itself has validated.
     *
     * @param  array<string, mixed>|null  $doc  the submitted reply document, already through EmailDocument::problem()
     */
    protected function validateDesignedReplyDocument(Validator $validator, ?array $doc): void
    {
        if ($doc === null) {
            $validator->errors()->add('body_doc', 'Write your reply before choosing a design.');

            return;
        }

        // A design substitutes {{placeholders}} in its text, and refuses unknown ones and lone braces. A
        // reply has always been sent literally, so rather than start substituting a BD's words, a designed
        // reply may not contain them at all.
        $text = EmailDocument::toText($doc);
        if (str_contains($text, '{{') || str_contains($text, '}}')) {
            $validator->errors()->add('body_doc', 'Placeholders like {{first_name}} can\'t be used in a designed reply. Remove them or choose No design.');

            return;
        }

        // The design's own content rules, from its own validator — so the 5,000-character limit here is the
        // limit the renderer enforces, not a copy of it.
        $content = app(EmailTemplateDesignService::class)->replyContent($doc);
        $problem = app(EmailDesignValidator::class)->contentProblem($content);
        if ($problem === null) {
            return;
        }

        $validator->errors()->add('body_doc', $problem['message'] === sprintf(EmailDesignValidator::TEXT_TOO_LONG, EmailDesignSchema::MAX_BLOCK_TEXT)
            ? sprintf('A designed reply can be at most %s characters. Shorten it or choose No design.', number_format(EmailDesignSchema::MAX_BLOCK_TEXT))
            : 'This reply can\'t be shown in the selected design. Remove unusual characters or formatting, or choose No design.');
    }
}
