<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use BelongsToOrg;
    use SoftDeletes;

    protected $table = 'hr_departments';

    protected $fillable = ['name', 'code', 'description', 'head_id', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** The employee who heads this department (optional). */
    public function head(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'head_id');
    }

    /** Roles/positions that exist within this department. */
    public function positions(): HasMany
    {
        return $this->hasMany(DepartmentPosition::class)->orderBy('name');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
