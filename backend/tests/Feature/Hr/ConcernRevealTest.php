<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Http\Resources\ConcernResource;
use App\Modules\Hr\Models\Concern;
use App\Modules\Hr\Models\ConcernRevealRequest;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\ConcernRevealService;
use App\Modules\Hr\Services\ConcernService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Anonymity on concerns, and the negotiation that can lift it.
 *
 * The rule these tests exist to hold: HR sees `Anonymous` until the person who filed the
 * concern has been asked and has said yes. Every assertion below is written against what the
 * RESOURCE emits rather than what the service returns, because the resource is what actually
 * reaches a browser — a privacy rule enforced anywhere else is a rule with a way around it.
 */
class ConcernRevealTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Agent', 'hire_date' => '2020-01-01',
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    private function service(): ConcernRevealService
    {
        return app(ConcernRevealService::class);
    }

    private function concern(bool $anonymous = true): Concern
    {
        return app(ConcernService::class)->create($this->employee, [
            'category' => 'conduct',
            'description' => 'My supervisor keeps reassigning my approved leave.',
            'is_anonymous' => $anonymous,
        ]);
    }

    /**
     * What the API would actually send this viewer. Concerns are re-fetched with the same
     * eager loads the list endpoints use, so the test cannot pass on a relation the real
     * request would not have loaded.
     *
     * @return array<string, mixed>
     */
    private function payloadFor(Concern $concern, User $viewer): array
    {
        $fresh = Concern::with(['employee.user', 'canceller', 'revealRequests.requester'])
            ->findOrFail($concern->id);

        $request = Request::create('/api/v1/hr/concerns');
        $request->setUserResolver(fn () => $viewer);

        return ConcernResource::make($fresh)->toArray($request);
    }

    // --- the rule ------------------------------------------------------------------------

    public function test_hr_cannot_see_who_filed_an_anonymous_concern(): void
    {
        $concern = $this->concern();

        $payload = $this->payloadFor($concern, $this->hrUser());

        $this->assertNull($payload['employee'], 'This is the whole feature.');
        $this->assertNull($payload['identity_revealed_at']);
    }

    public function test_hr_still_sees_who_filed_a_named_concern(): void
    {
        // The control. Without it, the test above passes on a resource that hides everyone.
        $concern = $this->concern(anonymous: false);

        $payload = $this->payloadFor($concern, $this->hrUser());

        $this->assertNotNull($payload['employee']);
        $this->assertSame($this->employee->user->name, $payload['employee']['name']);
    }

    public function test_accepting_is_the_only_thing_that_reveals_the_name(): void
    {
        $concern = $this->concern();
        $request = $this->service()->request($concern, $this->hrUser(), str_repeat('why ', 10));

        $this->assertNull($this->payloadFor($concern, $this->hrUser())['employee'], 'Asking alone reveals nothing.');

        $this->service()->accept($request, $this->employee->user);

        $payload = $this->payloadFor($concern->fresh(), $this->hrUser());
        $this->assertSame($this->employee->user->name, $payload['employee']['name']);
        $this->assertNotNull($payload['identity_revealed_at']);
    }

    public function test_declining_leaves_the_name_hidden_and_gives_hr_the_reason(): void
    {
        $concern = $this->concern();
        $request = $this->service()->request($concern, $this->hrUser(), str_repeat('why ', 10));

        $this->service()->decline($request, $this->employee->user, 'I am afraid of retaliation.');

        $payload = $this->payloadFor($concern->fresh(), $this->hrUser());
        $this->assertNull($payload['employee']);
        $this->assertSame('declined', $payload['reveal_request']['status']);
        $this->assertSame('I am afraid of retaliation.', $payload['reveal_request']['decline_reason']);
    }

    public function test_a_revealed_identity_does_not_leak_through_the_cancellation_field(): void
    {
        // `cancelled_by` names the reporter too. It rides the same gate — a second field
        // handing back the identity the first one withholds is how these features fail.
        $concern = $this->concern();
        app(ConcernService::class)->cancel($concern, $this->employee->user, 'Filed by mistake');

        $payload = $this->payloadFor($concern->fresh(), $this->hrUser());

        $this->assertNull($payload['cancelled_by']);
        $this->assertNull($payload['employee']);
    }

    // --- who may answer ------------------------------------------------------------------

    public function test_hr_cannot_answer_on_the_reporters_behalf(): void
    {
        $concern = $this->concern();
        $request = $this->service()->request($concern, $this->hrUser(), str_repeat('why ', 10));

        $this->expectException(ValidationException::class);
        $this->service()->accept($request, $this->hrUser());
    }

    public function test_another_employee_cannot_answer_it(): void
    {
        $concern = $this->concern();
        $request = $this->service()->request($concern, $this->hrUser(), str_repeat('why ', 10));

        $other = Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => $this->employee->department_id,
            'employee_no' => 'E-002', 'job_title' => 'Agent', 'hire_date' => '2020-01-01',
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);

        $this->expectException(ValidationException::class);
        $this->service()->accept($request, $other->user);
    }

    // --- asking --------------------------------------------------------------------------

    public function test_only_one_question_may_be_open_at_a_time(): void
    {
        $concern = $this->concern();
        $this->service()->request($concern, $this->hrUser(), str_repeat('why ', 10));

        $this->expectException(ValidationException::class);
        $this->service()->request($concern->fresh(), $this->hrUser(), str_repeat('again ', 10));
    }

    public function test_hr_may_ask_again_after_a_decline(): void
    {
        $concern = $this->concern();
        $first = $this->service()->request($concern, $this->hrUser(), str_repeat('why ', 10));
        $this->service()->decline($first, $this->employee->user, 'Not comfortable.');

        $second = $this->service()->request($concern->fresh(), $this->hrUser(), str_repeat('again ', 10));

        $this->assertSame('pending', $second->status);
        $this->assertCount(2, $concern->revealRequests()->get());
    }

    public function test_nobody_can_be_asked_twice_once_they_have_agreed(): void
    {
        $concern = $this->concern();
        $request = $this->service()->request($concern, $this->hrUser(), str_repeat('why ', 10));
        $this->service()->accept($request, $this->employee->user);

        $this->expectException(ValidationException::class);
        $this->service()->request($concern->fresh(), $this->hrUser(), str_repeat('more ', 10));
    }

    public function test_a_named_concern_cannot_be_asked_about(): void
    {
        $concern = $this->concern(anonymous: false);

        $this->expectException(ValidationException::class);
        $this->service()->request($concern, $this->hrUser(), str_repeat('why ', 10));
    }

    public function test_an_answered_request_cannot_be_answered_again(): void
    {
        $concern = $this->concern();
        $request = $this->service()->request($concern, $this->hrUser(), str_repeat('why ', 10));
        $this->service()->decline($request, $this->employee->user, 'No.');

        $this->expectException(ValidationException::class);
        $this->service()->accept($request->fresh(), $this->employee->user);
    }

    // --- the trail -----------------------------------------------------------------------

    public function test_the_audit_trail_never_names_the_reporter(): void
    {
        $concern = $this->concern();
        $request = $this->service()->request($concern, $this->hrUser(), str_repeat('why ', 10));
        $this->service()->decline($request, $this->employee->user, 'I am afraid of retaliation.');

        $lines = \App\Modules\Hr\Models\AuditLog::where('module', 'Concerns')->pluck('description');

        $name = $this->employee->user->name;
        foreach ($lines as $line) {
            $this->assertStringNotContainsString($name, (string) $line);
            // The decline reason is the reporter's own words about why they will not be named,
            // and those can identify them as surely as the name would.
            $this->assertStringNotContainsString('retaliation', (string) $line);
        }

        $this->assertGreaterThan(0, $lines->count(), 'The control: something must have been logged.');
    }

    public function test_the_employee_sees_the_ask_on_their_own_concern(): void
    {
        $concern = $this->concern();
        $this->service()->request($concern, $this->hrUser(), 'We cannot verify these leave records.');

        $mine = app(ConcernService::class)->listForEmployee($this->employee)->firstOrFail();
        $payload = $this->payloadFor($mine, $this->employee->user);

        $this->assertSame('pending', $payload['reveal_request']['status']);
        $this->assertSame('We cannot verify these leave records.', $payload['reveal_request']['reason']);
        $this->assertNotNull($payload['reveal_request']['requested_by'], 'They are entitled to know who is asking.');
    }

    public function test_a_concern_nobody_has_asked_about_carries_a_null_request(): void
    {
        $concern = $this->concern();

        // Present and null, never absent: a client cannot tell a missing key from a settled
        // question, and this shape is read on both sides.
        $payload = $this->payloadFor($concern, $this->hrUser());

        $this->assertArrayHasKey('reveal_request', $payload);
        $this->assertNull($payload['reveal_request']);
    }

    public function test_a_request_on_a_withdrawn_concern_cannot_be_answered(): void
    {
        // A soft-deleted concern resolves through `belongsTo` as null, so the service's
        // null-concern guard is the thing standing between that and a fatal on `->employee_id`.
        // (A hard delete cascades this row away entirely — there is nothing left to answer.)
        $concern = $this->concern();
        $request = $this->service()->request($concern, $this->hrUser(), str_repeat('why ', 10));
        $concern->delete();

        $this->expectException(ValidationException::class);
        $this->service()->accept(ConcernRevealRequest::findOrFail($request->id)->fresh(), $this->employee->user);
    }
}
