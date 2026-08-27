<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Planner\FollowUpScheduler;
use Illuminate\Support\Facades\DB;

/**
 * Creates a lead and, optionally, the first internal note captured alongside it.
 *
 * Both writes happen in ONE transaction: the wizard's Notes field is text a user typed about a lead
 * that is being created in the same action, so a lead must never be persisted while that text is
 * silently dropped. If the note fails, the lead rolls back with it and the user keeps what they wrote.
 *
 * The note is delegated to {@see LeadNoteCreator} rather than written here, so the initial note is
 * created through the identical path as one added later from Lead Details — same relation, same author
 * association, same `note.created` Activity via the observer. Nothing about the note subsystem is
 * duplicated, and no note text is stored on `crm_leads`.
 *
 * Also mints the lead's first-touch Planner Todo (§4b) through
 * {@see FollowUpScheduler::scheduleOnLeadCreation()}, in the SAME transaction, so a lead can never be
 * persisted without the work it implies. This is the manual `POST /leads` path only — bulk import goes
 * through LeadImportService and is deliberately unchanged.
 */
class LeadCreator
{
    public function __construct(
        private readonly LeadNoteCreator $notes,
        private readonly FollowUpScheduler $followUps,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  Validated lead attributes (no note key).
     * @param  string|null  $initialNote  Optional note body; blank/whitespace creates no note.
     */
    public function create(array $attributes, User $creator, ?string $initialNote = null): Lead
    {
        return DB::transaction(function () use ($attributes, $creator, $initialNote): Lead {
            // BD-created leads auto-assign to the creator (BDs own their own leads); Admin/SA must
            // select the owning BD (enforced by StoreLeadRequest). Behaviour moved verbatim from the
            // controller — unchanged.
            if ($creator->hasRole('crm_bd')) {
                $attributes['assigned_to'] = $creator->id;
            }

            $lead = Lead::create([
                ...$attributes,
                'created_by' => $creator->id,
            ]);

            $body = trim((string) $initialNote);
            if ($body !== '') {
                $this->notes->create($lead, $body, $creator);
            }

            // §4b — the first touch this lead now owes. Runs AFTER Lead::create(), so a lead born
            // `cold_calling = 'scheduled'` has already had its cold-call follow-up minted by
            // LeadObserver and this call declines on the reused dedupe: exactly one event, never two.
            // Returns null (harmlessly) for an unowned or prospect lead.
            $this->followUps->scheduleOnLeadCreation($lead);

            return $lead;
        });
    }
}
