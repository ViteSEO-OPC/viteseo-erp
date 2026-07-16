<?php

declare(strict_types=1);

namespace App\Modules\Projects\Concerns;

use Illuminate\Support\Str;

/**
 * Gives a model a public UUID identifier alongside its internal bigint primary key.
 *
 * The auto-increment `id` stays the primary key (fast joins / foreign keys), while
 * the `uuid` column is the identifier exposed to clients and used in URLs. Route
 * model binding resolves `{model}` params by `uuid`.
 */
trait HasPublicUuid
{
    protected static function bootHasPublicUuid(): void
    {
        static::creating(function ($model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::orderedUuid();
            }
        });
    }

    /**
     * Resolve implicit route bindings by the public UUID, not the primary key.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
