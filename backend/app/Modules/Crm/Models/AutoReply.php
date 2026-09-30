<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 3I — the durable record of one AI auto-reply decision, keyed by its inbound trigger.
 *
 * NOTHING IS FILLABLE. Every column is written by AiAutoReplyService, one attribute at a time; no
 * request payload ever reaches this model. Identifiers and codes only — see the migration.
 */
class AutoReply extends Model
{
    use BelongsToOrg;

    /** Claimed; the decision has not finished yet. */
    public const DECIDED = 'decided';

    /** Every guard passed; the model is being asked. */
    public const DRAFTING = 'drafting';

    /** The pre-send recheck passed; handed to the outbound service. */
    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    /** The send may or may not have reached the customer. Never retried. */
    public const AMBIGUOUS = 'ambiguous';

    public const SKIPPED = 'skipped';

    /** @var list<string> */
    public const IN_FLIGHT = [self::DECIDED, self::DRAFTING, self::SENDING];

    protected $table = 'crm_auto_replies';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'org_id' => 'integer',
            'conversation_id' => 'integer',
            'inbound_message_id' => 'integer',
            'outbound_message_id' => 'integer',
            'prompt_version' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function isTerminal(): bool
    {
        return ! in_array($this->status, self::IN_FLIGHT, true);
    }
}
