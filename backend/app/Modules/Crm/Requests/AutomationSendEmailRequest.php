<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RC3 Outreach Automation Pack — request for the dedicated automation send endpoint. n8n names the
 * business intent (workflow + purpose + tone); Laravel selects the template, renders it, resolves the
 * recipient, and evaluates reply state. n8n never supplies subject/body/recipient — content and
 * targeting are decided server-side.
 */
class AutomationSendEmailRequest extends FormRequest
{
    /** The business purposes the automation pack may send. Also the email's persisted `purpose`. */
    public const PURPOSES = ['proposal', 'proposal_follow_up', 'introduction', 'meeting_reminder', 'thank_you'];

    /** Route authorization is enforced by `permission:outreach.automation.send`; no per-user gate here. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Business purpose — selects the template AND is persisted on the email (drives proposal.sent).
            'purpose' => ['required', 'string', 'in:'.implode(',', self::PURPOSES)],
            // Template tone; defaults server-side to the standard tone when omitted.
            'tone' => ['nullable', 'string', 'max:100'],
            // Orchestration identifier for the audit trail (e.g. "proposal_follow_up"). Not business logic.
            'workflow' => ['required', 'string', 'max:100'],
            // Server-side reply gate: when true, skip the send if the lead has already replied.
            'require_unreplied' => ['nullable', 'boolean'],
        ];
    }
}
