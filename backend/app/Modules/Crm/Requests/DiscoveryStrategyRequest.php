<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Services\Discovery\OpenAiDiscoveryStrategist;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request for a discovery STRATEGY — a category, optionally a scope to break down.
 *
 * `scope` is free text and stays free text. There is no enum of areas, no region list and no
 * validation against any place registry, because the moment this request knew what a valid Philippine
 * area was, the system would have a hardcoded geography. Whether a scope is real is settled by the
 * geocoder at execution time, not by a whitelist here.
 */
class DiscoveryStrategyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route-level `can('import', Lead::class)` authorizes.
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'max:120'],
            // The target's real area. Optional because a caller may legitimately ask about a niche
            // with no place in mind (a cacheable, area-independent answer).
            'scope' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Context only — how many businesses the run wants. Bounded so it cannot be used to ask
            // for an unreasonably broad plan; it caps nothing downstream.
            'limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'knownSelectors' => ['sometimes', 'array', 'max:'.OpenAiDiscoveryStrategist::MAX_KNOWN_SELECTORS],
            'knownSelectors.*' => ['string', 'max:120'],
        ];
    }
}
