<?php

namespace Database\Factories;

use App\Models\Org;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Put every generated user in an org.
     *
     * Not a convenience — a correctness fix. Since multi-tenancy landed, a user
     * with no org membership is not a lighter fixture, it is an INVALID user:
     * `org_user.user_id` is uniquely indexed (one org per user), `CurrentOrg::id()`
     * fails closed and returns null for an orgless account, so such a user cannot
     * read or write a single tenant-scoped row, and every HR/CRM/Projects route
     * answers 402 because the subscription gate can find no subscription for
     * "no org". Hundreds of tests hand-built exactly that user and then wondered
     * why the endpoint under test was never reached.
     *
     * The org is whichever one already exists — Tests\TestCase::tenant() creates
     * it before any test body runs. Nothing is attached when there is no org
     * (a plain `db:seed`, or a test that deliberately runs tenant-less).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if ($user->orgs()->exists()) {
                return;
            }

            $orgId = Org::query()->orderBy('id')->value('id');

            if ($orgId !== null) {
                $user->orgs()->attach($orgId, ['is_default' => true]);
            }
        });
    }

    /**
     * A user belonging to no org — for the tests that describe exactly that:
     * onboarding before an org exists, and CurrentOrg's fail-closed behaviour.
     */
    public function orgless(): static
    {
        return $this->afterCreating(fn (User $user) => $user->orgs()->detach());
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
