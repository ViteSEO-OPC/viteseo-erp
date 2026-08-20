<?php

declare(strict_types=1);

namespace App\Modules\Crm\Resources;

use App\Models\User;
use App\Modules\Crm\Services\CrmAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The minimal identity payload behind the email-first Create Account flow.
 *
 * Answers exactly one question — "whose account is this, and can they already reach Zerem?" — so an
 * admin can confirm they are granting access to the right colleague. Deliberately narrower than
 * {@see UserResource}: no permission list, no phone, no last-login, no status. Someone discovering a
 * user by exact email should learn who they are and which systems they already use, nothing more.
 *
 * @mixin User
 */
class UserLookupResource extends JsonResource
{
    /**
     * Role → the product name of the system it grants. `super_admin` spans all three, so it is
     * expanded rather than mapped to one label.
     *
     * @var array<string, list<string>>
     */
    private const SYSTEMS = [
        'hr_officer' => ['TeamOra'],
        'hr_employee' => ['TeamOra'],
        'crm_admin' => ['Zerem'],
        'crm_bd' => ['Zerem'],
        'pm_apm' => ['Sprintly'],
        'pm_member' => ['Sprintly'],
        'super_admin' => ['TeamOra', 'Zerem', 'Sprintly'],
    ];

    public function __construct(User $resource, private readonly CrmAccessService $access)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $roles = $this->getRoleNames()->all();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // Every platform role the account holds — shown so the admin can see they are about to
            // ADD to an existing TeamOra/Sprintly account rather than create a parallel one.
            'roles' => $roles,
            // The same information as product names, for the confirmation copy.
            'systems' => $this->systemsFor($roles),
            // Non-null when they can already enter Zerem, which makes the grant a no-op.
            'crm_role' => $this->access->primaryCrmRole($this->resource),
        ];
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    private function systemsFor(array $roles): array
    {
        $systems = [];

        foreach ($roles as $role) {
            foreach (self::SYSTEMS[$role] ?? [] as $system) {
                $systems[$system] = true;
            }
        }

        // Stable product order rather than role-pivot order.
        return array_values(array_filter(
            ['TeamOra', 'Zerem', 'Sprintly'],
            fn (string $system): bool => isset($systems[$system]),
        ));
    }
}
