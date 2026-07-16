---
type: personal-reference
title: How Our Tech Stack Works Together (+ full worked example)
tags: [hris, learning, stack, laravel, nextjs, reference, personal]
owner: devops@viteseo.ph
audience: just me (learning reference)
pairs_with: [HRIS-AI-Build-Guide.md, HRIS-Step-by-Step-Execution.md]
---

# How Our Tech Stack Works Together

> **This is a personal learning reference.** Goal: see *the whole machine* — what each tool is for, how they connect, and a **complete tiny app ("TaskFlow")** with real file structure + code so you have a working mental model before touching the real HRIS.
>
> TaskFlow is a throwaway example (a login + a task list you can create/complete/delete). It uses **every** piece of our stack, wired exactly the way HRIS will be. Read it once and the real thing becomes "just more of the same."

---

# PART 1 — The cast: what each tool is *for*

| Tool | Layer | Its ONE job (plain words) |
|---|---|---|
| **MySQL** | Data | Stores everything permanently, in tables. |
| **Laravel** | Backend | The brain: runs logic, talks to MySQL, answers with JSON. The *only* backend. |
| **Eloquent (Models)** | Backend | Lets Laravel read/write tables using PHP instead of raw SQL. |
| **Sanctum** | Backend | Logs users in and hands out **tokens**; guards protected routes. |
| **spatie/permission** | Backend | Roles & permissions ("can this user approve leave?"). |
| **Form Requests** | Backend | Validate incoming data before logic runs. |
| **API Resources** | Backend | Shape the JSON the API sends back (`{ "data": ... }`). |
| **Service classes** | Backend | Hold the actual business logic (kept out of controllers). |
| **Next.js (React)** | Frontend | The whole UI. Runs in the browser. *No logic* — just shows things. |
| **TypeScript** | Frontend | Adds types so the UI and the API's JSON shapes stay in sync. |
| **Axios** | Frontend | The phone line: sends HTTP requests to Laravel, gets JSON back. |
| **TanStack Query** | Frontend | Manages fetching/caching/loading/refetching of that API data. |
| **React Hook Form + Zod** | Frontend | Build forms and validate them in the browser before sending. |
| **Tailwind + shadcn/ui** | Frontend | Styling + ready-made components (buttons, inputs, tables). |

**The golden split:** everything in the "Backend" rows lives in Laravel. Everything in "Frontend" lives in Next.js. They only touch through **Axios → HTTP → JSON**. That single seam is the entire integration.

---

# PART 2 — The big picture (visual)

## 2.1 The layers (who sits where)

```
┌───────────────────────────────────────────────────────────────────┐
│  BROWSER (the user)                                                 │
│     │ types apps.viteseo.ph  (locally: localhost:3000)              │
│     ▼                                                               │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  FRONTEND — Next.js (React + TypeScript)      port :3000     │   │
│  │  Tailwind + shadcn (looks) · React Hook Form + Zod (forms)  │   │
│  │  TanStack Query (data state) ──▶ Axios (the phone line)      │   │
│  └───────────────────────────────┬─────────────────────────────┘   │
└──────────────────────────────────┼─────────────────────────────────┘
                                    │  HTTPS request + JSON response
                                    │  Header: Authorization: Bearer <token>
┌──────────────────────────────────┼─────────────────────────────────┐
│  ┌───────────────────────────────▼─────────────────────────────┐   │
│  │  BACKEND — Laravel (PHP)                      port :8000     │   │
│  │  Route → Sanctum (auth) → Form Request (validate)           │   │
│  │       → Controller (thin) → Service (logic)                 │   │
│  │       → Eloquent Model → [ MySQL ] → API Resource (JSON)    │   │
│  │  spatie permissions guard sensitive actions                 │   │
│  └───────────────────────────────┬─────────────────────────────┘   │
│                                   ▼                                  │
│                            ┌─────────────┐                          │
│                            │   MySQL DB  │  (one shared database)   │
│                            └─────────────┘                          │
└─────────────────────────────────────────────────────────────────────┘
```

## 2.2 One request, every tool labeled (a "complete task list" load)

