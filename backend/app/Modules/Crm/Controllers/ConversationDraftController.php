<?php

declare(strict_types=1);

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Requests\ConversationDraftRequest;
use App\Modules\Crm\Services\AiDraft\ReplyDraftResult;
use App\Modules\Crm\Services\AiDraft\ReplyDraftService;
use App\Support\CurrentOrg;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 4A-lite — an AI-written DRAFT reply for the agent to review. NEVER SENDS ANYTHING.
 *
 * The response is text for the composer and nothing else: no message row is written, no provider is
 * called, no OutboundMessageRequest is built. Sending stays with {@see ConversationReplyController}.
 *
 * TENANCY FROM TWO PLACES. The conversation arrives by route-model binding under `BelongsToOrg`, so
 * another tenant's id is a 404 before this runs; it is re-asserted against `CurrentOrg::id()` because
 * the model is about to read the thread's text.
 */
class ConversationDraftController extends Controller
{
    public function __construct(private readonly ReplyDraftService $drafts) {}

    public function store(ConversationDraftRequest $request, Conversation $conversation): JsonResponse
    {
        if ((int) $conversation->org_id !== (int) CurrentOrg::id()) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $result = $this->drafts->draft($conversation, $request->user()?->id);

        if ($result->succeeded()) {
            return response()->json([
                'draft' => $result->draft,
                'failureCode' => null,
                'model' => $result->model,
                'generatedAt' => now()->toIso8601String(),
            ], Response::HTTP_OK);
        }

        $code = (string) $result->failureCode;

        return response()->json([
            'draft' => null,
            'failureCode' => $code,
            'message' => self::copy($code),
        ], self::status($code));
    }

    /**
     * 409 for "the request was fine; the system's current state will not carry it" — the same
     * status the reply endpoint uses for NotConfigured. 502 for anything upstream. 429 is passed
     * through for an upstream rate limit; a client tells it from this route's own throttle by the
     * presence of `failureCode`.
     */
    private static function status(string $code): int
    {
        return match ($code) {
            ReplyDraftResult::DISABLED,
            ReplyDraftResult::NOT_CONFIGURED,
            ReplyDraftResult::EMPTY_CONVERSATION => Response::HTTP_CONFLICT,
            ReplyDraftResult::RATE_LIMITED => Response::HTTP_TOO_MANY_REQUESTS,
            default => Response::HTTP_BAD_GATEWAY,
        };
    }

    /** Stable, provider-neutral copy. Clients branch on `failureCode`, never on this sentence. */
    private static function copy(string $code): string
    {
        return match ($code) {
            ReplyDraftResult::DISABLED => 'AI drafts are switched off.',
            ReplyDraftResult::NOT_CONFIGURED => 'AI drafts are not configured yet.',
            ReplyDraftResult::EMPTY_CONVERSATION => 'There is no message text to draft a reply from.',
            ReplyDraftResult::TIMEOUT => 'The drafting service did not respond in time. Try again.',
            ReplyDraftResult::RATE_LIMITED => 'The drafting service is busy. Try again in a minute.',
            ReplyDraftResult::AUTH_FAILED => 'The drafting service rejected our credentials.',
            ReplyDraftResult::INVALID_RESPONSE => 'The drafting service returned an unusable draft. Try again.',
            default => 'The drafting service is unavailable. Try again.',
        };
    }
}
