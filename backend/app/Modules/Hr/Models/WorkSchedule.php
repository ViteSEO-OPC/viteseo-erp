<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkSchedule extends Model
{
    use SoftDeletes;

    protected $table = 'hr_work_schedules';

    protected $fillable = [
        'name',
        'workdays',
        'start_time',
        'end_time',
        'grace_minutes',
        'is_default',
    ];

    protected $casts = [
        'workdays' => 'array',
        'grace_minutes' => 'integer',
        'is_default' => 'boolean',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
