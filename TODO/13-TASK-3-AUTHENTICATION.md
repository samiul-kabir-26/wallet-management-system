# Task 3: Module 1 — Authentication Implementation

**Status:** Ready to start  
**Estimated Duration:** 4-5 hours  
**Difficulty:** Medium

---

> **Revised 2026-09-20.** The authentication model changed after requirements were clarified: three login routes (not two tracks), users may hold multiple roles across tracks, and tokens must be scoped. `CLAUDE.md` section 9 is authoritative — read it before this document.

---

## Objective

Implement Sanctum-based authentication with **three login routes**, one per panel, and **scoped tokens** that prevent privilege escalation between them.

| Route | Credentials | Second factor | Role required | Token ability |
|---|---|---|---|---|
| `POST /api/v1/auth/admin/login` | (email **or** phone) + password | 6-digit OTP | SUPER_ADMIN / ADMIN / MODERATOR | `admin` |
| `POST /api/v1/auth/agent/login` | phone + PIN | — | AGENT **and** status `APPROVED` | `agent` |
| `POST /api/v1/auth/user/login` | phone + PIN | — | USER | `user` |

---

## The One Thing That Must Not Be Got Wrong

A user may hold both admin and user roles, behind different credentials. If a token issued by a **PIN** login carries that user's full role list, then:

1. An attacker brute-forces the 5-digit PIN (100,000 combinations)
2. Logs in on the user route — legitimately, with correct credentials
3. Sends the resulting token to `PATCH /api/v1/system-settings`
4. Authorization asks "does this user hold ADMIN?" — yes → **granted**

The password and OTP guarding the admin route become decorative, because the user route is a way around them.

**Therefore every authorization check has two parts:**

1. Does the user hold the required role?
2. Does the token carry the ability entitled to exercise it?

Sanctum token abilities provide part 2 natively. Issue the ability at login; check it with `tokenCan()` on every protected route.

**Design this in from the first endpoint.** Retrofitting scope checks means re-auditing every route already written, and the one you miss is the breach.

---

## What You'll Learn

- Laravel Sanctum: token issuance, abilities, and `tokenCan()`
- Why authentication and authorization are separate questions here
- OTP generation, expiry, single-use enforcement, and attempt limiting
- Signed URLs (`URL::temporarySignedRoute()`) for one-time invite links
- Password and PIN hashing with bcrypt
- Form Requests for validation
- Service classes for business logic
- Authentication middleware and route-level ability gates

---

## Architecture Overview

```
Auth Flow:
├── Route 1 — Admin
│   ├── Login (email|phone + password)  → issues OTP
│   └── Verify OTP                      → token[admin]
├── Route 2 — Agent
│   └── Login (phone + PIN)             → token[agent]
├── Route 3 — User
│   └── Login (phone + PIN)             → token[user]
├── Cross-track acquisition
│   ├── Set PIN        (admin gains agent/user credentials)
│   ├── Grant admin    (admin issues signed invite to existing user)
│   └── Accept invite  → forced change password
├── Token Refresh
├── Password / PIN Reset
└── Logout
```

**Key Files:**
- Controllers: Handle HTTP requests
- Services: Contain business logic
- Requests: Validate input
- Middleware: Protect routes, enforce token abilities
- Resources: Format responses

---

## Prerequisite

**`TODO/22-TASK-2-REVIEW.md` must be cleared first.** Several blockers there break this module directly — `User` is missing `phone_number` and `pin` from `$fillable` (registration silently creates unusable accounts), `pin` is not hidden (every response leaks the hash), `UserFactory` references dropped columns (no test can run), and `password_changed_at` does not exist yet (the invite flow cannot be enforced).

---

## Step 1: Understand Laravel Authentication

### Concepts to Learn

1. **Authentication vs. Authorization**
   - Authentication: Who are you? (login)
   - Authorization: What can you do? (permissions)

2. **Laravel Sanctum vs. Passport**
   - Sanctum: Simpler, API tokens + sessions
   - Passport: OAuth 2.0 compliant
   - **For this project: Sanctum** — not merely because it is simpler, but because its **token abilities** solve the scoping requirement natively. `createToken($name, ['admin'])` stamps the ability onto the token; `$user->tokenCan('admin')` checks it. Without a feature like this you would be hand-rolling scope claims and their verification, which is exactly the kind of security-critical wheel not to reinvent.
   - Read the "Token Abilities" section of the Sanctum docs before writing the first login endpoint.

