<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One candidate email, as it was sent (or as it failed to send).
 *
 * @property string $status 'sent' or 'failed'
 */
class CandidateEmail extends Model
{
    use BelongsToOrg;
    use SoftDeletes;

    protected $table = 'hr_candidate_emails';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'candidate_id', 'type', 'to_email', 'cc', 'bcc', 'reply_to',
        'subject', 'body_html', 'attachments', 'status', 'error',
        'sent_at', 'sent_by_user_id',
    ];

    protected $casts = [
        'cc' => 'array',
        'bcc' => 'array',
        'attachments' => 'array',
        'sent_at' => 'datetime',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