```
[TanStack Query] "I need the tasks"
        │ calls
        ▼
[Axios] GET http://localhost:8000/api/v1/tasks   (+ Bearer token attached automatically)
        │  travels over HTTP
        ▼
[Laravel Route] matches → runs [Sanctum] check (valid token? yes)
        ▼
[Controller.index] (thin) → calls [Service.list()]
        ▼
[Service] builds query → [Eloquent Model] → SQL → [MySQL] returns rows
        ▼
[API Resource] wraps rows → { "data": [ ...tasks... ], "meta": {...} }
        │  JSON travels back over HTTP
        ▼
[Axios] hands JSON to [TanStack Query] → caches it → [React] renders the list
```

## 2.3 The login/token flow (how auth "connects" the two apps)

```
1. User submits email+password on the Next.js login page
   [React Hook Form + Zod] validate it in the browser
        │
2. [Axios] POST /api/v1/auth/login  { email, password }
        ▼
3. [Laravel] checks the password → [Sanctum] creates a TOKEN (a long secret string)
        │  returns { "data": { "token": "abc123...", "user": {...} } }
        ▼
4. Frontend saves the token:  localStorage.setItem("token", "abc123...")
        ▼
5. From now on, [Axios interceptor] adds  Authorization: Bearer abc123...
   to EVERY request → Laravel knows who you are on every call.
```

**That token is the bridge.** The two apps are separate, so Laravel can't use a normal session cookie tied to a page — instead it issues a token the frontend carries on every request. Lose the token (logout) → protected routes return 401.

---

# PART 3 — Where each piece connects (the "seams")

Think of the whole system as a relay race. Here's every hand-off (baton pass) and *how* they connect:

| From → To | How they connect (the actual seam) |
|---|---|
| Browser → Next.js | User visits a URL; Next serves the React page for that route. |
| React page → TanStack Query | The page calls a hook like `useTasks()`. |
| TanStack Query → Axios | The hook's `queryFn` calls an Axios function (`getTasks()`). |
| Axios → Laravel | HTTP request to `http://localhost:8000/api/v1/...` with the Bearer token. |
| Laravel Route → Controller | The URL+method match a route line pointing to a controller method. |
| Sanctum → Controller | `auth:sanctum` middleware validates the token *before* the controller runs. |
| Form Request → Controller | Type-hinting the request validates the body *before* the method body runs. |
| Controller → Service | Controller calls `$this->service->doThing()`. |
| Service → Model | Service calls Eloquent (`Task::create(...)`, `Task::paginate()`). |
| Model → MySQL | Eloquent turns PHP into SQL and runs it. |
| Model → API Resource | Rows come back; controller wraps them in a Resource. |
| API Resource → Axios | Resource becomes JSON `{ "data": ... }`; sent as the HTTP response. |
| Axios → TanStack Query | Query caches the JSON and gives it to the component. |
| TanStack Query → React | Component re-renders with `data`, `isLoading`, `error`. |
| React → Tailwind/shadcn | Components render with Tailwind classes / shadcn primitives. |
| Form → Zod → Axios | RHF collects input, Zod validates it, then Axios POSTs it to Laravel. |

**Every feature is just this relay, run once.** Learn it here and HRIS is repetition.

---

# PART 4 — The worked example: "TaskFlow"

A tiny full-stack app: **log in**, then **see / create / complete / delete tasks**. It touches every tool. Built with the *exact* structure HRIS uses (Core for auth, a module for the feature).

## 4.1 What it does
- `/login` → sign in, get a token.
- `/tasks` → list your tasks, add a new one (validated form), mark done, delete.

## 4.2 File tree (mirrors the real monorepo)

