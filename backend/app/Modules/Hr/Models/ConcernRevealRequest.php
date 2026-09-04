<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * HR asking an anonymous reporter to identify themselves.
 *
 * @see \App\Modules\Hr\Services\ConcernRevealService
 */
class ConcernRevealRequest extends Model
{
    use BelongsToOrg;
    use SoftDeletes;

    protected $table = 'hr_concern_reveal_requests';

    protected $fillable = [
        'concern_id', 'requested_by', 'reason', 'status', 'responded_at', 'decline_reason',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    public function concern(): BelongsTo
    {
        return $this->belongsTo(Concern::class);
    }

    /**
     * The HR officer who asked. `withTrashed()` is not needed — `users` are not soft-deleted
     * by separation the way `hr_employees` are — but the FK is nullOnDelete, so a deleted
     * account leaves this null and every reader must cope with an unnamed asker.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
