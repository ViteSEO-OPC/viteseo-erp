<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Validation rule: the given user id must be a member of the CURRENT org.
 *
 * `users` is the one table tenancy deliberately did NOT partition — an account is
 * platform-wide and its membership lives in the `org_user` pivot. That makes a bare
 * `exists:users,id` a fence that no longer fences anything: it accepts ANY tenant's
 * user id, which is how a cross-org account could be made a project member, a task
 * assignee, a group leader, a lead owner or a performance-target subject.
 *
 * Ported from kps (App\Modules\Projects\Support\OrgUserRule) but placed in
 * App\Support rather than inside Projects, because CRM needs exactly the same fence
 * and two copies of a security rule is one copy too many.
 *
 * Validating against `org_user` rather than `users` also means the rule cannot be
 * satisfied by a soft-deleted or orgless account.
 */
final class OrgUserRule
{
    public static function exists(): Exists
    {
        return Rule::exists('org_user', 'user_id')->where('org_id', CurrentOrg::id());
    }
}
