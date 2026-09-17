<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Start an on-demand discovery run.
 *
 * WHAT THIS DELIBERATELY DOES NOT VALIDATE
 *
 * There is NO niche whitelist and NO location whitelist here, and adding one later would be a
 * product regression rather than a hardening. "pharmacy", "dentist" and the rest are vocabulary and
 * examples, never an allowed set: the factual provider owns the category vocabulary (OpenStreetMap
 * falls through to a raw `key=value` selector for unknown words; the CRM's niche registry passes
 * unregistered values through unchanged), and the area is whatever the engine's geocoder can
 * resolve. A user must be able to ask for "vape shops in Roxas City" without a code change.
 *
 * So the rules below constrain SHAPE and VOLUME only — is it a non-empty string, and is the run
 * small enough to be affordable — never MEANING.
 *
 * Route-authorized. `authorize()` returns true exactly as its sibling
 * {@see IngestDiscoveredLeadsRequest} does, because the route carries the real gates:
 * `can('import', Lead::class)` plus the existing paid-capability role middleware. No RBAC decision
 * is made, widened or restated in this class.
 */
class StoreDiscoveryRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route-level `can('import', Lead::class)` + role gate authorize.
    }

    /**
     * The country itself, in the spellings a person actually types.
     *
     * NOT A LOCATION BLOCKLIST, and not the beginning of one. This is a single RESERVED WORD: the
     * whole country is a distinct mode with its own coverage model, its own per-area target and its
     * own budget, and typing its name into the locations box asks a geocoder for one rectangle
     * around the entire archipelago — which returns a few dozen businesses from wherever the
     * provider felt like looking and presents them as national coverage. That is the exact failure
     * the nationwide mode exists to replace, so the name routes to the mode instead of silently
     * under-answering. Every other place name on earth remains free text.
     *
     * @var list<string>
     */
    private const COUNTRY_NAMES = [
        'philippines',
        'the philippines',
        'republic of the philippines',
        'ph',
        'phl',
        'pilipinas',
    ];

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $nationwide = $this->isNationwide();

        return [
            // THE COUNTRY, for both modes. Absent means the configured default, so every existing
            // caller keeps working. A country that is not supported is REFUSED rather than silently
            // replaced with the default: a user who asked to search Malaysia must be told it cannot
            // be done, not handed Philippine results and left to notice.
            'country' => ['sometimes', 'string', 'in:'.implode(',', (array) config('discovery.countries', ['PH']))],

            // `scoped` (the default) searches places the user named; `nationwide` searches the
            // country. Absent means scoped, so every existing caller keeps working unchanged.
            'scope' => ['sometimes', 'string', 'in:scoped,nationwide'],

            // Free-text place names, resolved by the engine's geocoder at execution time. Not
            // validated against any list this codebase owns.
            //
            // REQUIRED FOR A SCOPED RUN, FORBIDDEN FOR A NATIONWIDE ONE. Nationwide coverage comes
            // from the national polygon, so a location here would be a filter the mode does not have
            // — and accepting it silently would be worse than refusing it.
            'locations' => [$nationwide ? 'prohibited' : 'required', 'array', 'min:1', 'max:50'],
            'locations.*' => ['required', 'string', 'min:2', 'max:255'],

            // Nationwide only, both optional, both fenced by config. These are OPERATIONAL dials —
            // how fine the tiling is and how much one click may spend — never a narrowing of where
            // the run may look, and never a quota on what it may find.
            'cell_degrees' => ['sometimes', 'numeric', 'min:0.05', 'max:5'],
            'max_tasks' => ['sometimes', 'integer', 'min:1', 'max:'.config('discovery.nationwide.max_tasks_ceiling', 25000)],

            // Free-text business niches. See the class docblock: no whitelist, by product decision.
            'niches' => ['required', 'array', 'min:1', 'max:25'],
            'niches.*' => ['required', 'string', 'min:2', 'max:120'],

            // The structured provider's own ceiling is 60 per call (PAGE_SIZE 20 × MAX_PAGES 3);
            // asking for more spends requests that cannot return anything new.
            'limit_per_task' => ['sometimes', 'integer', 'min:1', 'max:'.config('discovery.max_limit_per_task', 60)],

            // Supplied by the client so a double-click, a refresh or a network retry resolves to the
            // SAME run instead of buying a second one.
            'idempotency_key' => ['sometimes', 'string', 'max:64'],
        ];
    }

    /**
     * The one cross-field rule: a run must be affordable.
     *
     * This is a BILLING fence, not a discovery boundary — it caps how much a single click may spend,
     * never which businesses are reachable. A user who wants more asks for more runs.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // A nationwide run's volume is fenced by its own budget (`max_tasks`), which is
                // already bounded by the rules above. The cross product below does not apply to it:
                // it has no locations to multiply.
                if ($this->isNationwide()) {
                    return;
                }

                $tasks = count((array) $this->input('locations', []))
                    * count((array) $this->input('niches', []));
                $max = (int) config('discovery.max_tasks_per_run', 100);

                if ($tasks > $max) {
                    $validator->errors()->add(
                        'locations',
                        "That is {$tasks} searches ({$max} is the most one run may schedule). Narrow the locations or niches, or start more than one run.",
                    );
                }
            },

            // The reserved word. See COUNTRY_NAMES: this points one input at the mode that answers
            // it properly, and constrains nothing else.
            function (Validator $validator): void {
                if ($this->isNationwide()) {
                    return;
                }

                foreach ((array) $this->input('locations', []) as $index => $location) {
                    if (! is_string($location)) {
                        continue;
                    }

                    if (in_array(mb_strtolower(trim($location)), self::COUNTRY_NAMES, true)) {
                        $validator->errors()->add(
                            "locations.{$index}",
                            'To search the whole country, use the "Nationwide Philippines" mode — it '
                            .'covers Luzon, the Visayas and Mindanao properly. Entering the country '
                            .'as a location searches one box around the entire archipelago and '
                            .'returns only a handful of results.',
                        );
                    }
                }
            },
        ];
    }

    /** Which mode this request is asking for. Absent means scoped — the pre-existing behaviour. */
    public function isNationwide(): bool
    {
        return $this->input('scope') === 'nationwide';
    }

    /** The country this run is restricted to, already validated against the supported list. */
    public function country(): string
    {
        $country = $this->input('country');

        return is_string($country) && $country !== ''
            ? mb_strtoupper($country)
            : (string) config('discovery.default_country', 'PH');
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'country.in' => 'Discovery currently supports the Philippines only. '
                .'Other countries are not available yet, so no search was started.',
        ];
    }
}
