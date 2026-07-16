<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class ContributionSetting extends Model
{
    protected $table = 'hr_contribution_settings';

    protected $fillable = [
        'type',
        'config',
        'is_active',
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
    ];
}
