<?php

declare(strict_types=1);

namespace App\Modules\Projects\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Translates a public UUID identifier into the internal auto-increment primary key.
 *
 * Clients reference records by UUID; internally the app keys off bigint ids. This
 * helper accepts either form: a UUID is resolved to its owning row's id, while a
 * value that is not a UUID (e.g. an internal id used in tests) is returned
 * unchanged so downstream `exists:...,id` / where-clauses still behave.
 *
 * @param  class-string<Model>  $modelClass
 */
class PublicId
{
    public static function toInternalId(string $modelClass, mixed $value): mixed
    {
        // Only a UUID needs translating; anything else (e.g. an internal id) is
        // passed through. This also avoids comparing the native UUID column
        // against a non-UUID value, which the database rejects.
        if (! is_string($value) || ! Str::isUuid($value)) {
            return $value;
        }

        $id = $modelClass::query()->where('uuid', $value)->value('id');

        return $id ?? $value;
    }
}
