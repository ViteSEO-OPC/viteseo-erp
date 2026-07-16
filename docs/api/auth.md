# Shared Authentication — `/api/v1/auth/*`

Platform-wide auth (not HR-specific). Every module's users log in here. Issues
Sanctum personal access tokens. Source: `backend/routes/api.php` +
`backend/app/Http/Controllers/AuthController.php`.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/api/v1/auth/login` | Public | Verify credentials, mint a token, return the user + roles. |
| GET | `/api/v1/auth/me` | Bearer | Current user + roles + employee context. |
| POST | `/api/v1/auth/logout` | Bearer | Revoke the token used for this request. |

---

### POST `/api/v1/auth/login`

Log in and receive an API token. Same generic error whether the email or the
password is wrong (does not reveal which).

**Request fields** (from `LoginRequest`):

| Field | Rules |
|---|---|
| `email` | required, email |
| `password` | required, string |

**Response** (`data`):

```json
{
  "data": {
    "token": "<plain-text-token>",
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
department, management flag). Cached by the frontend for the greeting, portal
routing, and the user card. Same `data` shape as login minus `token`.

---

### POST `/api/v1/auth/logout`

Revoke only the token used for this request (other devices/sessions stay logged
in). Returns `{ "data": { "message": "Logged out." } }`.
</content>
