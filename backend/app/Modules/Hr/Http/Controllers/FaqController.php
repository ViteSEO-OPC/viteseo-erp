<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\StoreFaqRequest;
use App\Modules\Hr\Http\Resources\FaqResource;
use App\Modules\Hr\Models\Faq;
use App\Modules\Hr\Services\FaqService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FaqController extends Controller
{
    public function __construct(private readonly FaqService $service) {}

    // GET /hr/faqs?q= — HR sees all; employees see published only.
    public function index(Request $request): JsonResponse
    {
        return FaqResource::collection($this->service->list($request->user(), $request->query('q')))->response();
    }

    // POST /hr/faqs — HR only.
    public function store(StoreFaqRequest $request): JsonResponse
    {
        return FaqResource::make($this->service->create($request->validated()))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }

    // PUT/PATCH /hr/faqs/{faq} — HR only.
    public function update(StoreFaqRequest $request, Faq $faq): JsonResponse
    {
        return FaqResource::make($this->service->update($faq, $request->validated()))->response();
    }

    // DELETE /hr/faqs/{faq} — HR only.
    public function destroy(Faq $faq): Response
    {
        $this->service->delete($faq);

        return response()->noContent();
    }

    // POST /hr/faqs/{faq}/publish — HR only.
    public function publish(Faq $faq): JsonResponse
    {
        return FaqResource::make($this->service->setStatus($faq, 'published'))->response();
    }

    // POST /hr/faqs/{faq}/archive — HR only.
    public function archive(Faq $faq): JsonResponse
    {
        return FaqResource::make($this->service->setStatus($faq, 'archived'))->response();
    }

    // POST /hr/faqs/{faq}/pin — HR only (toggles).
    public function pin(Faq $faq): JsonResponse
    {
        return FaqResource::make($this->service->togglePin($faq))->response();
    }

    // POST /hr/faqs/{faq}/view — any authenticated user (view-count insight).
    public function view(Faq $faq): Response
    {
        $this->service->registerView($faq);

        return response()->noContent();
    }
}
