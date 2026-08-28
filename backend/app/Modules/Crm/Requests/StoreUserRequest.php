<?php

namespace App\Modules\Crm\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /users serves TWO shapes, chosen by whether the email already belongs to a centralized user:
 *
 *  - **New person** → create an account (name + optional temporary password), exactly as before.
 *  - **Existing centralized user** → grant CRM access additively; the `users` row is not written.
 *
 * The grant shape is enforced here rather than merely ignored in the controller: `name`, `status`
 * and `password` become `prohibited`, so a grant request cannot even EXPRESS a change to the
 * existing account. That turns "we don't touch the user row" from a code-path promise into a
 * validation boundary a test can prove.
 */
class StoreUserRequest extends FormRequest
{
    /**
     * Memoized so the lookup runs once per request and the controller reuses the exact model the
     * rules were built from (no second query, no chance of the two disagreeing).
     */
    private ?User $existingUser = null;

    private bool $lookupDone = false;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The live centralized user this email already belongs to, or null.
     *
     * Deliberately EXCLUDES soft-deleted accounts: a deleted user is handled by the `unique` rule
     * below, not silently resurrected by a grant.
     */
    public function existingUser(): ?User
    {
        if (! $this->lookupDone) {
            $email = trim((string) $this->input('email'));

            $this->existingUser = $email === ''
                ? null
                : User::query()->where('email', $email)->first();

            $this->lookupDone = true;
        }

        return $this->existingUser;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $granting = $this->existingUser() !== null;

        return [
            // A grant never renames the account it is granting access to.
            'name' => $granting
                ? ['prohibited']
                : ['required', 'string', 'max:255'],

            // On the grant path the address is expected to exist, so `unique` is dropped.
            //
            // On the create path the rule now matches the DATABASE exactly. `users.email` carries a
            // plain UNIQUE index (`users_email_unique`) that a soft-delete does not free, so the
            // previous `->whereNull('deleted_at')` clause made the validator MORE permissive than
            // the schema: an address belonging to a soft-deleted account passed validation and then
            // died at the insert with an integrity violation — a 500 where the admin needed a
            // sentence. Dropping the clause turns that into a clean 422. It relaxes nothing: the
            // index already refused the write.
            'email' => $granting
                ? ['required', 'email', 'max:255']
                : ['required', 'email', 'max:255', Rule::unique('users', 'email')],

            // Only roles the creator may assign (Super Admin → admin/BDM, Admin → BDM). Never
            // super-admin. This is what stops a CRM Admin escalating anyone (themselves included)
            // beyond `crm_bd`, on BOTH the create and the grant path.
            'role' => ['required', Rule::in($this->user()->assignableRoles())],

            // Prohibited on BOTH branches. `status` is a column on the SHARED `users` row that
            // TeamOra and Sprintly also read, and CRM no longer owns it: Zerem access lives in
            // `crm_user_access` and is set by PATCH /users/{user}, never at creation. Accepting it
            // here would write a value nothing in the CRM reads — an account that looked suspended
            // in the shared column while Zerem, correctly, let it straight in.
            'status' => ['prohibited'],

            // A grant never sets, resets, or generates a password — the person keeps the
            // credentials they already sign in to TeamOra/Sprintly with.
            'password' => $granting
                ? ['prohibited']
                : ['nullable', 'string', 'min:8', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.prohibited' => 'This email already belongs to a Vite SEO account — granting CRM access cannot change their name.',
            // Not grant-specific, unlike the two around it: `status` is prohibited on BOTH branches,
            // so this message must be true for a brand-new account as well.
            'status.prohibited' => 'Account status is not set from the CRM. Zerem access is managed on the account after it exists.',
            'password.prohibited' => 'This email already belongs to a Vite SEO account — granting CRM access never changes their password.',

            // `email.unique` is only ever evaluated on the CREATE path, and a LIVE account can never
            // reach it: `existingUser()` resolves an active address first and routes the request to
            // the grant branch, where this rule is absent. So a failure here means exactly one
            // thing — the address belongs to a soft-deleted account — and the copy can say so.
            //
            // ⚠️ This message's accuracy depends on that upstream interception. If the grant branch
            // ever stops catching active addresses, an active duplicate would land here and be told
            // the account is "deleted". Revisit this string alongside any change to `existingUser()`
            // or to the `$granting` split above.
            'email.unique' => 'This email belongs to a deleted Vite SEO account. Restoring it is a developer operation — ask an administrator rather than creating a second account.',
        ];
    }
}
