# Structure of a Normal (Single-App, No-Modules) Project

> **Who this is for:** the same beginner audience as `PROJECT-STRUCTURE.md`. That doc explains *this* project (a modular monolith built for three teams to merge). **This** doc explains how you'd build a **normal, everyday app** — one system, one team, no merge to worry about — using the **same stack** but a **simpler layout with no modules**.
>
> If you only ever read one for future personal/normal projects, read **this** one.

---

## 1. The one-sentence difference

The stack is identical (Laravel API + Next.js + Axios + TanStack Query + Tailwind/shadcn). The **only** difference is:

> A normal app has **no `Modules/` layer, no table prefixes, and no per-module service providers.** You just use Laravel and Next.js exactly the way they ship.

Everything else — how a request flows, why Services hold logic, why Axios and TanStack Query exist — is the same. So this doc focuses on *where things go* in the simpler layout, and shows the same "Department" feature built the normal way.

*(If a term here is unfamiliar — Model, Migration, Endpoint, Hook, Provider, Interceptor — see the mini-glossary at the top of `PROJECT-STRUCTURE.md`. All the same definitions apply.)*

---

## 2. Why modules exist there but not here

Modules aren't "better" or "more professional" — they solve **one specific problem**: multiple teams building separate systems that must **combine into a single platform later** without rewrites. The prefixes (`hr_`, `crm_`), the `Modules/` folders, and the per-module providers are all machinery for that merge.

**A normal app has no merge to plan for.** One system, one team. So all that machinery would just be extra complexity for no benefit. Adding modules to a simple app is **over-engineering** — the classic beginner trap of "structuring for a scale you don't have."

Here's the same things, side by side, so the difference is concrete:

```
Modular monolith (Viteseo project)          Normal single app (this doc)
──────────────────────────────────          ─────────────────────────────
app/Modules/Hr/Models/Department.php    →    app/Models/Department.php
app/Modules/Hr/Services/...             →    app/Services/...
hr_departments  (prefixed table)        →    departments  (plain table name)
/api/v1/hr/departments                  →    /api/departments
HrServiceProvider mounts the routes     →    routes/api.php holds all routes directly
src/modules/hr/hooks/use-departments    →    src/hooks/use-departments
src/core/api-client.ts                  →    src/lib/api-client.ts
```

Notice: the **files are basically the same** — they just sit in Laravel's/Next's default folders instead of inside a module.

---

# PART A — BACKEND: a normal Laravel app

Everything lives in Laravel's **default** locations. There is **no** `app/Modules/`.

```
backend/   (or the repo root, if backend + frontend aren't split)
├── app/
│   ├── Models/                 ← ALL models: User.php, Department.php, Employee.php ...
│   ├── Http/
│   │   ├── Controllers/        ← ALL controllers: DepartmentController.php ...
│   │   ├── Requests/           ← validation: StoreDepartmentRequest.php ...
│   │   └── Resources/          ← JSON shaping: DepartmentResource.php ...
│   ├── Services/               ← business logic (optional folder, but recommended)
│   ├── Policies/               ← authorization
│   └── Providers/              ← just the default AppServiceProvider (you rarely add more)
├── bootstrap/                  ← app.php, providers.php (you DON'T add module providers)
├── config/                     ← settings
├── database/
│   ├── migrations/             ← ALL migrations; tables named plainly (departments)
│   ├── seeders/
│   └── factories/
├── routes/
│   └── api.php                 ← ⭐ ALL your API routes go here, directly
├── public/  resources/  storage/  tests/  vendor/
├── .env  .env.example  composer.json  composer.lock  artisan
```

### The same "Department" feature, built the normal way

Compare each file to its modular cousin — same code, simpler location and names.