```
taskflow/
├── backend/                                  ← Laravel (only backend)
│   ├── app/
│   │   ├── Core/
│   │   │   └── Http/
│   │   │       ├── Controllers/AuthController.php   ← login/logout
│   │   │       ├── Requests/LoginRequest.php
│   │   │       └── Resources/UserResource.php
│   │   ├── Models/User.php                          ← shared user (Sanctum + spatie traits)
│   │   ├── Modules/
│   │   │   └── Tasks/
│   │   │       ├── Models/Task.php
│   │   │       ├── Http/
│   │   │       │   ├── Controllers/TaskController.php
│   │   │       │   ├── Requests/StoreTaskRequest.php
│   │   │       │   └── Resources/TaskResource.php
│   │   │       ├── Services/TaskService.php
│   │   │       ├── Routes/api.php
│   │   │       ├── Database/Migrations/2026_..._create_tasks_table.php
│   │   │       └── Providers/TasksServiceProvider.php
│   │   └── Providers/ ...
│   ├── routes/api.php                               ← mounts /api/v1/auth
│   └── bootstrap/providers.php                      ← registers TasksServiceProvider
│
└── frontend/                                 ← Next.js (frontend only)
    └── src/
        ├── app/
        │   ├── layout.tsx                    ← wraps app in QueryProvider
        │   ├── login/page.tsx                ← login screen
        │   └── tasks/page.tsx                ← task list + create form
        ├── core/
        │   ├── api-client.ts                 ← Axios instance + token interceptor
        │   ├── query-provider.tsx            ← TanStack Query provider
        │   └── auth.ts                        ← login()/logout() helpers
        └── modules/
            └── tasks/
                ├── types/task.ts
                ├── api/tasks.ts               ← Axios calls
                └── hooks/use-tasks.ts         ← TanStack Query hooks (query + mutations)
```

---

## 4.3 BACKEND CODE

### (a) The User model — wired for Sanctum + spatie
`backend/app/Models/User.php`
```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasRoles, Notifiable;

    protected $fillable = ['name', 'email', 'password'];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = ['password' => 'hashed'];   // auto-hash passwords
}
```
> `HasApiTokens` = can create Sanctum tokens. `HasRoles` = can have spatie roles/permissions. `casts password => hashed` = passwords are hashed automatically.

### (b) Login — Auth lives in Core (shared by all modules)
`backend/app/Core/Http/Requests/LoginRequest.php`
```php
<?php

namespace App\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
```

`backend/app/Core/Http/Resources/UserResource.php`
```php
<?php

namespace App\Core\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'    => $this->id,
            'name'  => $this->name,
            'email' => $this->email,
            'roles' => $this->getRoleNames(),   // from spatie
        ];
    }
}
```

`backend/app/Core/Http/Controllers/AuthController.php`
```php
<?php

namespace App\Core\Http\Controllers;

use App\Core\Http\Requests\LoginRequest;
use App\Core\Http\Resources\UserResource;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        $user = User::where('email', $request->email)->first();

        // Verify the password. Wrong creds → standard 422 error envelope.
        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        // Sanctum issues a token string the frontend will store and re-send.
        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user'  => new UserResource($user),
            ],
        ]);
    }

    public function logout(\Illuminate\Http\Request $request)
    {
        $request->user()->currentAccessToken()->delete();   // revoke the token
        return response()->noContent();                      // 204
    }
}
```

`backend/routes/api.php` (Core/auth routes — Laravel loads this file by default)
```php
<?php

use App\Core\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);          // public
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);    // protected
    });
});
```
> Full URL becomes `/api/v1/auth/login` because Laravel already prefixes `api/` for this file.

### (c) The Tasks module

**Migration** — `backend/app/Modules/Tasks/Database/Migrations/2026_07_01_000000_create_tasks_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // owner → users.id
            $table->string('title');
            $table->boolean('is_done')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('tasks'); }
};
```
> `foreignId('user_id')->constrained()` links each task to a row in `users` — this is how cross-references work everywhere in our stack (a task's owner, an HR record's employee, etc.).

**Model** — `backend/app/Modules/Tasks/Models/Task.php`
```php
<?php

namespace App\Modules\Tasks\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    protected $fillable = ['user_id', 'title', 'is_done'];
    protected $casts = ['is_done' => 'boolean'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');   // Task → User relationship
    }
}
```

**Form Request** — `backend/app/Modules/Tasks/Http/Requests/StoreTaskRequest.php`
```php
<?php

namespace App\Modules\Tasks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:255']];
    }
}
```

**Resource** — `backend/app/Modules/Tasks/Http/Resources/TaskResource.php`
```php
<?php

namespace App\Modules\Tasks\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'      => $this->id,
            'title'   => $this->title,
            'is_done' => $this->is_done,
        ];
    }
}
```

