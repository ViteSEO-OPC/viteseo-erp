# Project Structure — Viteseo Platform (Modular Monolith)

> **Who this is for:** someone who has built **traditional Laravel (Blade)** and **Create React App**, but is new to a **headless/decoupled** setup and to this modular architecture. This document assumes **nothing is obvious**. It defines every term, shows real code, and walks through a full feature end-to-end.
>
> **Companion doc:** `SINGLE-APP-STRUCTURE.md` shows how a *normal* app (no modules) is laid out. Read this one to understand *this* project; read that one to understand everyday projects.

---

## 1. Words you need first (mini-glossary)

Don't skip this — the rest of the doc uses these constantly.

| Term | Plain meaning |
|---|---|
| **Headless / decoupled** | The backend and frontend are **two separate programs**. The backend doesn't make web pages anymore — it only sends **data**. The frontend makes all the pages. |
| **API** | A set of URLs the frontend can call to get or change data. Think of it as the backend's "menu of actions." |
| **Endpoint** | One specific API URL, e.g. `GET /api/v1/hr/departments` = "give me the list of departments." |
| **JSON** | The text format the API sends data in. Example: `{ "data": [ { "id": 1, "name": "HR" } ] }`. |
| **Monorepo** | **One** git repository that holds **both** the backend and frontend folders. |
| **Module** | A self-contained slice of the backend for one system (HR, CRM, PM). Keeps each system's code separate so they don't clash. |
| **Namespace** | PHP's way of organizing class names into folders, like `App\Modules\Hr\Models\Department`. Roughly mirrors the folder path. |
| **Eloquent / Model** | Laravel's tool for talking to a database **table** using a PHP class instead of raw SQL. One model class = one table. |
| **Migration** | A PHP file that **creates or changes a database table**. Instead of clicking around in a DB tool, you write the table as code and run it. |
| **Service provider** | A small "startup script" Laravel runs when the app boots. We use one to "plug in" each module. |
| **Trait** | A reusable bundle of methods you can drop into a class with `use SomeTrait;`. It adds abilities to a class. |
| **Middleware** | Code that runs *before* your endpoint, like a bouncer. `auth:sanctum` = "must have a valid login token to pass." |
| **Token (Sanctum)** | A long secret string the API gives a user when they log in. The frontend sends it on every request to prove who it is. |
| **Component (React)** | A reusable piece of UI (a button, a table, a whole page) written as a function that returns HTML-like markup (JSX). |
| **Hook (React)** | A special function (name starts with `use`) that adds behavior to a component — e.g. fetching data, holding state. |
| **App Router (Next.js)** | Next.js's system where **a file's location decides its URL**. `app/hr/departments/page.tsx` automatically becomes the page at `/hr/departments`. |

---

## 2. The big picture

This project is a **headless modular monolith**:
- **Headless** → backend sends JSON, frontend makes the UI (two programs).
- **Monolith** → it's *one* backend and *one* frontend (not many microservices).
- **Modular** → inside that one backend, each system (HR, CRM, PM) is a separate **module**, so three teams can build in parallel and their code **merges into one platform** with no rewrites.

Analogy: the backend is **the kitchen** (cooks data, never faces customers). The frontend is **the dining room** (everything the customer sees). They talk through a hatch (HTTP requests carrying JSON).

```
viteseo-platform-hris/          ← ONE git repo (the monorepo)
├── backend/                    ← Laravel — the ONLY backend ("kitchen"): logic + database + auth. Sends JSON.
├── frontend/                   ← Next.js — the frontend ONLY ("dining room"): every page & button. Calls the API.
├── docs/                       ← guides + the official standards
├── .gitignore                  ← tells git which files to never save
├── PROJECT-STRUCTURE.md         ← THIS file
└── SINGLE-APP-STRUCTURE.md      ← the "normal app" version
```

**Four rules that explain why everything is shaped the way it is:**
1. **Laravel is the only backend.** All logic, data, and login checks happen here. The frontend never contains business rules.
2. **One database, shared by all modules.** To stop tables from clashing, each module's tables are **prefixed**: HR → `hr_`, CRM → `crm_`, PM → `pm_`. Shared tables (like `users`) have **no** prefix.
3. **Each system is a module, not its own app.** Shared things live in "Core"; system-specific things live in `Modules/Hr`, `Modules/Crm`, etc.
4. **Shared things are built once.** The `users` table, the login system, the frontend's Axios client — made once, reused everywhere.

