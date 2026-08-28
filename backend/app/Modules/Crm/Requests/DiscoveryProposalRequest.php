<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Services\Discovery\OpenAiProposalGenerator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The scraper asking the CRM for candidate business names.
 *
 * Route-authorized by `can('import', Lead::class)` — the same permission the enrichment ingest and
 * the website-audit queue use, because this is the same actor (the discovery service) doing the same
 * job. Not gated on an admin ROLE: the scraper authenticates as a service account, and requiring a
 * role would mean handing a worker an administrator.
 *
 * `count` is bounded here as well as in the generator. This endpoint spends money per call, so the
 * ceiling belongs at the edge — a caller must not be able to ask for a thousand names and have the
 * refusal happen deep inside a service that has already been reached.
 */
class DiscoveryProposalRequest extends FormRequest
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
            'count' => ['sometimes', 'integer', 'min:1', 'max:'.OpenAiProposalGenerator::MAX_PROPOSALS],
        ];
    }
}