**Service** — `backend/app/Modules/Tasks/Services/TaskService.php`
```php
<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Tasks\Models\Task;
use App\Models\User;

class TaskService
{
    // Only the logged-in user's own tasks.
    public function listFor(User $user)
    {
        return $user->id
            ? Task::where('user_id', $user->id)->latest()->paginate(20)
            : Task::query()->whereRaw('1=0')->paginate(20);
    }

    public function createFor(User $user, array $data): Task
    {
        $data['user_id'] = $user->id;
        return Task::create($data);
    }

    public function toggle(Task $task): Task
    {
        $task->update(['is_done' => ! $task->is_done]);
        return $task;
    }

    public function delete(Task $task): void
    {
        $task->delete();
    }
}
```

**Controller (thin)** — `backend/app/Modules/Tasks/Http/Controllers/TaskController.php`
```php
<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tasks\Http\Requests\StoreTaskRequest;
use App\Modules\Tasks\Http\Resources\TaskResource;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Services\TaskService;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(private TaskService $service) {}

    public function index(Request $request)
    {
        return TaskResource::collection($this->service->listFor($request->user()));
    }

    public function store(StoreTaskRequest $request)
    {
        $task = $this->service->createFor($request->user(), $request->validated());
        return new TaskResource($task);
    }

    public function update(Task $task)                 // route-model binding: {task} → Task
    {
        return new TaskResource($this->service->toggle($task));
    }

    public function destroy(Task $task)
    {
        $this->service->delete($task);
        return response()->noContent();                // 204
    }
}
```
> `$request->user()` = the user Sanctum identified from the token. `Task $task` in `update`/`destroy` = Laravel auto-fetches the task by the id in the URL ("route-model binding").

**Routes** — `backend/app/Modules/Tasks/Routes/api.php`
```php
<?php

use App\Modules\Tasks\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

// All task routes require login (mounted under /api/v1/tasks by the provider).
Route::middleware('auth:sanctum')->group(function () {
    Route::get('tasks', [TaskController::class, 'index']);
    Route::post('tasks', [TaskController::class, 'store']);
    Route::patch('tasks/{task}', [TaskController::class, 'update']);
    Route::delete('tasks/{task}', [TaskController::class, 'destroy']);
});
```

**Provider** — `backend/app/Modules/Tasks/Providers/TasksServiceProvider.php`
```php
<?php

namespace App\Modules\Tasks\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TasksServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::prefix('api/v1')->middleware('api')
            ->group(__DIR__ . '/../Routes/api.php');
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }
}
```

**Register it** — `backend/bootstrap/providers.php`
```php
return [
    App\Providers\AppServiceProvider::class,
    App\Modules\Tasks\Providers\TasksServiceProvider::class,   // ← without this, /api/v1/tasks = 404
];
```

✅ **Backend done.** You now have: `POST /api/v1/auth/login`, and `GET/POST/PATCH/DELETE /api/v1/tasks` (all token-protected).

---

## 4.4 FRONTEND CODE

### (a) The Axios client (the phone line + auto token)
`frontend/src/core/api-client.ts`
```ts
import axios from "axios";

export const apiClient = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_BASE_URL, // http://localhost:8000/api/v1
  headers: { Accept: "application/json" },
});

// Attach the saved token to every request automatically.
apiClient.interceptors.request.use((config) => {
  if (typeof window !== "undefined") {
    const token = localStorage.getItem("token");
    if (token) config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});
```

### (b) Auth helpers
`frontend/src/core/auth.ts`
```ts
import { apiClient } from "./api-client";

export async function login(email: string, password: string) {
  const res = await apiClient.post("/auth/login", { email, password });
  const { token, user } = res.data.data;
  localStorage.setItem("token", token);   // store the token → interceptor uses it next
  return user;
}

export async function logout() {
  await apiClient.post("/auth/logout");
  localStorage.removeItem("token");
}
```

### (c) TanStack Query provider
`frontend/src/core/query-provider.tsx`
```tsx
"use client";

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useState } from "react";

export function QueryProvider({ children }: { children: React.ReactNode }) {
  const [client] = useState(() => new QueryClient());
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}
```

`frontend/src/app/layout.tsx`
```tsx
import { QueryProvider } from "@/core/query-provider";
import "./globals.css";

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <body>
        <QueryProvider>{children}</QueryProvider>
      </body>
    </html>
  );
}
```

