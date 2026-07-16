---
type: feature-plan
title: HRIS — Location-Verified Attendance (geofenced office time-in + field-worker selfie flow)
owner: devops@viteseo.ph
status: proposal — not yet built
pairs_with: HRIS-BUILD-PLAN.md (Slice 6 — Attendance)
extends: [Slice 6 Attendance, Slice 7 Reimbursement (upload + approval pattern reused verbatim)]
---

# Location-Verified Attendance

> **Goal.** An employee may only **Time In (office mode)** when their device is physically **inside the office geofence**. If they are legitimately off-site (field workers, client visits), they use a **Field Time-In** path instead: it captures a **selfie + location + a short reason**, saves the attendance row as **pending HR review**, and an HR officer **manually approves or rejects** it. Approved field time-ins count as attendance; rejected ones don't.
>
> This is an **extension of Slice 6 (Attendance)** in `HRIS-BUILD-PLAN.md`. It reuses two patterns the codebase already ships: the **geofence math sits where `AttendanceService::clockIn()` already sets `status`**, and the **field selfie-upload + approve/reject flow is a near-verbatim copy of `ReimbursementService`** (file upload via `Storage`, `pending → approved/rejected` transitions with `reviewed_by`/`reviewed_at`/`review_note`). Nothing here breaks the platform-merge contracts (`hr_*` prefix, `/api/v1/hr/*`, `{data}` envelope, one `users` table).

---

## 0. How this fits the existing code (read before designing)

The current attendance slice already gives us the exact seams to extend:

| Existing thing | File | What we hook into |
|---|---|---|
| `clockIn()` computes today's Manila `work_date`, sets `time_in` (UTC) + `late/present` status | `backend/app/Modules/Hr/Services/AttendanceService.php:34` | **Add the geofence gate here** — reject the clock-in when the device is outside the fence. |
| Clock endpoints take **no body** today | `AttendanceController::clockIn` (`…/Http/Controllers/AttendanceController.php:37`) + route `attendance/clock-in` (`…/Routes/api.php:42`) | Clock-in must now accept `{latitude, longitude, accuracy}`; add a `ClockInRequest`. |
| Receipt upload → `Storage::disk('public')` + `receipt_path` | `ReimbursementService::create()` (`…/Services/ReimbursementService.php`) | **Selfie upload is identical** — `->store('field-selfies','public')`. `storage:link` is already run. |
| Approve/reject with `assertStatus()` guard + `transition()` writing `reviewed_by/reviewed_at/review_note` | `ReimbursementService::approve/reject/transition` | **Field-review approve/reject is a copy** of this state machine. |
| Notifications derived from `status='pending'` rows (no table) | `NotificationService::forHr()` (`…/Services/NotificationService.php:27`) | Pending **field time-ins** slot in as a third pending-approval source → red badge + bell item, for free. |
| Timezone constant | `config/app.php:72` (`timezone_local = Asia/Manila`) | Day-boundary math already correct; we don't touch it. |
| Public disk + symlink | `config/filesystems.php:41`, `public/storage` symlink exists | Selfies are viewable at `/storage/field-selfies/…` with no extra setup. |

**Frontend seams:** `TimeClockPanel` (`frontend/src/modules/hr/components/time-clock-panel.tsx`) owns the *Time in* button; `api/attendance.ts` + `hooks/use-attendance.ts` own the calls; `types/attendance.ts` owns the shapes; the HR roster lives at `src/app/hr/attendance/`.

---

## 1. Methods to prove "actually inside the office" — survey & comparison

There are six practical ways to verify presence from a **browser-based** HRIS. None is un-spoofable; the job is to pick the best trust/effort ratio for a web app and **verify server-side**.

