<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Candidate extends Model
{
    use BelongsToOrg;
    use SoftDeletes;

    protected $table = 'hr_candidates';

    protected $fillable = [
        'requisition_id', 'name', 'email', 'phone', 'source',
        'experience_years', 'stage', 'rating', 'notes', 'hired_employee_id',
        'expected_salary', 'location', 'school', 'required_hours',
        'feedback', 'interview_email_sent_at',
        'emails_sent', 'rejection_reason', 'rejected_at',
        'resume_path',
        'assessment_link', 'assessment_instructions', 'assessment_deadline', 'assessment_sent_at',
    ];

    protected $casts = [
        'experience_years' => 'integer',
        'required_hours' => 'integer',
        'rating' => 'decimal:1',
        // expected_salary is now a free-text range string (see recruitment_form_fields).
        'interview_email_sent_at' => 'datetime',
        'emails_sent' => 'array',
        'rejected_at' => 'datetime',
        'assessment_deadline' => 'date',
        'assessment_sent_at' => 'datetime',
    ];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }
}
