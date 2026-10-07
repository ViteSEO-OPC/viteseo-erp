<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\EmailDesign;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Requests\CreateStructuredEmailRequest;
use App\Modules\Crm\Requests\PreviewStructuredEmailRequest;
use App\Modules\Crm\Requests\SaveStructuredEmailRequest;
use App\Modules\Crm\Requests\ScheduleStructuredEmailRequest;
use App\Modules\Crm\Resources\EmailResource;
use App\Modules\Crm\Services\Outreach\StructuredEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Lead Details → New designed email: a per-lead copy of a designed Email Template (StructuredEmailService).
 * Sending a structured draft is the existing POST /emails/{email}/send; cancelling, rescheduling and
 * send-now of a scheduled one are the existing scheduled-email routes. Authorization is on the routes.
 */
class StructuredEmailController extends Controller
{
    public function store(CreateStructuredEmailRequest $request, Lead $lead, StructuredEmailService $structured): JsonResponse
    {
        /** @var User $sender */
        $sender = $request->user();
        $template = EmailTemplate::query()->findOrFail((int) $request->validated('email_template_id'));

        return (new EmailResource($structured->createFromTemplate($lead, $sender, $template)))->response()->setStatusCode(201);
    }

    public function update(SaveStructuredEmailRequest $request, Email $email, StructuredEmailService $structured): JsonResponse
    {
        /** @var User $sender */
        $sender = $request->user();

        return (new EmailResource($structured->save($email, $sender, $request->validated())))->response();
    }

    public function preview(PreviewStructuredEmailRequest $request, Lead $lead, StructuredEmailService $structured): JsonResponse
    {
        /** @var User $sender */
        $sender = $request->user();
        $design = EmailDesign::query()->findOrFail((int) $request->validated('email_design_id'));

        return response()->json(['data' => $structured->preview($lead, $sender, $design, (string) $request->validated('content'), (string) ($request->validated('subject') ?? ''))]);
    }

    public function schedule(ScheduleStructuredEmailRequest $request, Email $email, StructuredEmailService $structured): JsonResponse
    {
        /** @var User $sender */
        $sender = $request->user();

        return (new EmailResource($structured->schedule($email, $sender, Carbon::parse((string) $request->validated('scheduled_at')))))->response();
    }
}
