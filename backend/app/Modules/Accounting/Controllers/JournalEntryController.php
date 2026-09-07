<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Requests\StoreJournalEntryRequest;
use App\Modules\Accounting\Resources\JournalEntryResource;
use App\Modules\Accounting\Services\JournalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class JournalEntryController extends Controller
{
    public function __construct(
        private readonly JournalService $journal,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', JournalEntry::class);

        $entries = JournalEntry::query()
            ->with('lines.account')
            ->latest('entry_date')
            ->latest('id')
            ->get();

        return JournalEntryResource::collection($entries);
    }

    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        $this->authorize('create', JournalEntry::class);

        $entry = $this->journal->create($request->validated());

        if ($request->boolean('post')) {
            $this->authorize('post', $entry);
            $entry = $this->journal->post($entry, $request->user());
        }

        $entry->load('lines.account');

        return JournalEntryResource::make($entry)
            ->response()
            ->setStatusCode(201);
    }

    public function post(Request $request, JournalEntry $journalEntry): JournalEntryResource
    {
        $this->authorize('post', $journalEntry);

        $entry = $this->journal->post($journalEntry, $request->user());
        $entry->load('lines.account');

        return JournalEntryResource::make($entry);
    }

    public function reverse(Request $request, JournalEntry $journalEntry): JournalEntryResource
    {
        $this->authorize('reverse', $journalEntry);

        $reversal = $this->journal->reverse($journalEntry, $request->user());
        $reversal->load('lines.account');

        return JournalEntryResource::make($reversal);
    }
}
