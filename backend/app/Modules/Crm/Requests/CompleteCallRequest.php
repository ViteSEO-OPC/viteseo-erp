<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Enums\CallOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Conclude-a-call contract. `outcome` is a required CallOutcome — but never `cancelled`: cancelling is
 * the distinct "Discard" action (POST .../discard), which the server dispositions, so it can never arrive
 * as a completion outcome. `notes` is the BD's optional free-text call summary. The route + CallPolicy@update
 * authorize (crm.calls.log + lead ownership); starting and discarding a call carry no request body.
 */
class CompleteCallRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route authorizes: ->can('update', 'call') → CallPolicy@update.
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::enum(CallOutcome::class)->except([CallOutcome::CANCELLED])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
