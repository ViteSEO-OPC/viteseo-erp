<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The discovery engine (volume mode) asking which of a cell's OpenStreetMap keys this org already holds.
 *
 * Bounded at the edge: one cell sweep reads at most a few hundred elements, and the engine chunks
 * anything larger, so 500 is a ceiling nothing legitimate reaches.
 */
class DiscoveryKnownPlacesRequest extends FormRequest
{
    public const MAX_KEYS = 500;

    public function authorize(): bool
    {
        return true; // Route-level `can('import', Lead::class)` + EnsureNerbyServiceCaller authorize.
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'place_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_KEYS],
            'place_ids.*' => ['required', 'string', 'max:255'],
        ];
    }
}
