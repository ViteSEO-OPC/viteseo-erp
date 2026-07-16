# Headless Architecture Guide: Learning Next.js + Laravel

> Goal: teach you how to build a real headless application where Laravel owns the backend/API and Next.js owns the frontend/UI. This guide is intentionally general and uses the standard/common Laravel + Next.js structure.

## Table of Contents

**Start Here**
- [How to Use This Guide](#how-to-use-this-guide)
- [Build the Task Manager: Step-by-Step](#build-the-task-manager-step-by-step)

**Part A — Understanding the Architecture**
- [0. Senior Engineer Review of the Previous Version](#0-senior-engineer-review-of-the-previous-version)
- [1. The Core Mental Model](#1-the-core-mental-model)
- [2. What You Must Learn Separately Before Combining Them](#2-what-you-must-learn-separately-before-combining-them)
- [3. Standard Laravel Structure](#3-standard-laravel-structure)
- [4. Recommended General Architecture](#4-recommended-general-architecture)
- [5. API Contract Standards](#5-api-contract-standards)

**Part B — Full Code Examples**
- [6. Laravel Backend Example: Standard Task Manager](#6-laravel-backend-example-standard-task-manager)
- [7. Next.js Frontend Example](#7-nextjs-frontend-example)
- [8. Server Components vs Client Components in a Laravel Headless App](#8-server-components-vs-client-components-in-a-laravel-headless-app)
- [9. Authentication Choices](#9-authentication-choices)
- [10. CORS](#10-cors)
- [11. Full Request Lifecycle](#11-full-request-lifecycle)
- [12. Debugging Decision Tree](#12-debugging-decision-tree)

**Part C — The "How" — Building From Scratch**
- [13. How to Set Up Your Environment From Scratch](#13-how-to-set-up-your-environment-from-scratch)
- [14. The Artisan CLI — How to Create Files](#14-the-artisan-cli--how-to-create-files)
- [15. How to Build Authentication (Full Implementation)](#15-how-to-build-authentication-full-implementation)
- [16. How to Build the Missing Frontend Components](#16-how-to-build-the-missing-frontend-components)
- [17. Factories, Seeders, and Tinker — How to Create Test Data](#17-factories-seeders-and-tinker--how-to-create-test-data)
- [18. Eloquent Beyond Basics — How to Query Effectively](#18-eloquent-beyond-basics--how-to-query-effectively)
- [19. Tailwind CSS — How to Style Without Writing CSS](#19-tailwind-css--how-to-style-without-writing-css)
- [20. React Hook Form + Zod — How to Build Forms Properly](#20-react-hook-form--zod--how-to-build-forms-properly)
- [21. App Router Deep Dive — How Routes, Layouts, and Navigation Work](#21-app-router-deep-dive--how-routes-layouts-and-navigation-work)
- [22. How to Read Errors and Debug Like a Senior Engineer](#22-how-to-read-errors-and-debug-like-a-senior-engineer)

**Part D — Learning Path & Self-Assessment**
- [23. Learning Path](#23-learning-path)
- [24. What "Good" Looks Like](#24-what-good-looks-like)
- [25. Common Beginner Mistakes](#25-common-beginner-mistakes)
- [26. Final Answer: Is This Enough To Learn From?](#26-final-answer-is-this-enough-to-learn-from)

---

## How to Use This Guide

This guide has **two reading modes**. Choose based on your goal:

### Mode 1: "I want to understand the architecture first"
Read Part A → Part B → Part C → Part D in order. This gives you the mental model before the implementation.

### Mode 2: "I want to build the Task Manager project NOW"
Follow the **Build Order** below. This is the sequential path to build a complete headless app from scratch.

---

## Build the Task Manager: Step-by-Step

This is the exact order to build the complete Task Manager project. Each step points to the section you need to read.

### Phase 1: Foundation (Read First)

| Step | Section | What You'll Learn |
|------|---------|-------------------|
| 1 | [Section 1: The Core Mental Model](#1-the-core-mental-model) | What "headless" means, how Laravel and Next.js work together |
| 2 | [Section 2: What You Must Learn Separately](#2-what-you-must-learn-separately-before-combining-them) | The prerequisite knowledge for each technology |
| 3 | [Section 3: Standard Laravel Structure](#3-standard-laravel-structure) | Where files go in a Laravel project |
| 4 | [Section 4: Recommended General Architecture](#4-recommended-general-architecture) | What Laravel owns vs what Next.js owns |
| 5 | [Section 5: API Contract Standards](#5-api-contract-standards) | How the frontend and backend communicate |

**Checkpoint**: You can explain what headless means and why Laravel owns the backend. → Continue to Phase 2.

---

### Phase 2: Environment Setup

| Step | Section | What You'll Do |
|------|---------|----------------|
| 6 | [Section 13: How to Set Up Your Environment](#13-how-to-set-up-your-environment-from-scratch) | Install PHP, Node, Composer, MySQL. Create fresh Laravel + Next.js projects. Get both running. |
| 7 | [Section 14: The Artisan CLI](#14-the-artisan-cli--how-to-create-files) | Learn `make:model`, `make:controller`, `route:list`, `tinker` — the commands you'll use daily |

**Checkpoint**: Both servers are running (`php artisan serve` on :8000, `npm run dev` on :3000). You can run `php artisan route:list` and `php artisan tinker`. → Continue to Phase 3.

---

### Phase 3: Build Authentication (Backend First)

| Step | Section | What You'll Build |
|------|---------|-------------------|
| 8 | [Section 15: How to Build Authentication](#15-how-to-build-authentication-full-implementation) | Install Sanctum, create `AuthController` with login/register/logout, test with `curl` |

**Checkpoint**: You can register a user, login, and access `/api/v1/auth/me` with `curl`. → Continue to Phase 4.

---

### Phase 4: Build Task Manager (Backend)

| Step | Section | What You'll Build |
|------|---------|-------------------|
| 9 | [Section 6.1: Migration](#61-migration) | Create the `tasks` table |
| 10 | [Section 6.2: Model](#62-model) | Create the `Task` model with relationships |
| 11 | [Section 6.3: Form Request](#63-form-request) | Create `StoreTaskRequest` for validation |
| 12 | [Section 6.4: Resource](#64-resource) | Create `TaskResource` for API response formatting |
| 13 | [Section 6.5: Service](#65-service) | Create `TaskService` with business logic |
| 14 | [Section 6.6: Controller](#66-controller-with-ownership-protection) | Create `TaskController` (thin, delegates to service) |
| 15 | [Section 6.7: Routes](#67-routes) | Register all task routes in `routes/api.php` |

**Checkpoint**: You can create, list, complete, and delete tasks with `curl`. All tasks are scoped to the authenticated user. → Continue to Phase 5.

---

### Phase 5: Build Authentication (Frontend)

| Step | Section | What You'll Build |
|------|---------|-------------------|
| 16 | [Section 16.3: Auth API Functions](#163-full-auth-flow-frontend) | Create `src/core/api/auth.ts` with login/register/getMe |
| 17 | [Section 16.3: Auth Context](#163-full-auth-flow-frontend) | Create `src/core/auth-context.tsx` with AuthProvider |
| 18 | [Section 16.3: Login Page](#163-full-auth-flow-frontend) | Create `src/app/login/page.tsx` |
| 19 | [Section 16.3: Protected Layout](#163-full-auth-flow-frontend) | Create `src/app/(dashboard)/layout.tsx` |

**Checkpoint**: You can login, the token is stored in localStorage, and unauthenticated users are redirected to `/login`. → Continue to Phase 6.

---

### Phase 6: Build Task Manager (Frontend)

| Step | Section | What You'll Build |
|------|---------|-------------------|
| 20 | [Section 7.2: TypeScript Types](#72-typescript-types) | Create `src/features/tasks/types/task.ts` |
| 21 | [Section 7.3: Axios API Client](#73-axios-api-client) | Create `src/core/api-client.ts` with interceptors |
| 22 | [Section 7.4: API Functions](#74-api-functions) | Create `src/features/tasks/api/tasks.ts` |
| 23 | [Section 7.5: TanStack Query Hooks](#75-tanstack-query-hooks) | Create `src/features/tasks/hooks/use-tasks.ts` |
| 24 | [Section 7.6: Query Provider](#76-query-provider) | Create `src/core/query-provider.tsx` and wire into layout |
| 25 | [Section 16.1: TaskItem Component](#161-taskitem-component) | Create `src/features/tasks/components/task-item.tsx` |
| 26 | [Section 16.2: TaskForm Component](#162-taskform-component) | Create `src/features/tasks/components/task-form.tsx` |
| 27 | [Section 7.7: Client Page](#77-client-page) | Create `src/app/tasks/page.tsx` |

**Checkpoint**: You can create, list, complete, and delete tasks in the browser. Data persists after refresh. → Continue to Phase 7.

---

### Phase 7: Polish & Debug

| Step | Section | What You'll Learn |
|------|---------|-------------------|
| 28 | [Section 19: Tailwind CSS](#19-tailwind-css--how-to-style-without-writing-css) | Style your components properly |
| 29 | [Section 20: React Hook Form + Zod](#20-react-hook-form--zod--how-to-build-forms-properly) | Build forms with validation and error handling |
| 30 | [Section 21: App Router Deep Dive](#21-app-router-deep-dive--how-routes-layouts-and-navigation-work) | Add dynamic routes, loading states, navigation |
| 31 | [Section 22: How to Read Errors](#22-how-to-read-errors-and-debug-like-a-senior-engineer) | Debug when something breaks |

**Checkpoint**: Your app looks good, forms validate properly, and you can debug errors without panicking. → Continue to Phase 8.

---

### Phase 8: Test Data & Advanced Patterns

| Step | Section | What You'll Learn |
|------|---------|-------------------|
| 32 | [Section 17: Factories, Seeders, Tinker](#17-factories-seeders-and-tinker--how-to-create-test-data) | Generate fake data for development |
| 33 | [Section 18: Eloquent Beyond Basics](#18-eloquent-beyond-basics--how-to-query-effectively) | Scopes, accessors, N+1 problem, eager loading |

**Checkpoint**: You can seed your database with fake tasks and write efficient queries. → You're done!

---

### After You Finish

Read these sections to deepen your understanding:

| Section | What You'll Learn |
|---------|-------------------|
| [Section 8: Server vs Client Components](#8-server-components-vs-client-components-in-a-laravel-headless-app) | When to use `"use client"` vs server components |
| [Section 9: Authentication Choices](#9-authentication-choices) | Bearer tokens vs cookies, security tradeoffs |
| [Section 10: CORS](#10-cors) | Why cross-origin requests fail and how to fix them |
| [Section 11: Full Request Lifecycle](#11-full-request-lifecycle) | The complete flow from button click to database |
| [Section 12: Debugging Decision Tree](#12-debugging-decision-tree) | Structured way to find and fix bugs |
| [Section 23: Learning Path](#23-learning-path) | Progressive exercises to build competence |
| [Section 24: What "Good" Looks Like](#24-what-good-looks-like) | Architecture checklist |
| [Section 25: Common Beginner Mistakes](#25-common-beginner-mistakes) | Mistakes to avoid |

---

## 0. Senior Engineer Review of the Previous Version

Short answer: the previous guide was useful, but not enough on its own.

It was good at:
- Explaining the big idea of headless architecture.
- Showing the request lifecycle from UI to API to database and back.
- Introducing Laravel controllers, services, resources, requests, models, migrations, and Eloquent relationships.
- Introducing Next.js App Router, components, hooks, TanStack Query, Axios, Tailwind, and shadcn/ui.
- Giving you a concrete Task Manager example.

But it had important gaps if your goal is to learn generally, not just copy this current HRIS project:
- It mixed project-specific rules with general rules instead of teaching the common default Laravel structure.
- It made a multi-domain platform structure feel like the normal beginner/default structure.
- Some example code was unsafe: route model binding for tasks could allow one user to update/delete another user's task unless ownership is checked.
- It used token storage in `localStorage` without strongly explaining the security tradeoff versus httpOnly cookies.
- It leaned too much toward client components and TanStack Query without explaining when Server Components are useful in a Laravel-backed app.
- It needed a stronger learning path: what to learn first, what to build, and how to know you understand it.

This rewritten guide keeps the beginner-friendly explanations, fixes the security/architecture issues, and makes the examples more standard.

---

## 1. The Core Mental Model

### 1.1 The Restaurant Analogy

A traditional Laravel app with Blade is like a small diner where the kitchen and dining area are in the same room.

- Laravel receives the request.
- Laravel queries the database.
- Laravel renders HTML with Blade.
- The browser displays that HTML.

A headless app separates the kitchen from the dining room:

```txt
Browser / User
    |
    v
Next.js frontend
- screens
- forms
- buttons
- routing
- visual state
    |
    | HTTP / JSON
    v
Laravel backend/API
- auth
- validation
- business rules
- database
- permissions
```

Laravel is the kitchen. Next.js is the dining room. The API is the waiter.

Important rule:
- Next.js should not cook the food.
- Laravel should not arrange the tables.

In engineering terms:
- Laravel owns data, business logic, authentication, authorization, validation, and persistence.
- Next.js owns user interface, page routing, client interactions, frontend state, and visual presentation.

### 1.2 What "Headless" Actually Means

"Headless" means the backend does not render the final UI. It exposes data and actions through an API.

Laravel returns JSON:

```json
{
  "data": {
    "id": 1,
    "title": "Buy milk",
    "completed": false
  }
}
```

Next.js turns that JSON into UI:

```tsx
<h1>{task.title}</h1>
```

The backend has no head/UI. The frontend is the head.

---

## 2. What You Must Learn Separately Before Combining Them

Do not try to learn Laravel, Next.js, TypeScript, React, SQL, auth, APIs, and deployment all at once. That is like learning grammar, vocabulary, essay writing, and public speaking in the same day.

### 2.1 Laravel Fundamentals

Learn these first:

1. Routing
   - `routes/api.php`
   - HTTP verbs: GET, POST, PUT/PATCH, DELETE

2. Controllers
   - Receive requests.
   - Call services or model queries.
   - Return responses.

3. Requests / Validation
   - Validate input before business logic runs.

4. Models / Eloquent
   - Represent database tables.
   - Define relationships.

5. Migrations
   - Version-controlled database schema.

6. Resources
   - Control API output shape.

7. Services
   - Put business rules here when logic grows beyond simple CRUD.

8. Auth and Authorization
   - Sanctum for API auth.
   - Policies or permissions for access control.

### 2.2 Next.js Fundamentals

Learn these first:

1. React components
   - Components are functions that return UI.

2. Props and state
   - Props are inputs from the parent.
   - State is memory inside a component.

3. App Router
   - Folders become routes.
   - `page.tsx` defines a page.
   - `layout.tsx` wraps pages.

4. Server Components vs Client Components
   - Server Components are default.
   - Client Components use `"use client"` and support hooks/events/browser APIs.

5. Forms
   - React Hook Form and Zod are common choices.

6. Data fetching
   - For API-heavy apps, TanStack Query is excellent for client-side server state.

7. TypeScript
   - Interfaces/types describe the shape of API data.

### 2.3 API Fundamentals

This is the bridge between the two:

- HTTP methods
- Status codes
- JSON request bodies
- JSON response bodies
- Auth headers or cookies
- CORS
- Pagination
- Validation errors

If APIs are confusing, the whole headless stack will feel confusing.

---

## 3. Standard Laravel Structure

This is one of the most important parts.

For general learning and for most normal Laravel + Next.js applications, start with Laravel's standard structure. This is the common default you will see in tutorials, documentation, small-to-medium apps, and many production codebases.

A large company may eventually organize a backend by domains or packages, but that is not the baseline you should learn first. Learn the standard shape first, then you can understand other structures later.

### 3.1 The Common Laravel API Structure

For most small or medium apps, Laravel's default structure is normal and perfectly acceptable:

```txt
backend/
  app/
    Http/
      Controllers/
        TaskController.php
      Requests/
        StoreTaskRequest.php
      Resources/
        TaskResource.php
    Models/
      Task.php
    Services/
      TaskService.php
    Policies/
      TaskPolicy.php
  database/
    migrations/
    seeders/
    factories/
  routes/
    api.php
```

Use this when:
- You are learning Laravel.
- The app is small or medium.
- You are building one product.
- You want the common structure used by Laravel docs and many teams.
- You do not have several independent business domains owned by separate teams.

This is the best beginner and general-purpose default.

### 3.2 Why This Is the Better Default

Standard Laravel is easier to learn because every concept has one obvious home:

```txt
URL route            -> routes/api.php
HTTP request         -> Controller
Validation           -> Form Request
Business rule        -> Service
Database row/table   -> Model + Migration
JSON output          -> API Resource
Authorization        -> Policy or middleware
```

Analogy:
- Standard Laravel is like a well-organized backpack. Everything you need is close, obvious, and easy to find.
- More advanced domain/package structures are like a warehouse. Useful when the inventory is huge, but unnecessary when you are still learning or building a normal-sized app.

### 3.3 Rule of Thumb

If you are building a simple Task Manager, ecommerce app, booking app, LMS, blog, CMS, inventory app, or finance tracker, start with standard Laravel.

Only consider more advanced organization after the standard folders become genuinely painful to navigate. Do not add extra architecture just because it looks more professional. Good architecture removes confusion; bad architecture adds ceremony.

### 3.4 How This Differs From the Viteseo HRIS Project

This guide is a general learning guide. It intentionally uses the common/default Laravel and Next.js structure so you learn transferable fundamentals first.

The actual Viteseo HRIS project uses a more advanced modular monolith structure because HR, CRM, and Project Management are planned to live inside one shared platform.

| General learning guide | Viteseo HRIS project |
|---|---|
| `app/Http/Controllers` | `backend/app/Modules/Hr/Http/Controllers` |
| `app/Models` | `backend/app/Modules/Hr/Models` plus shared `backend/app/Models` |
| `app/Services` | `backend/app/Modules/Hr/Services` |
| `routes/api.php` | `backend/app/Modules/Hr/Routes/api.php` loaded by the HR service provider |
| `/api/v1/tasks` | `/api/v1/hr/...` |
| `frontend/src/features/tasks` | `frontend/src/modules/hr/...` |

Use this guide to understand the standard/common pattern. Use the project docs when working inside the HRIS codebase.

---

## 4. Recommended General Architecture

A clean general headless app looks like this:

```txt
repo/
  backend/       Laravel API
  frontend/      Next.js app
  docs/          API contracts and architecture notes
```

### 4.1 Backend Responsibility

Laravel should handle:

- Database schema
- Database queries
- Business rules
- Validation
- Authentication
- Authorization
- File storage rules
- Emails/notifications/jobs
- API response formatting

### 4.2 Frontend Responsibility

Next.js should handle:

- Screens/pages
- Components
- Form UX
- Loading/error states
- Calling the API
- Caching server data in the browser
- Display formatting
- Navigation

### 4.3 What Not To Do

Avoid these mistakes:

- Do not access the database directly from Next.js.
- Do not duplicate Laravel business rules in Next.js.
- Do not create a separate auth system in Next.js.
- Do not put secret API keys in `NEXT_PUBLIC_*` variables.
- Do not trust IDs sent by the browser for ownership.
- Do not skip backend validation because the frontend already validates.

Frontend validation is for user experience.
Backend validation is for security and correctness.

---

## 5. API Contract Standards

The API contract is the agreement between Laravel and Next.js.

### 5.1 Resource Shape

Use a consistent envelope:

```json
{
  "data": {
    "id": 1,
    "title": "Buy milk"
  }
}
```

For lists:

```json
{
  "data": [
    { "id": 1, "title": "Buy milk" },
    { "id": 2, "title": "Read docs" }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 2
  },
  "links": {
    "next": null
  }
}
```

For validation errors:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "title": ["The title field is required."]
  }
}
```

### 5.2 HTTP Methods

```txt
GET    /api/v1/tasks          list tasks
POST   /api/v1/tasks          create task
GET    /api/v1/tasks/1        show one task
PATCH  /api/v1/tasks/1        update part of a task
DELETE /api/v1/tasks/1        delete task
```

### 5.3 Status Codes

```txt
200 OK                  successful read/update
201 Created             successful create
204 No Content          successful delete with no body
401 Unauthorized        not logged in
403 Forbidden           logged in but not allowed
404 Not Found           resource does not exist or is hidden from user
422 Unprocessable       validation failed
500 Server Error        unexpected backend bug
```

### 5.4 Naming

Good endpoint names are plural nouns:

```txt
/api/v1/tasks
/api/v1/projects
/api/v1/leave_requests
```

Avoid verbs in basic CRUD endpoints:

```txt
Bad:  /api/v1/createTask
Good: POST /api/v1/tasks
```

Verbs are acceptable for domain actions:

```txt
PATCH /api/v1/tasks/1/complete
POST  /api/v1/invoices/1/send
```

> **Next step**: If you're building the project, go to [Section 13: Environment Setup](#13-how-to-set-up-your-environment-from-scratch). If you're just reading for understanding, continue to Section 6.

---

## 6. Laravel Backend Example: Standard Task Manager

This example uses standard Laravel structure, not modules. That makes it better for learning general Laravel.

### 6.1 Migration

```php
// database/migrations/xxxx_xx_xx_xxxxxx_create_tasks_table.php
// Migrations are version-controlled database schema changes.
// Each migration has an up() method (apply) and down() method (rollback).

use Illuminate\Database\Migrations\Migration;          // Base class for all migrations
use Illuminate\Database\Schema\Blueprint;               // Fluent API for defining columns
use Illuminate\Support\Facades\Schema;                  // Facade for creating/modifying tables

// Anonymous class extends Migration — Laravel auto-discovers files in database/migrations/
return new class extends Migration
{
    // up() runs when you execute "php artisan migrate"
    public function up(): void
    {
        // Create a new table called "tasks" — the closure defines all columns
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();                                // Auto-incrementing BIGINT primary key
            $table->foreignId('user_id')                 // BIGINT column that references users.id
                ->constrained()                          // Auto-detects the "users" table from column name
                ->cascadeOnDelete();                     // If the user is deleted, delete their tasks too
            $table->string('title');                     // VARCHAR(255) — required task title
            $table->text('description')->nullable();     // TEXT column — optional, can be NULL
            $table->boolean('completed')->default(false); // TINYINT(1) — defaults to false (not done)
            $table->timestamp('completed_at')->nullable(); // When the task was marked complete (NULL if not yet)
            $table->timestamp('due_at')->nullable();     // Optional deadline date/time
            $table->timestamps();                        // Adds created_at and updated_at TIMESTAMP columns
            $table->softDeletes();                       // Adds deleted_at column for soft-delete support
        });
    }

    // down() runs when you execute "php artisan migrate:rollback"
    public function down(): void
    {
        // Drop the table entirely — reverses the up() method
        Schema::dropIfExists('tasks');
    }
};
```

Why each column exists:

- `id`: database identity.
- `user_id`: task owner.
- `title`: required human-readable label.
- `description`: optional details.
- `completed`: fast boolean check.
- `completed_at`: when completion happened.
- `due_at`: optional deadline.
- `timestamps`: Laravel tracks creation/update times.
- `softDeletes`: deleted tasks can be restored later.

### 6.2 Model

```php
// app/Models/Task.php
// The Model represents a single row in the "tasks" database table.
// It provides methods to create, read, update, and delete task records.

namespace App\Models;                                   // All models live in this namespace

use Illuminate\Database\Eloquent\Model;                 // Base Eloquent class — provides CRUD methods
use Illuminate\Database\Eloquent\Relations\BelongsTo;   // Return type for "belongs to" relationships
use Illuminate\Database\Eloquent\SoftDeletes;           // Trait that enables soft-delete (sets deleted_at instead of removing row)

class Task extends Model
{
    // Enables soft deletes — calling delete() sets deleted_at instead of removing the row
    use SoftDeletes;

    // Whitelist of columns that can be mass-assigned via create() or update().
    // This prevents attackers from setting columns they shouldn't (like user_id on someone else's task).
    protected $fillable = [
        'user_id',          // Who owns this task
        'title',            // Task name
        'description',      // Optional details
        'completed',        // Whether the task is done
        'completed_at',     // When it was completed
        'due_at',           // Optional deadline
    ];

    // Tell Eloquent how to cast database values to PHP types.
    // Without casts, booleans come back as "0"/"1" strings and dates as plain strings.
    protected $casts = [
        'completed' => 'boolean',        // Convert 0/1 to true/false
        'completed_at' => 'datetime',    // Convert timestamp string to Carbon instance
        'due_at' => 'datetime',          // Convert timestamp string to Carbon instance
    ];

    // Defines a "belongs to" relationship: each task belongs to one user.
    // Eloquent uses the user_id column to look up the User model.
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);            // Auto-detects foreign key as user_id
    }
}
```

Analogy:
- The table is the spreadsheet.
- The model is the PHP object that knows how to read/write rows in that spreadsheet.

### 6.3 Form Request

```php
// app/Http/Requests/StoreTaskRequest.php
// Form Requests are dedicated validation classes. They run BEFORE the controller,
// so invalid data never reaches your business logic.

namespace App\Http\Requests;                            // All form requests live in this namespace

use Illuminate\Foundation\Http\FormRequest;             // Base class with validation + authorization

class StoreTaskRequest extends FormRequest
{
    // Determines if the user is ALLOWED to make this request.
    // Return true when authorization is handled elsewhere (e.g., middleware).
    public function authorize(): bool
    {
        return true;                                     // Auth is handled by auth:sanctum middleware
    }

    // Returns the validation rules that incoming data must pass.
    // If validation fails, Laravel automatically returns a 422 response with error messages.
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],       // Must be present, a string, max 255 chars
            'description' => ['nullable', 'string', 'max:2000'], // Optional, but if present must be string ≤ 2000
            'due_at' => ['nullable', 'date'],                    // Optional, but if present must be a valid date
        ];
    }
}
```

Backend validation protects the database and business rules even if someone bypasses the UI and sends requests manually.

### 6.4 Resource

```php
// app/Http/Resources/TaskResource.php
// API Resources transform Eloquent models into JSON responses.
// They control exactly which fields are exposed and how they're formatted.

namespace App\Http\Resources;                           // All resources live in this namespace

use Illuminate\Http\Request;                            // The current HTTP request (passed to toArray)
use Illuminate\Http\Resources\Json\JsonResource;        // Base class for single-model resources

class TaskResource extends JsonResource
{
    // Convert the model into an array that will be JSON-encoded for the API response.
    // $this refers to the underlying Task model being wrapped.
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,                          // The database primary key
            'title' => $this->title,                    // Task name as-is
            'description' => $this->description,        // Optional description (may be null)
            'completed' => $this->completed,            // Boolean (cast by the model)
            'completed_at' => $this->completed_at?->toIso8601String(), // Null-safe: format as ISO 8601 or null
            'due_at' => $this->due_at?->toIso8601String(),             // Same null-safe formatting
            'created_at' => $this->created_at?->toIso8601String(),     // Auto-managed by timestamps()
            'updated_at' => $this->updated_at?->toIso8601String(),     // Auto-managed by timestamps()
        ];
    }
}
```

Resources are important because the database shape and API shape should not have to be identical.

### 6.5 Service

```php
// app/Services/TaskService.php
// Services contain business logic — the "story" of what happens when you
// create, complete, or delete a task. Controllers delegate to services.

namespace App\Services;                                 // All services live in this namespace

use App\Models\Task;                                    // The Task Eloquent model
use App\Models\User;                                    // The User Eloquent model
use Illuminate\Contracts\Pagination\LengthAwarePaginator; // Return type for paginated queries

class TaskService
{
    // List tasks for a specific user, with optional search filtering.
    // Returns a paginated result (20 per page) so large datasets don't crash memory.
    public function listForUser(User $user, ?string $search = null): LengthAwarePaginator
    {
        return Task::query()                             // Start a new query builder for the tasks table
            ->whereBelongsTo($user)                      // Scope to ONLY this user's tasks (WHERE user_id = ?)
            ->when($search, function ($query) use ($search) { // Only apply this clause if $search is not null
                $query->where('title', 'like', "%{$search}%"); // SQL LIKE search in the title column
            })
            ->latest()                                   // Order by created_at DESC (newest first)
            ->paginate(20);                              // Return 20 results per page with pagination metadata
    }

    // Create a new task owned by the given user.
    // The user_id is set from the authenticated user, NOT from client input (security!).
    public function createForUser(User $user, array $data): Task
    {
        return Task::create([                            // Mass-assign using the $fillable whitelist
            'user_id' => $user->id,                      // Ownership comes from the token, not the request body
            'title' => $data['title'],                   // Required field from validated input
            'description' => $data['description'] ?? null, // Optional — default to null if not provided
            'due_at' => $data['due_at'] ?? null,         // Optional deadline
        ]);
    }


    // Mark a task as complete — sets both the boolean flag and the timestamp.
    public function complete(Task $task): Task
    {
        $task->update([
            'completed' => true,                         // Set the boolean flag
            'completed_at' => now(),                     // Record WHEN it was completed (Carbon::now())
        ]);

        return $task->refresh();                         // Return the fresh model with updated values
    }

    // Mark a task as incomplete — reverses the complete() action.
    public function incomplete(Task $task): Task
    {
        $task->update([
            'completed' => false,                        // Reset the boolean flag
            'completed_at' => null,                      // Clear the completion timestamp
        ]);

        return $task->refresh();                         // Return the fresh model
    }
}
```

A service is useful when controller actions need business language:

- createForUser
- complete
- incomplete
- approve
- reject
- calculateBalance

The controller should read like a table of contents. The service should contain the story.

### 6.6 Controller With Ownership Protection

This is where many beginner examples go wrong.

Bad pattern:

```php
public function complete(Task $task)
{
    $task->update(['completed' => true]);
}
```

Why bad? If task `5` belongs to another user, a malicious user might try:

```txt
PATCH /api/v1/tasks/5/complete
```

Correct pattern: resolve the task through the authenticated user's tasks.

```php
// app/Http/Controllers/TaskController.php
// Controllers are THIN — they receive HTTP requests, delegate to services,
// and return HTTP responses. No business logic lives here.

namespace App\Http\Controllers;                         // All controllers live in this namespace

use App\Http\Requests\StoreTaskRequest;                 // Form request with validation rules
use App\Http\Resources\TaskResource;                    // API resource for JSON formatting
use App\Models\Task;                                    // The Task Eloquent model
use App\Services\TaskService;                           // Service with business logic
use Illuminate\Http\JsonResponse;                       // Return type for JSON responses
use Illuminate\Http\Request;                            // The incoming HTTP request
use Illuminate\Http\Response;                           // HTTP status code constants

class TaskController extends Controller
{
    // Constructor injection: Laravel auto-resolves TaskService from the container.
    // readonly means it can't be reassigned after construction.
    public function __construct(private readonly TaskService $tasks) {}

    // GET /api/v1/tasks — list the authenticated user's tasks (with optional search)
    public function index(Request $request): JsonResponse
    {
        // Delegate to the service — pass the authenticated user and any search query param
        $tasks = $this->tasks->listForUser(
            $request->user(),                            // The user identified by the Sanctum token
            $request->query('search')                    // Optional ?search= query string parameter
        );

        // Wrap the paginated result in a Resource collection and return as JSON
        return TaskResource::collection($tasks)->response();
    }

    // POST /api/v1/tasks — create a new task for the authenticated user
    public function store(StoreTaskRequest $request): JsonResponse
    {
        // $request->validated() returns ONLY the fields that passed validation
        $task = $this->tasks->createForUser(
            $request->user(),                            // Ownership from the token, not the request body
            $request->validated()                        // Validated input data
        );

        // Return the created task wrapped in a Resource, with HTTP 201 status
        return TaskResource::make($task)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);     // 201 Created
    }

    // GET /api/v1/tasks/{task} — show one task (only if it belongs to the user)
    public function show(Request $request, int $task): JsonResponse
    {
        // findUserTask() ensures the task belongs to this user — returns 404 otherwise
        $task = $this->findUserTask($request, $task);

        return TaskResource::make($task)->response();
    }

    // PATCH /api/v1/tasks/{task}/complete — mark a task as done
    public function complete(Request $request, int $task): JsonResponse
    {
        $task = $this->findUserTask($request, $task);    // Verify ownership first
        $task = $this->tasks->complete($task);            // Delegate business logic to service

        return TaskResource::make($task)->response();
    }

    // PATCH /api/v1/tasks/{task}/incomplete — mark a task as not done
    public function incomplete(Request $request, int $task): JsonResponse
    {
        $task = $this->findUserTask($request, $task);    // Verify ownership first
        $task = $this->tasks->incomplete($task);          // Delegate to service

        return TaskResource::make($task)->response();
    }

    // DELETE /api/v1/tasks/{task} — soft-delete a task
    public function destroy(Request $request, int $task): JsonResponse
    {
        $task = $this->findUserTask($request, $task);    // Verify ownership first
        $task->delete();                                 // Soft-delete (sets deleted_at, doesn't remove row)

        // Return 204 No Content — successful deletion with no response body
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    // PRIVATE helper: find a task that belongs to the authenticated user.
    // Returns 404 if the task doesn't exist OR doesn't belong to this user.
    // This prevents IDOR attacks (accessing another user's tasks by guessing IDs).
    private function findUserTask(Request $request, int $taskId): Task
    {
        return Task::query()
            ->whereBelongsTo($request->user())           // WHERE user_id = authenticated user's ID
            ->whereKey($taskId)                          // AND id = the requested task ID
            ->firstOrFail();                             // Return the task or throw 404 ModelNotFoundException
    }
}
```

This returns 404 if the task either does not exist or does not belong to the user. That is usually safer than revealing that another user's task exists.

For bigger apps, use Laravel Policies. For beginner apps, the scoped query above is easier to understand.

### 6.7 Routes

```php
// routes/api.php
// This file defines all API endpoints. Laravel auto-mounts it under /api,
// so prefix('v1') produces URLs like /api/v1/tasks.

use App\Http\Controllers\TaskController;                // Import the controller class
use App\Http\Controllers\AuthController;                // Import the auth controller class
use Illuminate\Support\Facades\Route;                   // Facade for defining routes

// Group all v1 routes under the /api/v1 prefix
Route::prefix('v1')->group(function () {
    // Public auth route — no token required to log in
    Route::post('auth/login', [AuthController::class, 'login']);

    // All routes inside this group require a valid Sanctum token.
    // auth:sanctum middleware validates the Bearer token and sets $request->user().
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('tasks', [TaskController::class, 'index']);          // GET    /api/v1/tasks
        Route::post('tasks', [TaskController::class, 'store']);         // POST   /api/v1/tasks
        Route::get('tasks/{task}', [TaskController::class, 'show']);    // GET    /api/v1/tasks/1
        Route::patch('tasks/{task}/complete', [TaskController::class, 'complete']);     // PATCH /api/v1/tasks/1/complete
        Route::patch('tasks/{task}/incomplete', [TaskController::class, 'incomplete']); // PATCH /api/v1/tasks/1/incomplete
        Route::delete('tasks/{task}', [TaskController::class, 'destroy']);              // DELETE /api/v1/tasks/1
    });
});
```

In Laravel, `routes/api.php` is normally already mounted under `/api`, so `prefix('v1')` produces `/api/v1/...`.

> **Next step**: If you're building the project, you've finished the backend. Now go to [Section 15: Authentication](#15-how-to-build-authentication-full-implementation) to build the frontend auth, then [Section 16: Frontend Components](#16-how-to-build-the-missing-frontend-components) to build the task UI. If you're just reading, continue to Section 7.

---

## 7. Next.js Frontend Example

### 7.1 Suggested Frontend Structure

This feature-folder structure is a common general Next.js organization. In frontend React/Next.js apps, people commonly say feature folder, domain folder, or slice folder.

```txt
frontend/src/
  app/
    layout.tsx
    tasks/
      page.tsx
  core/
    api-client.ts
    query-provider.tsx
  features/
    tasks/
      api/tasks.ts
      hooks/use-tasks.ts
      types/task.ts
      components/task-form.tsx
      components/task-item.tsx
  components/ui/
```

### 7.2 TypeScript Types

```ts
// src/features/tasks/types/task.ts
// TypeScript interfaces define the SHAPE of data coming from the API.
// They give you autocomplete, catch typos at compile time, and document the contract.

// Represents a single task as returned by the Laravel API
export interface Task {
  id: number;                    // Database primary key
  title: string;                 // Task name (always present)
  description: string | null;    // Optional details (can be null from the API)
  completed: boolean;            // Whether the task is done
  completed_at: string | null;   // ISO 8601 timestamp or null (from toIso8601String())
  due_at: string | null;         // Optional deadline as ISO 8601 string
  created_at: string;            // Auto-managed by Laravel timestamps
  updated_at: string;            // Auto-managed by Laravel timestamps
}

// The shape of data sent when CREATING a new task (no id, timestamps, etc.)
export interface TaskInput {
  title: string;                 // Required — the task name
  description?: string;          // Optional — the ? means this field can be omitted
  due_at?: string;               // Optional deadline
}

// Generic wrapper for single-item API responses: { "data": { ... } }
export interface ApiEnvelope<T> {
  data: T;                       // The actual resource object
}

// Generic wrapper for paginated list responses: { "data": [...], "meta": {...}, "links": {...} }
export interface PaginatedEnvelope<T> {
  data: T[];                     // Array of resource objects for the current page
  meta: {
    current_page: number;        // Which page we're on (1-indexed)
    per_page: number;            // How many items per page
    total: number;               // Total number of items across all pages
  };
  links: {
    next: string | null;         // URL for the next page, or null if on the last page
  };
}
```

TypeScript is a map. It is not the territory. The backend response is the real territory. Keep your types aligned with actual API responses.

### 7.3 Axios API Client

```ts
// src/core/api-client.ts
// A shared Axios instance that every API call uses. It handles:
// 1. Attaching the auth token to every request
// 2. Redirecting to /login when a 401 response is received

import axios from "axios";                              // HTTP client library

// Create a pre-configured Axios instance with our API's base URL and default headers
export const apiClient = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_BASE_URL,        // e.g., "http://localhost:8000/api/v1"
  headers: {
    Accept: "application/json",                         // Tell Laravel we want JSON back
    "Content-Type": "application/json",                 // Tell Laravel we're sending JSON
  },
});

// REQUEST INTERCEPTOR: runs before every outgoing request.
// This is where we attach the auth token from localStorage.
apiClient.interceptors.request.use((config) => {
  // Guard: localStorage only exists in the browser, not during server-side rendering
  if (typeof window === "undefined") {
    return config;                                     // SSR context — skip token attachment
  }

  // Read the token that was saved during login
  const token = window.localStorage.getItem("token");

  // If a token exists, add it as a Bearer token in the Authorization header
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;  // e.g., "Bearer 1|abcdef123..."
  }

  return config;                                       // Return the modified config to continue the request
});

// RESPONSE INTERCEPTOR: runs after every incoming response (or error).
// This handles expired/invalid tokens globally — one place covers every screen.
apiClient.interceptors.response.use(
  (response) => response,                              // Success path: pass the response through unchanged
  (error) => {
    // If we're in the browser AND the server returned 401 (Unauthorized)...
    if (typeof window !== "undefined" && error.response?.status === 401) {
      window.localStorage.removeItem("token");         // Delete the stale/invalid token
      window.location.href = "/login";                 // Redirect to the login page
    }

    return Promise.reject(error);                      // Always reject so the calling code can handle the error
  }
);
```

Security note:
- `localStorage` is simple and common in demos, but vulnerable if your app has XSS.
- httpOnly cookies are safer against token theft, but add CSRF/session complexity.
- Treat this `localStorage` approach as the beginner/demo path, not automatic production best practice.
- For serious browser-only production apps, strongly consider httpOnly cookie-based auth with CSRF protection.
- If you choose `localStorage`, XSS prevention becomes critical because JavaScript can read the token.

### 7.4 API Functions

```ts
// src/features/tasks/api/tasks.ts
// API functions are thin wrappers around Axios calls. Components call these
// functions instead of using Axios directly — this isolates HTTP details in one place.

import { apiClient } from "@/core/api-client";          // Our pre-configured Axios instance
import type { ApiEnvelope, PaginatedEnvelope, Task, TaskInput } from "../types/task"; // TypeScript types

// Fetch a paginated list of tasks, with optional search filtering.
// The generic <PaginatedEnvelope<Task>> tells TypeScript the shape of response.data.
export async function getTasks(search?: string): Promise<Task[]> {
  const response = await apiClient.get<PaginatedEnvelope<Task>>("/tasks", {
    params: search ? { search } : undefined,            // Only send ?search= if a value was provided
  });

  // response.data is the full envelope { data: [...], meta: {...} }
  // response.data.data is the actual array of Task objects
  return response.data.data;
}

// Create a new task by POSTing the input data to the API.
export async function createTask(input: TaskInput): Promise<Task> {
  const response = await apiClient.post<ApiEnvelope<Task>>("/tasks", input); // Send input as JSON body

  return response.data.data;                            // Unwrap the { data: Task } envelope
}

// Mark a task as completed by PATCHing the /complete endpoint.
export async function completeTask(id: number): Promise<Task> {
  // Template literal builds the URL: /tasks/5/complete
  const response = await apiClient.patch<ApiEnvelope<Task>>(`/tasks/${id}/complete`);

  return response.data.data;                            // Return the updated task
}

// Mark a task as incomplete by PATCHing the /incomplete endpoint.
export async function incompleteTask(id: number): Promise<Task> {
  const response = await apiClient.patch<ApiEnvelope<Task>>(`/tasks/${id}/incomplete`);

  return response.data.data;
}

// Delete a task. Returns void because the API responds with 204 No Content (empty body).
export async function deleteTask(id: number): Promise<void> {
  await apiClient.delete(`/tasks/${id}`);               // Fire-and-forget — no response body to parse
}
```

API functions are translators. Components should not know Axios details.

### 7.5 TanStack Query Hooks

```ts
// src/features/tasks/hooks/use-tasks.ts
// TanStack Query hooks manage server state: fetching, caching, and invalidating data.
// Each hook wraps an API function and provides loading/error states automatically.

"use client";                                           // Required because hooks use useState/useEffect internally

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"; // Core TanStack Query hooks
import {
  completeTask,                                         // API function to mark task complete
  createTask,                                           // API function to create a task
  deleteTask,                                           // API function to delete a task
  getTasks,                                             // API function to fetch task list
  incompleteTask,                                       // API function to mark task incomplete
} from "../api/tasks";
import type { TaskInput } from "../types/task";          // TypeScript type for create input

// A stable query key used to identify the tasks cache.
// TanStack Query uses this key to store, retrieve, and invalidate cached data.
const TASKS_KEY = ["tasks"] as const;                   // as const makes it a readonly tuple type

// Hook to FETCH tasks (a "query" = reading data from the server).
// Automatically caches the result and refetches when the search param changes.
export function useTasks(search?: string) {
  return useQuery({
    queryKey: [...TASKS_KEY, { search: search ?? "" }], // Unique key per search value → separate caches
    queryFn: () => getTasks(search),                    // The function TanStack Query calls to fetch data
  });
}

// Hook to CREATE a task (a "mutation" = changing data on the server).
// After success, invalidates the tasks cache so the list refetches.
export function useCreateTask() {
  const queryClient = useQueryClient();                 // Access the cache manager

  return useMutation({
    mutationFn: (input: TaskInput) => createTask(input), // The function that performs the API call
    onSuccess: () => {
      // Tell TanStack Query: "The tasks list may have changed — refetch it"
      queryClient.invalidateQueries({ queryKey: TASKS_KEY });
    },
  });
}

// Hook to COMPLETE a task
export function useCompleteTask() {
  const queryClient = useQueryClient();                 // Access the cache manager

  return useMutation({
    mutationFn: (id: number) => completeTask(id),       // Call the API with the task ID
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: TASKS_KEY }); // Refetch the list after completion
    },
  });
}

// Hook to mark a task as INCOMPLETE
export function useIncompleteTask() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => incompleteTask(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: TASKS_KEY });
    },
  });
}

// Hook to DELETE a task
export function useDeleteTask() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => deleteTask(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: TASKS_KEY }); // Refetch list after deletion
    },
  });
}
```

Mental model:
- Query = read from server.
- Mutation = change server.
- Invalidation = tell TanStack Query, "Your cached answer may be stale; ask again."

### 7.6 Query Provider

```tsx
// src/core/query-provider.tsx
// This component wraps the entire app with TanStack Query's context.
// Without it, useQuery/useMutation hooks won't work.

"use client";                                           // Required because QueryClient uses browser state

import { QueryClient, QueryClientProvider } from "@tanstack/react-query"; // Core TanStack Query components
import { useState } from "react";                       // useState to create QueryClient once per component lifecycle

// The provider component — wrap this around your app in layout.tsx
export function QueryProvider({ children }: { children: React.ReactNode }) {
  // Create the QueryClient inside useState's initializer function.
  // This ensures it's created only ONCE, not on every re-render.
  // (If we wrote `new QueryClient()` directly, it would recreate on every render.)
  const [queryClient] = useState(() => new QueryClient());

  return (
    // QueryClientProvider makes the client available to all useQuery/useMutation hooks
    <QueryClientProvider client={queryClient}>
      {children}                                        {/* Render whatever is wrapped inside */}
    </QueryClientProvider>
  );
}
```

```tsx
// src/app/layout.tsx
// The root layout wraps EVERY page in the app. This is where you add
// global providers (Query, Auth, Theme) that all pages need.

import { QueryProvider } from "@/core/query-provider";  // Import our TanStack Query wrapper
import "./globals.css";                                  // Import global CSS (Tailwind directives, etc.)

// This is the root layout — it wraps all pages with <html> and <body> tags
export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">                                    // Set the document language for accessibility
      <body>
        {/* QueryProvider must be inside <body> because it's a Client Component */}
        <QueryProvider>{children}</QueryProvider>        {/* All pages can now use useQuery/useMutation */}
      </body>
    </html>
  );
}
```

### 7.7 Client Page

```tsx
// src/app/tasks/page.tsx
// This is the main Tasks page. It's a Client Component because it uses
// useState (for search) and TanStack Query hooks (for data fetching).

"use client";                                           // Marks this as a Client Component (runs in browser)

import { useState } from "react";                       // React hook for local component state
import { useTasks } from "@/features/tasks/hooks/use-tasks"; // Hook that fetches tasks via TanStack Query
import { TaskForm } from "@/features/tasks/components/task-form";   // Form to create new tasks
import { TaskItem } from "@/features/tasks/components/task-item";   // Component to display a single task

// Default export = the page component. Next.js renders this at /tasks
export default function TasksPage() {
  // Local state for the search input — re-renders the component on every keystroke
  const [search, setSearch] = useState("");
  // useTasks returns { data, isLoading, error } from TanStack Query.
  // data defaults to [] so .map() works even before the first fetch completes.
  // search || undefined converts empty string to undefined (so we don't send ?search=)
  const { data: tasks = [], isLoading, error } = useTasks(search || undefined);

  return (
    // Main container: centered, max width 2xl, vertical spacing between children
    <main className="mx-auto max-w-2xl space-y-6 p-6">
      <header>
        {/* Page title */}
        <h1 className="text-3xl font-bold">Tasks</h1>
        {/* Subtitle in muted color */}
        <p className="text-muted-foreground">Practice full-stack CRUD.</p>
      </header>

      {/* Search input — controlled by useState, triggers refetch on every keystroke */}
      <input
        className="w-full rounded-md border px-3 py-2"
        placeholder="Search tasks..."
        value={search}
        onChange={(event) => setSearch(event.target.value)}
      />

      {/* The create-task form component */}
      <TaskForm />

      {/* Show loading indicator while the initial fetch is in progress */}
      {isLoading && <p>Loading...</p>}
      {/* Show error message if the API call failed */}
      {error && <p className="text-red-600">Failed to load tasks.</p>}

      {/* Render the list of tasks — each TaskItem handles its own complete/delete */}
      {/* Vertical gap between task items */}
      <div className="space-y-2">
        {tasks.map((task) => (
          <TaskItem key={task.id} task={task} />
        ))}
      </div>
    </main>
  );
}
```

This page is a Client Component because it uses `useState` and TanStack Query hooks.

> **Next step**: The `TaskForm` and `TaskItem` components referenced above are fully built in [Section 16: Frontend Components](#16-how-to-build-the-missing-frontend-components). Continue to Section 8 to understand Server vs Client Components.

---

## 8. Server Components vs Client Components in a Laravel Headless App

Next.js App Router defaults to Server Components.

### 8.1 Server Component

Server Components:
- Run on the server.
- Cannot use `useState`, `useEffect`, or click handlers.
- Can render static layout and SEO metadata.
- Can call backend APIs from the server if appropriate.

### 8.2 Client Component

Client Components:
- Run in the browser.
- Can use state, effects, forms, click handlers, localStorage.
- Are required for TanStack Query hooks.

### 8.3 Practical Rule

Use Server Components for:
- layouts
- static pages
- marketing pages
- SEO metadata
- shells that do not need interactivity

Use Client Components for:
- dashboards
- forms
- tables with filters
- authenticated app screens using localStorage tokens
- anything using TanStack Query hooks

Best practice: push `"use client"` as low as practical. Do not turn your whole app into client components unless needed.

Analogy:
- Server Components are like pre-printing a textbook page.
- Client Components are like a calculator: interactive, stateful, and running in your hands.

---

## 9. Authentication Choices

### 9.1 Bearer Token Auth

Flow:

```txt
1. User logs in.
2. Laravel validates credentials.
3. Laravel creates a Sanctum token.
4. Next.js stores the token.
5. Axios sends Authorization: Bearer <token> on API requests.
6. Laravel auth:sanctum identifies the user.
```

Pros:
- Simple to understand.
- Easy for mobile apps too.
- Works well with pure APIs.

Cons:
- If stored in localStorage, token theft is possible through XSS.

### 9.2 Cookie-Based Auth

Flow:

```txt
1. Browser gets CSRF cookie.
2. User logs in.
3. Laravel sets secure httpOnly session cookie.
4. Browser sends cookie automatically.
5. Laravel identifies user from session.
```

Pros:
- httpOnly cookies are not readable by JavaScript.
- Often better for browser-only apps.

Cons:
- More CORS/CSRF/session configuration.
- Harder for beginners.

### 9.3 What Should You Learn First?

Learn bearer token auth first because it makes the API mechanics obvious.

Then learn cookie/session auth so you understand production browser security.

Important framing:
- Bearer tokens in `localStorage` are useful for learning and simple demos.
- httpOnly cookie auth is often safer for production browser apps because JavaScript cannot read the session cookie.
- The right production choice depends on your app, threat model, deployment, and whether you also support mobile/API clients.

---

## 10. CORS

CORS is not a Laravel feature or a Next.js feature. It is a browser security rule.

If your browser page is at:

```txt
http://localhost:3000
```

and it calls:

```txt
http://localhost:8000/api/v1/tasks
```

that is cross-origin.

Laravel must allow the frontend origin.

Typical Laravel CORS config allows:

```txt
allowed origin:  http://localhost:3000
allowed methods: GET, POST, PUT, PATCH, DELETE, OPTIONS
allowed headers: Content-Type, Authorization, Accept
```

Debugging checklist:

```bash
php artisan optimize:clear
php artisan route:list
curl -i -X OPTIONS http://localhost:8000/api/v1/tasks \
  -H "Origin: http://localhost:3000" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type,Authorization"
```

If curl works but the browser does not, inspect the browser Network tab.

---

## 11. Full Request Lifecycle

When the user creates a task:

```txt
1. User types into a form in Next.js.
2. React Hook Form collects values.
3. Zod validates basic frontend rules.
4. Component calls createTask mutation.
5. TanStack Query calls the API function.
6. API function calls Axios.
7. Axios attaches Authorization header.
8. Laravel route matches POST /api/v1/tasks.
9. auth:sanctum turns token into $request->user().
10. StoreTaskRequest validates backend rules.
11. TaskController calls TaskService.
12. TaskService writes to database.
13. TaskResource formats the response.
14. Laravel returns JSON.
15. Axios receives response.
16. TanStack Query marks mutation successful.
17. Query invalidation refetches the task list.
18. React re-renders the updated UI.
```

This chain is the backbone of headless development.

If something breaks, locate which step broke. Do not randomly change code.

---

## 12. Debugging Decision Tree

```txt
Problem: API returns 404
- Check Laravel route: php artisan route:list
- Check frontend URL base: NEXT_PUBLIC_API_BASE_URL
- Check route prefix: /api/v1/tasks vs /api/tasks

Problem: API returns 401
- Is Authorization header present?
- Is token saved?
- Is route protected by auth:sanctum?
- Is Sanctum installed and User model using HasApiTokens?

Problem: API returns 403
- User is logged in but lacks permission.
- Check policies, roles, permissions, middleware.

Problem: API returns 422
- Backend validation failed.
- Compare Form Request rules with frontend payload.

Problem: API returns 500
- Check Laravel logs.
- Open the file and line shown in the exception.

Problem: Browser shows CORS error
- Check Laravel CORS config.
- Check allowed origin and allowed headers.
- Clear Laravel config cache.

Problem: UI does not refresh after create/update/delete
- Check TanStack Query invalidation key.
- Query key and invalidation key must match.

Problem: Hydration error
- Server-rendered HTML differs from first client render.
- Avoid reading `window`, dates, random values, or localStorage during initial render.
```

---

# Part C — The "How" — Building From Scratch

> This is the section that answers "but HOW do I actually do this?" Every section below is a step-by-step walkthrough you can follow without AI help.

---

## 13. How to Set Up Your Environment From Scratch

You cannot build anything if you cannot start a project. This section walks you through creating a fresh Laravel + Next.js project from zero.

### 13.1 Prerequisites

Before you start, install these on your machine:

```bash
# Check each is installed:
php -v          # PHP 8.2+
node -v         # Node 18+
npm -v          # npm (comes with Node)
composer -V     # Composer (PHP package manager)
mysql --version # MySQL 8+
```

If any are missing:
- **PHP**: Use [Laravel Herd](https://herd.laravel.com/) (macOS/Windows) — it bundles PHP + Node + Composer
- **Composer**: `brew install composer` (macOS) or download from [getcomposer.org](https://getcomposer.org)
- **Node**: Download LTS from [nodejs.org](https://nodejs.org)
- **MySQL**: Use [DBngin](https://dbngin.com/) (macOS) or install directly

### 13.2 Create a Fresh Laravel Project

```bash
# Create a new Laravel project
composer create-project laravel/laravel taskmanager-api
cd taskmanager-api

# This gives you:
# taskmanager-api/
# ├── app/
# │   ├── Http/
# │   │   ├── Controllers/
# │   │   └── ...
# │   ├── Models/
# │   │   └── User.php
# │   └── ...
# ├── database/
# │   ├── migrations/
# │   ├── seeders/
# │   └── factories/
# ├── routes/
# │   ├── api.php          ← You'll add API routes here
# │   └── web.php
# ├── .env                 ← Environment configuration
# └── artisan              ← The CLI tool (Section 14)
```

### 13.3 Configure the Database

Open `.env` and set your database connection:

```env
# .env
APP_NAME=TaskManager
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=taskmanager
DB_USERNAME=root
DB_PASSWORD=your_password
```

Then create the database and run migrations:

```bash
# Create the database in MySQL
mysql -u root -p -e "CREATE DATABASE taskmanager;"

# Run migrations (creates default tables: users, etc.)
php artisan migrate

# Generate app key (if not auto-generated)
php artisan key:generate

# Start the server
php artisan serve
# Server running on http://localhost:8000
```

### 13.4 Create a Fresh Next.js Project

Open a **new terminal** (keep Laravel running):

```bash
npx create-next-app@latest taskmanager-web
cd taskmanager-web

# The wizard will ask:
# - TypeScript?               → Yes
# - Tailwind CSS?             → Yes
# - App Router?               → Yes
# - src/ directory?           → Yes
# - import alias?             → Yes, use "@/..."
# - ESLint?                   → Yes

# This gives you:
# taskmanager-web/
# ├── src/
# │   ├── app/
# │   │   ├── layout.tsx       ← Root layout
# │   │   └── page.tsx         ← Homepage
# │   └── ...
# ├── package.json
# ├── tailwind.config.ts
# ├── tsconfig.json            ← Has "@/*": ["./src/*"] path mapping
# └── next.config.ts
```

### 13.5 Install the Libraries You'll Need

```bash
# HTTP client
npm install axios

# Data fetching (TanStack Query)
npm install @tanstack/react-query

# Forms
npm install react-hook-form @hookform/resolvers zod

# UI components (shadcn/ui init)
npx shadcn@latest init
# Choose: TypeScript yes, style default, CSS variables yes,
# color neutral, --src-dir yes

# Add shadcn components as you need them:
npx shadcn@latest add button input dialog label card checkbox textarea

# Toast notifications
npm install sonner

# Icons
npm install lucide-react
```

### 13.6 Configure Environment Variables

```bash
# Create .env.local in taskmanager-web/
NEXT_PUBLIC_API_BASE_URL=http://localhost:8000/api/v1
```

Why `NEXT_PUBLIC_`? Next.js only exposes env vars to the browser if they're prefixed with `NEXT_PUBLIC_`. Without it, the variable is server-only.

### 13.7 The Two-Terminal Workflow

You'll always have **two terminals running** during development:

```
Terminal 1 (backend):          Terminal 2 (frontend):
cd taskmanager-api              cd taskmanager-web
php artisan serve               npm run dev
# :8000                         # :3000
```

Mental model:
- Terminal 1 = the kitchen (Laravel API)
- Terminal 2 = the dining room (Next.js UI)
- Your browser = you (visits :3000, which calls :8000)

### 13.8 Verify They Talk to Each Other

```bash
# In Terminal 1, test Laravel is running:
curl http://localhost:8000/api/v1/tasks -H "Accept: application/json"
# Should return 404 (no routes yet) or 401 (no auth) — that's fine!

# In Terminal 2, start Next.js:
npm run dev
# Visit http://localhost:3000 in your browser
```

If you get CORS errors, see Section 10. If you get 404s, that's expected — you haven't built routes yet.

> **Next step**: [Section 14: The Artisan CLI](#14-the-artisan-cli--how-to-create-files) — learn the commands you'll use to create every file in your project.

---

## 14. The Artisan CLI — How to Create Files

`artisan` is Laravel's command-line tool. It's how you create files, run migrations, seed data, and debug. If you don't know Artisan, you can't build Laravel apps on your own.

### 14.1 The Make Commands — Creating Files

Instead of creating PHP files manually, you use `make:` commands. These create the file in the **correct folder with the correct boilerplate**.

```bash
# Create a model (with migration, controller, resource, and seeder all at once!)
php artisan make:model Task -a
# -a = "all" → creates model + migration + controller + factory + seeder + resource

# Or create them individually:
php artisan make:model Task             # Just the model
php artisan make:model Task -m          # Model + migration
php artisan make:model Task -mf         # Model + migration + factory
php artisan make:model Task -mfsc       # Model + migration + factory + seeder + controller
php artisan make:model Task --all       # Everything

# Controller (always use --api or --resource for APIs)
php artisan make:controller TaskController --api
php artisan make:controller TaskController --resource

# Migration
php artisan make:migration create_tasks_table
php artisan make:migration add_priority_to_tasks_table --table=tasks

# Form Request (validation)
php artisan make:request StoreTaskRequest

# Resource (API response formatting)
php artisan make:resource TaskResource
php artisan make:resource TaskCollection

# Middleware
php artisan make:middleware EnsureUserHasRole

# Factory (for generating fake data)
php artisan make:factory TaskFactory

# Seeder
php artisan make:seeder TaskSeeder

# Policy (authorization)
php artisan make:policy TaskPolicy --model=Task
```

### 14.2 Database Commands

```bash
# Run all pending migrations
php artisan migrate

# Rollback the last migration batch
php artisan migrate:rollback

# Reset (rollback all) then re-migrate
php artisan migrate:refresh

# Drop all tables, re-migrate, and seed
php artisan migrate:fresh --seed

# Seed the database
php artisan db:seed

# Seed a specific class
php artisan db:seed --class=TaskSeeder
```

### 14.3 The Most Important Debugging Command

```bash
# List all registered routes — INVALUABLE for debugging 404s
php artisan route:list

# Filter by method or path
php artisan route:list --method=GET
php artisan route:list --path=api/v1/tasks

# Show middleware for each route
php artisan route:list -v

# Sample output:
# GET    api/v1/tasks          ► TaskController@index    auth:sanctum
# POST   api/v1/tasks          ► TaskController@store    auth:sanctum
# PATCH  api/v1/tasks/{task}/complete ► TaskController@complete  auth:sanctum
```

Pro tip: If you get a 404, run `php artisan route:list` first. It tells you the correct path immediately.

### 14.4 Tinker — The Interactive Playground

Tinker is a REPL (Read-Eval-Print Loop) for Laravel. It lets you run PHP code interactively against your app. It's the fastest way to test models and queries.

```bash
php artisan tinker
```

```php
// Inside tinker — an interactive PHP REPL connected to your Laravel app:
>>> $user = User::first();                               // Fetch the first user from the database
=> App\Models\User {#1234                                // Tinker shows the model with its attributes
     id: 1,
     name: "Test User",
     email: "test@test.com",
   }

>>> $user->tasks;                                        // Access the relationship — lazy-loads related tasks
=> Illuminate\Database\Eloquent\Collection {#...}        // Returns a Collection of Task models

>>> Task::where('completed', false)->count();            // Run any Eloquent query interactively
=> 5                                                     // Returns the count directly

>>> Task::create(['user_id' => 1, 'title' => 'Test task']); // Create records on the fly
=> App\Models\Task {#...                                 // Shows the newly created model
     id: 10,
     title: "Test task",
     completed: false,
   }

>>> exit                                                 // Type exit to leave tinker
```

When to use tinker:
- Test if a model relationship works
- Quickly create test data
- Debug a query that's returning unexpected results
- Check what `$request->user()` returns

### 14.5 Other Essential Commands

```bash
# Clear cached files (when things act weird)
php artisan optimize:clear          # Clears ALL caches
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

# Check current configuration
php artisan env                     # Current environment
php artisan config:show database    # Show resolved config

# Run tests
php artisan test
php artisan test --filter=TaskTest  # Run one test class

# List ALL available commands
php artisan list
```

The most important habit: When you don't know what command to use, run `php artisan list`. It shows every command available. When you don't know what a command does, add `--help`: `php artisan make:model --help`.

> **Next step**: [Section 15: Authentication](#15-how-to-build-authentication-full-implementation) — build login/register/logout. This is the first thing you build in any app.

---

## 15. How to Build Authentication (Full Implementation)

Authentication is the **first thing you build** in any app. This section walks through the complete Sanctum setup.

### 15.1 What is Sanctum?

Laravel Sanctum provides token-based authentication for APIs. Here's the flow:

```
1. User submits email + password to POST /api/v1/auth/login
   │
   ▼
2. Laravel checks credentials against the users table
   │
   ▼
3. If valid, Laravel generates a random token string
   e.g., "1|abcdef123456..."
   │
   ▼
4. Laravel returns the token to the frontend
   { "data": { "token": "1|abcdef..." } }
   │
   ▼
5. Frontend stores the token in localStorage
   │
   ▼
6. On every subsequent request, frontend sends:
   Authorization: Bearer 1|abcdef123456...
   │
   ▼
7. Laravel's auth:sanctum middleware validates the token
   → $request->user() returns the User model
   → If invalid, returns 401 Unauthorized
```

### 15.2 Install Sanctum

On Laravel 11+, Sanctum is included by default. To ensure it's installed:

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

### 15.3 Configure the User Model

Ensure your User model uses the `HasApiTokens` trait:

```php
// File: app/Models/User.php
// The User model needs the HasApiTokens trait to support Sanctum token auth.

use Illuminate\Foundation\Auth\User as Authenticatable; // Base class with auth capabilities (password hashing, etc.)
use Illuminate\Notifications\Notifiable;                // Trait that enables sending notifications to the user
use Laravel\Sanctum\HasApiTokens;                       // Trait that adds createToken(), tokens(), currentAccessToken()

class User extends Authenticatable
{
    // HasApiTokens: enables $user->createToken('name') and token validation
    // Notifiable: enables $user->notify(new SomeNotification())
    use HasApiTokens, Notifiable;

    // Columns that can be mass-assigned via User::create() or User::update()
    protected $fillable = ['name', 'email', 'password'];
    // Columns hidden from JSON serialization (never sent in API responses)
    protected $hidden = ['password', 'remember_token'];
    // Cast email_verified_at from string to Carbon datetime instance
    protected $casts = ['email_verified_at' => 'datetime'];
}
```

`HasApiTokens` adds these methods:
- `$user->createToken('name')` → generates a token
- `$user->tokens` → all the user's tokens
- `$user->currentAccessToken` → the token used for the current request
- `$user->tokenCan('ability')` → check if token has an ability/permission

### 15.4 The AuthController

```bash
php artisan make:controller AuthController
```

```php
<?php

// File: app/Http/Controllers/AuthController.php
// Handles user registration, login, logout, and fetching the current user.

namespace App\Http\Controllers;                         // Standard controller namespace

use App\Models\User;                                    // The User model for database operations
use Illuminate\Http\JsonResponse;                       // Return type for JSON responses
use Illuminate\Http\Request;                            // The incoming HTTP request object
use Illuminate\Http\Response;                           // HTTP status code constants (200, 201, etc.)
use Illuminate\Support\Facades\Auth;                    // Facade for authentication (attempt, check, user)
use Illuminate\Support\Facades\Hash;                    // Facade for password hashing (bcrypt)
use Illuminate\Validation\ValidationException;          // Exception thrown when validation fails

class AuthController extends Controller
{
    // POST /api/v1/auth/login
    // Validates credentials, creates a Sanctum token, and returns it to the frontend.
    public function login(Request $request): JsonResponse
    {
        // 1. Validate that email and password are present and properly formatted
        $credentials = $request->validate([
            'email' => ['required', 'email'],            // Must be a valid email format
            'password' => ['required', 'string'],        // Must be present (hashing handles length)
        ]);

        // 2. Attempt authentication — Auth::attempt() checks email+password against the users table
        //    It automatically hashes the password and compares with the stored hash
        if (! Auth::attempt($credentials)) {
            // Same error message whether email or password was wrong — don't reveal which
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // 3. Auth::attempt() logged the user in, but for API tokens we need the User model
        $user = User::where('email', $credentials['email'])->first();

        // 4. Generate a Sanctum personal access token.
        //    createToken() returns a NewAccessToken object; ->plainTextToken gives the raw string.
        //    'web' is just a name to identify this token (useful when listing user's tokens).
        $token = $user->createToken('web')->plainTextToken;

        // 5. Return the token and basic user info so the frontend can store them
        return response()->json([
            'data' => [
                'token' => $token,                       // The raw token string (only shown once!)
                'user' => [
                    'id' => $user->id,                   // User's database ID
                    'name' => $user->name,               // Display name
                    'email' => $user->email,             // Login email
                ],
            ],
        ]);
    }

    // POST /api/v1/auth/register
    // Creates a new user account, hashes the password, and returns a token.
    public function register(Request $request): JsonResponse
    {
        // Validate registration input — email must be unique, password must be confirmed
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],                // Display name
            'email' => ['required', 'email', 'unique:users,email'],     // Must not already exist
            'password' => ['required', 'string', 'min:8', 'confirmed'], // min 8 chars + password_confirmation field
        ]);

        // Create the user — Hash::make() bcrypts the password before storing
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),                // Never store plain-text passwords
        ]);

        // Immediately generate a token so the user is logged in after registering
        $token = $user->createToken('web')->plainTextToken;

        // Return 201 Created with the token and user data
        return response()->json([
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ],
        ], Response::HTTP_CREATED);                                     // 201 status code
    }

    // POST /api/v1/auth/logout
    // Revokes ONLY the current token (other devices/sessions stay logged in).
    public function logout(Request $request): JsonResponse
    {
        // currentAccessToken() returns the Sanctum token model for THIS request.
        // delete() removes it from the personal_access_tokens table.
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    // GET /api/v1/auth/me
    // Returns the currently authenticated user's info.
    // Used by the frontend on page refresh to restore the session.
    public function me(Request $request): JsonResponse
    {
        // $request->user() returns the User model identified by the Bearer token
        $user = $request->user();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }
}
```

### 15.5 Auth Routes

```php
// File: routes/api.php
// Defines all API routes. Public auth routes don't need a token.
// All other routes are protected by auth:sanctum middleware.

use App\Http\Controllers\AuthController;                // Handles login/register/logout/me
use App\Http\Controllers\TaskController;                // Handles task CRUD operations
use Illuminate\Support\Facades\Route;                   // Facade for defining routes

// Public auth routes (no token needed) — login and register are open to everyone
Route::prefix('v1/auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);       // POST /api/v1/auth/login
    Route::post('register', [AuthController::class, 'register']); // POST /api/v1/auth/register
});

// Protected routes (require a valid Sanctum Bearer token in the Authorization header)
Route::middleware('auth:sanctum')->prefix('v1')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']); // POST /api/v1/auth/logout
    Route::get('auth/me', [AuthController::class, 'me']);          // GET  /api/v1/auth/me

    // Explicit task routes keep the beginner example aligned with the custom
    // complete/incomplete actions defined in TaskController.
    Route::get('tasks', [TaskController::class, 'index']);
    Route::post('tasks', [TaskController::class, 'store']);
    Route::get('tasks/{task}', [TaskController::class, 'show']);
    Route::patch('tasks/{task}/complete', [TaskController::class, 'complete']);
    Route::patch('tasks/{task}/incomplete', [TaskController::class, 'incomplete']);
    Route::delete('tasks/{task}', [TaskController::class, 'destroy']);
});
```

Note: `Route::apiResource(...)` is useful for standard CRUD, but this tutorial uses explicit routes because the Task Manager has custom `complete` and `incomplete` actions.

### 15.6 Test with curl

After setting this up, test it works before building the frontend:

```bash
# 1. Register a user
curl -X POST http://localhost:8000/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"name":"Test User","email":"test@test.com","password":"password123","password_confirmation":"password123"}'

# Response: {"data":{"token":"2|abcdef...","user":{...}}}

# 2. Use the token to access protected route
curl http://localhost:8000/api/v1/auth/me \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"

# Response: {"data":{"id":1,"name":"Test User","email":"test@test.com"}}

# 3. Try without token (should get 401)
curl http://localhost:8000/api/v1/auth/me \
  -H "Accept: application/json"

# Response: {"message":"Unauthenticated."}
```

Critical habit: Always test backend with `curl` BEFORE building the frontend. If the backend is broken, the frontend errors will be confusing and misleading.

> **Next step**: Now build the Task Manager backend. Go to [Section 6: Laravel Backend Example](#6-laravel-backend-example-standard-task-manager) and follow steps 6.1 through 6.7 to create the migration, model, form request, resource, service, controller, and routes. Test with `curl` before continuing.

---

## 16. How to Build the Missing Frontend Components

Section 7 referenced `TaskForm` and `TaskItem` but never showed them. Here's the complete code.

### 16.1 TaskItem Component

```tsx
// File: src/features/tasks/components/task-item.tsx
// Displays a single task with checkbox toggle, description, due date, and delete button.

"use client";                                           // Client Component — uses hooks and event handlers

import { useCompleteTask, useDeleteTask, useIncompleteTask } from "../hooks/use-tasks"; // Mutation hooks
import type { Task } from "../types/task";                // TypeScript type for the task prop

// Props interface: this component receives a single Task object from its parent
interface TaskItemProps {
  task: Task;                                            // The task to display
}

export function TaskItem({ task }: TaskItemProps) {
  // Initialize mutation hooks — each provides .mutate() to trigger the API call
  // and .isPending to show loading states while the request is in flight
  const completeMut = useCompleteTask();                 // Hook to mark task as done
  const incompleteMut = useIncompleteTask();             // Hook to mark task as not done
  const deleteMut = useDeleteTask();                     // Hook to delete the task

  // Toggle between complete and incomplete based on current state
  function handleToggle() {
    if (task.completed) {
      incompleteMut.mutate(task.id);                     // If currently done, mark as not done
    } else {
      completeMut.mutate(task.id);                       // If not done, mark as done
    }
  }

  // Delete with a browser confirmation dialog to prevent accidental deletion
  function handleDelete() {
    if (confirm("Delete this task?")) {                  // Native browser confirm dialog
      deleteMut.mutate(task.id);                         // Trigger the delete API call
    }
  }

  return (
    // Flex row: checkbox | content (flex-1) | delete button
    <div className="flex items-center gap-3 rounded-lg border p-3">
      <input
        type="checkbox"                                  // Native checkbox input
        checked={task.completed}                          // Controlled: reflects the task's completed state
        onChange={handleToggle}                           // Calls handleToggle when clicked
        disabled={completeMut.isPending || incompleteMut.isPending} // Disable while API call is in flight
        className="h-4 w-4"                               // Fixed size: 1rem × 1rem
      />

      {/* flex-1 takes remaining space, space-y-1 adds vertical gaps */}
      <div className="flex-1 space-y-1">
        <p className={task.completed ? "text-muted-foreground line-through" : ""}>
          {/* Strike-through and muted color when completed */}
          {task.title}
        </p>
        {task.description && (                            // Only render if description exists (not null/empty)
          <p className="text-sm text-muted-foreground">{task.description}</p>
        )}
        {task.due_at && (                                 // Only render if a due date is set
          <p className="text-xs text-muted-foreground">
            Due: {new Date(task.due_at).toLocaleDateString()} {/* Convert ISO string to human-readable date */}
          </p>
        )}
      </div>

      <button
        onClick={handleDelete}                            // Triggers confirmation + delete
        disabled={deleteMut.isPending}                    // Disable while delete is in progress
        className="text-red-600 hover:text-red-800 disabled:opacity-50" // Red text, darker on hover, faded when disabled
      >
        Delete
      </button>
    </div>
  );
}
```

### 16.2 TaskForm Component

```tsx
// File: src/features/tasks/components/task-form.tsx
// A form to create new tasks. Uses React Hook Form for state management
// and Zod for client-side validation before the API call.

"use client";                                           // Client Component — uses hooks, forms, and state

import { useState } from "react";                       // For toggling the form open/closed
import { useForm } from "react-hook-form";              // Form state management library
import { zodResolver } from "@hookform/resolvers/zod";  // Connects Zod schemas to React Hook Form
import { z } from "zod";                                // Schema validation library
import { useCreateTask } from "../hooks/use-tasks";     // Mutation hook for creating tasks
import type { TaskInput } from "../types/task";          // TypeScript type for the API payload

// Define the validation schema using Zod.
// This runs on the CLIENT before the API call — for UX, not security.
const schema = z.object({
  title: z.string().min(1, "Title is required"),        // Must be a non-empty string
  description: z.string().optional(),                   // Optional — can be empty or omitted
  due_at: z.string().optional(),                        // Optional date string
});

// Infer the TypeScript type from the Zod schema — keeps types and validation in sync
type FormValues = z.infer<typeof schema>;               // = { title: string; description?: string; due_at?: string }

export function TaskForm() {
  const createMut = useCreateTask();                    // Mutation hook — provides .mutateAsync() and .isPending
  const [isOpen, setIsOpen] = useState(false);          // Controls whether the form is visible

  // Initialize React Hook Form with Zod validation and default empty values
  const {
    register,       // Returns props (onChange, onBlur, name, ref) to spread on each input
    handleSubmit,   // Wraps onSubmit — validates first, then calls your function
    reset,          // Resets all form fields to their default values
    formState: { errors }, // Contains validation error messages keyed by field name
  } = useForm<FormValues>({
    resolver: zodResolver(schema),                      // Use Zod for validation instead of RHF's built-in
    defaultValues: {
      title: "",                                        // Start with empty title
      description: "",                                  // Start with empty description
      due_at: "",                                       // Start with no due date
    },
  });

  // Called by handleSubmit() AFTER Zod validation passes
  async function onSubmit(values: FormValues) {
    // Convert form values to the API's expected TaskInput shape
    const input: TaskInput = {
      title: values.title,
      description: values.description || undefined,      // Convert empty string to undefined
      due_at: values.due_at || undefined,                // Convert empty string to undefined
    };

    try {
      // mutateAsync() returns a Promise — we await it so we can reset on success
      await createMut.mutateAsync(input);
      reset();                                          // Clear all form fields after successful creation
      setIsOpen(false);                                 // Hide the form
    } catch {
      alert("Failed to create task");                   // Show error if the API call fails
    }
  }

  // When the form is closed, show an "Add Task" button to open it
  if (!isOpen) {
    return (
      <button
        onClick={() => setIsOpen(true)}                 // Open the form on click
        className="rounded-lg bg-blue-600 px-4 py-2 text-white hover:bg-blue-700"
      >
        Add Task
      </button>
    );
  }

  // The open form with inputs for title, description, and due date
  return (
    // handleSubmit(onSubmit) validates with Zod, then calls onSubmit if valid
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4 rounded-lg border p-4">
      <div className="space-y-2">
        {/* register("title") connects this input to RHF — it tracks value, validation, etc. */}
        <input
          {...register("title")}                        // Spreads onChange, onBlur, name, ref onto the input
          placeholder="What needs to be done?"
          autoFocus                                     // Automatically focus this input when the form opens
          className="w-full rounded-md border px-3 py-2"
        />
        {/* Show validation error if title is empty */}
        {errors.title && (
          <p className="text-sm text-red-600">{errors.title.message}</p>
        )}
      </div>

      <div className="space-y-2">
        <textarea
          {...register("description")}                  // Connect to RHF for tracking
          placeholder="Description (optional)"
          rows={2}                                      // Show 2 rows by default
          className="w-full rounded-md border px-3 py-2"
        />
      </div>

      <div className="space-y-2">
        <input
          type="date"                                   // Native date picker
          {...register("due_at")}                       // Connect to RHF
          min={new Date().toISOString().split("T")[0]}  // Prevent selecting past dates (today's date as minimum)
          className="w-full rounded-md border px-3 py-2"
        />
      </div>

      {/* Horizontal row of buttons with gap */}
      <div className="flex gap-2">
        <button
          type="submit"                                 // Triggers form validation + onSubmit
          disabled={createMut.isPending}                // Disable while the API call is in flight
          className="rounded-lg bg-blue-600 px-4 py-2 text-white hover:bg-blue-700 disabled:opacity-50"
        >
          {createMut.isPending ? "Creating..." : "Create Task"} {/* Show loading text while pending */}
        </button>
        <button
          type="button"                                 // type="button" prevents form submission
          onClick={() => {
            reset();                                    // Clear form fields
            setIsOpen(false);                           // Close the form
          }}
          className="rounded-lg border px-4 py-2 hover:bg-gray-50"
        >
          Cancel
        </button>
      </div>
    </form>
  );
}
```

### 16.3 Full Auth Flow (Frontend)

Here's how to build login, protected routes, and logout on the frontend.

**Auth API Functions:**

```tsx
// File: src/core/api/auth.ts
// API functions for authentication — login, register, and fetching the current user.
// These wrap Axios calls so components don't deal with HTTP details directly.

import { apiClient } from "@/core/api-client";          // Our shared Axios instance with token interceptor

// Shape of the login/register response from the Laravel API
export interface LoginResponse {
  token: string;                                        // The Sanctum Bearer token
  user: { id: number; name: string; email: string };   // Basic user info
}

// POST /api/v1/auth/login — sends credentials, receives token + user
export async function login(email: string, password: string): Promise<LoginResponse> {
  const res = await apiClient.post("/auth/login", { email, password }); // Send email+password as JSON body
  return res.data.data;                                 // Unwrap the { data: { token, user } } envelope
}

// POST /api/v1/auth/register — creates account, receives token + user
export async function register(name: string, email: string, password: string): Promise<LoginResponse> {
  const res = await apiClient.post("/auth/register", {
    name,                                               // Display name
    email,                                              // Login email
    password,                                           // Plain-text password (Laravel hashes it)
    password_confirmation: password,                    // Laravel's "confirmed" rule requires this matching field
  });
  return res.data.data;                                 // Unwrap the envelope
}

// GET /api/v1/auth/me — fetch the current user using the stored token.
// Called on app load to restore the session after a page refresh.
export async function getMe(): Promise<{ id: number; name: string; email: string }> {
  const res = await apiClient.get("/auth/me");          // Token is auto-attached by the Axios interceptor
  return res.data.data;                                 // Unwrap the envelope
}

// POST /api/v1/auth/logout — revoke the current token on the backend.
export async function logout(): Promise<void> {
  await apiClient.post("/auth/logout");
}
```

**Auth Context:**

```tsx
// File: src/core/auth-context.tsx
// React Context that manages authentication state globally.
// Provides user data, login/logout functions, and loading state to all components.

"use client";                                           // Client Component — uses hooks and localStorage

import { createContext, useContext, useState, useEffect, ReactNode } from "react"; // React hooks
import { useRouter } from "next/navigation";             // Next.js programmatic navigation
import { login as loginApi, getMe, logout as logoutApi } from "@/core/api/auth"; // Our auth API functions

// Shape of the user object stored in context
interface User {
  id: number;
  name: string;
  email: string;
}

// The shape of values this context provides to consumers
interface AuthContextValue {
  user: User | null;                                    // The logged-in user, or null if logged out
  isLoading: boolean;                                   // True while checking if a stored token is still valid
  login: (email: string, password: string) => Promise<void>; // Function to log in
  logout: () => Promise<void>;                          // Function to log out
}

// Create the context with a null default (consumers must be inside the Provider)
const AuthContext = createContext<AuthContextValue | null>(null);

// The Provider component — wrap this around your app in layout.tsx
export function AuthProvider({ children }: { children: ReactNode }) {
  const router = useRouter();                           // For redirecting after login/logout
  const [user, setUser] = useState<User | null>(null);  // The current user (null = logged out)
  const [isLoading, setIsLoading] = useState(true);     // True until we've checked for an existing token

  // On mount: check if a token exists in localStorage and try to restore the session
  useEffect(() => {
    const token = localStorage.getItem("token");         // Read the stored token
    if (!token) {
      setIsLoading(false);                              // No token → not logged in, stop loading
      return;
    }

    // Token exists → verify it's still valid by calling GET /api/v1/auth/me
    getMe()
      .then(setUser)                                    // On success: store the user in state
      .catch(() => {
        localStorage.removeItem("token");                // On failure: token is invalid, remove it
      })
      .finally(() => setIsLoading(false));               // Either way, we're done loading
  }, []);                                               // Empty dependency array = run once on mount

  // Login function: calls the API, stores the token, and redirects to /tasks
  async function handleLogin(email: string, password: string) {
    const { token, user: userData } = await loginApi(email, password); // Call POST /auth/login
    localStorage.setItem("token", token);                // Save the token for future requests
    setUser(userData);                                   // Store user info in React state
    router.push("/tasks");                               // Navigate to the tasks page
  }

  // Logout function: revokes the token on the backend, then clears local state
  async function handleLogout() {
    try {
      await logoutApi();                                 // Delete current token server-side
    } finally {
      localStorage.removeItem("token");                  // Remove the stored token locally
      setUser(null);                                     // Clear user from React state
      router.push("/login");                             // Navigate to the login page
    }
  }

  return (
    // Provide user, loading state, login, and logout to all child components
    <AuthContext.Provider value={{ user, isLoading, login: handleLogin, logout: handleLogout }}>
      {children}
    </AuthContext.Provider>
  );
}

// Custom hook to consume the auth context from any component
export function useAuth() {
  const ctx = useContext(AuthContext);                   // Read the context value
  if (!ctx) throw new Error("useAuth must be used within AuthProvider"); // Guard against misuse
  return ctx;
}
```

**Login Page:**

```tsx
// File: src/app/login/page.tsx
// The login page with email/password form, client-side validation, and error display.

"use client";                                           // Client Component — uses hooks and form state

import { useState } from "react";                       // For tracking server-side error messages
import { useForm } from "react-hook-form";              // Form state management
import { zodResolver } from "@hookform/resolvers/zod";  // Connects Zod to React Hook Form
import { z } from "zod";                                // Schema validation
import { useAuth } from "@/core/auth-context";          // Access the login function from auth context

// Zod schema: defines what valid login input looks like
const schema = z.object({
  email: z.string().email("Enter a valid email"),       // Must be a valid email format
  password: z.string().min(1, "Password is required"),  // Must not be empty
});

// Infer the TypeScript type from the Zod schema
type FormValues = z.infer<typeof schema>;               // = { email: string; password: string }

export default function LoginPage() {
  const { login } = useAuth();                          // Get the login function from context
  const [serverError, setServerError] = useState("");   // Stores error message from the API (e.g., "Invalid credentials")

  // Set up React Hook Form with Zod validation
  const {
    register,                                           // Returns props to spread on inputs
    handleSubmit,                                       // Validates then calls onSubmit
    formState: { errors, isSubmitting },                // Validation errors and loading state
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  // Called after Zod validation passes
  async function onSubmit(values: FormValues) {
    setServerError("");                                 // Clear any previous server error
    try {
      await login(values.email, values.password);       // Call the auth context's login function
      // On success, login() redirects to /tasks automatically
    } catch {
      setServerError("Invalid credentials");            // Show error if login fails
    }
  }

  return (
    // Center the form vertically and horizontally on the page
    <div className="flex min-h-screen items-center justify-center">
      <form onSubmit={handleSubmit(onSubmit)} className="w-full max-w-sm space-y-4 p-6">
        {/* Page heading */}
        <h1 className="text-2xl font-bold">Log In</h1>

        {/* Show server-side error banner if login failed */}
        {serverError && (
          <div className="rounded-lg bg-red-100 p-3 text-sm text-red-800">
            {serverError}
          </div>
        )}

        {/* Email input field */}
        <div className="space-y-2">
          <input
            type="email"                                // Triggers email keyboard on mobile
            placeholder="Email"
            {...register("email")}                      // Connect to RHF for validation tracking
            className="w-full rounded-md border px-3 py-2"
          />
          {/* Show Zod validation error for email */}
          {errors.email && <p className="text-sm text-red-600">{errors.email.message}</p>}
        </div>

        {/* Password input field */}
        <div className="space-y-2">
          <input
            type="password"                             // Masks input characters
            placeholder="Password"
            {...register("password")}                   // Connect to RHF
            className="w-full rounded-md border px-3 py-2"
          />
          {/* Show Zod validation error for password */}
          {errors.password && <p className="text-sm text-red-600">{errors.password.message}</p>}
        </div>

        {/* Submit button — disabled while the login request is in flight */}
        <button
          type="submit"
          disabled={isSubmitting}                       // RHF sets this true during onSubmit execution
          className="w-full rounded-lg bg-blue-600 px-4 py-2 text-white hover:bg-blue-700 disabled:opacity-50"
        >
          {/* Show loading text while submitting */}
          {isSubmitting ? "Logging in..." : "Log In"}
        </button>
      </form>
    </div>
  );
}
```

**Protected Layout:**

```tsx
// File: src/app/(dashboard)/layout.tsx
// A protected layout that redirects unauthenticated users to /login.
// All pages inside the (dashboard) group share this layout.

"use client";                                           // Client Component — uses hooks and navigation

import { useEffect } from "react";                      // For running the redirect check after render
import { useRouter } from "next/navigation";             // For programmatic redirects
import { useAuth } from "@/core/auth-context";          // Access user state and loading flag

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  const { user, isLoading } = useAuth();                // Get current user and loading state from context
  const router = useRouter();                           // Next.js router for redirects

  // After the auth check finishes, redirect to /login if no user is found
  useEffect(() => {
    if (!isLoading && !user) {                          // Not loading AND no user = not authenticated
      router.replace("/login");                         // replace() doesn't add to browser history (can't go back)
    }
  }, [isLoading, user, router]);                        // Re-run when any of these values change

  // While checking the token, show a loading screen to prevent flash of protected content
  if (isLoading) {
    return <div className="flex h-screen items-center justify-center">Loading...</div>;
  }

  // If no user (and not loading), return null while the redirect happens
  if (!user) return null;

  // Authenticated: render the dashboard shell with the page content inside.
  // The outer div is the full-height flex container.
  return (
    <div className="flex h-screen">
      {/* Scrollable content area with padding */}
      <main className="flex-1 overflow-auto p-6">{children}</main>
    </div>
  );
}
```

> **Next step**: Your Task Manager is now complete end-to-end! Go to [Section 19: Tailwind CSS](#19-tailwind-css--how-to-style-without-writing-css) to style it, [Section 20: Forms](#20-react-hook-form--zod--how-to-build-forms-properly) to improve your forms, and [Section 22: Debugging](#22-how-to-read-errors-and-debug-like-a-senior-engineer) for when things break.

---

## 17. Factories, Seeders, and Tinker — How to Create Test Data

You need test data to develop. Factories generate fake data, seeders populate the database, and tinker lets you test interactively.

### 17.1 Factories — Generating Fake Data

```bash
php artisan make:factory TaskFactory
```

```php
<?php

// File: database/factories/TaskFactory.php
// Factories generate fake/seed data for development and testing.
// Each method returns an array of column values using Faker for randomness.

namespace Database\Factories;                         // All factories live in this namespace

use App\Models\Task;                                    // The model this factory creates
use App\Models\User;                                    // Needed to auto-create related users
use Illuminate\Database\Eloquent\Factories\Factory;     // Base factory class

class TaskFactory extends Factory
{
    // Tell the factory which model it creates instances of
    protected $model = Task::class;

    // Returns the default set of attributes for creating a Task.
    // fake() is Laravel's helper for the Faker library — generates random but realistic data.
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),               // Auto-create a User if one isn't provided
            'title' => fake()->sentence(4),              // Random 4-word sentence: "Buy milk from store"
            'description' => fake()->paragraph(),        // Random paragraph of lorem ipsum text
            'completed' => fake()->boolean(30),          // 30% chance of being true (most tasks are pending)
            'due_at' => fake()->dateTimeBetween('now', '+1 month'), // Random date between now and 1 month from now
        ];
    }

    // State: a named variation of the factory.
    // Call Task::factory()->completed()->create() to use this state.
    public function completed(): static
    {
        // Override the default attributes: force completed = true and set completed_at to now
        return $this->state(fn(array $attributes) => [
            'completed' => true,
            'completed_at' => now(),
        ]);
    }

    // Another state: explicitly mark as pending (not completed)
    public function pending(): static
    {
        return $this->state(fn(array $attributes) => [
            'completed' => false,
            'completed_at' => null,                     // No completion date for pending tasks
        ]);
    }
}
```

Using in tinker or tests:

```php
// Using factories in tinker or tests:
Task::factory()->count(10)->create();                   // Create 10 tasks with random data (each gets a random user)
Task::factory()->count(5)->completed()->create();        // Create 5 tasks using the "completed" state (all marked done)
Task::factory()->count(3)->pending()->create();          // Create 3 tasks using the "pending" state (all incomplete)
```

### 17.2 Seeders — Populating the Database

```bash
php artisan make:seeder TaskSeeder
```

```php
<?php

// File: database/seeders/TaskSeeder.php
// Seeders populate the database with test/demo data using factories.
// Run with: php artisan db:seed --class=TaskSeeder

namespace Database\Seeders;                           // All seeders live in this namespace

use App\Models\Task;                                    // The Task model
use App\Models\User;                                    // The User model
use Illuminate\Database\Seeder;                         // Base seeder class

class TaskSeeder extends Seeder
{
    // Called when the seeder runs — this is where you create your test data
    public function run(): void
    {
        // Create 3 users, each with 10 tasks.
        // ->has() creates the relationship: each user gets 10 tasks via the Task factory.
        User::factory()
            ->count(3)                                  // Create 3 users
            ->has(Task::factory()->count(10))            // Each user gets 10 tasks (30 tasks total)
            ->create();                                 // Execute the creation

        // Or manually: create a specific user with known data for demo purposes
        $user = User::factory()->create([
            'name' => 'Demo User',                      // Override the random name
            'email' => 'demo@test.com',                 // Override the random email (useful for login testing)
        ]);

        // Create 5 tasks specifically for this demo user
        Task::factory()->count(5)->create(['user_id' => $user->id]); // Override user_id to link to our demo user
    }
}
```

Wire it into the main seeder:

```php
// File: database/seeders/DatabaseSeeder.php
// The main seeder that calls all other seeders in order.
// Run with: php artisan db:seed (or php artisan migrate:fresh --seed)

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // $this->call() runs seeders in the order listed.
        // Add more seeders here as your app grows.
        $this->call([
            TaskSeeder::class,                          // Runs TaskSeeder::run() to populate tasks
        ]);
    }
}
```

Run it:

```bash
php artisan db:seed                 # Run all seeders
php artisan db:seed --class=TaskSeeder  # Run one seeder
php artisan migrate:fresh --seed     # Reset DB + migrate + seed (clean slate)
```

### 17.3 Tinker — Interactive Testing

```bash
php artisan tinker
```

```php
// Tinker examples for testing data and queries interactively:

// Create test data using factories
>>> User::factory()->create(['email' => 'test@test.com']);   // Create a user with a specific email
>>> Task::factory()->count(5)->create(['user_id' => 1]);     // Create 5 tasks for user #1

// Test queries to verify your data
>>> Task::where('user_id', 1)->count();                      // How many tasks does user 1 have?
=> 5

>>> Task::whereBelongsTo(User::first())->get();              // Get all tasks for the first user
=> Illuminate\Database\Eloquent\Collection {#...}

// Test relationships work correctly
>>> $user = User::first();                                   // Fetch a user
>>> $user->tasks;                                            // Access the hasMany relationship
=> Illuminate\Database\Eloquent\Collection {#...}            // Should return the related tasks

>>> exit                                                     // Leave tinker
```

---

## 18. Eloquent Beyond Basics — How to Query Effectively

The guide showed basic model CRUD. To become proficient, you need to know more.

### 18.1 Model Scopes — Reusable Query Filters

Scopes are pre-defined query constraints you can reuse.

```php
// File: app/Models/Task.php
// Scopes are reusable query constraints. Define them once, call them anywhere.

class Task extends Model
{
    // Local scope: call as Task::pending()->get()
    // The $query parameter is injected automatically by Eloquent — it's the query builder.
    public function scopePending($query)
    {
        return $query->where('completed', false);       // Adds WHERE completed = false to the query
    }

    // Another local scope: Task::completed()->get()
    public function scopeCompleted($query)
    {
        return $query->where('completed', true);        // Adds WHERE completed = true
    }

    // Scope with multiple conditions: Task::overdue()->get()
    public function scopeOverdue($query)
    {
        return $query->where('completed', false)        // Not yet completed AND
            ->where('due_at', '<', now());              // Due date is in the past
    }

    // Dynamic scope: accepts a parameter — Task::dueWithin(7)->get()
    // The $days parameter is passed when calling the scope
    public function scopeDueWithin($query, $days)
    {
        return $query->where('due_at', '<=', now()->addDays($days)); // Due within N days from now
    }
}
```

Using scopes:

```php
// Using scopes — chain them like any other query method:
Task::pending()->get();                                  // All incomplete tasks (uses scopePending)
Task::completed()->count();                              // Count of completed tasks (uses scopeCompleted)
Task::overdue()->get();                                  // All overdue tasks (uses scopeOverdue)
Task::dueWithin(7)->get();                               // Tasks due within the next 7 days (uses scopeDueWithin)
Task::pending()->dueWithin(3)->get();                    // Scopes are chainable! Pending AND due within 3 days
```

### 18.2 Accessors & Mutators — Computed/Transformed Attributes

Accessors transform attribute values when you **read** them. Mutators transform when you **write**.

```php
// File: app/Models/Task.php
// Accessors transform values when READ from the model.
// Mutators transform values when WRITTEN to the model.

class Task extends Model
{
    // Accessor: accessed as $task->title_upper (property syntax, not method call).
    // Laravel auto-converts getTitleUpperAttribute → title_upper.
    // This value is NOT stored in the database — it's computed on the fly.
    public function getTitleUpperAttribute(): string
    {
        return strtoupper($this->title);                // Convert the stored title to UPPERCASE
    }

    // Mutator: runs automatically when you set $task->title = "some value".
    // ucfirst() capitalizes the first letter before saving to the database.
    public function setTitleAttribute($value): void
    {
        $this->attributes['title'] = ucfirst($value);   // "buy milk" → "Buy milk" in the DB
    }

    // Computed attribute: $task->is_overdue returns a boolean.
    // Combines multiple columns to derive a new value that isn't stored.
    public function getIsOverdueAttribute(): bool
    {
        return ! $this->completed                       // Task is NOT completed AND
            && $this->due_at                            // A due date IS set AND
            && $this->due_at->isPast();                 // The due date has already passed
    }
}
```

Using:

```php
// Using accessors and mutators:
$task = Task::first();                                   // Fetch the first task from the database
$task->title_upper;                                      // "BUY MILK" — accessed as a property, not a method call
$task->is_overdue;                                       // true/false — computed from completed + due_at

$task->title = 'buy milk';                               // The mutator auto-capitalizes: stores "Buy milk" in DB
```

### 18.3 Querying Patterns Cheat Sheet

```php
// Eloquent Querying Patterns Cheat Sheet
// Common query patterns you'll use daily in Laravel.

// Where clauses — filter rows by conditions
Task::where('completed', false)->get();                 // WHERE completed = false
Task::where('user_id', $user->id)->get();               // WHERE user_id = specific user
Task::where('title', 'like', '%milk%')->get();           // SQL LIKE: title contains "milk"
Task::whereNotNull('due_at')->get();                     // WHERE due_at IS NOT NULL (has a deadline)
Task::whereIn('id', [1, 2, 3])->get();                   // WHERE id IN (1, 2, 3)
Task::whereBelongsTo($user)->get();                      // Laravel shorthand: WHERE user_id = $user->id

// Ordering — control the sort direction of results
Task::orderBy('created_at', 'desc')->get();              // Newest first
Task::latest()->get();                                   // Shorthand for orderBy('created_at', 'desc')
Task::oldest()->get();                                   // Shorthand for orderBy('created_at', 'asc')

// Limiting — control how many rows are returned
Task::take(5)->get();                                    // Return only the first 5 results
Task::paginate(15);                                      // Paginate: 15 per page, returns LengthAwarePaginator

// Aggregates — compute values across rows without loading them
Task::count();                                           // SELECT COUNT(*) FROM tasks
Task::where('completed', true)->count();                 // Count only completed tasks
Task::max('due_at');                                     // Latest due date across all tasks

// First or create — find an existing record or create one if it doesn't exist
Task::firstOrCreate(
    ['title' => 'Buy milk', 'user_id' => 1],             // Search criteria: look for this combination
    ['description' => 'From store']                       // If creating, also set these additional fields
);

// Update or create — find and update, or create if not found
Task::updateOrCreate(
    ['title' => 'Buy milk'],                             // Search criteria
    ['completed' => true]                                // Fields to update (or set on creation)
);

// Chunking — process large datasets in small batches to save memory
Task::chunk(100, function($tasks) {                      // Load 100 rows at a time into memory
    foreach ($tasks as $task) {
        // Process each task — only 100 are in memory at once, not all thousands
    }
});
```

### 18.4 The N+1 Problem (CRITICAL)

The N+1 problem is the **most common performance issue** in Eloquent. Learn to spot it.

```php
// The N+1 Problem — the most common Eloquent performance mistake.
// It happens when you access a relationship inside a loop, causing one extra
// query PER ROW instead of loading all related data in one query.

// BAD: N+1 PROBLEM — This runs 1 + N queries
$tasks = Task::all();                                   // Query 1: SELECT * FROM tasks (returns 100 rows)
foreach ($tasks as $task) {
    echo $task->user->name;                             // Query 2-101: SELECT * FROM users WHERE id = ? (one per task!)
}
// Total: 1 (tasks) + 100 (users) = 101 queries!

// GOOD: EAGER LOADING — This runs only 2 queries total
$tasks = Task::with('user')->get();                     // Query 1: SELECT * FROM tasks
                                                        // Query 2: SELECT * FROM users WHERE id IN (1, 2, 3, ...)
// Now $task->user is already loaded — no extra queries in the loop
foreach ($tasks as $task) {
    echo $task->user->name;                             // No query! Data is already in memory
}
// Total: 2 queries regardless of how many tasks

// NESTED eager loading — load relationships of relationships in one go
$tasks = Task::with('user.department')->get();           // Loads tasks → users → departments (3 queries total)

// Conditional eager loading — only load specific columns to reduce data transfer
$tasks = Task::with(['user' => function($q) {
    $q->select('id', 'name');                           // Only fetch id and name, skip email/password/etc.
}])->get();

// Count related records WITHOUT loading them into memory
$tasks = Task::withCount('comments')->get();             // Adds a comments_count attribute to each task
// Each $task now has $task->comments_count without loading any Comment models
```

How to detect N+1: If your app feels slow and you see many similar queries in the Laravel log, it's probably N+1. Use the [Laravel Debugbar](https://github.com/barryvdh/laravel-debugbar) package to see query counts.

---

## 19. Tailwind CSS — How to Style Without Writing CSS

Tailwind CSS is utility-first CSS. Instead of writing CSS files, you compose styles using class names.

### 19.1 Basic Usage

```tsx
// Instead of writing CSS like this:
// .card { padding: 1rem; border-radius: 0.5rem; background: white; }

// You write Tailwind classes directly:
<div className="p-4 rounded-lg bg-white">
  <h1 className="text-2xl font-bold text-gray-900">Title</h1>
  <p className="text-sm text-gray-500 mt-2">Description</p>
</div>
```

### 19.2 Common Tailwind Utilities

| Category | Examples |
|----------|----------|
| Spacing | `p-4` (padding), `m-2` (margin), `gap-4` (gap between items) |
| Flexbox | `flex`, `flex-row`, `items-center`, `justify-between` |
| Grid | `grid`, `grid-cols-3`, `gap-4` |
| Typography | `text-sm`, `text-lg`, `font-bold`, `text-gray-500` |
| Colors | `bg-white`, `text-blue-600`, `border-gray-200` |
| Sizing | `w-full`, `h-10`, `max-w-2xl` |
| Borders | `rounded-lg`, `border`, `border-2` |
| Responsive | `md:flex`, `lg:grid-cols-3` (prefix = breakpoint) |

### 19.3 Responsive Design

Tailwind uses mobile-first breakpoints:

```tsx
<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3">
  {/* 1 column on mobile, 2 on tablet, 3 on desktop */}
</div>
```

Breakpoints:
- `sm:` = 640px+
- `md:` = 768px+
- `lg:` = 1024px+
- `xl:` = 1280px+

### 19.4 Common Patterns

```tsx
// Centered container
<div className="mx-auto max-w-2xl p-6">

// Flex row with space between
<div className="flex items-center justify-between">

// Card
<div className="rounded-lg border bg-white p-4 shadow-sm">

// Button
<button className="rounded-lg bg-blue-600 px-4 py-2 text-white hover:bg-blue-700">

// Input
<input className="w-full rounded-md border px-3 py-2 focus:border-blue-500 focus:outline-none" />

// Error text
<p className="text-sm text-red-600">

// Muted text
<p className="text-sm text-gray-500">
```

---

## 20. React Hook Form + Zod — How to Build Forms Properly

React Hook Form handles form state. Zod validates the data. Together they make forms painless.

### 20.1 Basic Form Setup

```tsx
"use client";                                           // Client Component — uses hooks and browser APIs

import { useForm } from "react-hook-form";              // Form state management library
import { zodResolver } from "@hookform/resolvers/zod";  // Bridges Zod schemas with React Hook Form
import { z } from "zod";                                // Schema validation library

// Step 1: Define the schema — what valid form data looks like
const schema = z.object({
  title: z.string().min(1, "Title is required"),        // Must be non-empty string
  description: z.string().optional(),                   // Optional field
  due_at: z.string().optional(),                        // Optional date string
});

// Step 2: Infer the TypeScript type from the schema — keeps types and validation in sync
type FormValues = z.infer<typeof schema>;               // = { title: string; description?: string; due_at?: string }

export function MyForm() {
  // Step 3: Initialize the form with Zod validation and default values
  const {
    register,       // Returns props to spread on each input (onChange, onBlur, name, ref)
    handleSubmit,   // Wraps your submit handler — validates first, then calls it
    formState: { errors, isSubmitting }, // errors = validation messages, isSubmitting = loading state
  } = useForm<FormValues>({
    resolver: zodResolver(schema),                      // Use Zod for validation instead of RHF's built-in rules
    defaultValues: {
      title: "",                                        // Start with empty fields
      description: "",
      due_at: "",
    },
  });

  // Step 4: Handle submission — called only AFTER validation passes
  async function onSubmit(values: FormValues) {
    console.log(values);                                // Inspect the validated data
    // Call your API here (e.g., await createTask(values))
  }

  return (
    // handleSubmit(onSubmit) validates with Zod, then calls onSubmit if valid
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
      <div className="space-y-2">
        <input
          {...register("title")}                        // Connects this input to RHF — tracks value and validation
          placeholder="Title"
          className="w-full rounded-md border px-3 py-2"
        />
        {/* Show validation error if title is empty */}
        {errors.title && (
          <p className="text-sm text-red-600">{errors.title.message}</p>
        )}
      </div>

      <div className="space-y-2">
        <textarea
          {...register("description")}                  // Connect to RHF
          placeholder="Description"
          rows={3}                                      // Show 3 visible rows
          className="w-full rounded-md border px-3 py-2"
        />
      </div>

      <button
        type="submit"                                   // Triggers validation + onSubmit
        disabled={isSubmitting}                         // Disable while the async submit is running
        className="rounded-lg bg-blue-600 px-4 py-2 text-white hover:bg-blue-700 disabled:opacity-50"
      >
        {isSubmitting ? "Submitting..." : "Submit"}     {/* Show loading text while submitting */}
      </button>
    </form>
  );
}
```

### 20.2 Common Zod Validation Rules

```tsx
// Common Zod validation rules — use these in your schemas
const schema = z.object({
  // Required string — must be present and non-empty
  name: z.string().min(1, "Name is required"),

  // Email — validates format (has @ and domain)
  email: z.string().email("Enter a valid email"),

  // Number with minimum value
  age: z.number().min(18, "Must be 18+"),

  // Optional field — can be omitted entirely or be undefined
  bio: z.string().optional(),

  // String with both min and max length constraints
  password: z.string().min(8, "At least 8 characters").max(100),

  // Enum — value must be one of the listed options
  status: z.enum(["active", "inactive", "pending"]),

  // Array of strings — e.g., for tags or multi-select
  tags: z.array(z.string()),

  // Optional date string — common for date inputs that send ISO strings
  due_at: z.string().optional(),
});
```

### 20.3 Handling API Errors

```tsx
// Handling API validation errors in form submission.
// When Laravel returns 422, the response contains field-specific error messages.
async function onSubmit(values: FormValues) {
  try {
    await createTask(values);                           // Call the API
    // Success: reset form, close modal, show toast, etc.
  } catch (error) {
    // Check if it's an Axios error with a 422 status (Laravel validation failure)
    if (error instanceof AxiosError && error.response?.status === 422) {
      // Laravel returns: { errors: { title: ["The title field is required."] } }
      const serverErrors = error.response.data?.errors ?? {}; // Extract the errors object
      // Loop through each field that has server-side errors
      for (const field of Object.keys(serverErrors)) {
        // Use RHF's setError to display the server error under the matching input
        setError(field as keyof FormValues, {
          message: serverErrors[field][0],              // Take the first error message for each field
        });
      }
    } else {
      // Non-validation error (network failure, 500, etc.)
      alert("Something went wrong");
    }
  }
}
```

---

## 21. App Router Deep Dive — How Routes, Layouts, and Navigation Work

The guide showed basic `page.tsx`. The App Router is much more powerful.

### 21.1 File Conventions

Next.js has special filenames that do specific things:

```
src/app/
├── layout.tsx          ← Wraps all children, persists across navigations
├── page.tsx            ← The page content (must exist for route to be valid)
├── loading.tsx         ← Shows while page/children are loading (Suspense)
├── error.tsx           ← Catches errors in the route (error boundary)
├── not-found.tsx       ← Shows when route doesn't match (404)
└── route.ts            ← API route handler (Route Handlers)
```

### 21.2 Layouts — The Shell

Layouts wrap pages and **persist across navigation** (don't re-render when you move between pages that share the layout).

```tsx
// File: src/app/layout.tsx (ROOT LAYOUT — required, wraps ALL pages)
// This is the outermost wrapper. It sets up <html>, <body>, global CSS,
// and providers that every page needs (like TanStack Query).

import type { Metadata } from "next";                  // Type for SEO metadata
import "./globals.css";                                 // Import global styles (Tailwind directives, custom CSS)
import { QueryProvider } from "@/core/query-provider";  // TanStack Query context provider
import { AuthProvider } from "@/core/auth-context";     // Authentication state provider

// Static metadata — rendered as <title> and <meta> tags in the HTML <head>
export const metadata: Metadata = {
  title: "Task Manager",                               // Browser tab title
  description: "Manage your tasks",                    // SEO description
};

// The root layout receives { children } which is the current page being rendered
export default function RootLayout({
  children,
}: {
  children: React.ReactNode;                           // The page content
}) {
  return (
    <html lang="en">                                   // Set document language for accessibility/screen readers
      <body>
        {/* QueryProvider makes useQuery/useMutation available to all pages */}
        {/* AuthProvider makes useAuth available to login and protected pages */}
        <QueryProvider>
          <AuthProvider>{children}</AuthProvider>
        </QueryProvider>
      </body>
    </html>
  );
}
```

```tsx
// File: src/app/(dashboard)/layout.tsx (NESTED LAYOUT — wraps all dashboard pages)
// This layout adds a sidebar and top bar. It persists across navigation between
// dashboard pages — only the {children} change, not the sidebar/topbar.

"use client";                                           // Client Component — uses interactive components (Sidebar, TopBar)

import { Sidebar } from "@/components/sidebar";         // Navigation sidebar component
import { TopBar } from "@/components/top-bar";           // Top navigation bar component

export default function DashboardLayout({
  children,
}: {
  children: React.ReactNode;                           // The current dashboard page
}) {
  // Full-height flex container: sidebar + main area.
  return (
    <div className="flex h-screen">
      {/* Fixed-width sidebar on the left */}
      <Sidebar />
      {/* Right side: topbar on top, content below */}
      <div className="flex-1 flex flex-col">
        {/* Top bar with user info, notifications, etc. */}
        <TopBar />
        {/* Scrollable content area with padding */}
        <main className="flex-1 overflow-auto p-6">{children}</main>
      </div>
    </div>
  );
}
```

Key: When you navigate from `/tasks` to `/settings`, the `DashboardLayout` does NOT re-render. Only the `{children}` change. This is great for performance and UX.

### 21.3 Loading States

```tsx
// File: src/app/tasks/loading.tsx
// This file shows automatically while the page.tsx (or its data) is loading.
// Next.js wraps the page in a <Suspense> boundary and shows this as the fallback.

export default function Loading() {
  // The outer div creates vertical spacing between skeleton elements.
  return (
    <div className="space-y-4">
      {/* Skeleton for the page title — a gray rectangle that pulses */}
      <div className="h-8 w-48 animate-pulse rounded bg-gray-200" />
      {/* Grid of skeleton cards — mimics the layout of the real content */}
      {/* 3 columns on desktop */}
      <div className="grid gap-4 md:grid-cols-3">
        {Array.from({ length: 6 }).map((_, i) => (      // Create 6 placeholder cards
          <div key={i} className="h-32 animate-pulse rounded-lg bg-gray-200" /> // Pulsing gray rectangle
        ))}
      </div>
    </div>
  );
}
```

### 21.4 Error Boundaries

```tsx
// File: src/app/tasks/error.tsx
// This component catches errors thrown during rendering of the page.tsx.
// It acts as an error boundary — shows a friendly message instead of a crash.
// Must be a Client Component because it needs the reset() function.

"use client";                                           // Required for error boundaries (uses React state)

export default function Error({
  error,                                                // The Error object that was thrown
  reset,                                                // Function to retry rendering the page
}: {
  error: Error & { digest?: string };                   // TypeScript type: Error with optional digest
  reset: () => void;                                    // Calling this re-renders the page from scratch
}) {
  return (
    <div className="flex flex-col items-center justify-center gap-4 p-8">
      {/* User-friendly heading */}
      <h2 className="text-xl font-semibold">Something went wrong</h2>
      {/* Show the error message */}
      <p className="text-sm text-gray-600">{error.message}</p>
      <button
        onClick={reset}                                 // Retry rendering — useful for transient errors
        className="rounded-lg bg-blue-600 px-4 py-2 text-white"
      >
        Try again
      </button>
    </div>
  );
}
```

### 21.5 Dynamic Routes

```
src/app/tasks/
├── page.tsx              → /tasks          (list all)
├── [id]/
│   └── page.tsx          → /tasks/123      (one task by ID)
└── [id]/edit/
    └── page.tsx          → /tasks/123/edit (edit page)
```

```tsx
// File: src/app/tasks/[id]/page.tsx
// Dynamic route: the [id] folder creates a route that matches /tasks/ANY_VALUE.
// For example: /tasks/123, /tasks/abc, etc.

"use client";                                           // Client Component — uses useParams hook

import { useParams } from "next/navigation";            // Hook to read dynamic route parameters from the URL

export default function TaskDetailPage() {
  // useParams() returns an object with the dynamic segments from the URL.
  // For /tasks/123, it returns { id: "123" } (always a string!).
  const params = useParams<{ id: string }>();            // Type the params so TypeScript knows about `id`
  const id = Number(params.id);                         // Convert the string "123" to the number 123

  // Now you would fetch the task by ID using a TanStack Query hook:
  // const { data: task } = useTask(id);

  return <div>Task {id}</div>;                          // Render the task ID (placeholder for real content)
}
```

Dynamic route patterns:
| Pattern | URL | Access |
|---------|-----|--------|
| `[id]` | `/tasks/123` | `params.id` → "123" |
| `[...slug]` | `/docs/a/b/c` | `params.slug` → ["a","b","c"] |
| `[[...slug]]` | `/docs` or `/docs/a/b` | `params.slug` → [] or ["a","b"] |

### 21.6 Route Groups

Route groups `(folder)` don't affect the URL but let you organize and share layouts:

```
src/app/
├── (auth)/                → Routes grouped for organization
│   ├── login/page.tsx     → /login        (no (auth) in URL!)
│   └── register/page.tsx  → /register
│   └── layout.tsx         → Shared layout for auth pages
├── (dashboard)/
│   ├── tasks/page.tsx     → /tasks
│   └── layout.tsx         → Different layout for dashboard
```

### 21.7 Navigation

```tsx
"use client";                                           // Client Component — uses useRouter and Link

import { useRouter } from "next/navigation";            // Hook for programmatic navigation (push, replace, back)
import Link from "next/link";                           // Component for declarative navigation (clickable links)

export function NavigationExample() {
  const router = useRouter();                           // Get the router object for programmatic navigation

  return (
    <div>
      {/* Link component — performs client-side navigation without a full page reload.
          Next.js prefetches the linked page in the background for instant transitions. */}
      <Link href="/tasks/123">View Task 123</Link>
      {/* Link with custom styling */}
      <Link href="/tasks" className="text-blue-500">All Tasks</Link>

      {/* Programmatic navigation — triggered by code, not by clicking a link */}
      {/* push() adds to browser history (back button works) */}
      <button onClick={() => router.push("/tasks")}>
        Go to tasks
      </button>

      {/* Go back — same as clicking the browser's back button */}
      <button onClick={() => router.back()}>
        Back
      </button>

      {/* replace() navigates WITHOUT adding to browser history (back button skips this page) */}
      <button onClick={() => router.replace("/login")}>
        Go to login (no back)
      </button>
    </div>
  );
}
```

---

## 22. How to Read Errors and Debug Like a Senior Engineer

Debugging is a skill. Here's the mental model to find and fix issues fast.

### 22.1 The "Where to Look" Decision Tree

```
Something is broken. Where do I look?
│
├── Is it a 404 (page not found)?
│   ├── NEXT.js route: Check if page.tsx exists in the right folder
│   └── API route: Run `php artisan route:list` to see actual routes
│
├── Is it a 401 (unauthorized)?
│   ├── Token missing? Check localStorage.getItem("token")
│   ├── Token expired? The api-client interceptor should clear + redirect
│   └── Wrong auth middleware? Check routes/api.php
│
├── Is it a 403 (forbidden)?
│   └── User doesn't have the role/permission? Check middleware in routes
│
├── Is it a 422 (validation error)?
│   └── Check the Form Request rules vs what the frontend sends
│
├── Is it a 500 (server error)?
│   ├── Check Laravel logs: tail -f storage/logs/laravel.log
│   └── Check the exception trace for the file + line
│
├── Is it a CORS error (browser console)?
│   └── Check config/cors.php (see Section 10)
│
├── Is it a hydration error (Next.js console)?
│   └── Server and client render different content (see Section 8)
│
├── Is data not showing up?
│   ├── Open the Network tab → check the API response
│   ├── Check if TanStack Query is enabled
│   └── Check the query key matches between query and invalidation
│
└── Is a mutation not refreshing the list?
    ├── Check onSuccess invalidation in the hook
    └── Make sure query keys match
```

### 22.2 The Browser Network Tab (Your #1 Tool)

Open DevTools → Network tab. This shows every HTTP request your app makes.

What to look for:
1. Filter by "Fetch/XHR" to see only API calls
2. Click a request → Headers tab:
   - Request URL: Is it the correct URL?
   - Request Headers: Is Authorization: Bearer ... present?
3. Click a request → Response tab:
   - What's the actual response body?
   - Is the status code what you expect?
4. Click a request → Preview tab:
   - Formatted JSON view

### 22.3 Laravel Logs

When you get a 500 error, the actual error is in the Laravel log:

```bash
# Watch the log in real-time
tail -f storage/logs/laravel.log

# Or clear it and trigger the error again
> storage/logs/laravel.log  # Truncate

# The log shows:
# [2024-01-15 10:30:00] local.ERROR: Attempt to read property "name" on null
#   at app/Http/Resources/TaskResource.php:19
#   #0 /var/www/app/.../helpers.php(15): (...trace...)
```

### 22.4 Reading a Laravel Stack Trace

When you see an error response like this:

```json
{
  "message": "Attempt to read property \"name\" on null",
  "exception": "ErrorException",
  "file": "/app/Http/Resources/TaskResource.php",
  "line": 19,
  "trace": [...]
}
```

What each part tells you:
- `message` — What went wrong (a `null` was accessed as if it had `name`)
- `exception` — The exception class
- `file` + `line` — **Where to look first** — open this file at this line
- `trace` — The call stack (work backwards to find the root cause)

Strategy:
1. Open `file` at `line` — that's where it crashed
2. Look at what's `null` that shouldn't be
3. Work backwards through the trace to find where it became `null`

### 22.5 Common Error Messages Decoded

| Error message | What it means | Fix |
|---|---|---|
| `The route X could not be found` | The URL doesn't match any route | Run `php artisan route:list` |
| `Unauthenticated` | No valid token sent | Check the `Authorization` header |
| `This action is unauthorized` | User lacks permission | Check role middleware/policies |
| `The given data was invalid` | Validation failed | Check FormRequest rules vs frontend data |
| `No query results for model [Task]` | Route model binding couldn't find record | Check if ID exists |
| `Access to fetch blocked by CORS` | Server didn't allow your origin | Fix `config/cors.php` |
| `Hydration failed` | Server/client render mismatch | See Section 8 |
| `Add [X] to fillable property` | Mass assignment error | Add field to `$fillable` in model |
| `Class "App\Services\X" not found` | Wrong namespace or missing import | Check `use` statements |

### 22.6 Quick Diagnostic Checklist

When something breaks, run through this:

```bash
# On the backend:
php artisan route:list          # Are routes registered?
php artisan optimize:clear      # Clear stale caches?
php artisan tinker              # Test models/queries interactively
tail -f storage/logs/laravel.log # See server errors

# On the frontend:
# - Open DevTools → Console for JS errors
# - Open DevTools → Network for failed API calls
# - Check .env.local has NEXT_PUBLIC_API_BASE_URL
# - Check localStorage has "token"

# Between them:
curl -X GET http://localhost:8000/api/v1/auth/me \
  -H "Accept: application/json"        # Can the frontend reach the backend at all?
```

---

## 23. Learning Path

### Level 1: Backend Only

Build a Laravel-only API for notes.

Requirements:
- `notes` table: `id`, `user_id`, `title`, `body`, timestamps, soft deletes.
- Authenticated user can CRUD only their own notes.
- Use Form Requests.
- Use API Resources.
- Test everything with curl before building UI.

Success criteria:
- You can create a note with curl.
- You can list only the logged-in user's notes.
- You cannot access another user's note.

### Level 2: Frontend Only With Mock Data

Build the Next.js UI without Laravel first.

Requirements:
- Notes list.
- Create note form.
- Edit note form.
- Delete button.
- Loading and error placeholder components.

Success criteria:
- You understand components, props, state, and forms without API confusion.

### Level 3: Connect Next.js to Laravel

Replace mock data with Axios + TanStack Query.

Requirements:
- API functions.
- Query hooks.
- Mutation hooks.
- Invalidation after create/update/delete.

Success criteria:
- CRUD works in the browser and persists after refresh.

### Level 4: Auth

Requirements:
- Login page.
- Store token or use cookies.
- Protected dashboard.
- Logout.

Success criteria:
- Logged-out users cannot access notes.
- Logged-in users see only their data.

---

## 24. What "Good" Looks Like

Good Laravel backend:

```txt
routes/api.php
  -> maps URLs to controllers

Controller
  -> receives HTTP request
  -> calls service
  -> returns resource

FormRequest
  -> validates input

Service
  -> contains business rules and transactions

Model
  -> represents database table and relationships

Resource
  -> controls JSON output
```

Good Next.js frontend:

```txt
app/
  -> route pages and layouts

features/feature-name/api
  -> API calls

features/feature-name/hooks
  -> TanStack Query hooks

features/feature-name/types
  -> TypeScript types

features/feature-name/components
  -> reusable UI pieces

core/
  -> shared API client, auth, providers, utilities
```

Good API habit:

```txt
Backend first -> verify with curl -> build frontend -> verify in browser
```

If the backend is untested, frontend debugging becomes guesswork.

---

## 25. Common Beginner Mistakes

### Mistake 1: Trusting Client-Sent Ownership IDs

Bad:

```php
// DANGER: Trusting user_id from the request body.
// A malicious user could send any user_id to create tasks for other users!
Task::create([
    'user_id' => $request->input('user_id'),             // Client can fake this value
]);
```

Good:

```php
// SAFE: Always derive ownership from the authenticated token.
// $request->user() is resolved server-side from the Bearer token — cannot be faked.
Task::create([
    'user_id' => $request->user()->id,                   // Server-side identity from the token
]);
```

The browser is not trustworthy. The token is the identity source.

### Mistake 2: Duplicating Validation Only on Frontend

Frontend validation helps users. Backend validation protects the system.

Always do both for important inputs.

### Mistake 3: Putting Business Logic in React

Bad:
- React calculates payroll.
- React decides who can approve leave.
- React writes directly to a database.

Good:
- React displays the form.
- Laravel validates, authorizes, calculates, and persists.

### Mistake 4: Making Controllers Too Smart

If the controller has many queries, calculations, and conditionals, move logic into a service.

### Mistake 5: Forgetting Ownership on Route Model Binding

Route model binding finds a row by ID. It does not automatically know that the row belongs to the current user.

Use policies or scoped queries.

### Mistake 6: Query Key Mismatch

Bad:

```ts
// The query uses key ["tasks"] but invalidation targets ["task"] (singular).
// They don't match, so the list never refetches after a mutation!
useQuery({ queryKey: ["tasks"], queryFn: getTasks });    // Cache stored under ["tasks"]
queryClient.invalidateQueries({ queryKey: ["task"] });   // Tries to invalidate ["task"] — no match!
```

Good:

```ts
// Both use the same key ["tasks"] — invalidation correctly triggers a refetch.
useQuery({ queryKey: ["tasks"], queryFn: getTasks });    // Cache stored under ["tasks"]
queryClient.invalidateQueries({ queryKey: ["tasks"] });  // Invalidates ["tasks"] — match! List refetches.
```

### Mistake 7: Adding Architecture Too Early

Do not add extra folders, layers, packages, or patterns before they solve a real problem.

For general learning, master the standard Laravel structure first. Clean code in the default structure is better than confusing code hidden behind fancy organization.

---

## 26. Final Answer: Is This Enough To Learn From?

Yes, this guide is now comprehensive enough to guide your first serious learning path and build projects with minimal AI help.

**What this guide covers:**

**Part A (Sections 0-5)** gives you the mental model and architecture. You'll understand *what* headless means, *why* Laravel and Next.js are separated, and *how* to structure your code.

**Part B (Sections 6-12)** gives you complete code examples. You'll see a full Task Manager implementation with proper ownership protection, authentication, and data fetching patterns.

**Part C (Sections 13-22)** is the "how" section that answers your biggest question. You'll learn:
- How to set up your environment from scratch (Section 13)
- How to create files with Artisan CLI (Section 14)
- How to build authentication end-to-end (Section 15)
- How to build the missing frontend components (Section 16)
- How to create test data with factories and seeders (Section 17)
- How to query effectively with Eloquent (Section 18)
- How to style with Tailwind CSS (Section 19)
- How to build forms with React Hook Form + Zod (Section 20)
- How routes, layouts, and navigation work in Next.js (Section 21)
- How to read errors and debug like a senior engineer (Section 22)

**Part D (Sections 23-26)** gives you a learning path, shows you what "good" looks like, lists common mistakes, and helps you self-assess.

**Use this guide as:**
- Your step-by-step tutorial (follow Sections 13-22 in order)
- Your reference when you get stuck (use the debugging decision tree in Section 22)
- Your architecture checklist (review Sections 4-5 before building)
- Your self-assessment tool (answer the questions at the end of Section 23)

**Also use:**
- Laravel official docs for exact framework behavior: [laravel.com/docs](https://laravel.com/docs)
- Next.js official docs for App Router behavior: [nextjs.org/docs](https://nextjs.org/docs)
- The actual codebase you are working in
- Small practice projects that you build from memory

**You will know you are learning correctly when you can answer these without copying:**

1. What happens from button click to database write?
2. Why does backend validation still matter if Zod validates the form?
3. Why should ownership come from `$request->user()` instead of `user_id` in the payload?
4. What does TanStack Query cache, and when should you invalidate it?
5. Why is standard Laravel the default for general projects?
6. When should a component be a Client Component?
7. Why should Laravel own business logic in a headless Laravel + Next.js app?
8. How do you create a new model, migration, controller, and resource with one Artisan command?
9. How do you debug a 404 error? A 401 error? A CORS error?
10. How do you set up authentication from scratch?

If you can explain those clearly and build the Level 1-4 exercises in Section 23 without looking at this guide, you are no longer just copying a project. You are understanding the architecture and can build on your own.

**The path to minimal AI help:**

1. **Follow the setup guide (Section 13)** to get your environment working
2. **Use Artisan CLI (Section 14)** to create files instead of asking AI
3. **Test with curl (Section 15.6)** before building the frontend
4. **Use the debugging tree (Section 22)** when something breaks
5. **Build the practice projects (Section 23)** from memory
6. **Read the official docs** when you need exact API details

You now have everything you need to build headless applications with Laravel and Next.js. The rest is practice.
