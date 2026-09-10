<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Contact;
use App\Modules\Accounting\Requests\StoreContactRequest;
use App\Modules\Accounting\Resources\ContactResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ContactController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('accounting.journal.view'), 403);

        $query = Contact::query();

        // ?type=customer|vendor also matches "both".
        $type = $request->query('type');
        if ($type === 'customer') {
            $query->whereIn('type', ['customer', 'both']);
        } elseif ($type === 'vendor') {
            $query->whereIn('type', ['vendor', 'both']);
        }

        return ContactResource::collection($query->orderBy('name')->get());
    }

    public function store(StoreContactRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.journal.create'), 403);

        $contact = Contact::create([
            ...$request->validated(),
        ]);

        return ContactResource::make($contact)->response()->setStatusCode(201);
    }
}