### (d) Types + Axios calls for tasks
`frontend/src/modules/tasks/types/task.ts`
```ts
export type Task = {
  id: number;
  title: string;
  is_done: boolean;
};
```

`frontend/src/modules/tasks/api/tasks.ts`
```ts
import { apiClient } from "@/core/api-client";
import type { Task } from "../types/task";

export async function getTasks(): Promise<Task[]> {
  const res = await apiClient.get("/tasks");
  return res.data.data;                       // unwrap { data: [...] }
}

export async function createTask(title: string): Promise<Task> {
  const res = await apiClient.post("/tasks", { title });
  return res.data.data;
}

export async function toggleTask(id: number): Promise<Task> {
  const res = await apiClient.patch(`/tasks/${id}`);
  return res.data.data;
}

export async function deleteTask(id: number): Promise<void> {
  await apiClient.delete(`/tasks/${id}`);
}
```

### (e) TanStack Query hooks (read + write)
`frontend/src/modules/tasks/hooks/use-tasks.ts`
```ts
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { getTasks, createTask, toggleTask, deleteTask } from "../api/tasks";

// READ: fetch + cache the task list.
export function useTasks() {
  return useQuery({ queryKey: ["tasks"], queryFn: getTasks });
}

// WRITE: after a change, invalidate the list so it refetches fresh data.
export function useCreateTask() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (title: string) => createTask(title),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["tasks"] }),
  });
}

export function useToggleTask() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => toggleTask(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["tasks"] }),
  });
}

export function useDeleteTask() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteTask(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["tasks"] }),
  });
}
```
> **Query vs Mutation:** `useQuery` = *read* data (auto-caches). `useMutation` = *change* data (create/update/delete). After a mutation, `invalidateQueries` tells the cached list "you're stale, refetch" — so the UI updates itself with no manual state juggling.

### (f) The login page (React Hook Form + Zod + shadcn)
`frontend/src/app/login/page.tsx`
```tsx
"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { useRouter } from "next/navigation";
import { login } from "@/core/auth";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

// Zod schema = the shape + rules the form must satisfy IN THE BROWSER.
const schema = z.object({
  email: z.string().email("Enter a valid email"),
  password: z.string().min(1, "Password is required"),
});
type FormValues = z.infer<typeof schema>;

export default function LoginPage() {
  const router = useRouter();
  const { register, handleSubmit, setError, formState: { errors, isSubmitting } } =
    useForm<FormValues>({ resolver: zodResolver(schema) });

  async function onSubmit(values: FormValues) {
    try {
      await login(values.email, values.password);   // Axios → Laravel → token stored
      router.push("/tasks");                          // go to the app
    } catch {
      setError("email", { message: "Invalid credentials" });
    }
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="max-w-sm mx-auto mt-24 space-y-4">
      <h1 className="text-2xl font-semibold">Log in</h1>
      <div>
        <Input placeholder="Email" {...register("email")} />
        {errors.email && <p className="text-sm text-red-600">{errors.email.message}</p>}
      </div>
      <div>
        <Input type="password" placeholder="Password" {...register("password")} />
        {errors.password && <p className="text-sm text-red-600">{errors.password.message}</p>}
      </div>
      <Button type="submit" disabled={isSubmitting}>
        {isSubmitting ? "Signing in…" : "Sign in"}
      </Button>
    </form>
  );
}
```
> **Two layers of validation:** Zod checks the browser (instant feedback), and the Laravel Form Request checks the server (the real gate). You always keep both — never trust the browser alone.

