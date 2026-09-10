<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** The employer, as one row. Read by every document that carries a letterhead. */
class CompanyProfile extends Model
{
    use BelongsToOrg;
    use SoftDeletes;

    protected $table = 'hr_company_profiles';

    protected $fillable = [
        'legal_name',
        'trade_name',
        'address',
        'tin',
        'sss_employer_number',
        'philhealth_employer_number',
        'pagibig_employer_number',
        'email',
        'phone',
        'website',
        'logo_path',
    ];

    /** The name to print when the legal name is not specifically wanted. */
    public function displayName(): string
    {
        return $this->trade_name ?: $this->legal_name;
    }
}
