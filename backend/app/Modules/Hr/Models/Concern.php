<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Concern extends Model
{
    use SoftDeletes;

    protected $table = 'hr_concerns';

    protected $fillable = [
        'employee_id', 'reference_no', 'category', 'description',
        'is_anonymous', 'status', 'submitted_at', 'resolved_at',
    ];

    protected $casts = [
        'is_anonymous' => 'boolean',
        'submitted_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }
}