---

# PART A — BACKEND (`backend/` is a Laravel project)

When you ran `composer create-project laravel/laravel backend`, Laravel created a standard project inside `backend/`. Most folders below came with Laravel. The **one thing we added** is `app/Modules/`.

## A.1 The backend root folders — what each is for

| Folder / file | What it holds | Everyday example | Do you touch it? |
|---|---|---|---|
| `app/` | **All your PHP code.** This is where you spend 95% of your time. | Your models, controllers, services, and the HR module. | ✅ constantly |
| `bootstrap/` | App startup files. The important one is `bootstrap/providers.php` — a **list of service providers** Laravel loads on boot. | We added a line here so Laravel loads the HR module. | ✅ once per module |
| `config/` | Settings files, one per topic. | `database.php` (DB settings), `sanctum.php` (login tokens), `permission.php` (roles). | ✅ occasionally |
| `database/` | `migrations/` (tables as code), `seeders/` (starter data), `factories/` (fake data for tests). | `create_users_table.php`. | ✅ (shared tables) |
| `routes/` | Lists of URLs. `api.php` is the base API route file; `web.php` is for web pages (mostly unused here). | Module routes live *in the module*, not here. | rarely |
| `public/` | The single file the web server actually opens (`index.php`). Everything is routed through it. | You never put code here. | ❌ |
| `resources/` | Blade templates + old-style CSS/JS. **Unused** in headless (Next.js is the UI now). | — | ❌ |
| `storage/` | Auto-generated stuff: logs, cache, uploaded files. | Error logs land here. | ❌ (git ignores it) |
| `tests/` | Automated tests. | Later. | ✅ later |
| `vendor/` | Third-party PHP packages Composer installed. **Never edit or commit this.** | Laravel itself, Sanctum, spatie live here. | ❌ (git ignores it) |
| `.env` | **Your secrets + settings** (database password, app key). Different on each machine. **Never committed to git.** | `DB_PASSWORD=...`. | ✅ |
| `.env.example` | A copy of `.env` with the secrets blanked out. This one *is* committed, as a template. | — | ✅ |
| `composer.json` | The list of PHP packages your project wants. | "I need Laravel 13, Sanctum, spatie." | ✅ |
| `composer.lock` | The **exact** versions that got installed, so every teammate installs identical ones. Commit it. | — | ✅ |
| `artisan` | Laravel's command-line tool. You run things like `php artisan migrate` from inside `backend/`. | — | you run it |

> 🔑 **Why `.env` is never committed:** it has your database password. Git is shared; secrets in git = secrets leaked. So `.env` stays local and `.env.example` (no secrets) is the shared template.

## A.2 Inside `app/` — and the crucial "namespace" idea

```
backend/app/
├── Http/Controllers/   ← Laravel's default controllers live here (base Controller.php)
├── Models/             ← SHARED models. User.php is here (all systems share one users table).
├── Providers/          ← Laravel's default service provider
└── Modules/            ← ⭐ WE ADDED THIS. One folder per system.
    └── Hr/             ← the HR module (your POD's home)
```

**What is `app/` really?** It's just the folder where Laravel keeps your PHP classes. It is **not** "the whole application" — it's one folder among many in `backend/`. A very common beginner mix-up is thinking everything moves *into* `app/`. It doesn't. `bootstrap/`, `config/`, `routes/`, etc. stay where they are.

**Why is `Modules` placed *inside* `app/`?** Because of **namespaces**. Laravel has a rule (called PSR-4 autoloading): the `App\` namespace maps to the `app/` folder. So a file at:

```
app/Modules/Hr/Models/Department.php
```
automatically becomes the class:
```php
App\Modules\Hr\Models\Department
```
with **zero extra setup**. The folder path *is* the class name. Put it anywhere outside `app/` and you'd have to hand-configure autoloading. That's the whole reason `Modules` lives inside `app/`.

**Shared vs module — the split inside `app/`:**
- **Shared / Core** = top-level `app/Models`, `app/Http`, `app/Providers`. Used by *every* module. Example: `User.php` — there is **one** users table for the entire platform, so the User model is shared, not copied per module.
- **Module-specific** = `app/Modules/Hr/...`. Belongs to HR only.

## A.3 `app/Modules/Hr/` — the HR module, folder by folder (with code)

This is where nearly all HR feature code goes. Here's every folder, what it means in plain English, and a real example.

```
app/Modules/Hr/
├── Models/          ← one class per hr_* table (talks to the DB)
├── Http/
│   ├── Controllers/ ← thin "traffic cops": receive request → call a Service → return a Resource
│   ├── Requests/    ← validation rules (is the incoming data valid?)
│   └── Resources/   ← decides the exact JSON shape sent back
├── Services/        ← ⭐ the actual business logic (the real work)
├── Policies/        ← permission checks ("is this user allowed?")
├── Routes/
│   └── api.php      ← the module's list of endpoints (auto-mounted at /api/v1/hr)
├── Database/
│   ├── Migrations/  ← create/change hr_* tables (as code)
│   └── Seeders/     ← insert starter data (permissions, demo rows)
└── Providers/
    └── HrServiceProvider.php  ← the "glue" that plugs this module into Laravel
