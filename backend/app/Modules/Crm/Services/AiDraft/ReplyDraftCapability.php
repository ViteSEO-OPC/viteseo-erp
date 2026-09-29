<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\AiDraft;

use App\Models\User;

/**
 * Whether the inbox should offer a "Draft with AI" control. A PRESENTATION HINT, not authorization.
 *
 * The draft endpoint re-checks both abilities through route middleware and the flag and key inside
 * {@see ReplyDraftService}; a client that ignores this hint gets exactly the refusals it would have
 * got otherwise.
 *
 * QUERY-FREE: two cached ability checks and two config reads. `can()` rather than a permission-array
 * lookup, so `Gate::before(super_admin)` is honoured.
 *
 * PERMISSION FIRST, like ReplyCapability: a viewer who may not draft learns nothing about whether
 * the feature is switched on or configured.
 */
final class ReplyDraftCapability
{
    public const PERMISSION_DENIED = 'permission_denied';

    public const AI_DISABLED = 'ai_disabled';

    public const NOT_CONFIGURED = 'not_configured';

    /** Both are required by the route: reading the thread, and speaking for the business. */
    public const ABILITIES = [
        'crm.conversations.view',
        'crm.conversations.reply',
    ];

    /**
     * @return array{enabled: bool, reason: string|null}
     */
    public function for(?User $viewer): array
    {
        if ($viewer === null) {
            return self::refused(self::PERMISSION_DENIED);
        }

        foreach (self::ABILITIES as $ability) {
            if (! $viewer->can($ability)) {
                return self::refused(self::PERMISSION_DENIED);
            }
        }

        return match (ReplyDraftService::configurationFailure()) {
            ReplyDraftResult::DISABLED => self::refused(self::AI_DISABLED),
            ReplyDraftResult::NOT_CONFIGURED => self::refused(self::NOT_CONFIGURED),
            default => ['enabled' => true, 'reason' => null],
        };
    }

    /**
     * @return array{enabled: bool, reason: string|null}
     */
    private static function refused(string $reason): array
    {
        return ['enabled' => false, 'reason' => $reason];
    }
}
