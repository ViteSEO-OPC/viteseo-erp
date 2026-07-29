# HRIS Geofenced Attendance — MVP Build Plan

**Goal:** verify *where* an employee clocks in. ~5-hour build. MVP that works, not perfect.

**Toggle:** Company → Rules (attendance policy). **ON** = this feature is enforced. **OFF** = today's normal clock in/out, untouched.

---

## 1. What & Why

| Decision | Why |
|---|---|
| **Office clock-in checks IP + browser geolocation** (both must pass) | IP alone is weak (VPN, mobile data); GPS alone is spoofable. Requiring both is cheap and good enough for MVP. |
| **Office config lives on `hr_attendance_policies`** (one office) | Policy already drives clock-in rules and already has a Company → Rules UI. No new settings page needed. |
| **Outside clock-in = selfie → HR approves manually → time-in = submission time** | Employee isn't punished for HR's review delay; the selfie timestamp is the proof of when they started. |
| **New table `hr_remote_checkins`** for pending selfies | Keeps `hr_attendances` clean — a row only appears there once HR approves. |
| **No third-party maps/geo APIs** | Haversine distance is simple math; IP compare is a string match. Zero external deps, zero cost. |

**Requirement:** the office needs a **static public IP** for the IP check to mean anything. If the office IP is dynamic, the IP check will keep failing — that's an ops issue, not a code issue.

---

## 2. Flow

```
Employee clicks "Clock in"
  └─ Feature OFF? → normal clock-in (current behavior, done)
  └─ Feature ON?  → modal with 2 options:

  ┌─ Option A: "I'm at the Office"
  │    Browser asks for GPS → send lat/lng to clock-in endpoint
  │    Server checks: request IP in office_ips AND haversine(lat,lng, office) ≤ radius
  │    Pass → clocked in now.  Fail → 422 "You don't appear to be at the office."
  │
  └─ Option B: "I'm Outside / Field"
       Camera capture → upload selfie (+ GPS if available)
       → hr_remote_checkins row (status=pending), employee sees "Awaiting HR approval"
       HR approves → Attendance created with time_in = remote_checkin.created_at
       HR rejects  → employee notified, can re-submit
```

Clock-out and breaks are **unchanged** in both modes.

---

## 3. Data model

**Migration 1 — add to `hr_attendance_policies`:**
| Column | Type | Notes |
|---|---|---|
| `geofence_enabled` | bool, default false | The master toggle |
| `office_lat` / `office_lng` | decimal(10,7), nullable | Office pin |
| `office_radius_m` | int, default 200 | Allowed radius in meters |
| `office_ips` | json, nullable | Allowed public IPs, e.g. `["203.0.113.10"]` |

**Migration 2 — `hr_remote_checkins`:** `id`, `employee_id` (FK), `photo_path`, `lat`/`lng` decimal(10,7) nullable, `status` enum(`pending`,`approved`,`rejected`) default `pending`, `reviewed_by` nullable FK users, `reviewed_at` nullable, `rejection_note` nullable, soft deletes, timestamps. **`created_at` = the official time-in.**

---

## 4. Endpoints (all under `/api/v1/hr`, `auth:sanctum`)

| Method | URI | Who | What |
|---|---|---|---|
| POST | `/attendance/clock-in` | Any | **Extended**: accepts optional `lat`,`lng`. When `geofence_enabled`, requires them and runs IP + radius check. When off, behaves exactly as today. |
| POST | `/attendance/remote-checkin` | Any | Multipart: `photo` (required, image, max 5MB), `lat?`,`lng?`. Creates pending row. 422 if one is already pending. |
| GET | `/me/remote-checkin` | Any | Caller's latest pending/rejected row (for status display). |
| GET | `/attendance/remote-checkins?status=pending` | HR | Approval queue. |
| POST | `/attendance/remote-checkins/{id}/approve` | HR | Creates the attendance clock-in with `time_in = created_at`, marks approved. |
| POST | `/attendance/remote-checkins/{id}/reject` | HR | `note` required. Marks rejected. |
| PUT | `/attendance-policy` | HR | Existing request gains the 5 new fields. |

---

## 5. Slices

Per-slice protocol: backend → `curl` verify → UI → browser verify → commit → stop.

### Slice 1 — Policy fields + Company Rules toggle (~45 min)
- Both migrations; add fields to `AttendancePolicy::$fillable`, casts (`office_ips` → array), `UpdateAttendancePolicyRequest`, and the policy Resource.
- Company → Rules UI (`frontend/src/app/teamora/company/rules/page.tsx`): "Geofenced clock-in" switch + lat/lng/radius/IPs inputs, shown only when ON.
- Verify: toggle ON, save, reload → values persist. Toggle OFF → nothing else in the app changes.

### Slice 2 — Office clock-in check (~45 min)
- `AttendanceService::clockIn()`: guard clause at the top — if `!geofence_enabled`, return existing logic untouched.
- If enabled: require `lat`/`lng`; check `$request->ip()` against `office_ips` and haversine distance against `office_radius_m`. Fail either → 422 with a clear message.
- Verify with `curl`: office IP + inside radius → 200; wrong IP → 422; outside radius → 422; feature OFF → works with no lat/lng.
- **Gotcha:** local dev IPs are `127.0.0.1` — seed that into `office_ips` for testing.

### Slice 3 — Clock-in modal (~60 min)
- In `time-clock-panel.tsx`: when policy says geofence ON, "Clock in" opens a modal instead of firing directly.
- **Office button** → `navigator.geolocation.getCurrentPosition` → `useClockIn({lat,lng})`. Permission denied → error toast, suggest Outside option.
- When OFF → modal never appears, existing behavior.

### Slice 4 — Outside selfie + HR approval (~90 min)
- **Employee:** modal's Outside button → camera/file input → multipart upload → "Awaiting HR approval" state (from `GET /me/remote-checkin`). Block duplicate pendings server-side.
- **HR:** simple approval queue page/dialog — photo thumbnail, employee, submitted-at, GPS if present → Approve / Reject (note required).
- Approve → `AttendanceService` creates the row with `time_in = remote_checkin.created_at` (reuse existing clock-in internals; skip the geofence check — approval *is* the verification).
- Verify with `curl`: upload → approve → attendance row exists with the submission timestamp, not the approval timestamp.

### Slice 5 — Edge cases + E2E (~60 min)
- Reject → employee can re-submit; late/undertime math already runs off `time_in`, so it just works.
- Approved-after-shift-end → row still uses submission time; HR can fix via existing `/attendance/adjust`.
- Full walkthrough: feature OFF normal clock-in → feature ON office pass/fail → outside selfie → HR approve/reject.
- `php artisan test` green; commit.

---

## 6. Explicit non-goals (MVP)

- Multiple offices / per-department locations
- Geofenced clock-**out** or continuous tracking
- Face recognition / liveness — HR eyeballs the selfie
- IP ranges/CIDR — exact-match list only