```

Now each one, dumbed down with an example:

### `Models/` — the database-table objects
A **Model** is a PHP class that represents one table. Instead of writing SQL like `SELECT * FROM hr_departments`, you write `Department::all()`.

```php
// app/Modules/Hr/Models/Department.php
namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $table = 'hr_departments';                // which table this maps to
    protected $fillable = ['name', 'code', 'is_active']; // columns allowed to be filled from a form
    protected $casts = ['is_active' => 'boolean'];       // return true/false, not 1/0
}
```
- `$table` — because our table is `hr_departments` (prefixed), we tell the model its name.
- `$fillable` — a safety list. Only these columns can be set from user input (prevents a hacker from sneaking in extra fields — called "mass assignment protection").
- `$casts` — converts DB values into nicer PHP types (the DB stores `1`, but you get `true`).

### `Http/Requests/` — the validation rules
A **Form Request** is a class that holds "what counts as valid input." If the data fails, Laravel automatically rejects it with a `422` error — you write nothing extra.

```php
// app/Modules/Hr/Http/Requests/StoreDepartmentRequest.php
class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool { return true; } // later: check a permission

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:hr_departments,code'],
            'is_active' => ['boolean'],
        ];
    }
}
```
Why separate? So controllers stay clean and rules live in one obvious place.

### `Http/Resources/` — the JSON shaper
A **Resource** decides exactly which fields go out in the JSON (and hides internal ones). It also guarantees the standard `{ "data": ... }` envelope every module must follow.

```php
// app/Modules/Hr/Http/Resources/DepartmentResource.php
class DepartmentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
```

### `Services/` — the actual logic (THE important rule)
A **Service** holds the real work: creating records, calculating, approving leave, etc. **Business logic goes here — never in the controller.** This makes it testable and reusable.

```php
// app/Modules/Hr/Services/DepartmentService.php
class DepartmentService
{
    public function list()
    {
        return Department::query()->latest()->paginate(20); // newest first, 20 per page
    }

    public function create(array $data): Department
    {
        return Department::create($data);
    }
}
```

### `Http/Controllers/` — the thin traffic cop
A **Controller** is the entry point for an endpoint. Its job is tiny: take the request, hand off to the Service, return a Resource. Notice how little it does:

```php
// app/Modules/Hr/Http/Controllers/DepartmentController.php
class DepartmentController extends Controller
{
    public function __construct(private DepartmentService $service) {}

    public function index()
    {
        return DepartmentResource::collection($this->service->list());
    }