| # | Method | How it works | Strengths | Weaknesses / spoofability | Fit for our web HRIS |
|---|---|---|---|---|---|
| **A** | **GPS geofence** (browser Geolocation API) | Browser returns `{lat, lng, accuracy}`; server computes distance to the office coordinate; inside `radius` → allow. | Works on any phone/laptop, no hardware, no per-office setup beyond one coordinate. Directly answers "are you here?". | Needs HTTPS + user permission. GPS drifts indoors (accuracy 20–100 m). Spoofable with dev-tools / mock-location apps (mitigated by accuracy caps + IP corroboration + audit trail). | ✅ **Best primary** — pure web, one field per office, matches the requirement literally. |
| **B** | **Office IP / network allowlist** | Server reads the request's public IP; allow only if it matches the office's egress IP(s). | Zero user interaction, invisible, hard for a remote user to fake (they'd need to be on the office network / VPN). | Requires a **static office IP**; VPNs and "work-from-office-WiFi-but-routed-out" break it; useless for multi-site/mobile staff. | ✅ **Best corroborating signal** — pair with A, don't rely on alone. |
| **C** | **Wi-Fi SSID / BSSID check** | Confirm the device is on the office access point. | Strong indoor signal of presence. | **Browsers cannot read SSID/BSSID** (privacy) — needs a native/mobile app. | ❌ Not available to a web app. |
| **D** | **QR / rotating code at the door** | A poster/kiosk shows a QR (ideally time-rotating); employee scans to clock in. | Cheap, tangible, no GPS permission. | A photo of the QR can be shared; static QR is trivially copied (mitigated by rotation, which needs a kiosk). | 🟡 Nice secondary factor; not sufficient alone. |
| **E** | **BLE beacons / NFC** | Phone detects an in-office beacon/tag. | Precise indoor presence. | Hardware per site; **web BLE support is thin/native-only**. | ❌ Overkill; not web-friendly. |
| **F** | **Biometric terminal / kiosk** | Dedicated device at the door (fingerprint/face). | Very hard to spoof. | Hardware + cost + a second system to integrate; not "time in from your own device". | ❌ Out of scope for this software-only build. |

### Recommendation

**Primary: (A) GPS geofence, hardened, with (B) office-IP as an optional corroborating signal.**

Rationale:
- It's the only method that is **pure-web, hardware-free, and literally answers "are you inside the office?"** — matching the requirement with the least moving parts.
- It fits the existing code exactly: the distance check drops into `AttendanceService::clockIn()` right where `status` is already computed, and one coordinate per office is all the config it needs.
- Its weakness (spoofing) is **mitigated, not ignored**: (1) reject readings whose reported `accuracy` is worse than a threshold; (2) **recompute distance on the server** and never trust a client "I'm verified" flag; (3) persist the raw `lat/lng/accuracy` on every clock-in for an audit trail; (4) optionally require the request IP to also match the office network. For a workforce-honesty tool (not a bank vault) this is the right bar, and it degrades gracefully into the field-worker path when someone genuinely can't pass it.

**Explicitly not chosen:** C/E (browser can't read Wi-Fi/BLE), F (dedicated hardware, out of scope). D (QR) is noted as an optional future second factor. IP-only (B alone) is rejected because it can't cover a mobile workforce and dies on VPNs.

> **Selfie ≠ office verification.** In the requested design the **selfie belongs to the field path**, not the office path — its job is to give HR a human artifact to *manually* approve when GPS can't auto-verify. We do **not** attempt automated face-matching (no biometric data in schema, and it's a privacy/accuracy liability). HR eyeballs it.

### Known limitations of GPS geofencing (accept these before building)

Go in with eyes open — none of these is a blocker, but each shapes expectations and one or two need an operational decision:

1. **It is a deterrent, not a vault — it is spoofable.** A technical employee can inject fake coordinates via browser dev-tools or a mock-location app. A pure web app *cannot* make this impossible. Our answer is **detection + accountability, not prevention**: the server recomputes distance (never trusts a client "verified" flag), every clock-in stores raw `lat/lng/accuracy/distance` for an audit trail, and faking location becomes a deliberate, logged, disciplinary act rather than a casual convenience. The **Offsite path** is the honest fallback, so GPS only has to handle the easy "I'm at my desk" case.
2. **Indoor accuracy is imperfect (~20–100+ m).** Roofs/walls degrade GPS; laptops fall back to Wi-Fi positioning, desktops to city-level IP. Mitigated by a **generous 200 m fence** + a **`max_accuracy_m` cap** that rejects garbage readings + the optional **office-IP** shortcut for desktops. Expect to **tune the radius/accuracy per site** after seeing real readings.
3. **HTTPS is mandatory.** Browsers only expose geolocation on secure origins (`https://` or `http://localhost`). Dev works on localhost; production (`app.viteseo.ph`) **must** be HTTPS. Non-negotiable.
4. **Location permission can be denied.** If the employee blocks the prompt, Onsite is impossible — they're routed to Offsite (selfie + HR approval). You cannot force a browser to share location.
5. **Desktops/older devices geolocate poorly.** A deskbound PC with no GPS may only resolve to km-level. The office-IP corroboration rule exists precisely for this; without it, such users lean on Offsite.
6. **One fixed office assumed (for the IP signal).** Office-IP corroboration needs a **static egress IP** and breaks on VPNs/multi-site. GPS itself is location-agnostic, but the IP shortcut is single-site.
7. **Selfies are personal data.** The Offsite path stores photos of employees → retention policy, access limited to HR, soft-delete, and a privacy note for the platform-merge review. A compliance concern, not just a technical one.

### Stronger methods for the future (upgrade ladder — not building now)

If GPS spoofing ever becomes a *measured* problem, here's the path to stronger presence proof, ordered by how much friction/hardware each adds. **We ship Tier 0 now; the rest are documented options, not commitments.**

| Tier | Method | Why it's stronger | Cost / trade-off | When to reach for it |
|---|---|---|---|---|
| **0 — now** | **GPS geofence + office-IP + audit trail + Offsite/HR-approval** | Cheap, own-device, zero hardware; handles ~95% of honest behaviour | Deterrent-grade only; spoofable | The launch default. |
| **1 — cheap upgrade** | **Rotating-QR kiosk** — a tablet at the door shows a QR that changes every ~30 s; employee scans to clock in | You must *physically see the live code* — can't fake from home, and a screenshot is stale in 30 s. Sidesteps indoor-GPS accuracy entirely | One spare tablet + code; still web-only, still own phone. Weakness: someone at the door could relay the current code to a colleague in real time | **Recommended phase 2** if casual GPS spoofing appears. |
| **2 — hardware** | **Check-in kiosk / biometric terminal** (bolted tablet with PIN/face, or a ZKTeco-style fingerprint/face device) | Device is physically fixed to the site (and, for biometrics, tied to the person, not the phone) — hardest to spoof | Lose "clock in from my own phone"; buy/mount hardware; biometric = a separate system to integrate | When attendance drives payroll and fraud is real/costly. |
| **3 — native app** | **iOS/Android app** instead of a web page | Unlocks what browsers can't: read office **Wi-Fi SSID/BSSID**, **mock-location detection**, **device-integrity attestation** (Play Integrity / DeviceCheck) | A whole mobile app to build & ship — large scope jump from the headless web build | Only if you commit to a mobile product anyway. |

**Recommendation:** keep the current GPS plan as the build target; hold **Tier 1 (rotating-QR kiosk)** as the natural next step because it's the only upgrade that is *both stronger and still cheap/web-compatible*. Tiers 2–3 are business decisions with real cost — don't build them speculatively.

---

## 2. Target behaviour (the two paths)

```
                          press "Time in"
                                │
                    ┌───────────┴───────────┐
                    ▼                        ▼
             choose "Onsite"          choose "Offsite"
        (I'm at the office)      (field worker / off-site)
                    │                        │
        Browser asks for            open FIELD TIME-IN dialog
        geolocation permission      (selfie + reason + captured location)
                    │                        │
        ┌───────────┴──────────┐      POST field-clock-in
     granted                denied           │
        │                      │       row saved:
  send lat/lng/accuracy   422 "Location   work_mode=field, field_status=pending
        │                 needed to time  location_verified=false, time_in=now
  server recomputes        in Onsite —          │
  distance                 use Offsite."   shows in HR review queue + bell badge
        │                                       │
   ┌────┴─────┐                           HR approves ─▶ counts as attendance
 inside      outside                      HR rejects  ─▶ time_in cleared / rejected
 fence       fence
   │           │
  allow    422 "You're ~350 m from the office.
  (office   Switch to Offsite if you're working off-site."
  mode,     └─▶ offers the Offsite dialog
  verified)
```

When the employee presses **Time in**, they first pick **Onsite** or **Offsite** — the choice explicitly declares intent instead of the app silently guessing:

**Onsite** → **Office mode** (auto-verified): the browser captures location; inside the fence + acceptable accuracy → `time_in` set immediately, `location_verified=true`, `work_mode='office'`, same late/present logic as today. If location is denied, or the reading is outside the fence, we reject with a clear message **and offer to switch to Offsite**.

**Offsite** → **Field mode** (manual review): opens a dialog requiring a **selfie** and a **reason** (plus the best location we can capture). Row is written with `time_in=now`, `work_mode='field'`, `field_status='pending'`, `location_verified=false`. It is **provisional** — it shows in the employee's history marked *Pending review* and in HR's queue. HR **Approve** flips `field_status='approved'` (attendance counts); **Reject** flips `field_status='rejected'` and nulls `time_in` (or marks the day unverified — see §7 decision).

> The Onsite/Offsite choice is a **UI intent selector only** — it never relaxes enforcement. Picking "Onsite" still requires passing the server-side geofence; picking "Offsite" still requires a selfie + HR approval. A user can't self-declare their way past either gate.

---

## 3. Data model changes

### 3a. Office location(s) — where the fence lives

Two options; **pick one in §7**:

- **Option 1 — config constant (fastest, single office).** Add to `config/app.php` (or a new `config/attendance.php`):
  ```php
  'office_geofence' => [
      'latitude'  => (float) env('OFFICE_LAT', 14.5547),   // e.g. Makati
      'longitude' => (float) env('OFFICE_LNG', 121.0244),
      'radius_m'  => (int)   env('OFFICE_RADIUS_M', 200),
      'max_accuracy_m' => (int) env('OFFICE_MAX_ACCURACY_M', 100),
  ],
  ```
- **Option 2 — `hr_work_locations` table (recommended for production-quality / multi-site).** A proper `hr_*` table, merge-safe, lets HR manage sites and pin each employee to a home office later.
  ```
  hr_work_locations
    id, name, latitude decimal(10,7), longitude decimal(10,7),
    radius_m int default 200, max_accuracy_m int default 100,
    is_active bool default true, timestamps, softDeletes
  ```
  (Optionally add `work_location_id`→`hr_work_locations` (nullable) to `hr_employees` so each employee has a home office; unassigned falls back to the first active location.)

**Recommendation:** Option 2 if you want the "≥90% production" bar and expect more than one office; Option 1 if you want the smallest diff. The rest of this plan is written to work with either (a `GeofenceResolver` returns the active fence regardless of source).

### 3b. Extend `hr_attendance` (new migration — never edit the shipped one)

New file `…/Database/Migrations/2026_07_07_000001_add_location_to_hr_attendance.php`:

| Column | Type | Purpose |
|---|---|---|
| `work_mode` | `string` default `'office'` | `office` \| `field` |
| `time_in_latitude` | `decimal(10,7)` nullable | raw captured lat (audit) |
| `time_in_longitude` | `decimal(10,7)` nullable | raw captured lng (audit) |
| `time_in_accuracy_m` | `decimal(7,2)` nullable | browser-reported accuracy (m) |
| `time_in_distance_m` | `decimal(9,2)` nullable | server-computed distance to fence (audit) |
| `location_verified` | `boolean` default `false` | true only when auto-passed the fence |
| `field_status` | `string` nullable | `null` (office) \| `pending` \| `approved` \| `rejected` |
| `field_selfie_path` | `string` nullable | `field-selfies/…` on the public disk |
| `field_note` | `text` nullable | employee's "where/why" reason |
| `field_reviewed_by` | `foreignId`→`users` nullable | who decided |
| `field_reviewed_at` | `datetime` nullable | when |
| `field_review_note` | `string` nullable | HR's decision note (reason on reject) |
| `work_location_id` | `foreignId`→`hr_work_locations` nullable | (Option 2 only) which fence was used |

> Keep it additive and nullable so existing rows/seeders keep working. Add the new datetime/bool fields to the model's `$casts` and the fillable list.

---

## 4. Backend implementation (Phase-7 loop, in order)

Follow the standard slice loop from `HRIS-BUILD-PLAN.md` §11: migration → model → request → resource → service → controller → route → curl-verify → seed.

### Step 1 — Migration(s)
- (Option 2) create `hr_work_locations` + model `WorkLocation` (`$table='hr_work_locations'`, casts lat/lng/`is_active`, `SoftDeletes`).
- Add-columns migration for `hr_attendance` per §3b. Run `php artisan migrate`.

### Step 2 — Model
`app/Modules/Hr/Models/Attendance.php`: add the new columns to `$fillable`; cast `location_verified`→`bool`, `field_reviewed_at`→`datetime`, lat/lng/accuracy/distance→`decimal`/`float`. The existing `state` accessor is untouched. (Optionally add a `field_state` accessor if useful for the UI.)

### Step 3 — Geofence helper (the only genuinely new logic)
Add a private helper to `AttendanceService` (or a tiny `GeofenceResolver` service that reads config **or** `hr_work_locations`):

```php
/** Great-circle distance in metres (Haversine). */
private function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $R = 6371000.0; // Earth radius (m)
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}
```

### Step 4 — `AttendanceService` changes
Extend `clockIn()` to take the captured location and enforce the fence **before** setting `time_in`:

```php
public function clockIn(Employee $employee, float $lat, float $lng, float $accuracy): Attendance
{
    $att = Attendance::firstOrNew([
        'employee_id' => $employee->id,
        'work_date'   => $this->today(),
    ]);
    if ($att->time_in) {
        throw ValidationException::withMessages(['time_in' => ['You have already clocked in today.']]);
    }

    $fence = $this->geofence();                 // config or active hr_work_locations row
    $distance = $this->distanceMeters($lat, $lng, $fence->latitude, $fence->longitude);

    // Reject junk readings (accuracy worse than allowed) — prevents "0,0" / huge-radius spoofs.
    if ($accuracy > $fence->max_accuracy_m) {
        throw ValidationException::withMessages(['location' =>
            ["We couldn't get an accurate location (±{$accuracy} m). Move to an open area or use Field Time-In."]]);
    }
    if ($distance > $fence->radius_m) {
        throw ValidationException::withMessages(['location' =>
            ['You appear to be ~' . round($distance) . ' m from the office. Use Field Time-In if you are working off-site.']]);
    }

    $local  = $this->localNow();
    $cutoff = $local->copy()->setTime(self::WORK_START_HOUR, 0, 0);

    $att->fill([
        'work_mode'         => 'office',
        'time_in'           => now(),                       // UTC
        'status'            => $local->gt($cutoff) ? 'late' : 'present',
        'time_in_latitude'  => $lat,
        'time_in_longitude' => $lng,
        'time_in_accuracy_m'=> $accuracy,
        'time_in_distance_m'=> round($distance, 2),
        'location_verified' => true,
    ]);
    $att->save();
    return $att;
}
```

Add the **field path** (mirrors `ReimbursementService::create` for the upload, and `clockIn` for the day-key/late logic):

```php
public function fieldClockIn(Employee $employee, array $data, UploadedFile $selfie): Attendance
{
    $att = Attendance::firstOrNew(['employee_id' => $employee->id, 'work_date' => $this->today()]);
    if ($att->time_in) {
        throw ValidationException::withMessages(['time_in' => ['You have already clocked in today.']]);
    }
    $local  = $this->localNow();
    $cutoff = $local->copy()->setTime(self::WORK_START_HOUR, 0, 0);

    $att->fill([
        'work_mode'         => 'field',
        'time_in'           => now(),
        'status'            => $local->gt($cutoff) ? 'late' : 'present',
        'field_status'      => 'pending',
        'location_verified' => false,
        'field_selfie_path' => $selfie->store('field-selfies', 'public'),
        'field_note'        => $data['note'],
        'time_in_latitude'  => $data['latitude']  ?? null,   // best-effort, not enforced
        'time_in_longitude' => $data['longitude'] ?? null,
        'time_in_accuracy_m'=> $data['accuracy']  ?? null,
    ]);
    $att->save();
    return $att;
}
```

Add the **review state machine** (copy of `ReimbursementService::approve/reject/transition/assertStatus`, keyed on `field_status`):

```php
public function approveField(Attendance $att, User $reviewer): Attendance { /* assert field_status pending → 'approved' + field_reviewed_* */ }
public function rejectField(Attendance $att, User $reviewer, string $reason): Attendance
{
    // assert pending → set field_status='rejected', field_review_note=$reason,
    // field_reviewed_by/at; per §7 decision, either null time_in or leave it flagged-unverified.
}
```

Add an HR read: `pendingFieldRequests()` → `Attendance::with('employee.user')->where('field_status','pending')->latest()->get()` (feeds the queue + notifications).

> `clockOut()`, `toggleBreak()`, `adjust()`, `allForDate()` are unchanged — they already work off `time_in`. (`allForDate` may optionally surface `work_mode`/`field_status` so the HR roster can badge field/pending rows.)

### Step 5 — Form Requests
- **`ClockInRequest`** (new): `latitude` required/numeric/between:-90,90; `longitude` required/numeric/between:-180,180; `accuracy` required/numeric/min:0. `authorize()=true` (auth + ownership from token, same as `StoreReimbursementRequest`).
- **`FieldClockInRequest`** (new): `note` required/string/max:1000; `selfie` **required**/file/`mimes:jpg,jpeg,png`/`max:5120` (same rule shape as the receipt); `latitude`/`longitude`/`accuracy` **nullable** numeric (best-effort).
- **`ReviewFieldAttendanceRequest`** (new, reject): `reason` required/string/max:1000 (approve needs no body — matches reimbursement).

### Step 6 — Resource
`AttendanceResource`: add `work_mode`, `location_verified`, `field_status`, `field_note`, `field_reviewed_at`, `time_in_distance_m`, and `field_selfie_url` = `$this->field_selfie_path ? Storage::disk('public')->url($this->field_selfie_path) : null`. Keep returning datetimes as UTC (frontend formats to Manila, as today).

### Step 7 — Controller
`AttendanceController`:
- `clockIn(ClockInRequest $r)` → resolve `$r->user()->employee` (existing `clockAction` guard), call `service->clockIn($emp, ...$r->validated())`.
- `fieldClockIn(FieldClockInRequest $r)` → `service->fieldClockIn($emp, $r->validated(), $r->file('selfie'))`, return `201`.
- `pendingFieldRequests(Request $r)` (HR) → `['data' => AttendanceResource::collection($service->pendingFieldRequests())]`.
- `approveField(Request $r, Attendance $attendance)` / `rejectField(ReviewFieldAttendanceRequest $r, Attendance $attendance)` (HR) → mirror `ReimbursementController::approve/reject`.

### Step 8 — Routes (`app/Modules/Hr/Routes/api.php`)
Under the existing `auth:sanctum` group, replace/extend the clock routes:
```php
Route::post('attendance/clock-in',  [AttendanceController::class, 'clockIn']);      // now expects body
Route::post('attendance/field-clock-in', [AttendanceController::class, 'fieldClockIn']);
```
Under the `role:hr_officer` group:
```php
Route::get ('attendance/field-requests',            [AttendanceController::class, 'pendingFieldRequests']);
Route::post('attendance/{attendance}/approve-field',[AttendanceController::class, 'approveField']);
Route::post('attendance/{attendance}/reject-field', [AttendanceController::class, 'rejectField']);
```

### Step 9 — Permissions
Reuse `hr.attendance.view` for HR reads. Add `hr.attendance.review` for approve/reject (assign to `hr_officer` in `RolesAndPermissionsSeeder`). Clock/field-clock actions stay employee-self-only (guarded by `$request->user()->employee`, never a client ID — the §5.5 identity rule).

### Step 10 — Notifications (free win)
In `NotificationService::forHr()`, add a third source: pending field time-ins →
```php
$pendingField = Attendance::with('employee.user')->where('field_status','pending')->latest()->get();
// items: title "Field time-in · {name}", subtitle work_date, href '/hr/attendance', at created_at
// nav: '/hr/attendance' => $pendingField->count()
```
The bell + red badge then work with no new plumbing.

### Step 11 — curl verification (before touching the frontend)
```bash
TOKEN=…   # from tinker: User::first()->createToken('t')->plainTextToken

# inside the fence → 200, location_verified:true
curl -s -X POST :8000/api/v1/hr/attendance/clock-in \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  -d 'latitude=14.5547&longitude=121.0244&accuracy=15'

# far away → 422 with the "~N m from the office" message
curl -s -X POST :8000/api/v1/hr/attendance/clock-in \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  -d 'latitude=14.60&longitude=121.10&accuracy=15'

# field path (multipart, selfie required) → 201, field_status:pending
curl -s -X POST :8000/api/v1/hr/attendance/field-clock-in \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  -F 'note=At ACME client site, Ortigas' -F 'selfie=@/path/to/test.jpg'

# HR approve
curl -s -X POST :8000/api/v1/hr/attendance/1/approve-field \
  -H "Authorization: Bearer $HR_TOKEN" -H 'Accept: application/json'
```

### Step 12 — Seeder
In `AttendanceDemoSeeder`: make most rows `work_mode='office'`, `location_verified=true` with sample coords near the fence; add **1–2 `field_status='pending'`** field rows (with a placeholder selfie path) so the HR review queue and the bell badge have live data to demo. Set the demo employee **un-clocked today** (existing behaviour) so you can clock in live.

---

## 5. Frontend implementation

Stack rules unchanged: shared Axios client + TanStack Query + shadcn/Tailwind + RHF+Zod; kit components, tokens not hexes; no logic in Next. (Heed `frontend/AGENTS.md` — this Next.js may differ from training; check `node_modules/next/dist/docs/` for any unfamiliar API.)

### 5a. "Time in" → Onsite / Offsite chooser — `time-clock-panel.tsx`
The *Time in* button currently calls `clockInMut.mutateAsync()` with no args. Replace that single action with a **two-way chooser**: pressing *Time in* opens a small shadcn `Dialog` (or an inline two-button prompt) titled **"Where are you working today?"** with two clear choices — **Onsite (at the office)** and **Offsite (field / remote)**. This makes intent explicit rather than silently guessing from geolocation.

```ts
function getPosition(): Promise<GeolocationPosition> {
  return new Promise((resolve, reject) =>
    navigator.geolocation.getCurrentPosition(resolve, reject, {
      enableHighAccuracy: true, timeout: 10_000, maximumAge: 0,
    }));
}
```

**Choice A — "Onsite"** (office mode, auto-verified against the geofence):
1. If `!navigator.geolocation` or the page isn't secure → toast "Location isn't available on this device — use Offsite" and steer them to the Offsite dialog.
2. `await getPosition()` →
   - **success** → `clockInMut.mutateAsync({ latitude: coords.latitude, longitude: coords.longitude, accuracy: coords.accuracy })`.
     - On `422` with a `location` error → toast the server message ("You're ~N m from the office…") **and offer "Switch to Offsite"** (open the field dialog).
   - **permission denied / timeout** → toast "Location needed to time in Onsite" and offer the Offsite dialog.

**Choice B — "Offsite"** (field mode) → opens the **Field Time-In dialog** (§5b) directly — selfie + reason.

Implementation notes:
- Track the chooser with local state, e.g. `const [mode, setMode] = useState<"choose" | null>(null)` plus the existing field-dialog open state; *Time in* sets it to `"choose"`.
- Keep the buttons disabled while `state !== "not_clocked_in"` or a mutation is pending, exactly as today.
- Copy suggestion: **Onsite** subtitle "Verifies you're at the office by location"; **Offsite** subtitle "Requires a selfie + reason, approved by HR".

### 5b. Field Time-In dialog — new `field-clock-in-dialog.tsx`
A shadcn `Dialog` + RHF/Zod form:
- **Selfie (required).** Two capture options:
  - Simplest/most reliable: `<input type="file" accept="image/*" capture="user">` — on mobile this opens the front camera; on desktop it's a file picker. **Recommended for MVP** (no `getUserMedia` plumbing, works everywhere).
  - Optional upgrade: live `getUserMedia` preview + canvas snapshot → `File`. More polish, more edge cases (permissions, no camera). Defer unless time allows.
  - Show a thumbnail preview of the chosen image before submit.
- **Reason (required)** textarea — "Where are you working and why off-site?" (maps to `note`).
- Best-effort location: silently attempt `getPosition()` and attach `latitude/longitude/accuracy` if granted (not required).
- Submit as **`multipart/form-data`** (like the reimbursement receipt): build a `FormData`, append `selfie`, `note`, and optional coords. Toast "Field time-in submitted — pending HR approval". Invalidate `["hr","me","attendance"]`.

### 5c. API + hooks + types
- `api/attendance.ts`:
  - `clockIn(coords: { latitude; longitude; accuracy })` → `POST /hr/attendance/clock-in` with body.
  - `fieldClockIn(form: FormData)` → `POST /hr/attendance/field-clock-in` (multipart).
  - `getFieldRequests()` → `GET /hr/attendance/field-requests`.
  - `approveField(id)` / `rejectField(id, reason)` → the two HR endpoints.
- `hooks/use-attendance.ts`: update `useClockIn` to take coords; add `useFieldClockIn` (invalidate `MY_KEY`), `useFieldRequests`, `useApproveField`/`useRejectField` (invalidate `["hr","attendance"]` **and** `["hr","me","notifications"]` so the bell badge clears).
- `types/attendance.ts`: extend `Attendance` with `work_mode`, `location_verified`, `field_status`, `field_note`, `field_selfie_url`, `field_reviewed_at`, `time_in_distance_m`; add a `FieldStatus` union.

### 5d. HR review UI — `src/app/hr/attendance/`
Add a **"Field time-in requests"** section/tab above (or beside) the daily roster:
- kit `DataTable`: EMPLOYEE (avatar+name), WORK DATE, TIME IN (Manila), SELFIE (thumbnail → click to enlarge the `field_selfie_url`), REASON (`field_note`), STATUS (`StatusBadge`: pending amber / approved green / rejected red), ACTIONS (**Approve** / **Reject**).
- **Reject** opens a small reason dialog (required note), fires the red "Declined" toast variant (per the 2026-07-07 toast revision); **Approve** fires the green "Approved" toast. Both invalidate the queue + notifications.
- The daily roster (`allForDate`) optionally shows a small **"Field · pending"** tag on field rows so HR sees them in context.

### 5e. Employee history
Wherever the employee sees their attendance (the panel's week mini-table / an optional `/attendance` history page): show a **"Pending review"** badge on field rows so they know it isn't final; flip to Approved/Rejected after HR decides (query invalidation handles the refresh, per §5.5a).

---

## 6. Security, privacy & edge cases (don't skip — this is the "≥90% production" bar)

- **HTTPS is mandatory for geolocation.** `navigator.geolocation` only works on secure origins — `https://` **or `http://localhost`**. Localhost dev is fine; the deployed `app.viteseo.ph` must be HTTPS (it will be). Add this to the merge/deploy checklist. No CORS change needed (Bearer tokens, not cookies).
- **Never trust the client's verdict.** The browser sends raw `lat/lng/accuracy` only; the **server** recomputes distance and decides. There is no client "verified" boolean that the API accepts.
- **Anti-spoofing (accepted bar, not perfect):** accuracy cap rejects `0,0`/wildly-imprecise readings; server stores raw coords + computed distance on every row for audit; optional office-IP corroboration (`$request->ip()` in the allowlist) as a second gate; repeated far-but-claimed patterns are visible to HR in the data. We do **not** promise defeat of a determined mock-location app — that's why the field path + manual review exists as the honest fallback.
- **Selfie privacy.** Selfies are personal data: store on the non-public-listing `public` disk path (already how receipts work), serve only via authenticated HR UI, keep them behind soft-delete, and note a retention policy (e.g. purge after N months) for the platform-merge privacy review. Don't index or expose the raw directory.
- **Permission-denied UX.** If the user blocks location, don't hard-fail — route them to Field Time-In with a clear message. Never leave them unable to clock in at all.
- **Accuracy vs. radius tuning.** Default `radius_m=200`, `max_accuracy_m=100`. Indoors, GPS can read 30–80 m; a 200 m fence with a 100 m accuracy cap is forgiving enough to avoid false rejects while still catching "at home 5 km away". Make both env-configurable so you can tighten per site.
- **Timezone.** Unchanged — `work_date`, late/present, and the week window are already Manila-correct via `config('app.timezone_local')`; the geofence adds no time logic.
- **Double clock-in / already-clocked** guards already exist in `clockIn`; the field path reuses the same `time_in` check so a user can't do both.
- **Reject semantics (decide in §7).** On reject, either (a) null `time_in` + set `status='rejected'` (day doesn't count), or (b) keep `time_in` but leave `location_verified=false`/`field_status='rejected'` as a flag for payroll to handle. Pick one so `hours_worked` and reporting stay coherent.
- **Offline / no-GPS device** (desktop with no GPS): accuracy will be poor (Wi-Fi geolocation, often 100–1000 m) → likely fails the office fence → field path. That's acceptable; office staff on desktops are usually on the office network, so enabling the **IP corroboration** path lets you optionally *allow* office-IP requests even when GPS accuracy is poor (a small `if request-IP in office-allowlist → treat as verified` branch in `clockIn`).

---

## 7. Decisions for the operator (resolve before building)

1. **One office or many?** → §3a Option 1 (config, single office) vs Option 2 (`hr_work_locations` table, multi-site). *Recommended: Option 2 for production-quality; Option 1 if you want the minimal diff.*
2. **Enforce IP corroboration?** Off by default (pure GPS). Turn on only if the office has a known static egress IP.
3. **Reject behaviour** (§6): null the `time_in` (day doesn't count) **or** keep-but-flag. *Recommended: null it + `status='rejected'` for a clean attendance ledger.*
4. **Selfie capture** on the frontend: file-input `capture="user"` (MVP, recommended) vs live `getUserMedia` (polish).
5. **Geofence defaults**: confirm the real office coordinate + radius (the `14.5547 / 121.0244 / 200 m` above is a placeholder).

---

## 8. Build checklist (map to the Slice-6 "done when")

- [ ] Migration(s): `hr_work_locations` (if Option 2) + `add_location_to_hr_attendance`; model casts/fillable updated.
- [ ] `GeofenceResolver` + Haversine helper; `AttendanceService::clockIn` enforces fence; `fieldClockIn` + `approveField`/`rejectField` + `pendingFieldRequests`.
- [ ] `ClockInRequest`, `FieldClockInRequest`, `ReviewFieldAttendanceRequest`.
- [ ] `AttendanceResource` exposes the new fields + `field_selfie_url`.
- [ ] Controller actions + routes (employee clock/field + HR review) + `hr.attendance.review` permission.
- [ ] `NotificationService::forHr` includes pending field requests.
- [ ] Seeder: office rows verified + 1–2 pending field rows.
- [ ] curl: inside→200/verified, outside→422, field→201/pending, approve→approved.
- [ ] Frontend: *Time in* → Onsite/Offsite chooser; Onsite captures geolocation, Offsite opens the Field dialog (selfie+reason); HR review table; employee "Pending review" badge; mutations invalidate attendance + notifications.
- [ ] Browser E2E: allow location & clock in at office; deny/off-site → field submit → HR approves → employee row flips to Approved.
- [ ] `git commit -m "feat(hr): location-verified attendance (geofence + field selfie approval)"`.

**Done when:** an employee inside the fence clocks in normally; an employee outside is blocked with a clear message and can instead submit a Field Time-In with a selfie + reason; that request appears in HR's queue (and the bell badge); HR approves or rejects it, and the employee's attendance reflects the decision — end to end, all logic in Laravel.