**1. Migration** — table is just `departments` (no `hr_` prefix):
```php
// database/migrations/xxxx_create_departments_table.php
Schema::create('departments', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('code')->unique();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

**2. Model** — lives in `app/Models/`. Because the table name (`departments`) matches the class name (`Department`) by Laravel's convention, you don't even need `$table`:
```php
// app/Models/Department.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $fillable = ['name', 'code', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];
    // no $table line needed — "Department" ⇒ "departments" automatically
}
```

**3. Form Request** — validation, in `app/Http/Requests/`:
```php
// app/Http/Requests/StoreDepartmentRequest.php
public function rules(): array
{
    return [
        'name' => ['required', 'string', 'max:255'],
        'code' => ['required', 'string', 'max:50', 'unique:departments,code'],
        'is_active' => ['boolean'],
    ];
}
```

**4. Resource** — JSON shape, in `app/Http/Resources/` (same as modular).

**5. Service** — logic, in `app/Services/`. (Keeping logic out of controllers is good practice in *any* app, module or not.)
```php
// app/Services/DepartmentService.php
namespace App\Services;

use App\Models\Department;

class DepartmentService
{
    public function list() { return Department::query()->latest()->paginate(20); }
    public function create(array $data): Department { return Department::create($data); }
}
```

**6. Controller** — in `app/Http/Controllers/` (thin, same pattern).

**7. Routes** — the big simplification: **all routes go straight into `routes/api.php`.** No module route file, no provider to mount them:
```php
// routes/api.php
use App\Http\Controllers\DepartmentController;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('departments', DepartmentController::class);
    // becomes /api/departments  (or /api/v1/departments if you add a version prefix)
});
```

### What you DON'T need in a single app

- ❌ **No `app/Modules/` folder** — models/controllers/services live in `app/Models`, `app/Http/Controllers`, `app/Services`.
- ❌ **No per-module service provider** — `bootstrap/providers.php` keeps just Laravel's defaults.
- ❌ **No table prefixes** — call it `departments`, not `hr_departments`.
- ❌ **No `/{module}/` URL segment** — `/api/departments` is fine (add `/v1/` if you want versioning).

### The request flow is exactly the same (minus the module layer)
```
routes/api.php → Controller (thin) → Service (logic) → Model → database
                       ↑ validates via Request            ↓ returns Resource (JSON)
```

### Optional: when a single app gets big
If a solo app grows large, you *can* group related classes into folders (e.g. `app/Domains/Billing/`), but for the vast majority of apps the **flat Laravel default is the right, simplest choice.** Add structure only when the flat layout genuinely starts to hurt — not before.

---

# PART B — FRONTEND: a normal Next.js app

Same Next.js + Axios + TanStack Query, but organized **by type** (all hooks together, all types together) instead of by module.

```
frontend/   (or repo root)
├── src/
│   ├── app/                     ← routing + pages. File path = URL (App Router). Same as modular.
│   │   ├── layout.tsx           ← app shell + providers (same idea)
│   │   ├── page.tsx             ← "/"
│   │   └── departments/         ← /departments   (no "/hr" segment)
│   │       └── page.tsx
│   ├── components/              ← ALL components
│   │   └── ui/                  ← shadcn components
│   ├── lib/                     ← shared helpers: the API client, the query provider, utils
│   │   ├── api-client.ts        ← Axios instance (in a single app, "lib" replaces "core")
│   │   ├── query-provider.tsx   ← TanStack Query provider
│   │   └── utils.ts             ← cn() helper
│   ├── hooks/                   ← ALL data-fetching hooks: use-departments.ts ...
│   └── types/                   ← ALL TypeScript types: department.ts ...
├── public/  package.json  tsconfig.json  next.config.ts  .env.local
```

### The same Departments page, the normal way

Only the **folder locations and import paths** change — the code is identical to the modular version.

**1. Type** (`src/types/department.ts`):
```ts
export type Department = {
  id: number; name: string; code: string; is_active: boolean; created_at: string;
};
```

**2. API function** (`src/lib/api/departments.ts` — or just `src/lib/departments.ts` for a small app):
```ts
import { apiClient } from "@/lib/api-client";
import type { Department } from "@/types/department";

