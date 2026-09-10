<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepartmentPosition extends Model
{
    use BelongsToOrg;
    protected $table = 'hr_department_positions';

    protected $fillable = ['department_id', 'name'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
