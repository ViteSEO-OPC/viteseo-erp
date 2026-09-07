<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Requests\StoreAccountRequest;
use App\Modules\Accounting\Requests\UpdateAccountRequest;
use App\Modules\Accounting\Resources\AccountResource;
use App\Modules\Accounting\Support\AccountReferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    /**
     * Chart of accounts for the current org, grouped by account type
     * (asset → liability → equity → income → expense), then by code.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Account::class);

        $accounts = Account::query()
            ->orderByRaw("CASE type WHEN 'asset' THEN 1 WHEN 'liability' THEN 2 WHEN 'equity' THEN 3 WHEN 'income' THEN 4 WHEN 'expense' THEN 5 ELSE 6 END")
            ->orderBy('code')
            ->get();

        return AccountResource::collection($accounts);
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        $this->authorize('create', Account::class);

        $account = Account::create([
            'code' => $request->validated()['code'],
            'name' => $request->validated()['name'],
            'type' => $request->validated()['type'],
            'parent_id' => $request->validated()['parent_id'] ?? null,
            'is_active' => true,
            'is_bank' => $request->boolean('is_bank'),
        ]);

        return AccountResource::make($account)->response()->setStatusCode(201);
    }

    /** Edit an account's name / active flag (code + type stay immutable). */
    public function update(UpdateAccountRequest $request, Account $account): AccountResource
    {
        $this->authorize('update', $account);

        $account->update([
            'name' => $request->validated()['name'],
            'is_active' => $request->boolean('is_active', (bool) $account->is_active),
            'is_bank' => $request->boolean('is_bank', (bool) $account->is_bank),
        ]);

        return AccountResource::make($account);
    }

    /** Delete an unused account. If it has ledger entries, deactivate instead. */
    public function destroy(Account $account): JsonResponse
    {
        $this->authorize('delete', $account);

        // Not just the ledger. A purchase order posts no journal line by design,
        // a recurring invoice is a template that has not run, and a budget is a
        // plan -- all three point at an account, and all three used to be
        // deletable out from under. The PO line then kept an account_id that
        // resolved to nothing, converting it to a bill still succeeded, and the
        // trial balance showed a code the client could not find in their own
        // chart of accounts.
        $holder = app(AccountReferences::class)->firstReferencing($account->id);
        if ($holder !== null) {
            throw ValidationException::withMessages([
                'account' => $holder === 'acc_journal_lines'
                    ? 'This account has ledger entries and cannot be deleted. Deactivate it instead.'
                    : 'This account is still in use elsewhere in your books and cannot be deleted. Deactivate it instead.',
            ]);
        }

        $account->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }
}
