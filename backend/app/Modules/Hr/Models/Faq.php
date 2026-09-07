<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Faq extends Model
{
    use BelongsToOrg;
    use SoftDeletes;

    protected $table = 'hr_faqs';

    protected $fillable = [
        'question', 'answer', 'category', 'tags',
        'status', 'is_pinned', 'view_count', 'updated_by',
    ];

    protected $casts = [
        'tags' => 'array',
        'is_pinned' => 'boolean',
        'view_count' => 'integer',
    ];

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
