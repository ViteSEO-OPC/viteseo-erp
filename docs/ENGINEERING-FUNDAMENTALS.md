# Engineering Fundamentals Guide

Purpose: learn the fundamentals of system design, system architecture, data structures, and algorithms in a way that connects directly to real projects.

This guide is intentionally practical. It does not try to turn you into a distributed-systems expert in one document. It gives you the mental models, vocabulary, trade-offs, and code patterns you need to understand how production applications are designed and why engineers choose one approach over another.

Use this together with `docs/HEADLESS-ARCHITECTURE-GUIDE.md` when you want a full Laravel + Next.js build tutorial. This file is broader: it teaches the engineering concepts behind projects like that.

---

## Table of Contents

1. [How to Use This Guide](#1-how-to-use-this-guide)
2. [The Big Picture: What Engineers Actually Design](#2-the-big-picture-what-engineers-actually-design)
3. [System Design Fundamentals](#3-system-design-fundamentals)
4. [System Architecture Fundamentals](#4-system-architecture-fundamentals)
5. [Data Fundamentals](#5-data-fundamentals)
6. [Data Structures](#6-data-structures)
7. [Algorithms](#7-algorithms)
8. [Applying DSA to Real Projects](#8-applying-dsa-to-real-projects)
9. [Design Walkthroughs](#9-design-walkthroughs)
10. [Learning Roadmap](#10-learning-roadmap)
11. [Self-Check Questions](#11-self-check-questions)
12. [Quick Reference](#12-quick-reference)

---

## 1. How to Use This Guide

### 1.1 What this guide is good for

This guide is good for learning:

- What system design means in real applications.
- What system architecture means and how it differs from system design.
- How common architecture patterns work: monolith, modular monolith, microservices, layered architecture, event-driven architecture.
- Why data structures matter outside coding interviews.
- How algorithms show up in database queries, search, sorting, queues, caching, permissions, routing, reports, and background jobs.
- How to connect theory to real Laravel / Next.js / SQL projects.

### 1.2 What this guide is not

This guide is not enough by itself for:

- Deep distributed systems.
- Advanced database internals.
- Advanced graph theory.
- Competitive programming mastery.
- Cloud certification-level architecture.

That is okay. The goal here is fundamentals first.

### 1.3 Recommended reading path

Read in this order:

1. System design fundamentals.
2. System architecture fundamentals.
3. Data fundamentals.
4. Data structures.
5. Algorithms.
6. Design walkthroughs.
7. Self-check questions.

Do not memorize everything. For each topic, ask:

- What problem does this solve?
- What trade-off does it introduce?
- Where would I see this in a real app?
- What would happen if I did not use it?

---

## 2. The Big Picture: What Engineers Actually Design

A software system is not just code. A real application includes:

```txt
Users
  ↓
Frontend / UI
  ↓ HTTP / JSON
Backend API
  ↓
Business logic
  ↓
Database / cache / queues / files
  ↓
External services
```

When engineers design a system, they make decisions about:

- What the user can do.
- What data the system stores.
- Which component owns each responsibility.
- How data moves between components.
- How the system behaves when traffic grows.
- How the system behaves when something fails.
- How easy it is to change later.

### 2.1 System design vs system architecture

These terms overlap, but they are not exactly the same.

| Concept | Main question | Example |
|---|---|---|
| System design | How should this whole feature or product work? | Design leave requests, approval flow, notifications, reports, database tables, API endpoints. |
| System architecture | How should the code and components be organized? | Laravel API + Next.js frontend, layered backend, service classes, database, queue workers. |
| Data structures | How should data be represented in memory or storage? | Array of tasks, hash map of users by ID, tree of departments, queue of jobs. |
| Algorithms | What steps transform input into output? | Search employees, sort payroll records, calculate leave days, traverse org chart. |

Simple mental model:

```txt
System design     = what the product/system must do and how parts interact
Architecture      = where responsibilities live
Data structures   = how data is shaped
Algorithms        = how work is performed
```

### 2.2 Real-world example: leave request feature

A leave request feature uses all four areas:

| Area | Example in leave request feature |
|---|---|
| System design | Employee submits leave, manager approves, balance updates, notification sent. |
| Architecture | Controller validates request, service applies rules, model stores data, queue sends email. |
| Data structure | Leave balances keyed by employee and year; manager hierarchy as a tree. |
| Algorithm | Count working days between dates; skip weekends and holidays; check remaining balance. |

---

## 3. System Design Fundamentals

System design is the process of deciding how a system should meet requirements under real-world constraints.

The most important word is trade-off.

You rarely get everything at once:

- Fast and cheap.
- Simple and infinitely scalable.
- Strong consistency and maximum availability.
- Flexible and easy to understand.

Good design chooses the simplest solution that satisfies the current requirements while leaving a reasonable path to grow.

### 3.1 Start with requirements

Before designing, ask two kinds of questions.

#### Functional requirements

What must the system do?

Example for an HRIS:

- Employees can view their profile.
- Employees can submit leave requests.
- HR officers can approve or reject leave requests.
- The system sends notifications.
- The system generates reports.

#### Non-functional requirements

How well must the system do it?

Examples:

- Response time under 200ms for common API requests.
- Only authorized users can view HR data.
- Data must not be lost if a request fails halfway.
- Reports can take longer but should not block normal user actions.
- The system should keep working if email sending fails.

Non-functional requirements usually drive architecture decisions.

### 3.2 Capacity estimation

Capacity estimation means making rough guesses about scale.

You do not need perfect math. You need enough numbers to avoid obviously bad choices.

Ask:

- How many users?
- How many requests per day?
- How many database rows per year?
- How large are uploaded files?
- Which operations happen most often?
- Which operations are slow or expensive?

Example:

```txt
Company size: 500 employees
Each employee views attendance twice per day
Attendance API reads per day: 500 × 2 = 1,000 reads/day
Leave requests per month: maybe 200/month
Payroll runs: maybe 2/month
```

Design impact:

- Attendance reads should be fast and indexed.
- Leave requests do not need extreme scale.
- Payroll can run in a background queue because it is rare but heavy.

### 3.3 Latency, throughput, and bandwidth

| Term | Meaning | Example |
|---|---|---|
| Latency | Time for one request to complete. | One API call takes 120ms. |
| Throughput | How many requests the system handles per second. | 200 requests/second. |
| Bandwidth | How much data moves per second. | 50MB/s of file downloads. |

Important distinction:

- Low latency means one user gets a fast response.
- High throughput means many users can be served at once.

A system can have high throughput but bad latency if requests wait in line too long.

### 3.4 Scalability

Scalability is the ability to handle growth.

#### Vertical scaling: scale up

Add more power to one machine.

```txt
Small server: 2 CPU / 4GB RAM
Bigger server: 8 CPU / 32GB RAM
```

Pros:

- Simple.
- No major application changes.
- Often enough for early projects.

Cons:

- Hardware has limits.
- One machine can still be a single point of failure.
- Expensive at the high end.

#### Horizontal scaling: scale out

Add more machines.

```txt
Users
  ↓
Load balancer
  ↓       ↓       ↓
App 1   App 2   App 3
  ↓       ↓       ↓
Shared database / cache
```

Pros:

- Handles more traffic.
- Can survive one app server failing.
- Common in production.

Cons:

- More moving parts.
- Requires stateless app servers.
- Sessions, files, cache, and background jobs must be designed carefully.

### 3.5 Stateless vs stateful services

A stateless service does not depend on memory stored inside one specific server.

Good for horizontal scaling:

```txt
Request includes token
Any app server can validate token
Any app server can handle request
```

Bad for horizontal scaling:

```txt
User logs in on App Server 1
Session stored only in App Server 1 memory
Next request goes to App Server 2
App Server 2 does not know the user
```

Real-world rule:

- Store durable data in the database.
- Store shared temporary data in Redis or another shared cache.
- Do not rely on local memory for user sessions if multiple app servers exist.

### 3.6 Reliability

Reliability means the system continues to behave correctly even when parts fail.

Common reliability techniques:

| Technique | Meaning | Example |
|---|---|---|
| Redundancy | Have backup components. | Multiple app servers. |
| Retry | Try again after temporary failure. | Retry failed email send. |
| Timeout | Stop waiting after a limit. | External API timeout after 5 seconds. |
| Circuit breaker | Temporarily stop calling a failing dependency. | Stop calling payroll provider after repeated failures. |
| Transaction | Make several DB changes succeed or fail together. | Create user + employee record atomically. |
| Queue | Move slow or fragile work out of the request. | Send notification after response. |

### 3.7 Reliability example: database transaction

Problem: creating an employee might require multiple writes.

- Create a user login.
- Create an employee profile.
- Create initial leave balances.

If step 3 fails, steps 1 and 2 should not remain half-created.

```php
// Import Laravel's DB facade so we can use database transactions.
use Illuminate\Support\Facades\DB;

// Service class: this is where employee creation business logic lives.
class EmployeeService
{
    // Create a user account, employee profile, and leave balance together.
    public function createEmployee(array $data): Employee
    {
        // Start a transaction: either every database write succeeds, or all are rolled back.
        return DB::transaction(function () use ($data) {
            // First, create the login user record.
            $user = User::create([
                // Copy the employee's name from validated input.
                'name' => $data['name'],
                // Copy the email from validated input.
                'email' => $data['email'],
                // Hash the temporary password before storing it.
                'password' => bcrypt($data['temporary_password']),
            ]);

            // Next, create the HR employee record linked to the user.
            $employee = Employee::create([
                // Connect this employee profile to the user account created above.
                'user_id' => $user->id,
                // Store which department the employee belongs to.
                'department_id' => $data['department_id'],
                // Store employment status, such as active, regular, probationary, etc.
                'employment_status' => $data['employment_status'],
            ]);

            // Finally, create this employee's starting leave balance.
            LeaveBalance::create([
                // Connect the balance to the employee profile.
                'employee_id' => $employee->id,
                // Store the balance for the current year.
                'year' => now()->year,
                // Give the employee 15 vacation days.
                'vacation_days' => 15,
                // Give the employee 15 sick days.
                'sick_days' => 15,
            ]);

            // Return the employee record if all steps succeeded.
            return $employee;
        });
    }
}
```

Explanation:

- `DB::transaction(...)` starts a database transaction.
- If all statements succeed, Laravel commits the changes.
- If any exception is thrown, Laravel rolls everything back.
- This prevents partial data such as a user account without an employee record.

This is a fundamental system design idea: protect consistency at important boundaries.

### 3.8 Reliability example: timeout and retry

External services fail. They can be slow, down, or rate-limited.

```php
// Import Laravel's HTTP client facade.
use Illuminate\Support\Facades\Http;

// Small client class responsible for calling one external service.
class ExchangeRateClient
{
    // Fetch the latest exchange rates from an external API.
    public function latestRates(): array
    {
        // Make the HTTP request with safety limits.
        $response = Http::timeout(5)
            // Retry up to 3 times, waiting 200ms between attempts.
            ->retry(3, 200)
            // Send a GET request to the external endpoint.
            ->get('https://api.example.com/rates');

        // Check if the final response was still unsuccessful.
        if ($response->failed()) {
            // Throw a clear error so callers know the provider is unavailable.
            throw new RuntimeException('Exchange-rate provider is unavailable.');
        }

        // Decode and return the JSON response as an array.
        return $response->json();
    }
}
```

Explanation:

- `timeout(5)` prevents the request from hanging forever.
- `retry(3, 200)` tries up to 3 times, waiting 200ms between attempts.
- `failed()` catches non-success HTTP responses.
- The caller can decide whether to show an error, use cached data, or try later.

Important: retries are good for temporary failures, but bad if they multiply traffic against an already failing service. Use them carefully.

### 3.9 Performance

Performance means the system responds quickly and uses resources efficiently.

Common techniques:

| Technique | Problem solved | Example |
|---|---|---|
| Pagination | Too many rows returned at once. | `Employee::paginate(20)` |
| Indexing | Slow database lookup. | Index `department_id`. |
| Caching | Repeated expensive reads. | Cache department list. |
| Eager loading | Too many repeated queries. | `Employee::with('department')` |
| Queueing | Slow work blocks request. | Process payroll in background. |
| Denormalization | Expensive repeated calculations. | Store monthly attendance summary. |

### 3.10 Performance example: pagination

Bad:

```php
// Bad for large tables: this loads every employee row into application memory.
$employees = Employee::all();
```

Why this is bad:

- It loads every employee row into memory.
- It sends too much data over the network.
- It becomes slower as the table grows.

Better:

```php
// Start a database query instead of loading all employees.
$employees = Employee::query()
    // Only include active employees.
    ->where('employment_status', 'active')
    // Sort the result by last name in the database.
    ->orderBy('last_name')
    // Return only 20 employees for the current page.
    ->paginate(20);
```

Explanation:

- `where(...)` filters at the database level.
- `orderBy(...)` sorts at the database level.
- `paginate(20)` returns only one page of results.
- The API response can include metadata like current page and total count.

### 3.11 Performance example: database index

If you often query employees by department, add an index.

```php
// Modify the hr_employees table schema.
Schema::table('hr_employees', function (Blueprint $table) {
    // Add an index to speed up queries filtering by department_id.
    $table->index('department_id');
});
```

Query using the index:

```php
// Query employees from the database.
$employees = Employee::query()
    // Use the indexed department_id column for filtering.
    ->where('department_id', $departmentId)
    // Return 20 matching employees for the current page.
    ->paginate(20);
```

Explanation:

- Without an index, the database may scan many rows.
- With an index, the database has a lookup structure that helps it find matching rows faster.
- Indexes speed up reads but add cost to writes because the index must also be updated.

Trade-off:

```txt
More indexes = faster reads for matching queries
More indexes = slower writes and more storage
```

### 3.12 Caching

Caching stores a copy of data somewhere faster than the original source.

Example:

```php
// Import Laravel's Cache facade.
use Illuminate\Support\Facades\Cache;

// Try to read active departments from cache first.
$departments = Cache::remember('departments:active', 3600, function () {
    // This query runs only if the cache key does not already exist.
    return Department::query()
        // Include only active departments.
        ->where('is_active', true)
        // Sort departments alphabetically.
        ->orderBy('name')
        // Execute the query and return the collection.
        ->get();
});
```

Explanation:

- `departments:active` is the cache key.
- `3600` means keep the cached value for one hour.
- The callback runs only on a cache miss.
- Future requests within the hour read from cache instead of the database.

Important cache questions:

- How fresh must this data be?
- When should the cache expire?
- Should the cache be cleared when data changes?
- What happens if the cache is empty?

Caching is not free. It adds invalidation complexity.

### 3.13 Consistency

Consistency means the data remains correct according to the rules of the system.

Examples:

- A leave balance should not go negative.
- A payslip should belong to the correct employee.
- A user should not update another user's task.
- A payroll run should not be marked complete if payslip generation failed.

Tools for consistency:

- Database constraints.
- Transactions.
- Validation.
- Authorization.
- Idempotency.
- Locking for sensitive operations.

### 3.14 Idempotency

An operation is idempotent if running it multiple times has the same effect as running it once.

Example:

```txt
DELETE /tasks/123
```

If the task is already deleted, deleting it again should not create extra side effects.

Important for payments, webhooks, retries, and background jobs.

Example concept:

```php
// Controller that receives webhook requests from an external system.
class WebhookController
{
    // Handle one incoming webhook request.
    public function handle(Request $request): JsonResponse
    {
        // Read the unique event ID sent by the external provider.
        $eventId = $request->input('event_id');

        // If we already processed this event ID, do not apply it again.
        if (ProcessedWebhook::where('event_id', $eventId)->exists()) {
            // Return a safe response: duplicate received, but no duplicate work done.
            return response()->json(['data' => ['status' => 'already_processed']]);
        }

        // Use a transaction so applying the event and recording it happen together.
        DB::transaction(function () use ($request, $eventId) {
            // Apply the webhook effect once.
            $this->applyEvent($request->all());

            // Record the event ID so duplicates can be detected later.
            ProcessedWebhook::create(['event_id' => $eventId]);
        });

        // Tell the caller the event was processed successfully.
        return response()->json(['data' => ['status' => 'processed']]);
    }
}
```

Explanation:

- The system stores the external `event_id` after processing.
- If the same webhook arrives again, it returns safely without applying the effect twice.
- This protects against duplicate messages.

---

## 4. System Architecture Fundamentals

Architecture is how you organize responsibilities.

Good architecture makes it clear:

- Where code belongs.
- Which component owns which responsibility.
- How data flows.
- What should be easy to change.
- What should be hard to misuse.

### 4.1 The simplest useful architecture

For many web apps, a simple layered architecture is enough.

```txt
Controller / Route layer
  ↓
Validation layer
  ↓
Service / business logic layer
  ↓
Model / data access layer
  ↓
Database
```

In Laravel terms:

```txt
routes/api.php
  → Controller
  → Form Request
  → Service
  → Eloquent Model
  → Migration / Database table
  → API Resource response
```

### 4.2 Layered architecture example

```php
// Controller handles the HTTP request and response, not the business rules.
class LeaveRequestController extends Controller
{
    // Store a new leave request.
    public function store(StoreLeaveRequest $request, LeaveRequestService $service): LeaveRequestResource
    {
        // Ask the service to submit the leave request.
        $leaveRequest = $service->submit(
            // Get the employee from the authenticated user, not from browser input.
            employee: $request->user()->employee,
            // Pass only validated form data into the service.
            data: $request->validated(),
        );

        // Format the response using an API Resource.
        return new LeaveRequestResource($leaveRequest);
    }
}
```

Explanation:

- The controller receives the HTTP request.
- `StoreLeaveRequest` validates the request data before the method runs.
- The authenticated user determines the employee. The browser does not get to choose `employee_id`.
- The service performs the business rules.
- The resource formats the response.

Service example:

```php
// Service owns the business rules for leave requests.
class LeaveRequestService
{
    // Submit a leave request for one employee.
    public function submit(Employee $employee, array $data): LeaveRequest
    {
        // Use a transaction so checking balance and creating the request stay consistent.
        return DB::transaction(function () use ($employee, $data) {
            // Calculate how many working days the leave request uses.
            $days = $this->workingDaysBetween($data['start_date'], $data['end_date']);

            // Start a query for this employee's leave balance.
            $balance = LeaveBalance::query()
                // Only this employee's balance.
                ->where('employee_id', $employee->id)
                // Only the current year's balance.
                ->where('year', now()->year)
                // Lock the row so another request cannot spend the same balance at the same time.
                ->lockForUpdate()
                // Get the row or fail if it does not exist.
                ->firstOrFail();

            // If the employee does not have enough remaining days, reject the request.
            if ($balance->remaining_days < $days) {
                // Return a validation-style error message for the frontend.
                throw ValidationException::withMessages([
                    'start_date' => 'Not enough leave balance.',
                ]);
            }

            // Create the pending leave request.
            return LeaveRequest::create([
                // Ownership comes from the authenticated employee.
                'employee_id' => $employee->id,
                // Store requested start date.
                'start_date' => $data['start_date'],
                // Store requested end date.
                'end_date' => $data['end_date'],
                // Store calculated number of leave days.
                'days' => $days,
                // New requests start as pending until approved or rejected.
                'status' => 'pending',
            ]);
        });
    }
}
```

Explanation:

- The service owns the business rule: calculate days and check balance.
- `lockForUpdate()` prevents two simultaneous leave requests from spending the same balance at the same time.
- The transaction keeps the read/check/create sequence consistent.
- The caller does not need to know how leave is calculated.

### 4.3 Monolithic architecture

A monolith is one application deployed as one unit.

```txt
One Laravel backend
  ├─ Auth
  ├─ HR
  ├─ Reports
  └─ Notifications

One database
```

Pros:

- Simple to build.
- Simple to deploy.
- Easy local development.
- No network calls between internal features.
- Good default for small teams and MVPs.

Cons:

- Large codebases can become hard to navigate.
- Scaling one feature often means scaling the whole app.
- Bad boundaries can turn into tangled code.

Use when:

- You are building an MVP.
- The team is small.
- The product is still changing quickly.
- You do not need independent service deployments.

### 4.4 Modular monolith

A modular monolith is still one deployed application, but code is grouped by business domain.

```txt
One backend deployment
  ├─ HR module
  ├─ CRM module
  ├─ Project Management module
  └─ Shared core

One database, with clear table ownership
```

This project uses a modular monolith for HR/CRM/PM separation.

Important framing:

- This is common for larger multi-domain platforms.
- It is not the first architecture every beginner should learn.
- Learn a normal monolith and layered architecture first.
- Then modular monolith makes sense as a scaling organization pattern.

Pros:

- Better boundaries than a flat monolith.
- Still simpler than microservices.
- Good for multiple teams working in one platform.

Cons:

- More folder structure and conventions.
- Boundaries require discipline.
- Can become confusing if used too early.

### 4.5 Microservices

Microservices split the product into multiple independently deployed services.

```txt
Frontend
  ↓
API Gateway
  ├─ HR Service → HR database
  ├─ CRM Service → CRM database
  └─ Payroll Service → Payroll database
```

Pros:

- Independent deployment.
- Independent scaling.
- Teams can own services separately.
- Different services can use different tech stacks.

Cons:

- Much harder debugging.
- Network failures become normal.
- Data consistency is harder.
- Local development is harder.
- Observability becomes required, not optional.

Use when:

- Teams are large enough to own separate services.
- A domain needs independent scaling or deployment.
- The organization can handle operational complexity.

Do not start with microservices just because they sound advanced.

### 4.6 Event-driven architecture

In event-driven architecture, components react to events.

```txt
EmployeeCreated event
  ├─ Send welcome email listener
  ├─ Create leave balance listener
  └─ Notify HR listener
```

Example:

```php
// Service creates employees and then announces that an employee was created.
class EmployeeService
{
    // Create a new employee from validated input data.
    public function create(array $data): Employee
    {
        // First do the important database writes inside a transaction.
        $employee = DB::transaction(function () use ($data) {
            // Create user and employee records.
            return $this->createEmployeeRecords($data);
        });

        // After the employee exists, dispatch an event for side effects.
        EmployeeCreated::dispatch($employee);

        // Return the created employee to the caller.
        return $employee;
    }
}
```

Listener:

```php
// Listener that reacts when an EmployeeCreated event is dispatched.
class SendWelcomeEmail
{
    // Laravel calls handle() and passes the event object into it.
    public function handle(EmployeeCreated $event): void
    {
        // Send the email to the user connected to the new employee.
        Mail::to($event->employee->user->email)
            // Queue the email so it does not slow down the request.
            ->queue(new WelcomeEmployeeMail($event->employee));
    }
}
```

Explanation:

- `EmployeeService` creates the employee.
- It dispatches an event after the transaction succeeds.
- The email listener reacts without the service directly knowing about email details.
- New listeners can be added later without rewriting the original service.

Trade-off:

- Events reduce coupling.
- But they make the flow less obvious because work happens indirectly.

Use events for side effects, not for hiding core business rules.

### 4.7 Synchronous vs asynchronous work

Synchronous means the user waits for the work to finish.

```txt
User clicks Submit
  → API validates
  → API writes database
  → API returns response
```

Asynchronous means the system accepts work now and finishes later.

```txt
User clicks Generate Report
  → API creates report job
  → API returns 202 Accepted
  → Queue worker generates report later
  → User gets notification
```

Use async for:

- Emails.
- Notifications.
- Report generation.
- Payroll processing.
- File processing.
- External API calls that are slow or unreliable.

Do not use async when the user needs an immediate confirmed answer.

### 4.8 Queue example

```php
// Controller receives the HTTP request to generate a report.
class ReportController extends Controller
{
    // Start report generation for the authenticated user.
    public function generate(Request $request): JsonResponse
    {
        // Create a database record so the user can track report status.
        $report = Report::create([
            // Report belongs to the logged-in user.
            'user_id' => $request->user()->id,
            // Initial status: queued, because the job has not run yet.
            'status' => 'queued',
        ]);

        // Put the expensive report work onto the queue.
        GenerateReportJob::dispatch($report->id);

        // Return immediately so the user does not wait for report generation.
        return response()->json([
            'data' => [
                // Give the frontend the report ID for status polling later.
                'id' => $report->id,
                // Tell the frontend that the report is waiting in the queue.
                'status' => 'queued',
            ],
        // 202 means accepted for processing, but not finished yet.
        ], 202);
    }
}
```

```php
// Queue job that performs the slow report-generation work.
class GenerateReportJob implements ShouldQueue
{
    // Queueable gives this job Laravel queue features.
    use Queueable;

    // Store only the report ID in the job payload.
    public function __construct(private int $reportId) {}

    // Laravel calls handle() when a queue worker processes this job.
    public function handle(): void
    {
        // Reload the report from the database using the ID.
        $report = Report::findOrFail($this->reportId);
        // Mark the report as actively being generated.
        $report->update(['status' => 'processing']);

        // Expensive work happens here.
        $path = app(ReportGenerator::class)->generate($report);

        // Save the generated file path and mark the report as completed.
        $report->update([
            'status' => 'completed',
            'file_path' => $path,
        ]);
    }
}
```

Explanation:

- The controller creates a report record and dispatches a job.
- It returns `202 Accepted`, meaning work has started but is not done.
- The job loads the report by ID, performs the expensive work, and updates the status.
- Passing only the ID into the job avoids serializing a large model snapshot.

### 4.9 API contract

The API contract is the agreement between frontend and backend.

A good contract defines:

- URL.
- HTTP method.
- Request body.
- Response shape.
- Error shape.
- Status codes.
- Authentication rules.
- Pagination format.

Example:

```txt
POST /api/v1/leave-requests
Auth: required
Body:
{
  "start_date": "2026-07-20",
  "end_date": "2026-07-22",
  "reason": "Family trip"
}

Success: 201 Created
{
  "data": {
    "id": 123,
    "start_date": "2026-07-20",
    "end_date": "2026-07-22",
    "days": 3,
    "status": "pending"
  }
}
```

Why this matters:

- Frontend engineers know what to send.
- Backend engineers know what to return.
- Tests can verify the shape.
- Changes become intentional instead of accidental.

---

## 5. Data Fundamentals

Most real systems are data systems. They store, retrieve, transform, protect, and present data.

### 5.1 Tables, rows, and relationships

Relational databases organize data into tables.

```txt
users
  id
  email
  password

employees
  id
  user_id
  department_id

leave_requests
  id
  employee_id
  start_date
  end_date
  status
```

Relationships:

- One user has one employee profile.
- One department has many employees.
- One employee has many leave requests.

### 5.2 Primary keys and foreign keys

Primary key:

- Unique ID for a row.
- Example: `employees.id`.

Foreign key:

- A column that points to another table.
- Example: `leave_requests.employee_id` points to `employees.id`.

Why foreign keys matter:

- They protect data integrity.
- They prevent leave requests from pointing to nonexistent employees.
- They make relationships explicit.

### 5.3 Normalization

Normalization means storing each fact in one place.

Example:

Bad:

```txt
leave_requests table:
employee_name
employee_email
department_name
start_date
end_date
```

Problem:

- If employee email changes, old rows become stale.
- Department name is repeated many times.

Better:

```txt
employees table:
id
user_id
department_id

leave_requests table:
employee_id
start_date
end_date
```

Benefits:

- Less duplicate data.
- Easier updates.
- More consistent data.

### 5.4 Denormalization

Denormalization means intentionally duplicating or precomputing data to make reads faster.

Example:

```txt
monthly_attendance_summaries
employee_id
month
late_count
absent_count
total_hours
```

This duplicates information that could be calculated from raw attendance logs.

Why do it?

- Reports become faster.
- Dashboards do not recalculate huge datasets every request.

Trade-off:

- Reads are faster.
- Writes or background jobs become more complex.
- Summaries must be kept in sync.

### 5.5 Database indexes

An index is a data structure maintained by the database to speed up lookup and sorting.

Common indexes:

```php
Schema::table('hr_leave_requests', function (Blueprint $table) {
    $table->index('employee_id');
    $table->index(['status', 'created_at']);
});
```

Use indexes on columns frequently used in:

- `WHERE`
- `JOIN`
- `ORDER BY`
- foreign keys

Avoid indexing every column. Indexes cost storage and slow down writes.

### 5.6 The N+1 query problem

Bad:

```php
$employees = Employee::query()->paginate(20);

foreach ($employees as $employee) {
    echo $employee->department->name;
}
```

What happens:

- 1 query loads employees.
- Then 20 more queries load each employee's department.
- Total: 21 queries.

Better:

```php
$employees = Employee::query()
    ->with('department')
    ->paginate(20);
```

Explanation:

- `with('department')` eager loads departments.
- The database work becomes predictable.
- This is one of the most common real-world performance fixes.

---

## 6. Data Structures

A data structure is a way to organize data so operations become easier or faster.

The key question is not "which data structure is best?"

The key question is:

```txt
What operation do I need to do often?
```

Examples:

- Need fast lookup by ID? Use a hash map.
- Need ordered traversal? Use an array/list.
- Need first-in-first-out processing? Use a queue.
- Need undo behavior? Use a stack.
- Need hierarchy? Use a tree.
- Need many-to-many relationships? Use a graph.

### 6.1 Big O notation

Big O describes how work grows as input grows.

| Complexity | Name | Meaning | Example |
|---|---|---|---|
| O(1) | Constant | Same amount of work regardless of size. | Hash map lookup by key. |
| O(log n) | Logarithmic | Cuts the problem down each step. | Binary search. |
| O(n) | Linear | Work grows with number of items. | Loop through all employees. |
| O(n log n) | Linearithmic | Common efficient sorting cost. | Merge sort, many built-in sorts. |
| O(n²) | Quadratic | Nested comparison of all pairs. | Compare every employee with every other employee. |
| O(2^n) | Exponential | Doubles with each added input. | Naive recursive combinations. |

Big O ignores constants and focuses on growth.

Example:

```txt
10 employees: scanning all is fine
10,000 employees: scanning all may be slow
10,000,000 employees: scanning all is a serious problem
```

### 6.2 Arrays / lists

An array/list stores items in order.

Use when:

- You need ordered data.
- You need to loop through every item.
- You need fast access by numeric index.

Example:

```php
// Store employee names in order: index 0, index 1, index 2.
$employeeNames = ['Ana', 'Ben', 'Carla'];

// Read the first item. Arrays start counting at 0, so index 0 is "Ana".
echo $employeeNames[0]; // Ana
```

Explanation:

- The array preserves order.
- Index `0` means the first item.
- Access by index is fast.

Real-world example: displaying a paginated list.

```php
// Start a query against the employees table.
$employees = Employee::query()
    // Ask the database to sort employees alphabetically by last name.
    ->orderBy('last_name')
    // Return 20 employees for the current page.
    ->paginate(20);
```

The result is list-like because the UI displays employees in order.

Performance:

| Operation | Typical cost |
|---|---|
| Access by index | O(1) |
| Loop through all items | O(n) |
| Search by value | O(n) |
| Append to end | O(1) average |

### 6.3 Hash maps / dictionaries

A hash map stores key-value pairs.

Use when:

- You need fast lookup by key.
- You need to count occurrences.
- You need to group items.
- You need simple in-memory caching.

Example: key employees by ID.

```php
// Create a map where each employee ID points directly to that employee's data.
$employeesById = [
    // Key 10 points to Ana's record.
    10 => ['id' => 10, 'name' => 'Ana'],
    // Key 25 points to Ben's record.
    25 => ['id' => 25, 'name' => 'Ben'],
];

// Look up employee 25 directly by key.
$employee = $employeesById[25];
```

Explanation:

- The key is `25`.
- The value is Ben's employee data.
- Lookup by key is O(1) on average.

Laravel collection example:

```php
// Query only the employees whose IDs are in this list.
$employeesById = Employee::query()
    // WHERE id IN (10, 25, 30)
    ->whereIn('id', [10, 25, 30])
    // Execute the query and return a Laravel Collection.
    ->get()
    // Re-key the collection so each item is stored by employee ID.
    ->keyBy('id');

// Retrieve employee 25 from the keyed collection without manually looping.
$employee = $employeesById->get(25);
```

Explanation:

- The database fetches only the IDs you need.
- `keyBy('id')` turns the collection into a map keyed by employee ID.
- `get(25)` avoids scanning the collection manually.

Counting example:

```php
// Start with an empty map: status => count.
$statusCounts = [];

// Look at each leave request one by one.
foreach ($leaveRequests as $request) {
    // Read the status from this request, such as pending or approved.
    $status = $request['status'];
    // If this status is new, start from 0; then add 1 for this request.
    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
}
```

Explanation:

- `$statusCounts` is a hash map.
- Keys are statuses like `pending`, `approved`, `rejected`.
- Values are counts.
- `?? 0` means "if this key does not exist yet, start from 0."

Performance:

| Operation | Typical cost |
|---|---|
| Lookup by key | O(1) average |
| Insert by key | O(1) average |
| Delete by key | O(1) average |
| Search by value | O(n) |

### 6.4 Stacks

A stack is last in, first out (LIFO).

Analogy: stack of plates. The last plate placed on top is the first plate removed.

Use when:

- Undo/redo.
- Browser history.
- Backtracking.
- Depth-first search.
- Function call stack.

Example: undo actions.

```php
// This class stores actions so the app can undo them later.
class UndoManager
{
    // Internal stack. The newest action is stored at the end of the array.
    private array $stack = [];

    // Record a new action that can be undone later.
    public function record(Action $action): void
    {
        // Push the action onto the top of the stack.
        $this->stack[] = $action;
    }

    // Undo should return the most recent action.
    public function undo(): ?Action
    {
        // Pop removes and returns the last item from the array.
        return array_pop($this->stack);
    }
}
```

Explanation:

- `$stack[] = $action` pushes a new action onto the top.
- `array_pop(...)` removes the most recent action.
- The most recent action is undone first.

Example flow:

```php
// Create a new undo manager.
$undo = new UndoManager();
// Record the rename action first.
$undo->record(new RenameEmployeeAction($employeeId, 'Old Name', 'New Name'));
// Record the department change second, so it is now the most recent action.
$undo->record(new ChangeDepartmentAction($employeeId, $oldDept, $newDept));

// Get the most recent action from the stack: the department change.
$lastAction = $undo->undo();
// Run the reverse behavior if an action was returned.
$lastAction?->reverse();
```

The department change is reversed first because it happened last.

### 6.5 Queues

A queue is first in, first out (FIFO).

Analogy: line at a store. First person in line is served first.

Use when:

- Background jobs.
- Breadth-first search.
- Processing tasks in arrival order.
- Rate-limited work.

Simple queue example:

```php
// Create an empty queue.
$queue = new SplQueue();

// Add the email job first.
$queue->enqueue('send-welcome-email');
// Add the report job second.
$queue->enqueue('generate-report');

// Remove the oldest job from the queue: send-welcome-email.
$nextJob = $queue->dequeue(); // send-welcome-email
```

Explanation:

- `enqueue(...)` adds to the back.
- `dequeue()` removes from the front.
- `SplQueue` is better than repeatedly using `array_shift(...)` because shifting arrays can be O(n).

Laravel queue example:

```php
// Add a notification job to Laravel's queue.
// Passing the ID keeps the queued job small and easy to reload later.
SendLeaveDecisionNotification::dispatch($leaveRequest->id);
```

Explanation:

- Laravel stores the job in a queue backend such as database or Redis.
- A queue worker later picks up the job.
- The user does not wait for the notification to be sent.

### 6.6 Trees

A tree stores hierarchical parent-child data.

Use when:

- Org charts.
- Categories.
- Comments and replies.
- File systems.
- Menus.

Example: employee manager hierarchy.

```php
// Employee model represents one employee row in the database.
class Employee extends Model
{
    // Get this employee's manager.
    public function manager(): BelongsTo
    {
        // manager_id points to another employee's id.
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    // Get the employees who report directly to this employee.
    public function directReports(): HasMany
    {
        // Find employees whose manager_id equals this employee's id.
        return $this->hasMany(Employee::class, 'manager_id');
    }
}
```

Explanation:

- `manager_id` points from an employee to their manager.
- `manager()` gets the parent.
- `directReports()` gets the children.

Tree shape:

```txt
CEO
├─ VP Engineering
│  ├─ Engineering Manager
│  │  ├─ Developer 1
│  │  └─ Developer 2
└─ VP Sales
   └─ Sales Manager
```

Common operations:

- Find someone's manager.
- Find all direct reports.
- Find all descendants.
- Find the chain of command.

### 6.7 Graphs

A graph stores nodes connected by edges.

Use when relationships are not strictly hierarchical.

Examples:

- Users following users.
- Employees with skills.
- Tasks depending on other tasks.
- Routes between locations.
- Recommendations.

Example: employee-skill graph.

```php
// Adjacency list: each node points to the nodes connected to it.
$graph = [
    // Ana is connected to these skill nodes.
    'Ana' => ['PHP', 'Laravel', 'SQL'],
    // Ben is connected to SQL and React.
    'Ben' => ['SQL', 'React'],
    // Carla is connected to Laravel and React.
    'Carla' => ['Laravel', 'React'],
    // Skill nodes point back to employees who have that skill.
    'PHP' => ['Ana'],
    'Laravel' => ['Ana', 'Carla'],
    'SQL' => ['Ana', 'Ben'],
    'React' => ['Ben', 'Carla'],
];
```

Explanation:

- Each key is a node.
- Each value is a list of connected nodes.
- This representation is called an adjacency list.

Finding similar employees:

```php
// Find employees who share skills with the given employee.
function findSimilarEmployees(array $graph, string $employee): array
{
    // Get this employee's skills. If the employee is missing, use an empty list.
    $skills = $graph[$employee] ?? [];
    // Scores will store: employee name => number of shared skills.
    $scores = [];

    // For every skill the employee has...
    foreach ($skills as $skill) {
        // Look at every other employee connected to that skill.
        foreach ($graph[$skill] ?? [] as $otherEmployee) {
            // Skip the original employee; we only want other people.
            if ($otherEmployee === $employee) {
                continue;
            }

            // Add 1 shared skill to this other employee's score.
            $scores[$otherEmployee] = ($scores[$otherEmployee] ?? 0) + 1;
        }
    }

    // Sort from highest shared-skill count to lowest.
    arsort($scores);

    // Return the ranked list of similar employees.
    return $scores;
}
```

Explanation:

- Start from an employee.
- Visit their skills.
- For each skill, visit other employees with that skill.
- Count how many skills each other employee shares.
- Sort the result so the strongest matches appear first.

### 6.8 Heaps / priority queues

A priority queue returns the most important item first, not necessarily the oldest item.

Use when:

- Jobs have priorities.
- You need top N results.
- Scheduling.
- Dijkstra's shortest path algorithm.

Example:

```php
// Create a priority queue where higher-priority items come out first.
$queue = new SplPriorityQueue();

// Add a low-priority job with priority 1.
$queue->insert('low-priority-report', 1);
// Add an urgent job with priority 10.
$queue->insert('urgent-payroll-fix', 10);
// Add a normal job with priority 5.
$queue->insert('normal-email', 5);

// Extract returns the highest-priority item: urgent-payroll-fix.
echo $queue->extract(); // urgent-payroll-fix
```

Explanation:

- Each item has a priority score.
- Higher priority is returned first.
- This is useful when order of importance matters more than arrival time.

### 6.9 Tries

A trie is a tree for prefixes.

Use when:

- Autocomplete.
- Prefix search.
- Dictionary lookup.

Example concept:

```txt
Words: car, cart, care

c
└─ a
   └─ r
      ├─ end(car)
      ├─ t end(cart)
      └─ e end(care)
```

In many real web apps, you will not implement a trie yourself. You may use database full-text search, Elasticsearch, Meilisearch, or a search package. But the concept explains why prefix search can be optimized with the right structure.

---

## 7. Algorithms

An algorithm is a step-by-step method for solving a problem.

In real applications, algorithms appear in ordinary features:

- Searching records.
- Sorting results.
- Grouping data.
- Matching users to permissions.
- Calculating balances.
- Finding shortest paths.
- Scheduling jobs.
- Validating dependencies.

### 7.1 Linear search

Linear search checks each item one by one.

```php
// Define a function that receives a list of employees and the email we want to find.
function findEmployeeByEmail(array $employees, string $targetEmail): ?array
{
    // Look at one employee at a time from the beginning of the array to the end.
    foreach ($employees as $employee) {
        // Compare this employee's email with the email we are searching for.
        if ($employee['email'] === $targetEmail) {
            // If they match, stop immediately and return this employee.
            return $employee;
        }
    }

    // If the loop finishes, no employee had that email.
    return null;
}
```

Explanation:

- The loop checks employees in order.
- If it finds the target email, it returns immediately.
- If it reaches the end, it returns `null`.
- Worst-case cost is O(n), because the match might be last or missing.

When this is fine:

- Small arrays.
- One-off operations.
- Data is already in memory.

When this is bad:

- Large datasets.
- Repeated lookup by the same field.
- Data should have been queried directly from the database.

Better for database-backed data:

```php
// Ask the database to start a query against the employees table.
$employee = Employee::query()
    // Search through the related user record instead of loading every employee into PHP.
    ->whereHas('user', fn ($query) => $query->where('email', $targetEmail))
    // Return the first matching employee, or null if there is no match.
    ->first();
```

The database should search database data. PHP should not load all rows just to search them.

### 7.2 Binary search

Binary search finds a value in sorted data by repeatedly cutting the search range in half.

```php
// Define a function that searches a sorted list of numbers for one target number.
function binarySearch(array $sortedNumbers, int $target): int
{
    // Left boundary starts at the first index of the array.
    $left = 0;
    // Right boundary starts at the last index of the array.
    $right = count($sortedNumbers) - 1;

    // Keep searching while there is still a valid range between left and right.
    while ($left <= $right) {
        // Pick the middle index. intdiv keeps it as a whole number.
        $middle = intdiv($left + $right, 2);

        // If the middle value is the target, we found it.
        if ($sortedNumbers[$middle] === $target) {
            // Return the index where the target was found.
            return $middle;
        }

        // If the middle value is too small, the target must be on the right side.
        if ($sortedNumbers[$middle] < $target) {
            // Move the left boundary after the middle to discard the left half.
            $left = $middle + 1;
        } else {
            // Otherwise the middle value is too large, so the target must be on the left side.
            $right = $middle - 1;
        }
    }

    // If the loop ends, the target was not found.
    return -1;
}
```

Explanation:

- `$left` and `$right` define the active search range.
- `$middle` checks the center.
- If the middle value is too small, discard the left half.
- If the middle value is too large, discard the right half.
- If no range remains, the target was not found.

Requirement:

- The input must already be sorted.

Complexity:

- O(log n).

Real-world connection:

- Database indexes use tree-like structures to avoid scanning every row.
- You usually rely on the database index instead of writing binary search yourself.

### 7.3 Sorting

Sorting puts items in order.

In real projects, you usually use built-in language functions or database sorting.

PHP example:

```php
// Sort the employees array in place using a custom comparison function.
usort($employees, function (array $a, array $b) {
    // Compare last names alphabetically: -1 means $a first, 1 means $b first, 0 means equal.
    return $a['last_name'] <=> $b['last_name'];
});
```

Explanation:

- `usort(...)` sorts the array in place.
- The comparison function receives two items.
- `<=>` returns -1, 0, or 1.
- This tells PHP which item should come first.

Database example:

```php
// Start a database query for employees.
$employees = Employee::query()
    // Ask the database to sort by last name first.
    ->orderBy('last_name')
    // If last names are the same, sort by first name next.
    ->orderBy('first_name')
    // Return only 20 rows for this page instead of every employee.
    ->paginate(20);
```

Why this is usually better:

- The database can sort before returning rows.
- Indexes may help with sorting.
- The application does not load unnecessary data.
- Pagination works naturally.

Important correction:

- Do not assume every `orderBy` is O(log n).
- Actual database cost depends on indexes, filters, row count, query plan, and whether filesort is needed.
- The practical lesson is: sort in the database when the data lives in the database.

### 7.4 Quick sort concept

Quick sort is a classic sorting algorithm.

```php
// Quick sort returns a new sorted copy of the input array.
function quickSort(array $items): array
{
    // Base case: an empty array or one-item array is already sorted.
    if (count($items) <= 1) {
        // Return it as-is so recursion can stop.
        return $items;
    }

    // Choose the first item as the pivot value.
    $pivot = $items[0];
    // Values smaller than the pivot go here.
    $smaller = [];
    // Values equal to or larger than the pivot go here.
    $largerOrEqual = [];

    // Start at index 1 because index 0 is already used as the pivot.
    for ($i = 1; $i < count($items); $i++) {
        // If this item is smaller than the pivot, put it in the smaller group.
        if ($items[$i] < $pivot) {
            $smaller[] = $items[$i];
        } else {
            // Otherwise, put it in the larger-or-equal group.
            $largerOrEqual[] = $items[$i];
        }
    }

    // Sort both groups, then combine: sorted smaller values + pivot + sorted larger values.
    return array_merge(
        // Recursive call: sort everything smaller than the pivot.
        quickSort($smaller),
        // Put the pivot between the two sorted groups.
        [$pivot],
        // Recursive call: sort everything greater than or equal to the pivot.
        quickSort($largerOrEqual),
    );
}
```

Explanation:

- If the array has 0 or 1 items, it is already sorted.
- Pick one item as the pivot.
- Put smaller items on the left.
- Put larger or equal items on the right.
- Sort both sides recursively.
- Merge left + pivot + right.

Complexity:

- Average: O(n log n).
- Worst case: O(n²), especially with poor pivot choices.

Real-world advice:

- Learn the concept.
- Use built-in sort functions in production.
- Let the database sort database rows.

### 7.5 Breadth-first search (BFS)

BFS explores level by level.

Use when:

- You need shortest path in an unweighted graph.
- You need level-order traversal.
- You need all employees at each org depth.

Example: group org chart by level.

```php
// This function groups employees by their distance from the root employee.
function orgLevels(Employee $root): array
{
    // This will become: level 0 => CEO, level 1 => VPs, level 2 => managers, etc.
    $levels = [];
    // A queue gives BFS behavior: first employee added is the first employee processed.
    $queue = new SplQueue();
    // Start with the root employee at level 0.
    $queue->enqueue([$root, 0]);

    // Keep going until there are no more employees waiting in the queue.
    while (!$queue->isEmpty()) {
        // Remove the next employee from the front of the queue.
        [$employee, $level] = $queue->dequeue();

        // Add this employee to the correct level group.
        $levels[$level][] = $employee;

        // Visit every direct report of this employee.
        foreach ($employee->directReports as $directReport) {
            // Add each direct report to the queue one level deeper than their manager.
            $queue->enqueue([$directReport, $level + 1]);
        }
    }

    // Return all employees grouped by level.
    return $levels;
}
```

Explanation:

- Start with the root employee at level 0.
- Dequeue one employee at a time.
- Add their direct reports at the next level.
- Because this uses a queue, all level 1 employees are processed before level 2.

Caution:

- If relationships are lazy-loaded, this may create many queries.
- For large org charts, consider recursive SQL queries, eager loading strategies, or dedicated hierarchy tables.

### 7.6 Depth-first search (DFS)

DFS goes deep down one path before backtracking.

Use when:

- You need to explore complete branches.
- You need cycle detection.
- You need dependency resolution.
- You need tree traversal.

Example: collect descendants with a stack.

```php
// This function collects everyone below the root employee.
function descendants(Employee $root): array
{
    // This will hold all descendants we find.
    $result = [];
    // A stack gives DFS behavior: last employee added is the first employee processed.
    $stack = [$root];

    // Keep going until there are no more employees waiting in the stack.
    while (!empty($stack)) {
        // Take the most recently added employee from the stack.
        $employee = array_pop($stack);

        // Look at each direct report of the current employee.
        foreach ($employee->directReports as $directReport) {
            // Add the direct report to the final answer.
            $result[] = $directReport;
            // Also add them to the stack so we later inspect their reports too.
            $stack[] = $directReport;
        }
    }

    // Return the flat list of all descendants.
    return $result;
}
```

Explanation:

- `$stack` stores employees still to visit.
- `array_pop(...)` takes the most recently added employee.
- That makes the algorithm follow one branch deeply before siblings.
- Every direct report is added to both the result and the stack.

### 7.7 Cycle detection

Graphs can have cycles.

Example:

```txt
A depends on B
B depends on C
C depends on A
```

This is a problem for workflows, package dependencies, approval chains, and task dependencies.

DFS cycle detection concept:

```php
// Returns true if this graph contains a cycle starting from $node.
function hasCycle(string $node, array $graph, array &$visiting, array &$visited): bool
{
    // If the node is already in the current path, we looped back to it: cycle found.
    if (isset($visiting[$node])) {
        return true;
    }

    // If this node was already fully checked before, it does not need to be checked again.
    if (isset($visited[$node])) {
        return false;
    }

    // Mark this node as part of the current DFS path.
    $visiting[$node] = true;

    // Visit every node that the current node points to.
    foreach ($graph[$node] ?? [] as $neighbor) {
        // If any neighbor leads to a cycle, the whole graph has a cycle.
        if (hasCycle($neighbor, $graph, $visiting, $visited)) {
            return true;
        }
    }

    // We are done exploring this path, so remove the node from the current path.
    unset($visiting[$node]);
    // Mark it as fully checked so future calls can skip it.
    $visited[$node] = true;

    // No cycle was found from this node.
    return false;
}
```

Explanation:

- `$visiting` tracks nodes in the current DFS path.
- `$visited` tracks nodes already fully checked.
- If you reach a node already in `$visiting`, you found a cycle.
- If a node is already `$visited`, it is safe to skip.

Real-world use:

- Prevent a manager hierarchy where employee A manages B and B manages A.
- Prevent tasks from depending on each other in a loop.

### 7.8 Greedy algorithms

A greedy algorithm makes the best local choice at each step.

It works only when local choices lead to a global optimum for that problem.

Example: schedule maximum number of non-overlapping meetings.

```php
// This function chooses as many non-overlapping meetings as possible.
function scheduleMostMeetings(array $meetings): array
{
    // Sort meetings by earliest end time first.
    usort($meetings, fn ($a, $b) => $a['end'] <=> $b['end']);

    // This will store the meetings we decide to keep.
    $scheduled = [];
    // null means we have not scheduled any meeting yet.
    $lastEnd = null;

    // Check meetings in earliest-ending order.
    foreach ($meetings as $meeting) {
        // A meeting fits if there is no previous meeting or it starts after the last one ended.
        if ($lastEnd === null || $meeting['start'] >= $lastEnd) {
            // Keep this meeting because it does not overlap.
            $scheduled[] = $meeting;
            // Update the end time so the next meeting is compared against this one.
            $lastEnd = $meeting['end'];
        }
    }

    // Return the final schedule.
    return $scheduled;
}
```

Explanation:

- Sort meetings by earliest end time.
- Pick the first meeting that fits.
- Always choosing the earliest-ending meeting leaves the most room for future meetings.

Input:

```php
// Example input: four meetings with start and end times.
$meetings = [
    // Long meeting from 9 to 11.
    ['start' => 9, 'end' => 11],
    // Shorter meeting from 9 to 10.
    ['start' => 9, 'end' => 10],
    // Meeting that can start right after the 9-to-10 meeting.
    ['start' => 10, 'end' => 11],
    // Meeting that can start right after the 10-to-11 meeting.
    ['start' => 11, 'end' => 12],
];
```

Result:

```txt
[9,10], [10,11], [11,12]
```

### 7.9 Dynamic programming and memoization

Dynamic programming solves problems by breaking them into overlapping subproblems and reusing previous results.

Memoization means caching function results.

Simple example: Fibonacci.

```php
// This function returns the nth Fibonacci number.
function fibonacci(int $n, array &$memo = []): int
{
    // Base case: fibonacci(0) is 0 and fibonacci(1) is 1.
    if ($n <= 1) {
        // Return immediately because no smaller problem is needed.
        return $n;
    }

    // If we already calculated fibonacci($n), reuse the saved answer.
    if (isset($memo[$n])) {
        return $memo[$n];
    }

    // Calculate the answer from the two previous Fibonacci numbers and save it.
    $memo[$n] = fibonacci($n - 1, $memo) + fibonacci($n - 2, $memo);

    // Return the saved answer.
    return $memo[$n];
}
```

Explanation:

- Fibonacci depends on smaller Fibonacci values.
- Without memoization, the same values are recalculated many times.
- `$memo` stores results already computed.
- Repeated subproblems become fast lookups.

Real-world example: caching working-day calculations.

```php
// A small class that counts working days and remembers repeated calculations.
class WorkingDayCalculator
{
    // Memo cache: key = date range, value = number of working days.
    private array $memo = [];

    // Count weekdays between two dates, excluding holidays.
    public function count(Carbon $start, Carbon $end, array $holidays): int
    {
        // Build a unique key for this exact date range.
        $key = $start->toDateString() . ':' . $end->toDateString();

        // If this range was already calculated, return the cached answer.
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        // Turn the holiday list into a map so holiday checks are fast.
        $holidayMap = array_flip($holidays);
        // Start the working-day count at zero.
        $days = 0;
        // Copy the start date so we do not accidentally modify the original object.
        $current = $start->copy();

        // Move one day at a time until we pass the end date.
        while ($current->lte($end)) {
            // Convert the current date to a simple string like "2026-07-06".
            $date = $current->toDateString();

            // Count the day only if it is not a weekend and not in the holiday map.
            if (!$current->isWeekend() && !isset($holidayMap[$date])) {
                $days++;
            }

            // Move forward by one day.
            $current->addDay();
        }

        // Save the result so the same date range is instant next time.
        $this->memo[$key] = $days;

        // Return the calculated number of working days.
        return $days;
    }
}
```

Explanation:

- The date range becomes the cache key.
- `array_flip($holidays)` creates a hash map for O(1) holiday checks.
- The loop counts only weekdays that are not holidays.
- The result is stored so repeated calls for the same range are instant.

Important caution:

- This is memoization. It is related to dynamic programming, but not every cache is dynamic programming.
- For large date/reporting problems, a precomputed calendar table may be better than recalculating dates in PHP.

### 7.10 Backtracking

Backtracking tries possibilities and undoes choices when they fail.

Use when:

- Generating combinations.
- Solving constraint problems.
- Exploring possible assignments.

Example: generate role combinations.

```php
// Generate every possible combination of $size items from the input list.
function combinations(array $items, int $size): array
{
    // This will store all completed combinations.
    $result = [];

    // Define a helper function that chooses items step by step.
    $backtrack = function (int $start, array $chosen) use (&$backtrack, &$result, $items, $size) {
        // If we already chose enough items, this combination is complete.
        if (count($chosen) === $size) {
            // Save the completed combination.
            $result[] = $chosen;
            // Stop this branch of recursion.
            return;
        }

        // Try each remaining item as the next choice.
        for ($i = $start; $i < count($items); $i++) {
            // Choose the current item.
            $chosen[] = $items[$i];
            // Recurse, only allowing later items so we do not repeat combinations.
            $backtrack($i + 1, $chosen);
            // Undo the choice so the next loop iteration can try a different item.
            array_pop($chosen);
        }
    };

    // Start with no chosen items and begin from index 0.
    $backtrack(0, []);

    // Return all combinations found.
    return $result;
}
```

Explanation:

- Choose an item.
- Recurse with the choice included.
- Undo the choice with `array_pop(...)`.
- Try the next item.

Backtracking can become expensive quickly. Use it only for small inputs or with pruning.

---

## 8. Applying DSA to Real Projects

DSA is not just for interviews. It appears in daily engineering decisions.

### 8.1 API list endpoint

Problem:

```txt
Show employees with search, department filter, status filter, sorting, and pagination.
```

Concepts used:

- Database indexes.
- Sorting.
- Pagination.
- Filtering.
- Query complexity.

Example:

```php
// Start a query against the employees table.
$employees = Employee::query()
    // Load each employee's department in advance to avoid N+1 queries.
    ->with('department')
    // Add search filters only when $search has a value.
    ->when($search, function ($query) use ($search) {
        // Match employees whose last name contains the search text.
        $query->where('last_name', 'like', "%{$search}%")
            // Also match employees whose first name contains the search text.
            ->orWhere('first_name', 'like', "%{$search}%");
    })
    // Add department filtering only when $departmentId has a value.
    ->when($departmentId, fn ($query) => $query->where('department_id', $departmentId))
    // Add status filtering only when $status has a value.
    ->when($status, fn ($query) => $query->where('employment_status', $status))
    // Sort results by last name in the database.
    ->orderBy('last_name')
    // Return one page of 20 employees instead of every matching row.
    ->paginate(20);
```

Explanation:

- `when(...)` conditionally adds filters.
- `with('department')` prevents N+1 queries.
- `orderBy(...)` sorts at the database level.
- `paginate(20)` limits result size.

Real-world lesson:

- Avoid loading everything and filtering in PHP.
- Let the database do set-based work.

### 8.2 Permission checks

Problem:

```txt
Check if a user has permission hr.leave.approve.
```

Naive approach:

```php
// Check every permission one by one.
foreach ($userPermissions as $permission) {
    // If the current permission is the one we need, allow the action.
    if ($permission === 'hr.leave.approve') {
        return true;
    }
}
```

This is O(n).

Faster approach using a hash map:

```php
// Convert the permission list into a map: permission name => true.
$permissionMap = array_fill_keys($userPermissions, true);

// Check the exact permission key directly instead of looping through the list.
return isset($permissionMap['hr.leave.approve']);
```

Explanation:

- `array_fill_keys(...)` turns a list into a map.
- Checking `isset(...)` is O(1) average.
- This matters if permission checks happen many times per request.

### 8.3 Rate limiting

Problem:

```txt
Allow at most 60 requests per user per minute.
```

Concepts:

- Hash map key.
- Counter.
- Expiration time.

Example concept:

```php
// Build a unique cache key for this user's rate limit counter.
$key = 'rate-limit:user:' . $user->id;
// Increase the counter by 1 for this request.
$count = Cache::increment($key);

// If this is the first request in the current window, set the expiration time.
if ($count === 1) {
    // Keep this counter for one minute, then let it disappear automatically.
    Cache::put($key, 1, now()->addMinute());
}

// If the user made more than 60 requests in this minute, block the request.
if ($count > 60) {
    // Return HTTP 429, which means "Too Many Requests".
    abort(429, 'Too many requests.');
}
```

Explanation:

- Each user gets a cache key.
- Every request increments the counter.
- The counter expires after one minute.
- If the count exceeds 60, reject the request.

Production note:

- Use Laravel's built-in rate limiting for real apps when possible.
- The example teaches the underlying idea.

### 8.4 Background jobs

Problem:

```txt
Process payroll for 500 employees without making the HR officer wait.
```

Concepts:

- Queue.
- Batch processing.
- Idempotency.
- Status tracking.

Design:

```txt
POST /payroll-runs
  → create payroll_run row with status queued
  → dispatch ProcessPayrollRun job
  → return 202 Accepted

Queue worker
  → calculate each payslip
  → update run status completed or failed
```

Key lesson:

- The API request should accept the work.
- The queue worker should perform the heavy work.
- The database should track progress.

### 8.5 Org chart

Problem:

```txt
Show every employee under a manager.
```

Concepts:

- Tree.
- BFS/DFS.
- Recursive relationships.

Small org chart:

- DFS in application code can be fine.

Large org chart:

- Consider recursive SQL, closure tables, materialized paths, or cached hierarchy data.

Real-world lesson:

- The same data structure can have different implementation strategies depending on scale.

### 8.6 Search and autocomplete

Problem:

```txt
User types "mar" and wants matching employees.
```

Basic approach:

```php
// Start an employee query for autocomplete suggestions.
Employee::query()
    // Match first names that start with "mar".
    ->where('first_name', 'like', 'mar%')
    // Also match last names that start with "mar".
    ->orWhere('last_name', 'like', 'mar%')
    // Return only a small number of suggestions.
    ->limit(10)
    // Execute the query.
    ->get();
```

Concepts:

- Prefix search.
- Indexing.
- Tries/search engines.

Scale path:

```txt
Small app: database LIKE query
Medium app: database full-text index
Large app: dedicated search engine
```

Do not start with a search engine unless the simpler approach is not enough.

---

## 9. Design Walkthroughs

### 9.1 Design a leave request system

#### Requirements

Functional:

- Employee submits leave request.
- System calculates working days.
- System checks leave balance.
- Manager or HR approves/rejects.
- Employee receives notification.

Non-functional:

- Must prevent overspending leave balance.
- Must be auditable.
- Normal request should be fast.
- Notifications should not block the response.

#### Architecture

```txt
Frontend form
  ↓
POST /api/v1/hr/leave-requests
  ↓
Controller
  ↓
Form Request validation
  ↓
LeaveRequestService
  ↓
Database transaction
  ↓
LeaveRequestResource
  ↓
Event / queued notification
```

#### Data model

```txt
hr_leave_requests
  id
  employee_id
  start_date
  end_date
  days
  status
  reason
  approved_by
  approved_at

hr_leave_balances
  id
  employee_id
  year
  remaining_days
```

#### Important algorithms/data structures

- Date loop or calendar table for working-day calculation.
- Hash map for holiday lookup.
- Transaction and row lock for balance consistency.
- Queue for notification.

#### Main trade-offs

| Decision | Simple option | Scalable option |
|---|---|---|
| Working-day calculation | Loop dates in PHP | Calendar table with precomputed working days |
| Notification | Send immediately | Queue job |
| Balance update | Simple update | Transaction with row lock |
| Reports | Calculate live | Precompute summaries |

### 9.2 Design a URL shortener

#### Requirements

- User submits long URL.
- System returns short code.
- Visiting short URL redirects to long URL.
- Track click count.

#### Core flow

```txt
POST /short-links
  → validate URL
  → generate unique code
  → store code + long URL
  → return short URL

GET /{code}
  → find long URL by code
  → increment click count
  → redirect
```

#### Data model

```txt
short_links
  id
  code unique
  long_url
  click_count
  created_at
```

#### Data structures and algorithms

- Hash map concept: code → long URL lookup.
- Database unique index on `code`.
- Random or base62 code generation.
- Cache hot links if traffic grows.

#### Example code generation

```php
// Generate a short random code, defaulting to 7 characters.
function generateCode(int $length = 7): string
{
    // Characters allowed in the short code.
    $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    // Start with an empty code.
    $code = '';

    // Repeat until the code reaches the requested length.
    for ($i = 0; $i < $length; $i++) {
        // Pick one random position inside the alphabet string.
        $index = random_int(0, strlen($alphabet) - 1);
        // Add the randomly selected character to the code.
        $code .= $alphabet[$index];
    }

    // Return the finished short code.
    return $code;
}
```

Explanation:

- `$alphabet` contains allowed characters.
- `random_int(...)` picks a secure random index.
- The loop builds a code one character at a time.
- A unique database constraint should still protect against collisions.

### 9.3 Design a notification system

#### Requirements

- Send notifications for leave approval, payroll release, and announcements.
- Users can see unread notifications.
- Email/push can happen later.

#### Architecture

```txt
Business action
  ↓
NotificationCreated event
  ↓
Store database notification
  ↓
Queue email/push delivery
```

#### Data model

```txt
notifications
  id
  user_id
  type
  title
  body
  read_at
  created_at
```

#### Concepts used

- Queue for delivery.
- Index on `(user_id, read_at)` for unread lookup.
- Pagination for notification list.
- Idempotency key if external provider retries webhooks.

#### Query example

```php
// Start a query against the notifications table.
$notifications = Notification::query()
    // Only fetch notifications owned by the authenticated user.
    ->where('user_id', $request->user()->id)
    // Only fetch unread notifications. read_at is null until the user reads it.
    ->whereNull('read_at')
    // Sort newest notifications first.
    ->latest()
    // Return 20 notifications for the current page.
    ->paginate(20);
```

Explanation:

- Only fetch notifications for the authenticated user.
- `whereNull('read_at')` gets unread notifications.
- `latest()` orders newest first.
- `paginate(20)` prevents returning too much data.

---

## 10. Learning Roadmap

### Phase 1: Core vocabulary

Learn:

- HTTP methods and status codes.
- JSON request/response shapes.
- Relational tables and foreign keys.
- Big O notation.
- Arrays, maps, stacks, queues, trees, graphs.

Build:

- A small CRUD API.
- A paginated list endpoint.
- A search endpoint.

You understand this phase when:

- You can explain what happens from button click to database row.
- You can explain why pagination matters.
- You can explain why loading all records is dangerous.

### Phase 2: Backend architecture

Learn:

- Controllers.
- Form Requests.
- Resources.
- Services.
- Models.
- Migrations.
- Transactions.
- Authorization.

Build:

- Task manager.
- Leave request flow.
- Notification list.

You understand this phase when:

- Your controller is thin.
- Validation is not mixed with business logic.
- You know where a bug probably lives based on the symptom.

### Phase 3: Performance basics

Learn:

- Indexes.
- N+1 queries.
- Caching.
- Pagination.
- Queue jobs.

Build:

- Employee directory with filters.
- Background report generation.
- Cached department dropdown.

You understand this phase when:

- You can explain why a query is slow.
- You can decide whether to cache, index, paginate, or queue.
- You can explain the trade-off of each choice.

### Phase 4: System design practice

Practice designing:

- URL shortener.
- Rate limiter.
- Notification system.
- File upload system.
- Payroll processing system.

For each design, write:

1. Functional requirements.
2. Non-functional requirements.
3. Core entities/tables.
4. API endpoints.
5. Data flow.
6. Failure cases.
7. Scaling bottlenecks.
8. Simplest version first.
9. What you would improve later.

### Phase 5: Algorithms in context

Learn and implement:

- Linear search.
- Binary search.
- Sorting.
- BFS.
- DFS.
- Greedy scheduling.
- Memoization.
- Cycle detection.

Then connect each to projects:

| Algorithm | Project connection |
|---|---|
| Linear search | Small in-memory filters. |
| Binary search | Understanding indexes/search over sorted data. |
| Sorting | Ordered lists, reports, ranking. |
| BFS | Org chart levels, shortest path. |
| DFS | Dependency traversal, descendants. |
| Greedy | Scheduling, resource selection. |
| Memoization | Repeated calculations, expensive function caching. |
| Cycle detection | Task dependencies, manager hierarchy validation. |

---

## 11. Self-Check Questions

### 11.1 System design

You should be able to answer:

1. What is the difference between latency and throughput?
2. When would you use a queue instead of doing work immediately?
3. What problem does caching solve, and what problem does it create?
4. Why should a multi-step database operation use a transaction?
5. What is idempotency, and why does it matter for retries?
6. What is the difference between vertical and horizontal scaling?
7. Why should app servers be stateless when horizontally scaled?

### 11.2 Architecture

You should be able to answer:

1. What belongs in a controller?
2. What belongs in a service?
3. What belongs in a model?
4. What belongs in a resource?
5. Why is a modular monolith different from microservices?
6. Why is a normal monolith often the best starting point?
7. What is an API contract?
8. Why should the backend not trust `employee_id` sent by the browser for "my data" endpoints?

### 11.3 Data structures

You should be able to answer:

1. When would you use a hash map instead of a list?
2. What data structure powers background job processing?
3. Why is an org chart a tree?
4. Why are employee-skill relationships better modeled as a graph?
5. What is the difference between a stack and a queue?
6. Why can `array_shift(...)` be worse than a real queue for repeated dequeue operations?

### 11.4 Algorithms

You should be able to answer:

1. Why does binary search require sorted data?
2. Why should database rows usually be sorted in SQL instead of PHP?
3. How does BFS differ from DFS?
4. What problem does cycle detection solve?
5. When does a greedy algorithm work?
6. What is memoization?
7. Why is Big O useful even if exact runtime depends on hardware and database internals?

---

## 12. Quick Reference

### 12.1 Problem → common solution

| Problem | Common solution |
|---|---|
| API returns too much data | Pagination |
| Repeated expensive read | Cache |
| Slow filtered lookup | Database index |
| Too many repeated relationship queries | Eager loading |
| Multi-step write must not partially succeed | Transaction |
| Slow side effect blocks user response | Queue job |
| External API is unreliable | Timeout, retry, fallback |
| Need fast lookup by key | Hash map |
| Need ordered list | Array/list |
| Need undo behavior | Stack |
| Need first-in-first-out processing | Queue |
| Need hierarchy | Tree |
| Need many-to-many relationships | Graph |
| Need shortest path by levels | BFS |
| Need deep dependency traversal | DFS |
| Need repeated calculation optimization | Memoization / precomputation |

### 12.2 Architecture pattern reference

| Pattern | Good for | Be careful of |
|---|---|---|
| Simple monolith | MVPs, small teams, normal web apps | Can become tangled without boundaries. |
| Layered architecture | Most CRUD/business apps | Too many layers for tiny features can be overkill. |
| Modular monolith | Larger multi-domain apps | More conventions; not beginner default. |
| Microservices | Large teams, independent scaling/deployments | Operational and data consistency complexity. |
| Event-driven | Side effects, decoupled reactions | Hidden flows can make debugging harder. |
| Queue-based async | Slow or unreliable work | Need job monitoring and retry strategy. |

### 12.3 Performance checklist

Before optimizing, ask:

1. Is the endpoint paginated?
2. Are filters and sorting done in the database?
3. Are important foreign keys indexed?
4. Is there an N+1 query problem?
5. Is expensive repeated data cached?
6. Should slow work move to a queue?
7. Is the optimization worth the complexity?

### 12.4 Design checklist

When designing a feature, write:

```txt
Feature name:

Functional requirements:
- ...

Non-functional requirements:
- ...

Core data:
- tables/entities
- relationships

API contract:
- endpoints
- request body
- response body
- errors

Flow:
- step 1
- step 2
- step 3

Failure cases:
- what can go wrong?
- how does the system recover?

Performance/scaling:
- likely bottleneck
- simplest solution now
- upgrade path later
```

### 12.5 Final answer: is this document good enough now?

Yes, this version is a strong fundamentals guide for learning the basic concepts of system design, system architecture, data structures, and algorithms as they apply to real projects.

It is detailed enough to teach:

- The vocabulary and mental models.
- The most common trade-offs.
- How backend features are structured.
- How data structures connect to practical application code.
- How algorithms appear in ordinary product features.
- How to think through realistic design problems.

To learn deeply, do not only read it. Build the examples, change them, break them, debug them, and explain each trade-off in your own words.

Remember: good engineering is not choosing the most advanced pattern. Good engineering is choosing the simplest design that satisfies the requirements and can evolve when the requirements become real.