3. **JWT Token Structure**
   ```
   Header.Payload.Signature
   ```
   - Header: Token type, hashing algorithm
   - Payload: Claims (user_id, roles, expiry)
   - Signature: Cryptographic signature

4. **Password Hashing**
   - Use bcrypt (Laravel's default)
   - `Hash::make($password)` — Hash a password
   - `Hash::check($password, $hash)` — Verify password

5. **PIN vs. Password**
   - PIN: Shorter (5-10 digits), hashed same as password
   - Password: Longer, more complex

### Before You Code

Research:
- What is JWT?
- How does token refresh work?
- What is OTP and how is it used?
- What does "stateless authentication" mean?

---

## Step 2: Implement PIN Authentication (Routes 2 and 3)

Routes 2 and 3 share one credential — phone + PIN — and differ only in the role they require afterwards. Build the credential check **once** in a shared service method, then have each route apply its own role gate and issue its own token ability.

Resist the temptation to collapse them into a single endpoint with a `panel` parameter. Separate routes mean the agent route can independently enforce `agent_info.status = APPROVED`, and rate limits apply per route rather than being shared across both.

| | Agent route | User route |
|---|---|---|
| Path | `POST /api/v1/auth/agent/login` | `POST /api/v1/auth/user/login` |
| Credential | phone + PIN | phone + PIN |
| Role required | AGENT | USER |
| Extra gate | `agent_info.status = APPROVED` | — |
| Token ability | `agent` | `user` |

**Authenticating successfully but failing the role check must return 403, not 200.** Knowing the PIN does not make someone an agent.

### Endpoint 1: User Registration

**Route:** `POST /api/v1/auth/register`

**Request Body:**
```json
{
  "name": "John Doe",
  "phone_number": "01712345678",
  "pin": "123456",
  "role": "USER"  // or "AGENT"
}
```

**Response (201):**
```json
{
  "success": true,
  "message": "User registered successfully",
  "data": {
    "user": {
      "id": 1,
      "name": "John Doe",
      "phone_number": "01712345678",
      "roles": ["USER"],
      "is_verified": false,
      "is_active": "ACTIVE"
    },
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "token_type": "Bearer",
    "abilities": ["user"]
  }
}
```

**Business Logic:**
1. Validate input using Form Request
2. Check if phone already registered (unique constraint)
3. Hash PIN using `Hash::make()`
4. Create User record
5. Create Wallet (auto-created? or explicit?)
6. Create Cap record
7. If AGENT: Create AgentInfo (PENDING status)
8. Assign role via UserRole
9. Generate JWT token
10. Return user + token

**What to Create:**
- `app/Http/Requests/Auth/RegisterRequest.php` — Form request with validation rules
- `app/Services/Auth/UserAuthService.php` — Service class with registration logic
- `app/Http/Controllers/Auth/AuthController.php` — Controller endpoint
- `app/Http/Resources/AuthResource.php` — Format auth responses
- Route in `routes/api.php`

**Validation Rules (RegisterRequest):**
- `name`: required, string, min 2, max 255
- `phone_number`: required, phone format, unique in users table
- `pin`: required, regex (digits only), length 5-10
- `role`: required, in ['USER', 'AGENT']

**What You Should Understand:**
- How Form Requests work
- How to hash sensitive data
- How to use Eloquent factories for testing
- Wallet auto-creation strategy

---

### Endpoint 2: User Login

**Route:** `POST /api/v1/auth/login`

**Request Body:**
```json
{
  "phone_number": "01712345678",
  "pin": "123456"
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user": {
      "id": 1,
      "name": "John Doe",
      "phone_number": "01712345678"
    },
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "token_type": "Bearer"
  }
}
```

**Business Logic:**
1. Validate input using Form Request
2. Find user by phone_number
3. If not found: return 401 Unauthorized
4. Verify PIN using `Hash::check()`
5. If PIN incorrect: return 401 Unauthorized
6. Check if user is active (`is_active == 'ACTIVE'`)
7. Generate JWT token
8. Return user + token

**What to Create:**
- `app/Http/Requests/Auth/LoginRequest.php` — Validation
- Add login method to `UserAuthService`
- Add login endpoint to `AuthController`

**Validation Rules (LoginRequest):**
- `phone_number`: required, phone format
- `pin`: required, digits only

**What You Should Understand:**
- How to verify hashed data
- Token generation
- Error handling and status codes

---

## Step 3: Implement Admin Authentication (Route 1)

### Endpoint 1: Admin Login (Request OTP)

**Route:** `POST /api/v1/auth/admin/login`

**Request Body:** the identifier may be an email **or** a phone number.
```json
{
  "identifier": "admin@example.com",
  "password": "secure_password"
}
```

**Resolving the identifier:** branch on format — contains `@` means email, otherwise phone — and query that one column.

Do not write `where('email', $id)->orWhere('phone_number', $id)`. It works, but it lets a single request probe both identifier namespaces, and it is the shape of query that grows a bug the moment someone adds a third identifier type.

**Response (200):**
```json
{
  "success": true,
  "message": "OTP sent to your email. Please check your inbox.",
  "data": {
    "email": "admin@example.com"
  }
}
```

**Business Logic:**
1. Validate input using Form Request
2. Resolve the identifier by format, find user by that column
3. If not found: return 401 Unauthorized
4. Verify **`password`** using `Hash::check()` — never fall back to `pin`
5. If password incorrect: return 401 Unauthorized
6. Check the user holds SUPER_ADMIN, ADMIN, or MODERATOR. If not: 403. (There is no `user_type` column — capability comes from credentials, permission comes from roles.)
7. Generate 6-digit OTP code
8. Save OTP to `otp_tokens` table with:
   - `otp_code`: generated code
   - `purpose`: 'LOGIN'
   - `expires_at`: now + 5 minutes
   - `user_id`: admin's user_id
9. Send OTP via email (stub for now)
10. Return success message

**What to Create:**
- `app/Http/Requests/Auth/AdminLoginRequest.php`
- `app/Services/Auth/AdminAuthService.php`
- `app/Services/Otp/OtpService.php` — OTP generation and validation
- Add admin login endpoint to `AuthController`

**OtpService Responsibilities:**
- `generateOtp()` — Generate 6-digit random code
- `saveOtp($user_id, $code, $purpose)` — Save to DB with expiry
- `validateOtp($email, $otp_code)` — Check if valid, not expired, not used, attempt count < max
- `markOtpAsUsed($otp_token)` — Set used_at timestamp

**What You Should Understand:**
- OTP generation logic
- Database expiry handling
- Email sending (stub)
- Rate limiting on attempts

---

### Endpoint 2: Admin Verify OTP (Complete Login)

**Route:** `POST /api/v1/auth/admin/verify-otp`

**Request Body:**
```json
{
  "email": "admin@example.com",
  "otp_code": "123456"
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user": {
      "id": 2,
      "name": "Admin User",
      "email": "admin@example.com"
    },
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "token_type": "Bearer"
  }
}
```

**Business Logic:**
1. Validate input
2. Find user by email
3. Find OTP token for this user with purpose='LOGIN'
4. Validate OTP:
   - OTP code matches input
   - Not expired (`expires_at > now()`)
   - Not yet used (`used_at == NULL`)
   - Attempt count < max_attempts (5)
5. If validation fails:
   - Increment attempt_count
   - If attempts >= max: reject future attempts
   - Return 401 with reason
6. If valid:
   - Mark OTP as used (`used_at = now()`)
   - Generate JWT token
   - Return user + token

**What to Create:**
- `app/Http/Requests/Auth/VerifyOtpRequest.php`
- Add verify endpoint to `AuthController`
- Add verification logic to `OtpService`

**What You Should Understand:**
- Single-use tokens
- Expiration validation
- Rate limiting/attempt limiting
- Atomic updates

---

## Step 3B: Cross-Track Access Flows

A user who already exists on one track may need credentials for the other. Two directions, two different trust models.

### Case A — Admin gains agent/user access

The admin already proved who they are. They simply need a second credential.

**OTP verification is required first.** Two endpoints, both authenticated with the `admin` ability:

```
POST /api/v1/auth/set-pin/request-otp   → OTP to the registered email
POST /api/v1/auth/set-pin               → { otp_code, pin, pin_confirmation }
```

**Business logic — request-otp:**
1. Require an authenticated session with the `admin` ability
2. Generate a 6-digit OTP, `purpose = SET_PIN`, 5-minute expiry
3. Send to the account's **registered** email — never an address supplied in the request
4. Return success without echoing the code

**Business logic — set-pin:**
1. Validate the OTP: matches, unexpired, unused, `attempt_count < max_attempts`
2. Validate PIN: numeric only, 5–10 digits, confirmed
3. Hash with `Hash::make()` and store
4. Mark the OTP used
5. Return success — **do not** issue a new token here

**Why the OTP, and why to the registered address:** a stolen admin token must not be enough to mint a PIN that opens the user panel. The OTP goes to an inbox the token-holder does not control, so possession of the token alone fails. If the endpoint accepted a caller-supplied email, the check would prove only that the attacker controls the attacker's inbox — which is no check at all.

A current-password re-check would be reasonable defence-in-depth, but the email OTP already proves control of the admin identity, so it is optional rather than load-bearing.

**Setting a PIN does not grant a role.** It creates the ability to authenticate on routes 2 and 3; whether the user may actually enter those panels still depends on holding USER or AGENT. AGENT additionally requires an `agent_info` record and admin approval. Keep credential-granting and role-granting as separate operations — conflating them is how privilege creep starts.

### Case B — Existing user gains admin access

The opposite direction, and the riskier one: someone is being handed elevated permissions.

**Step 1 — `POST /api/v1/users/:id/grant-admin-access`** (admin only)

1. Authorize: only SUPER_ADMIN may grant ADMIN (per section 16 of `CLAUDE.md`)
2. Validate the target user's phone is verified
3. Assign the email and a **temporary** password
4. Leave `password_changed_at` NULL — this is what marks the password as not yet owned by the user
5. Assign the admin role
6. Generate a **signed URL** via `URL::temporarySignedRoute()` with a short expiry
7. Deliver the link (stub the mail for now)

**Step 2 — `POST /api/v1/auth/accept-invite`**

1. The `signed` middleware validates the signature and expiry — Laravel does this for you; do not hand-roll it
2. Verify identity with the temporary password
3. Issue a token, but with **no abilities** — or a single `password-change` ability

**Step 3 — `POST /api/v1/auth/change-password`**

1. Validate the new password meets policy and differs from the temporary one
2. Hash and store; set `password_changed_at` to now
3. Revoke the restricted token, issue a proper `admin`-ability token

### Enforcing the forced change

This is the part that is easy to get wrong. A `password_changed_at` column achieves nothing unless something checks it.

Add **middleware** that rejects any request from a user whose `password_changed_at` is NULL while `password` is set, allowing only the change-password route through. Middleware rather than per-controller checks, because the guarantee you want is "no route can be reached", and a per-controller check only guarantees "the routes I remembered".

**Why signed URLs over `otp_tokens`:** `URL::temporarySignedRoute()` gives you a cryptographically signed, self-expiring link with no table, no cleanup job, and no lookup. The tradeoff is that you lose the attempt-limiting and single-use tracking your OTP table already provides — a signed URL is replayable until it expires. Mitigate by keeping the expiry short and by making `accept-invite` fail once `password_changed_at` is set, which makes the link effectively single-use.

### Case C — PIN reset

**There is no self-service PIN recovery.** A user who cannot log in contacts customer care, who verifies identity out of band — a human process outside this system.

**Step 1 — `POST /api/v1/auth/pin-reset/initiate`** (MODERATOR and above)

**Body:** `{ "phone_number": "01712345678", "email": "user@example.com" }`

Two inputs doing two different jobs: the **phone number** says *whose* PIN is being reset, the **email** says *where the link goes*. They are independent — nothing requires the email to already be associated with that account.

1. Authorize: MODERATOR, ADMIN, or SUPER_ADMIN
2. Find the user by `phone_number`. Not found → return a clear error. This is authenticated staff tooling, so enumeration concerns do not apply; customer care needs to know the number is wrong
3. Confirm the account actually has a PIN to reset — an admin-only account with no PIN is not a valid target
4. Attach the email to the account
5. Handle the uniqueness collision: `users.email` is unique, so that address may already belong to someone else. Reject with a clear message rather than letting the constraint throw
6. Generate a signed URL via `URL::temporarySignedRoute()` with a short expiry
7. Send the link to that address
8. Record the initiator, the target user, the email attached, and the timestamp

**Step 2 — `POST /api/v1/auth/reset-pin`** (consumes the link)

1. The `signed` middleware validates signature and expiry
2. Validate the new PIN: numeric only, 5–10 digits, confirmed
3. Hash and store
4. **Do not issue a general-purpose token.** The link authorizes exactly one action. The user logs in normally afterwards

### Why staff must not choose the PIN value

The same PIN authorizes login **and** transactions. If a staff member set the value, they could log in on the user route and spend that user's balance — with every transaction attributed to the victim in the audit trail. Here the user sets their own PIN and staff never learn it.

This is why the obvious simpler design — an admin "reset PIN" endpoint that takes a new PIN — is wrong, and why the extra round trip through email is worth its cost.

### Residual risk — accepted deliberately

Whoever attaches the email can point the link at themselves. This is inherent to account recovery: a user with no verified channel needs someone to vouch for them, and that someone can impersonate them. The controls are procedural, not technical.

What the system must do is make it **visible**: record the initiator, the target, the address attached, and the timestamp. Treat an email attached during recovery as an audit event, not a routine field update.

Note that attaching an email grants no admin access by itself — route 1 also requires a password and an admin role, neither of which this flow provides.

### Customer care = MODERATOR

Decided. `CLAUDE.md` §4 has been updated: MODERATOR gains "Can Initiate PIN Reset" and is otherwise read-only.

This is its **only** write capability, which is what makes extending the role acceptable. The action starts a credential change but never sets a credential value — the worst a compromised MODERATOR account can do here is cause a reset link to be emailed somewhere. Contrast with letting MODERATOR set a PIN directly, which would hand it spending authority over every wallet.

When you write `UserPolicy`, resist the temptation to fold this into a general `isStaff()` check. MODERATOR can do this one thing and no other write; a broad helper will quietly grant it more the moment someone adds another staff action.

---

## Step 4: Token Refresh & Logout

### Endpoint 1: Refresh Token

**Route:** `POST /api/v1/auth/refresh-token`

**Request Body:**
```json
{
  "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGc..."
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "Token refreshed",
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "token_type": "Bearer"
  }
}
```

**Business Logic:**
1. Validate refresh_token format
2. Decode refresh token
3. Check if token is blacklisted
4. Generate new access token
5. Return new token

**What You Should Understand:**
- Refresh token strategy
- Token blacklisting/revocation
- Access token expiry (shorter) vs. refresh token expiry (longer)

---

### Endpoint 2: Logout

**Route:** `POST /api/v1/auth/logout`

**Request Header:** `Authorization: Bearer {token}`

**Response (200):**
```json
{
  "success": true,
  "message": "Logged out successfully"
}
```

**Business Logic:**
1. Extract token from header
2. Blacklist the token (prevent further use)
3. Return success

**Options for Blacklisting:**
- Option A: Store blacklisted tokens in `token_blacklist` table
- Option B: Store in Redis with expiry
- Option C: Just invalidate on the frontend (simpler for MVP)

**For now:** Implement Option C (frontend invalidates)

---

## Step 5: Password Reset Flow

### Endpoint 1: Request Password Reset

**Route:** `POST /api/v1/auth/forgot-password`

**Request Body:**
```json
{
  "email": "admin@example.com"
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "OTP sent to your email"
}
```

**Business Logic:**
1. Find user by email
2. If not found: return success anyway (security: don't leak if email exists)
3. Generate OTP with purpose='PASSWORD_RESET'
4. Send OTP via email (stub)
5. Return success

---

### Endpoint 2: Reset Password

**Route:** `POST /api/v1/auth/reset-password`

**Request Body:**
```json
{
  "email": "admin@example.com",
  "otp_code": "123456",
  "new_password": "new_secure_password"
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "Password reset successfully"
}
```

**Business Logic:**
1. Validate OTP (same validation as admin login)
2. Update user's password using `Hash::make()`
3. Mark OTP as used
4. Return success

---

## Step 6: Authentication Middleware

### Create Custom Middleware

**File:** `app/Http/Middleware/Authenticate.php`

**Responsibilities:**
1. Extract token from `Authorization: Bearer {token}` header
2. Decode and validate JWT token
3. Check token expiry
4. Extract user from token
5. Inject user into request (`$request->user()`)
6. Allow request to continue
7. If invalid: return 401 Unauthorized

**What You Should Understand:**
- Middleware lifecycle
- Request/response cycle
- Token validation
- Guard system

---

## Step 7: API Resources & Responses

### Create Response Resources

**File:** `app/Http/Resources/AuthResource.php`

**Purpose:** Format authentication responses consistently

**Format:**
```json
{
  "success": true,
  "message": "Operation successful",
  "data": {
    "user": {...},
    "token": "...",
    "token_type": "Bearer",
    "expires_in": 3600
  }
}
```

**User Resource:**
- Include: id, name, email (if admin), phone_number (if user), roles
- Exclude: password, pin, password_hash

---

## Step 8: Testing

### Unit Tests

**File:** `tests/Unit/Services/Auth/UserAuthServiceTest.php`

Test:
- PIN hashing works
- PIN verification works
- User creation with wallet + caps
- Role assignment

**File:** `tests/Unit/Services/Otp/OtpServiceTest.php`

Test:
- OTP generation (6 digits)
- OTP saving with expiry
- OTP validation (not expired, not used, attempt limiting)

### Feature Tests

**File:** `tests/Feature/Auth/RegisterTest.php`

Test scenarios:
```
✅ Register with valid phone + PIN
❌ Register with existing phone number (409 Conflict)
❌ Register with invalid phone format
❌ Register with PIN too short
✅ User wallet created automatically
✅ Roles assigned
✅ Return token in response
```

**File:** `tests/Feature/Auth/LoginTest.php`

Test scenarios:
```
✅ Login with valid phone + PIN
❌ Login with invalid phone
❌ Login with wrong PIN
❌ Login with inactive user
✅ Return token
```

**File:** `tests/Feature/Auth/AdminLoginTest.php`

Test scenarios:
```
✅ Admin login with valid email + password
❌ Admin login with wrong password
✅ OTP sent successfully
❌ Verify with invalid OTP
✅ Verify with correct OTP → token returned
❌ Verify with expired OTP
❌ Verify after max attempts exceeded
```

**File:** `tests/Feature/Auth/TokenRefreshTest.php`

Test:
- Valid refresh_token → new access token
- Invalid refresh_token → 401

**File:** `tests/Feature/Auth/LogoutTest.php`

Test:
- After logout, token should be unusable

---

## Files to Create (Summary)

### Controllers
- `app/Http/Controllers/Auth/AuthController.php`

### Requests (Validation)
- `app/Http/Requests/Auth/RegisterRequest.php`
- `app/Http/Requests/Auth/PinLoginRequest.php` — shared by agent and user routes
- `app/Http/Requests/Auth/AdminLoginRequest.php`
- `app/Http/Requests/Auth/VerifyOtpRequest.php`
- `app/Http/Requests/Auth/SetPinRequest.php`
- `app/Http/Requests/Auth/GrantAdminAccessRequest.php`
- `app/Http/Requests/Auth/AcceptInviteRequest.php`
- `app/Http/Requests/Auth/ChangePasswordRequest.php`
- `app/Http/Requests/Auth/InitiatePinResetRequest.php`
- `app/Http/Requests/Auth/ResetPinRequest.php`
- `app/Http/Requests/Auth/RefreshTokenRequest.php`
- `app/Http/Requests/Auth/ForgotPasswordRequest.php`
- `app/Http/Requests/Auth/ResetPasswordRequest.php`

### Services
- `app/Services/Auth/PinAuthService.php` — credential check shared by routes 2 and 3
- `app/Services/Auth/AdminAuthService.php`
- `app/Services/Auth/AccountInviteService.php` — Case B, signed URL issue and accept
- `app/Services/Auth/PinResetService.php` — Case C, attach email, issue link, consume it
- `app/Services/Otp/OtpService.php`

### Middleware
- `app/Http/Middleware/Authenticate.php` (custom, if needed)
- `app/Http/Middleware/EnsurePasswordChanged.php` — blocks every route but change-password while `password_changed_at` is NULL
- Route-level ability gates via Sanctum's `abilities` / `ability` middleware

### Resources
- `app/Http/Resources/AuthResource.php`

### Routes
- Update `routes/api.php` with all auth routes

### Tests
- `tests/Feature/Auth/RegisterTest.php`
- `tests/Feature/Auth/UserLoginTest.php`
- `tests/Feature/Auth/AgentLoginTest.php`
- `tests/Feature/Auth/AdminLoginTest.php`
- `tests/Feature/Auth/VerifyOtpTest.php`
- `tests/Feature/Auth/TokenAbilityTest.php` — **the privilege-escalation suite, see below**
- `tests/Feature/Auth/SetPinTest.php`
- `tests/Feature/Auth/InviteFlowTest.php`
- `tests/Feature/Auth/ForcedPasswordChangeTest.php`
- `tests/Feature/Auth/RefreshTokenTest.php`
- `tests/Feature/Auth/LogoutTest.php`
- `tests/Unit/Services/Auth/PinAuthServiceTest.php`
- `tests/Unit/Services/Otp/OtpServiceTest.php`

### The test that matters most

`TokenAbilityTest` is the one that proves the security model holds. Build the fixture deliberately: **one user holding both ADMIN and USER roles, with both a password and a PIN.**

```
✅ Admin login (password + OTP) issues a token with the `admin` ability
✅ User login (PIN) issues a token with only the `user` ability
❌ The PIN-issued token is REJECTED by an admin-only endpoint — 403
   ...even though the user genuinely holds the ADMIN role
✅ The admin-issued token is accepted by that same endpoint
❌ A PIN login by a user holding no USER role is rejected — 403, not 200
❌ An agent whose status is PENDING cannot log in on the agent route
```

The third case is the whole point. If it passes only because the user lacks the ADMIN role, the test proves nothing — the fixture must hold ADMIN for the assertion to be meaningful.

---

## Checklist

Before moving to Task 4, verify:

**Blockers cleared**
- [ ] `TODO/22-TASK-2-REVIEW.md` Rounds 1–4 complete — this module will not work otherwise

**Routes**
- [ ] Registration works (phone + PIN)
- [ ] User login works and returns a `user`-ability token
- [ ] Agent login works and returns an `agent`-ability token
- [ ] Agent login rejected when `agent_info.status` is not APPROVED
- [ ] Admin login works with email **and** with phone number
- [ ] Admin OTP verification returns an `admin`-ability token
- [ ] Authenticating correctly but lacking the route's role returns 403

**Token scoping**
- [ ] Tokens carry exactly one ability, matching their issuing route
- [ ] A PIN-issued token is refused by admin endpoints even when the user holds ADMIN
- [ ] Every protected route checks ability **and** role, not just role

**Cross-track**
- [ ] `set-pin` requires a valid `SET_PIN` OTP, not just a valid token
- [ ] The set-pin OTP goes to the **registered** email, never a caller-supplied address
- [ ] Granting admin access leaves `password_changed_at` NULL
- [ ] The signed invite link expires, and fails once the password has been changed
- [ ] While `password_changed_at` is NULL, every route except change-password is blocked

**PIN reset (Case C)**
- [ ] No self-service PIN reset endpoint exists — confirm by trying to find one
- [ ] `initiate-pin-reset` rejects an email already belonging to another user
- [ ] The reset link is single-purpose: it sets a PIN and issues no general token
- [ ] Staff never submit a PIN value anywhere in this flow
- [ ] Initiator, target, attached address, and timestamp are all recorded

**General**
- [ ] Expired tokens are rejected
- [ ] Invalid credentials return 401
- [ ] OTP expires after 5 minutes and is single-use
- [ ] Rate limiting is active on all three login routes
- [ ] No passwords or PINs in any API response — check the login response body specifically
- [ ] All tests pass

---

## Key Learnings

After completing this task, you should understand:

✅ How Laravel Sanctum works  
✅ JWT token structure  
✅ Form Requests for validation  
✅ Service classes for business logic  
✅ OTP generation and validation  
✅ Password hashing with bcrypt  
✅ Middleware for protecting routes  
✅ API resources for response formatting  
✅ Testing authentication flows  

---

## Next Task

Once complete and all tests pass, proceed to **Task 4: Users & Roles Management**.
