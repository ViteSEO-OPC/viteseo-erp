<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Attachment;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Requests\StoreAttachmentRequest;
use App\Modules\Accounting\Resources\AttachmentResource;
use App\Modules\Accounting\Support\CurrentOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('accounting.journal.view'), 403);

        $data = $request->validate([
            'attachable_type' => ['required', 'in:invoice,bill'],
            'attachable_id' => ['required', 'integer'],
        ]);

        $rows = Attachment::query()
            ->where('attachable_type', $data['attachable_type'])
            ->where('attachable_id', (int) $data['attachable_id'])
            ->latest('id')
            ->get();

        return AttachmentResource::collection($rows);
    }

    public function store(StoreAttachmentRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.journal.create'), 403);

        $data = $request->validated();
        $attachable = $this->resolveAttachable($data['attachable_type'], (int) $data['attachable_id']);
        abort_unless($attachable !== null, 404, 'Record not found.');

        $file = $request->file('file');
        // Private disk — never web-accessible; served only via download() below.
        $path = $file->store('attachments/' . CurrentOrg::id(), 'local');

        $attachment = Attachment::create([
            'attachable_type' => $data['attachable_type'],
            'attachable_id' => (int) $data['attachable_id'],
            'original_name' => $file->getClientOriginalName(),
            'disk' => 'local',
            'path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return AttachmentResource::make($attachment)->response()->setStatusCode(201);
    }

    /** Gated download — requires auth + permission + same org. */
    public function download(Request $request, Attachment $attachment)
    {
        abort_unless($request->user()->can('accounting.journal.view'), 403);
        abort_unless($attachment->org_id === CurrentOrg::id(), 403);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }

    private function resolveAttachable(string $type, int $id): ?Model
    {
        return match ($type) {
            'invoice' => Invoice::query()->find($id),
            'bill' => Bill::query()->find($id),
            default => null,
        };
    }
}
