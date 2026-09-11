<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Crm\Requests\IndexEmailRequest;
use App\Modules\Crm\Requests\RescheduleEmailRequest;
use App\Modules\Crm\Requests\SaveDraftRequest;
use App\Modules\Crm\Requests\SendEmailRequest;
use App\Modules\Crm\Resources\EmailResource;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use App\Models\User;
use App\Modules\Crm\Services\Outreach\OutreachEmailService;
use App\Modules\Crm\Services\Outreach\ScheduledEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Outreach email — the whole Email lifecycle lives here (draft → sent → failed). A draft is
 * simply an `emails` row with status=draft (no separate table/model/controller). `store()` stays
 * dedicated to sending; drafts get their own thin methods. Sending a draft transitions the SAME
 * row via OutreachEmailService::sendDraft (no second record).
 */
class EmailController extends Controller
{
    /**
     * Send an outreach email to a lead through the configured provider, persist the
     * history record, and log an `email.sent` Activity. Authorization on the route
     * (view lead + follow-ups.create).
     */
    public function store(
        SendEmailRequest $request,
        Lead $lead,
        OutreachEmailService $service,
        ScheduledEmailService $scheduled,
    ): JsonResponse {
        $data = $request->validated();

        /** @var User $sender */
        $sender = $request->user();

        $to = isset($data['to']) ? (string) $data['to'] : null;

        // A formatted reply carries a validated document and its body is the server's projection of
        // it; every other caller (Follow-ups, drafts, templates) sends plain `body` exactly as before.
        $body = $request->bodyText();
        $bodyDoc = $request->emailDocument();

        // RC3 Send-Later: a future `scheduled_at` schedules the email (no send now); otherwise send now.
        $email = isset($data['scheduled_at'])
            ? $scheduled->schedule($lead, $sender, (string) $data['subject'], $body, Carbon::parse((string) $data['scheduled_at']), $to, $bodyDoc)
            : $service->sendToLead($lead, $sender, (string) $data['subject'], $body, $to, $bodyDoc);

        return (new EmailResource($email))->response()->setStatusCode(201);
    }

    /** Cancel a still-scheduled email (author/Admin; scheduled-only — EmailPolicy@cancel). */
    public function cancel(Email $email, ScheduledEmailService $scheduled): JsonResponse
    {
        return (new EmailResource($scheduled->cancel($email)))->response();
    }

    /** Reschedule a still-scheduled email to a new future time (EmailPolicy@reschedule). */
    public function reschedule(RescheduleEmailRequest $request, Email $email, ScheduledEmailService $scheduled): JsonResponse
    {
        $scheduledAt = Carbon::parse((string) $request->validated()['scheduled_at']);

        return (new EmailResource($scheduled->reschedule($email, $scheduledAt)))->response();
    }

    /** Send a still-scheduled email immediately through the single pipeline (EmailPolicy@sendNow). */
    public function sendNow(Request $request, Email $email, ScheduledEmailService $scheduled): JsonResponse
    {
        /** @var User $sender */
        $sender = $request->user();

        return (new EmailResource($scheduled->sendNow($email, $sender)))->response();
    }

    /** The current user's own drafts (author-private WIP), newest first. */
    public function index(IndexEmailRequest $request): AnonymousResourceCollection
    {
        $status = $request->validated()['status'] ?? 'draft';

        $emails = Email::query()
            ->where('user_id', $request->user()->id)
            ->where('status', $status)
            ->latest()
            ->get();

        return EmailResource::collection($emails);
    }

    /** Save a draft for a lead (status=draft; not sent, no provider, no Activity). Author = current user. */
    public function storeDraft(SaveDraftRequest $request, Lead $lead): JsonResponse
    {
        $data = $request->validated();

        $email = Email::create([
            'lead_id' => $lead->id,
            'user_id' => $request->user()->id,
            'direction' => 'outbound',
            'to_email' => $this->recipientFor($lead, $data['to'] ?? null),
            'to_name' => $lead->contact_name,
            'subject' => (string) ($data['subject'] ?? ''),
            'body' => (string) ($data['body'] ?? ''),
            'status' => 'draft',
        ]);

        return (new EmailResource($email))->response()->setStatusCode(201);
    }

    /** Update a draft (author/Admin/SA; draft-only — enforced by EmailPolicy@update). */
    public function update(SaveDraftRequest $request, Email $email): JsonResponse
    {
        $data = $request->validated();

        $email->update([
            'to_email' => array_key_exists('to', $data) && $data['to'] !== null
                ? (string) $data['to']
                : $email->to_email,
            'subject' => (string) ($data['subject'] ?? ''),
            'body' => (string) ($data['body'] ?? ''),
        ]);

        return (new EmailResource($email))->response();
    }

    /** Discard a draft (author/Admin/SA; draft-only — enforced by EmailPolicy@delete). */
    public function destroy(Email $email): JsonResponse
    {
        $email->delete();

        return response()->json(null, 204);
    }

    /**
     * Send an existing draft: transitions the SAME row draft → sent via the existing send
     * pipeline (Decision 2). Authorization on the route (EmailPolicy@send — author, draft-only).
     */
    public function send(Request $request, Email $email, OutreachEmailService $service): JsonResponse
    {
        /** @var User $sender */
        $sender = $request->user();

        $sent = $service->sendDraft($email, $sender);

        return (new EmailResource($sent))->response();
    }

    /** A draft may be saved without a recipient yet; the lead's email is the fallback (may be empty). */
    private function recipientFor(Lead $lead, ?string $to): string
    {
        return $to !== null && $to !== '' ? $to : (string) $lead->email;
    }
}
