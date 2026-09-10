<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\CurrentOrg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Makes a model tenant-aware. Every query is automatically filtered to the
 * current org, and new records are automatically stamped with it — so you
 * can never forget a `where('org_id', ...)` and leak another tenant's data.
 *
 * Requires an `org_id` column on the table.
 *
 * The global scope is named "org"; a super-admin / cross-org query can opt out
 * with `Model::withoutGlobalScope('org')` when that is genuinely intended.
 */
trait BelongsToOrg
{
    public static function bootBelongsToOrg(): void
    {
        // Auto-filter every query to the current tenant.
        static::addGlobalScope('org', function (Builder $builder): void {
            // Migrations run before the column they filter on necessarily exists.
            // See CurrentOrg::suspendScope() -- it is the only caller.
            if (CurrentOrg::scopeSuspended()) {
                return;
            }

            $model = $builder->getModel();
            $builder->where($model->qualifyColumn('org_id'), CurrentOrg::id());
        });

        // Auto-stamp the current tenant on new records (unless explicitly set).
        static::creating(function (Model $model): void {
            if (CurrentOrg::scopeSuspended()) {
                return;
            }

            if ($model->getAttribute('org_id') === null) {
                $model->setAttribute('org_id', CurrentOrg::id());
            }
        });
    }
}