### (g) The tasks page (everything working together)
`frontend/src/app/tasks/page.tsx`
```tsx
"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import {
  useTasks, useCreateTask, useToggleTask, useDeleteTask,
} from "@/modules/tasks/hooks/use-tasks";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

const schema = z.object({ title: z.string().min(1, "Title required") });
type FormValues = z.infer<typeof schema>;

export default function TasksPage() {
  const { data: tasks, isLoading, error } = useTasks();
  const createTask = useCreateTask();
  const toggleTask = useToggleTask();
  const deleteTask = useDeleteTask();

  const { register, handleSubmit, reset, formState: { errors } } =
    useForm<FormValues>({ resolver: zodResolver(schema) });

  function onSubmit(values: FormValues) {
    createTask.mutate(values.title, { onSuccess: () => reset() });
  }

  if (isLoading) return <p className="p-6">Loading…</p>;
  if (error) return <p className="p-6 text-red-600">Failed to load tasks.</p>;

  return (
    <div className="max-w-lg mx-auto p-6">
      <h1 className="text-2xl font-semibold mb-4">My Tasks</h1>

      <form onSubmit={handleSubmit(onSubmit)} className="flex gap-2 mb-6">
        <Input placeholder="New task…" {...register("title")} />
        <Button type="submit" disabled={createTask.isPending}>Add</Button>
      </form>
      {errors.title && <p className="text-sm text-red-600 mb-4">{errors.title.message}</p>}

      <ul className="space-y-2">
        {tasks?.map((t) => (
          <li key={t.id} className="flex items-center justify-between rounded border p-3">
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={t.is_done}
                onChange={() => toggleTask.mutate(t.id)}
              />
              <span className={t.is_done ? "line-through text-gray-400" : ""}>{t.title}</span>
            </label>
            <Button variant="destructive" size="sm" onClick={() => deleteTask.mutate(t.id)}>
              Delete
            </Button>
          </li>
        ))}
      </ul>
    </div>
  );
}
```

---

## 4.5 Trace ONE action through the whole stack (adding a task)

You type "Buy coffee" and hit **Add**. Watch every tool light up:

```
1. [React Hook Form] collects { title: "Buy coffee" }
2. [Zod] validates it in the browser → OK
3. createTask.mutate("Buy coffee")  → [TanStack Query mutation]
4. → calls createTask()  → [Axios] POST /api/v1/tasks { title: "Buy coffee" }
       (+ Authorization: Bearer <token> added by the interceptor)
5. → [Laravel route] matches → [Sanctum] verifies token → knows it's YOU
6. → [StoreTaskRequest] validates title → OK
7. → [TaskController.store] (thin) → [TaskService.createFor(you, data)]
8. → [Eloquent] Task::create(...) → [MySQL] INSERT ... user_id = you
9. → [TaskResource] wraps it → { "data": { "id": 7, "title": "Buy coffee", "is_done": false } }
10. → JSON returns → [Axios] → mutation onSuccess → invalidateQueries(["tasks"])
11. → [TanStack Query] refetches the list → [React] re-renders → "Buy coffee" appears
```

Eleven steps, ~8 tools, one button click. **This is the whole stack.** Every other feature — in TaskFlow or HRIS — is a variation of this exact relay.

---

# PART 5 — Quick "which file do I touch?" map (for any new feature)

| To add… | Backend | Frontend |
|---|---|---|
| A new table | `Modules/X/Database/Migrations/...` | — |
| Read/write that table | `Modules/X/Models/X.php` | `modules/x/types/x.ts` |
| Input validation | `Http/Requests/StoreXRequest.php` | Zod schema in the form |
| Output JSON shape | `Http/Resources/XResource.php` | (matches the TS type) |
| Business logic | `Services/XService.php` | — |
| Endpoints | `Http/Controllers/XController.php` + `Routes/api.php` | `modules/x/api/x.ts` |
| Fetch/cache data | — | `modules/x/hooks/use-x.ts` (`useQuery`/`useMutation`) |
| A screen | — | `app/x/page.tsx` |
| Buttons/inputs/tables | — | shadcn components + Tailwind |
| Auth on a route | `->middleware('auth:sanctum')` on the route | token in `localStorage` (set at login) |

---

# PART 6 — The five things to remember

1. **Two apps, one seam.** Next.js (UI) and Laravel (brain) are separate; they only talk via **Axios → HTTP → JSON**.
2. **Laravel holds ALL logic.** The frontend never calculates business rules — it asks and displays.
3. **The token is the bridge.** Login returns a token; Axios sends it on every request; that's how the stateless API knows who you are.
4. **Every request is the same relay:** Route → Sanctum → Form Request → Controller → Service → Model → MySQL → Resource → JSON → Axios → Query → React.
5. **Validate twice:** Zod in the browser (fast UX) + Form Request on the server (the real gate).

---

## Related
- Rules: `HRIS-AI-Build-Guide.md`
- Setup + build steps: `HRIS-Step-by-Step-Execution.md`
- TaskFlow here is a *learning mirror* of the HRIS "Departments" slice — same shape, different name.
