<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One entry in the PO-managed Service-Area Registry — an administrative area (`name` at `level`
 * region|province|city) that counts as a **target service location** for the frozen Target
 * Location (+10) signal. Business configuration, not a formula: adding/removing areas re-scopes
 * which leads are "in target" without changing any Lead Score computation.
 */
#[Fillable(['name', 'level', 'active'])]
class ServiceArea extends Model
{
    protected $table = 'crm_service_areas';

    use SoftDeletes;

    /** The admin levels a target area may be defined at. */
    public const LEVELS = ['region', 'province', 'city'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
