<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Requisition extends Model
{
    use BelongsToOrg;
    use SoftDeletes;

    protected $table = 'hr_requisitions';

    protected $fillable = [
        'title', 'pipeline', 'employment_type', 'salary_range',
        'allowance_amount', 'allowance_period',
        'department_id', 'hiring_manager_id', 'openings', 'stage',
        'opened_at', 'target_start_date', 'location', 'job_description',
    ];

    protected $casts = [
        'opened_at' => 'date',
        'target_start_date' => 'date',
        'openings' => 'integer',
        'allowance_amount' => 'decimal:2',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function hiringManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'hiring_manager_id');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class);
    }
}
