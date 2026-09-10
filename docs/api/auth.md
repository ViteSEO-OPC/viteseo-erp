# Shared Authentication — `/api/v1/auth/*`

Platform-wide auth (not HR-specific). Every module's users log in here. A browser
signs in to a **session**: an httpOnly cookie the page's JavaScript cannot read.
No token is ever handed to the browser. Source: `backend/routes/api.php`,
`backend/app/Http/Controllers/AuthController.php`,
`backend/app/Http/Controllers/SessionController.php`. The reasoning is in
`docs/access-lifecycle-2026-09.md` §4–§8.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/api/sanctum/csrf-cookie` | Public | Set the `XSRF-TOKEN` cookie before logging in. |
| POST | `/api/v1/auth/login` | Public (browser only) | Verify credentials, start a session, return the user + roles. |
| GET | `/api/v1/auth/me` | Session | Current user + roles + employee context. |
| POST | `/api/v1/auth/logout` | Session | End this session only. |
| POST | `/api/v1/auth/change-password` | Session | Change the password and sign every other device out. |
| GET | `/api/v1/auth/sessions` | Session | List this account's signed-in devices. |
| DELETE | `/api/v1/auth/sessions/{key}` | Session | End one device (`key` from the list). |
| DELETE | `/api/v1/auth/sessions` | Session | End every device except this one. |

## How a browser talks to the API

- Same origin as the app, `withCredentials` on, and every state-changing request
  carries `X-XSRF-TOKEN` (the value of the `XSRF-TOKEN` cookie). Axios does this
  with `withXSRFToken: true`. A missing header answers `419`.
- The request must say it comes from the app: `Referer` or `Origin` naming the
  app's host. Browsers send it on their own; do not add a `Referrer-Policy` that
  strips it, or every request stops being recognised as the browser's.
- A session ends after **2 days without use**, and after **30 days** in any case.
  `401`/`419` means "sign in again".
- The cookie is visible to its owner in DevTools (every cookie is); what httpOnly
  buys is that no script on the page — including an injected one — can read it.
  `XSRF-TOKEN` is readable by design and is not a credential.

## Scripts and machines

`login` refuses a caller that is not the app (`403 Sign in from the app.`). Servers
and automations get a named token with its own expiry instead, minted on the host:

```
php8.4 artisan tokens:issue <email> <name> [--days=90] [--rotate]
```

and send it as `Authorization: Bearer <token>`. Every token expires; one created
without a stated expiry gets 30 days.

---

### POST `/api/v1/auth/login`

Same generic error whether the email or the password is wrong (does not reveal
which). Starts a new session (the session id is regenerated).

**Request fields** (from `LoginRequest`):

| Field | Rules |
|---|---|
| `email` | required, email |
| `password` | required, string |

**Response** (`data`):

```json
{
  "data": {
    "user": { ...UserResource },
    "roles": ["hr_officer"],
    "employee": { "id": 1, "job_title": "...", "department": "...", "is_management": false }
  }
}
```

`employee` is `null` for users without an employee record (e.g. a bare admin).
The frontend uses `roles` to route to the correct portal.

---

### GET `/api/v1/auth/me`

Return the current user, roles, and minimal employee context (job title,
department, management flag). Same `data` shape as login. The frontend re-reads
it when a tab comes back to the front and when an `AccessChanged` event arrives on
`private-user.{id}`, so a role taken away shows up without a reload.

---

### POST `/api/v1/auth/logout`

End only this session (other devices stay signed in; end them from the device
list). Returns `{ "data": { "message": "Logged out." } }`. Called with a machine
token, revokes that token instead.

---

### POST `/api/v1/auth/change-password`

Changes the password, keeps this device signed in, and signs every other device
out — their sessions and any tokens.

---

### GET `/api/v1/auth/sessions`

```json
{
  "data": [
    {
      "key": "<sha256 of the session id>",
      "device": "Chrome on Windows",
      "user_agent": "...",
      "ip_address": "203.0.113.7",
      "last_active_at": "2026-09-10T08:15:00+00:00",
      "current": true
    }
  ]
}
```

Only sessions still alive are listed. `key` is a hash; the session id itself never
leaves the server.

### DELETE `/api/v1/auth/sessions/{key}` · DELETE `/api/v1/auth/sessions`

End one device, or every device but this one. A `key` that is not one of this
account's sessions answers `404`. Ending the current device's key is a logout.
