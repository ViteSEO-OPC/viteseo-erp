<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A date HR has declared non-working, and whether it is still paid.
 *
 * @see \App\Modules\Hr\Services\WorkSuspensionService
 */
class WorkSuspension extends Model
{
    use SoftDeletes;

    protected $table = 'hr_work_suspensions';

    /**
     * The derived attendance statuses a suspended day produces. Not stored per employee —
     * see AttendanceService::allForDate(), which computes them the way `holiday` and
     * `rest_day` are computed, so flipping `is_paid` moves every day at once instead of
     * leaving hundreds of rows saying the old thing.
     */
    public const STATUS_PAID = 'suspended_paid';

    public const STATUS_UNPAID = 'suspended_unpaid';

    protected $fillable = ['date', 'name', 'is_paid', 'note', 'declared_by'];

    protected $casts = [
        'date' => 'date',
        'is_paid' => 'boolean',
    ];

    /** The attendance status this declaration puts on the day. */
    public function status(): string
    {
        return $this->is_paid ? self::STATUS_PAID : self::STATUS_UNPAID;
    }

    public function declarer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by');
    }
}
