<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Services\Discovery\GooglePlacesTextSearch;
use Illuminate\Foundation\Http\FormRequest;

class DiscoveryPlacesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route-level `can('import', Lead::class)` authorizes.
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'area' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:120'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.(GooglePlacesTextSearch::PAGE_SIZE * GooglePlacesTextSearch::MAX_PAGES)],
            // A Places (New) type such as `pharmacy`. Google validates the vocabulary; this only
            // refuses anything that could not possibly be one.
            'includedType' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z][a-z0-9_]{1,59}$/'],
            // An AI-planned phrase replacing the category half of the text query. The AREA is never
            // taken from here — it stays the validated `area` above — so a planned query can shape
            // what is searched for but never where.
            'textQuery' => ['sometimes', 'nullable', 'string', 'max:120'],
            // Several planned phrases for ONE task. Bounded here as well as in the service, because a
            // query count is a multiplier on paid provider calls: the request layer must refuse an
            // oversized list outright rather than rely on something downstream trimming it.
            'textQueries' => ['sometimes', 'nullable', 'array', 'max:'.GooglePlacesTextSearch::MAX_QUERIES],
            'textQueries.*' => ['string', 'max:120'],
            'bbox' => ['sometimes', 'nullable', 'array'],
            'bbox.south' => ['required_with:bbox', 'numeric', 'between:-90,90'],
            'bbox.west' => ['required_with:bbox', 'numeric', 'between:-180,180'],
            'bbox.north' => ['required_with:bbox', 'numeric', 'between:-90,90'],
            'bbox.east' => ['required_with:bbox', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * The planned phrases for this task, from either accepted spelling.
     *
     * `textQuery` (one phrase) and `textQueries` (several) are both honoured so a caller written
     * against the single-phrase shape keeps working unchanged. The service bounds and de-duplicates
     * whatever arrives; this only collects it.
     *
     * @return list<string>
     */
    public function plannedQueries(): array
    {
        $single = $this->validated('textQuery');
        $many = $this->validated('textQueries');

        $queries = is_array($many) ? array_values(array_filter($many, 'is_string')) : [];
        if (is_string($single) && trim($single) !== '') {
            array_unshift($queries, $single);
        }

        return $queries;
    }

    /** @return array{0: float, 1: float, 2: float, 3: float}|null south, west, north, east */
    public function boundingBox(): ?array
    {
        $bbox = $this->validated('bbox');

        if (! is_array($bbox)) {
            return null;
        }

        return [(float) $bbox['south'], (float) $bbox['west'], (float) $bbox['north'], (float) $bbox['east']];
    }
}