    public function store(StoreDepartmentRequest $request)
    {
        $department = $this->service->create($request->validated());
        return new DepartmentResource($department);
    }
}
```
See the pattern: **validate (via the Request type-hint) → call service → return resource.** No logic here.

### `Policies/` — the permission checker
A **Policy** answers "is this user allowed to do this action?" (e.g. only HR officers can delete a department). Kept separate from logic.

### `Routes/api.php` — the module's endpoint menu
Lists the URLs and which controller method handles each. Everything inside the `auth:sanctum` group requires a valid login token.

```php
// app/Modules/Hr/Routes/api.php
Route::middleware('auth:sanctum')->group(function () {
    Route::get('departments', [DepartmentController::class, 'index']);   // becomes GET /api/v1/hr/departments
    Route::post('departments', [DepartmentController::class, 'store']);  // becomes POST /api/v1/hr/departments
});
```
(The `/api/v1/hr` prefix is added automatically by the provider — see below.)

### `Database/Migrations/` — the table blueprint
A **Migration** builds the table in code. Run `php artisan migrate` and Laravel creates it.

```php
// app/Modules/Hr/Database/Migrations/xxxx_create_hr_departments_table.php
Schema::create('hr_departments', function (Blueprint $table) {
    $table->id();                 // auto-increment primary key
    $table->string('name');
    $table->string('code')->unique();
    $table->boolean('is_active')->default(true);
    $table->timestamps();         // created_at + updated_at (required by the standard)
    $table->softDeletes();        // deleted_at — HR records are hidden, not truly deleted
});
```
Note the `hr_` prefix — mandatory so HR/CRM/PM tables never collide in the shared DB.

### `Database/Seeders/` — starter data
Inserts rows you always need — e.g. the permission list (`hr.departments.view`) or demo data.

### `Providers/HrServiceProvider.php` — the glue
This is the "startup script" that **activates** the module. Without it, Laravel wouldn't know your module folder exists.

```php
// app/Modules/Hr/Providers/HrServiceProvider.php
class HrServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Prefix every HR route with /api/v1/hr
        Route::prefix('api/v1/hr')->middleware('api')
            ->group(__DIR__ . '/../Routes/api.php');

        // Auto-find HR migrations inside the module
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }
}
```
Then we tell Laravel to run it, in `bootstrap/providers.php`:
```php
return [
    App\Providers\AppServiceProvider::class,
    App\Modules\Hr\Providers\HrServiceProvider::class,   // ← this line activates HR
];
```

## A.4 Full worked example — one request, start to finish

When the browser asks for the departments list, here's the exact journey (each step is one of the folders above):

```
1. Request arrives: GET /api/v1/hr/departments  (with header: Authorization: Bearer <token>)
2. auth:sanctum middleware checks the token → valid? continue. invalid? 401.
3. Routes/api.php matches the URL → sends it to DepartmentController@index
4. Controller (thin) calls DepartmentService->list()
5. Service asks the Department Model → Model runs SQL on hr_departments → rows come back
6. Controller wraps rows in DepartmentResource → becomes { "data": [...] , "meta": {...} }
7. JSON is sent back to the browser.
```

Every feature you build is a new copy of this loop. That's *why* the module has exactly these folders — each folder is one step in the journey.

## A.5 Shared / framework files we changed (outside the module)

Not everything is HR-specific, so some edits happen in shared places:

| File | What we changed | Why it's outside the module |
|---|---|---|
| `app/Models/User.php` | Added `HasApiTokens` (login tokens) + `HasRoles` (roles) traits. | There's one `users` table for everyone → User is shared/Core. |
| `bootstrap/providers.php` | Registered `HrServiceProvider`. | This is how Laravel switches the module on. |
| `config/permission.php` | Published by the spatie roles package. | Roles/permissions are shared across all modules. |
| `config/sanctum.php` | Added by `php artisan install:api`. | Login-token settings, app-wide. |
| `.env` | Set `DB_DATABASE=viteseo_platform`, `DB_USERNAME=viteseo`, etc. | Connects Laravel to your MySQL database. |
| `database/migrations/*` | Default `users`, `cache`, `jobs`, `personal_access_tokens`, `permission_tables`. | Shared/base tables — not owned by any one module. |

---

# PART B — FRONTEND (`frontend/` is a Next.js app)

`frontend/` holds **all the UI**. It contains **zero business logic** — when it needs data, it asks the Laravel API. A few frontend-only terms first:

| Term | Meaning |
|---|---|
| **Client Component** | A component that runs in the **browser** (needed for anything interactive: clicks, state, data fetching). Marked with `"use client";` at the top of the file. |
| **Server Component** | A component that runs on the **server** before being sent to the browser. This is the Next.js default. Can't use browser-only things like `localStorage`. |
| **Provider** | A component you wrap around your app so every component inside can access some shared thing (like the data cache). |
| **Interceptor** | A rule Axios runs automatically on every request — we use it to attach the login token so you don't do it by hand each time. |

## B.1 The frontend root folders

| Folder / file | What it holds | Edit? |
|---|---|---|
| `src/` | **All your code** (pages, components, modules). | ✅ constantly |
| `public/` | Static files served exactly as-is (images, icons). | occasionally |
| `package.json` | The list of JS packages the project wants. | ✅ |
| `package-lock.json` | The exact installed versions (commit it). | ✅ |
| `tsconfig.json` | TypeScript settings. Holds the import alias `@/*` → `./src/*` (so you write `@/core/...` instead of long `../../..` paths). | rarely |
| `next.config.ts` | Next.js settings. | rarely |
| `components.json` | shadcn/ui settings (style preset, where components are saved). | rarely |
| `.env.local` | Frontend settings like `NEXT_PUBLIC_API_BASE_URL`. **Not committed.** | ✅ |
| `eslint.config.mjs`, `postcss.config.mjs` | Code-linting + CSS tooling. | ❌ |
| `AGENTS.md`, `CLAUDE.md` | Instructions for AI coding tools (Next 16 shipped these; they tell AIs "this Next.js has breaking changes, read the docs"). | ❌ |

## B.2 Inside `src/` — mirrors the backend's Core/Module split

```
frontend/src/
├── app/                    ← ROUTING + pages. File location = URL (App Router).
│   ├── layout.tsx          ← the shell wrapping EVERY page (global providers go here)
│   ├── page.tsx            ← the home page "/"
│   ├── globals.css         ← app-wide styles (Tailwind)
│   └── hr/                 ← HR pages, e.g. hr/departments/page.tsx → the URL /hr/departments
├── core/                   ← ⭐ SHARED by every module (built once)
│   ├── api-client.ts       ← the one Axios instance (auto-attaches the login token)
│   └── query-provider.tsx  ← the one TanStack Query provider (shared data cache)
├── modules/                ← per-system frontend code (mirrors backend Modules/)
│   └── hr/
│       ├── api/            ← functions that call the API, e.g. getDepartments()
│       ├── hooks/          ← TanStack Query hooks, e.g. useDepartments()
│       ├── components/     ← HR-specific UI pieces
│       └── types/          ← TypeScript types, e.g. Department
├── components/
│   └── ui/                 ← shadcn/ui components you OWN (button.tsx, input.tsx, table.tsx)
└── lib/
    └── utils.ts            ← the cn() helper (merges CSS class names) used by shadcn
```

## B.3 Full worked example — the Departments page, front to back

This is the frontend half of the same feature. Four small files, each in a folder above:

**1. The type** (`src/modules/hr/types/department.ts`) — describes the shape of a department so TypeScript can catch mistakes:
```ts
export type Department = {
  id: number;
  name: string;
  code: string;
  is_active: boolean;
  created_at: string;
};
```

**2. The API function** (`src/modules/hr/api/departments.ts`) — the actual call, using the shared Axios client:
```ts
import { apiClient } from "@/core/api-client";
import type { Department } from "../types/department";

export async function getDepartments(): Promise<Department[]> {
  const res = await apiClient.get("/hr/departments"); // baseURL already includes /api/v1
  return res.data.data;                               // unwrap the { data: [...] } envelope
}
```

**3. The hook** (`src/modules/hr/hooks/use-departments.ts`) — wraps the call in TanStack Query so loading/caching are handled:
```ts
import { useQuery } from "@tanstack/react-query";
import { getDepartments } from "../api/departments";

export function useDepartments() {
  return useQuery({ queryKey: ["hr", "departments"], queryFn: getDepartments });
}
```

**4. The page** (`src/app/hr/departments/page.tsx`) — the screen. Its file path *is* the URL `/hr/departments`:
```tsx
"use client"; // needs the browser because it uses a hook

import { useDepartments } from "@/modules/hr/hooks/use-departments";

export default function DepartmentsPage() {
  const { data, isLoading, error } = useDepartments();

  if (isLoading) return <p className="p-6">Loading…</p>;
  if (error) return <p className="p-6 text-red-600">Failed to load.</p>;

  return (
    <ul className="p-6 space-y-2">
      {data?.map((d) => (
        <li key={d.id} className="rounded border p-3">{d.name} ({d.code})</li>
      ))}
    </ul>
  );
}
```

## B.4 The frontend data flow (who calls whom)

```
page.tsx  ── uses ──▶  use-departments.ts (hook: manages loading/cache)
                          └─ calls ──▶  departments.ts (api function)
                                          └─ uses ──▶  api-client.ts (Axios: HTTP + token)
                                                         └─ HTTP ──▶  Laravel  GET /api/v1/hr/departments
```
- **Axios** (`api-client.ts`) = *how* the request is sent (and it attaches your login token automatically).
- **TanStack Query** (`use-departments.ts`) = *when* to fetch + caching + `isLoading`/`error` for free.

### The two shared core files, explained

**`src/core/api-client.ts`** — one pre-configured Axios "phone line" every module reuses:
```ts
export const apiClient = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_BASE_URL, // so you write short paths like "/hr/departments"
  headers: { Accept: "application/json" },
});

apiClient.interceptors.request.use((config) => {
  if (typeof window !== "undefined") {            // only in the browser (server has no localStorage)
    const token = localStorage.getItem("token");
    if (token) config.headers.Authorization = `Bearer ${token}`; // attach login token to EVERY call
  }
  return config;
});
```

**`src/core/query-provider.tsx`** — one shared data cache wrapping the whole app:
```tsx
"use client"; // uses hooks/state → must run in the browser

export function QueryProvider({ children }: { children: React.ReactNode }) {
  const [client] = useState(() => new QueryClient()); // created ONCE, not on every re-render
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}
```
It's wrapped around everything in `src/app/layout.tsx`, so any page can fetch/cache data.

---

# PART C — Setup & changes we made (the story so far)

1. **Toolchain:** PHP **8.4** + extensions, Composer **2.10**, Node **24** (via nvm), MySQL **8.4 LTS**.
   - MySQL was tricky: the built-in `apt` only offered 8.0, so we added Oracle's official MySQL repo (using the renewed **2025** signing key, pinned to Ubuntu `noble` because this machine is Linux Mint) to get 8.4. Then created the shared database `viteseo_platform` and user `viteseo`.
2. **Repo:** renamed the folder to `viteseo-platform-hris`, moved the guides into `docs/`, ran `git init`, added `.gitignore`.
3. **Backend:** created Laravel 13 in `backend/`; pointed `.env` at MySQL; ran migrations; installed **Sanctum** (login tokens) and **spatie/laravel-permission ^6** (roles); added the `HasApiTokens` + `HasRoles` traits to `User`.
4. **HR module:** created `app/Modules/Hr/...`, the empty `Routes/api.php`, the `HrServiceProvider`, and registered it in `bootstrap/providers.php`. Verified the `/api/v1/hr` prefix works.
5. **Frontend:** created Next.js 16 (TypeScript, Tailwind, App Router); installed `axios` (**1.18.1**, security-checked against a known bad version), `@tanstack/react-query`, `react-hook-form`, `zod`; set up **shadcn/ui** (Radix + Nova preset); created `.env.local`.
6. **Frontend fix:** the app got created **without** a `src/` folder, so we moved `app/`, `components/`, `lib/` into `src/` and repointed the `@/*` alias — to match the standard's required `src/` layout.
7. **Shared core:** created `src/core/api-client.ts` and `src/core/query-provider.tsx`, wrapped the app in the provider, and confirmed everything compiles with a successful `next build`.

---

# PART D — "Where does X go?" cheat sheet

| You're adding… | Backend (`backend/app/Modules/Hr/`) | Frontend (`frontend/src/`) |
|---|---|---|
| A new table | `Database/Migrations/xxxx_create_hr_<name>_table.php` | — |
| A data model | `Models/<Name>.php` | `modules/hr/types/<name>.ts` |
| Validation | `Http/Requests/Store<Name>Request.php` | (Zod schema in the form) |
| API output shape | `Http/Resources/<Name>Resource.php` | — |
| Business logic | `Services/<Name>Service.php` | — (never in the frontend) |
| An endpoint | `Http/Controllers/<Name>Controller.php` + a line in `Routes/api.php` | `modules/hr/api/<name>.ts` |
| Authorization | `Policies/<Name>Policy.php` | (role checks in the UI) |
| Permission list | `Database/Seeders/HrPermissionsSeeder.php` | — |
| Data-fetching hook | — | `modules/hr/hooks/use-<name>.ts` |
| A screen / page | — | `app/hr/<name>/page.tsx` |
| A reusable UI piece | — | `modules/hr/components/<Thing>.tsx` |

**Naming rules:** tables `hr_*` (plural, snake_case) · endpoints `/api/v1/hr/<plural>` · permissions `hr.<resource>.<action>` · money `decimal(12,2)` (never a float) · dates in ISO 8601 UTC · soft-delete HR records (hide, don't erase).

**The three hard boundaries:**
1. HR-specific code → **inside** `app/Modules/Hr/` (backend) and `src/modules/hr/` (frontend).
2. Shared concepts (User, config, the Axios client, the query provider) → Core — edit carefully because everyone depends on them.
3. **Never** touch another module's folder (`app/Modules/Crm`) or read another module's tables (`crm_*`) directly. If you need their data, call a public Service they expose.
