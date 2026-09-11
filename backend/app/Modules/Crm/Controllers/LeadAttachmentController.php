<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadAttachment;
use App\Modules\Crm\Requests\StoreLeadAttachmentRequest;
use App\Modules\Crm\Resources\AttachmentPreviewResource;
use App\Modules\Crm\Resources\LeadAttachmentResource;
use App\Modules\Crm\Services\Attachments\DocumentPreviewException;
use App\Modules\Crm\Services\Attachments\DocumentPreviewService;
use App\Modules\Crm\Services\Attachments\SpreadsheetPreviewReader;
use App\Modules\Crm\Services\Attachments\UnreadableSpreadsheetException;
use App\Modules\Crm\Services\LeadAttachmentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Files attached to a lead. Read/create/download authorize through the parent lead (ownership via
 * LeadPolicy@view on the route); delete authorizes through LeadAttachmentPolicy (uploader/Admin/SA).
 *
 * Every action reaches the attachment through `$lead->attachments()` — never LeadAttachment
 * directly — so an attachment is only ever addressable inside the lead that owns it. Combined with
 * the routes' scopeBindings, that is what makes "Lead B can never show Lead A's files" a property
 * of the query rather than of the caller remembering to filter.
 */
class LeadAttachmentController extends Controller
{
    /** A lead's attachments, newest first (the relation's order). */
    public function index(Lead $lead): AnonymousResourceCollection
    {
        return LeadAttachmentResource::collection(
            $lead->attachments()->with('uploader')->get(),
        );
    }

    /** Upload a file against a lead (uploader = current user). */
    public function store(
        StoreLeadAttachmentRequest $request,
        Lead $lead,
        LeadAttachmentStore $store,
    ): JsonResponse {
        $attachment = $store->store($lead, $request->file('file'), $request->user());

        return (new LeadAttachmentResource($attachment->load('uploader')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Stream one attachment back as a download, under its original filename.
     *
     * Authorized by `view` on the lead — the same boundary as opening the lead — which is why the
     * bytes live on the private disk instead of being served by a public URL. A row whose file has
     * gone missing from disk 404s rather than emitting a broken download.
     */
    public function download(Lead $lead, LeadAttachment $attachment): SymfonyResponse
    {
        $disk = Storage::disk(LeadAttachmentStore::DISK);

        if (! $disk->exists($attachment->path)) {
            throw new NotFoundHttpException('The attached file is no longer available.');
        }

        return $disk->download($attachment->path, $attachment->original_name);
    }

    /**
     * A bounded, JSON preview of a spreadsheet attachment.
     *
     * Authorized EXACTLY as download is — `view` on the parent lead, plus the route's scopeBindings
     * tying {attachment} to {lead} — because it discloses the same bytes in a different shape. There
     * is no second access path and no public URL: an unauthorized caller is refused here for the
     * same reason, by the same policy, as at the download endpoint.
     *
     * The format is decided from the FILE CONTENT by the reader, not from `mime_type` or the
     * filename, so a `.xlsx` that is really something else 422s instead of reaching a parser. Any
     * non-spreadsheet attachment therefore also 422s, which is correct: Download is the fallback for
     * everything this cannot open.
     */
    public function preview(
        Lead $lead,
        LeadAttachment $attachment,
        SpreadsheetPreviewReader $reader,
    ): SymfonyResponse {
        $disk = Storage::disk(LeadAttachmentStore::DISK);

        if (! $disk->exists($attachment->path)) {
            throw new NotFoundHttpException('The attached file is no longer available.');
        }

        try {
            $preview = $reader->read($disk->path($attachment->path));
        } catch (UnreadableSpreadsheetException $e) {
            // getMessage() is one of the class's own constants — safe by construction. The cause is
            // attached as `previous` for the log and is never serialized into the response.
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new AttachmentPreviewResource($preview))->response();
    }

    /**
     * A PDF rendering of an Office attachment (DOC/DOCX/PPT/PPTX), streamed inline for the viewer.
     *
     * Authorized EXACTLY as download and the spreadsheet preview are — `view` on the parent lead plus
     * the route's scopeBindings — because it discloses the same content in a different shape. The PDF
     * comes from the private disk and is never given a URL of its own.
     *
     * Every refusal is one of DocumentPreviewException's own sentences with its own status: 422 for a
     * file that cannot be previewed, 503 for a converter that is busy or not installed. Download is
     * the fallback for all of them.
     */
    public function documentPreview(
        Lead $lead,
        LeadAttachment $attachment,
        DocumentPreviewService $previews,
    ): SymfonyResponse {
        $disk = Storage::disk(LeadAttachmentStore::DISK);

        if (! $disk->exists($attachment->path)) {
            throw new NotFoundHttpException('The attached file is no longer available.');
        }

        try {
            $pdf = $previews->pdfFor($attachment);
        } catch (DocumentPreviewException $e) {
            // Logged only when something actually went wrong underneath; "that is a ZIP" is not news.
            if ($e->getPrevious() !== null) {
                report($e);
            }

            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return $disk->response(
            $pdf,
            pathinfo((string) $attachment->original_name, PATHINFO_FILENAME).'.pdf',
            [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
                // The content is as private as the lead; no shared cache may keep a copy.
                'Cache-Control' => 'private, no-store',
                // Defence in depth for anyone who opens the URL directly: no script, no plugins, no
                // same-origin access. The viewer reads the bytes as a blob and is unaffected.
                'Content-Security-Policy' => 'sandbox',
            ],
            'inline',
        );
    }

    /** Delete an attachment (uploader/Admin/SA): soft-deletes the row and removes the bytes. */
    public function destroy(Lead $lead, LeadAttachment $attachment, LeadAttachmentStore $store): JsonResponse
    {
        $store->remove($attachment);

        return response()->json(null, 204);
    }
}
