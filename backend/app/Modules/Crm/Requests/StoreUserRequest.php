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

            // On the grant path the address is expected to exist, so `unique` is dropped. The create
            // path keeps the ORIGINAL rule verbatim, soft-delete clause included — this milestone
            // changes no create-path behavior.
            //
            // Known pre-existing gap, deliberately left alone and tracked separately: `users.email`
            // carries a plain UNIQUE index that a soft-delete does not free, so an address belonging
            // to a soft-deleted account passes this rule and then fails at the database. Fixing it
            // means dropping `whereNull('deleted_at')`, which is a create-path behavior change and
            // therefore belongs in its own commit, not in the CRM-access one.
            'email' => $granting
                ? ['required', 'email', 'max:255']
                : ['required', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],

            // Only roles the creator may assign (Super Admin → admin/BDM, Admin → BDM). Never
            // super-admin. This is what stops a CRM Admin escalating anyone (themselves included)
            // beyond `crm_bd`, on BOTH the create and the grant path.
            'role' => ['required', Rule::in($this->user()->assignableRoles())],

            // A grant never activates or suspends the existing account.
            'status' => $granting
                ? ['prohibited']
                : ['sometimes', Rule::in(['active', 'inactive'])],

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
            'status.prohibited' => 'This email already belongs to a Vite SEO account — granting CRM access cannot change their status.',
            'password.prohibited' => 'This email already belongs to a Vite SEO account — granting CRM access never changes their password.',
        ];
    }
}