export async function getDepartments(): Promise<Department[]> {
  const res = await apiClient.get("/departments"); // note: no "/hr" — plain resource
  return res.data.data;
}
```

**3. Hook** (`src/hooks/use-departments.ts`):
```ts
import { useQuery } from "@tanstack/react-query";
import { getDepartments } from "@/lib/api/departments";

export function useDepartments() {
  return useQuery({ queryKey: ["departments"], queryFn: getDepartments });
}
```

**4. Page** (`src/app/departments/page.tsx` → URL `/departments`):
```tsx
"use client";
import { useDepartments } from "@/hooks/use-departments";

export default function DepartmentsPage() {
  const { data, isLoading, error } = useDepartments();
  if (isLoading) return <p>Loading…</p>;
  if (error) return <p>Failed to load.</p>;
  return <ul>{data?.map((d) => <li key={d.id}>{d.name} ({d.code})</li>)}</ul>;
}
```

### Where the shared frontend code lives
In a single app, the conventional home for shared utilities is **`src/lib/`** (not `src/core/`). So:
- `src/lib/api-client.ts` — the Axios instance + token interceptor (same code as the modular `src/core/api-client.ts`).
- `src/lib/query-provider.tsx` — the TanStack Query provider (same code), wrapped around the app in `src/app/layout.tsx`.

**Important:** Axios and TanStack Query are still worth using here! They're **good practices**, not module machinery. A single app benefits from "auth attached once" and "caching/loading handled for you" just as much as a modular one.

### Optional: feature folders for a bigger single app
If flat `src/hooks` + `src/types` + `src/components` starts feeling crowded, you can group by feature:
```
src/features/departments/
  ├── api.ts
  ├── hooks.ts
  ├── components/
  └── types.ts
```
This is a **lightweight cousin of modules** — useful for a large single app, but still optional. Start flat; reach for feature folders only when flat gets noisy.

---

# PART C — Side-by-side summary

| Aspect | Modular monolith (Viteseo) | Normal single app |
|---|---|---|
| **Why it exists** | 3 systems must merge into 1 platform | 1 system, no merge |
| **Backend models** | `app/Modules/Hr/Models/` | `app/Models/` |
| **Backend logic** | `app/Modules/Hr/Services/` | `app/Services/` |
| **Routes** | per-module `Routes/api.php` + a provider | all in `routes/api.php` |
| **URL prefix** | `/api/v1/hr/...` | `/api/departments` (plain) |
| **Table names** | prefixed: `hr_departments` | plain: `departments` |
| **Service providers** | one per module, registered in `bootstrap/providers.php` | just Laravel's default |
| **Frontend shared code** | `src/core/` | `src/lib/` |
| **Frontend feature code** | `src/modules/hr/{api,hooks,components,types}` | `src/hooks`, `src/types`, `src/components` (or `src/features/`) |
| **Auth (Sanctum) · Axios · TanStack Query · shadcn** | ✅ same | ✅ same |
| **Request flow (route→controller→service→model)** | ✅ same | ✅ same |

---

# PART D — How to choose

- **One system, one team, no future merge?** → Use this **single-app** layout. Simpler = correct. Do **not** add modules "just in case."
- **Multiple systems that must combine into one platform later (like Viteseo)?** → Use the **modular** layout (`PROJECT-STRUCTURE.md`). The extra structure earns its keep at merge time.

**What never changes either way** (this is the real, portable knowledge):
- Laravel is the only backend; Next.js is frontend only.
- One database.
- Sanctum for login tokens.
- Axios for API calls (with the token attached once via an interceptor).
- TanStack Query for fetching/caching server data.
- Tailwind + shadcn for the UI.
- Logic in Services, validation in Requests, JSON shape in Resources, thin Controllers.

> **Rule of thumb:** modules are a **merge tool, not a default.** Keep a normal app as flat as Laravel and Next.js ship it. Add structure only when a real, present need (like a multi-team merge) demands it — never for an imaginary future one.
