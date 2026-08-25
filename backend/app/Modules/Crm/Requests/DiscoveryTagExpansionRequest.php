<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Services\Discovery\OpenAiTagExpander;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The scraper asking which additional OSM tags a category can hide under.
 *
 * Route-authorized by `can('import', Lead::class)`, like every other endpoint the discovery service
 * uses, because this is the same actor doing the same job.
 *
 * NO `area` FIELD, AND IT IS NOT AN OMISSION. Whether `amenity=fast_food` belongs with restaurants is
 * a fact about OSM's taxonomy rather than about a town, so the answer is the same everywhere and is
 * cached per category forever. Accepting an area would invite a cache keyed on one, which would turn
 * a nineteen-call capability into an unbounded one.
 *
 * `knownSelectors` is advisory — it lets the model add rather than repeat. The caller deduplicates
 * regardless, so a model that ignores it costs a wasted selector, never a duplicate sweep.
 */
class DiscoveryTagExpansionRequest extends FormRequest
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
            'knownSelectors' => ['sometimes', 'array', 'max:'.(OpenAiTagExpander::MAX_SELECTORS * 4)],
            'knownSelectors.*' => ['string', 'max:120'],
        ];
    }
}
