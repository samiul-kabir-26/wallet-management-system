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

3. **Sanctum Token Structure**
   - Not JWT. A Sanctum personal access token is a random plaintext string returned once at creation; only its **SHA-256 hash** is stored in the `personal_access_tokens` table, alongside `name`, `abilities` (JSON array), `tokenable_id`/`tokenable_type`, and `last_used_at`.
   - There is nothing to "decode" — the server looks up the hash on every request. No header/payload/signature, no client-side claims.
   - This is why revocation is trivial: `delete()` the row and the token is dead immediately, unlike a self-contained JWT which stays valid until it expires unless you maintain a separate blacklist.

4. **Password Hashing**
   - Use bcrypt (Laravel's default)
   - `Hash::make($password)` — Hash a password
   - `Hash::check($password, $hash)` — Verify password

5. **PIN vs. Password**
   - PIN: Shorter (5-10 digits), hashed same as password
   - Password: Longer, more complex

### Before You Code

Research:
- How do Sanctum token abilities work — `createToken($name, $abilities)` and `tokenCan()`?
- What is OTP and how is it used?
- What does "stateless authentication" mean, and how does Sanctum achieve it for an SPA/mobile client without sessions?

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
  "pin_confirmation": "123456",
  "role": "USER"  // or "AGENT"
}
```

**`pin_confirmation` added beyond the original spec** — the PIN is the sole credential for both login and transaction authorization (§10), and there's no self-service recovery if mistyped at registration (§9 Case C requires staff intervention). Laravel's `confirmed` validation rule enforces this; `pin_confirmation` is never persisted, only compared.

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
    "token": "1|p7X9...plaintext-once...",
    "token_type": "Bearer",
    "abilities": ["user"]
  }
}
```

**Business Logic (wrap steps 2–9 in a single `DB::transaction()` — a failure partway through must not leave a half-registered user, per CLAUDE.md §7/§18):**
1. Validate input using Form Request
2. Check if phone already registered (unique constraint)
3. Hash PIN using `Hash::make()`
4. Create User record
5. Create Wallet (auto-created? or explicit?)
6. Create Cap record
7. If AGENT: Create AgentInfo (PENDING status)
8. Assign role via UserRole
9. Generate Sanctum token with the `user` (or `agent`) ability
10. Return user + token

**Architectural note — module boundary seam, deliberately deferred:** steps 5–7 (`Wallet`, `Cap`, `AgentInfo` creation) are arguably not Authentication's responsibility — they belong to future `Wallets`/`Agents` modules. Written **inline in the Authentication registration service for now**, since those modules don't exist yet and building empty module shells prematurely isn't justified (§30). Revisit later: the clean seam is a `UserRegistered` event that a future `Wallets`/`Agents` module listens for, decoupling registration from what happens as a side effect of it. Not a blocker for this task — just don't be surprised this service does more than "authentication" strictly implies.

**What to Create (paths under `Modules/Authentication/`, not `app/` — see architecture note at top of this doc):**
- `Modules/Authentication/Http/Requests/RegisterRequest.php` — Form request with validation rules
- `Modules/Authentication/Services/RegistrationService.php` — service class with registration logic (name your call; this is the suggested one)
- `Modules/Authentication/Http/Controllers/AuthController.php` — new action on the existing controller
- `Modules/Authentication/Resources/AuthResource.php` — already exists, reused as-is
- Route in `Modules/Authentication/routes.php`

**Validation Rules (RegisterRequest):**
- `name`: required, string, min 2, max 255
- `phone_number`: required, string, BD mobile format `/^01[3-9]\d{8}$/`, unique in users table
- `pin`: required, `digits_between:5,10`, `confirmed` (requires sibling `pin_confirmation`)
- `role`: required, in ['USER', 'AGENT']

**What You Should Understand:**
- How Form Requests work
- How to hash sensitive data
- How to use Eloquent factories for testing
- Wallet auto-creation strategy

---

### Endpoint 2 & 3: Agent Login and User Login

**Routes:** `POST /api/v1/auth/agent/login`, `POST /api/v1/auth/user/login` — two routes, same request/response shape, different role gate and token ability.

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
    "token": "1|p7X9...plaintext-once...",
    "token_type": "Bearer",
    "abilities": ["user"]
  }
}
```

The `token` value is a one-time plaintext string Sanctum generates at `createToken()` — it is never recoverable again after this response, only its hash persists server-side.

**Business Logic (shared, in `PinAuthService::attempt(string $phoneNumber, string $pin): User`):**
1. Validate input using Form Request
2. Find user by `phone_number`
3. If not found: run a dummy `Hash::check()` against a throwaway hash anyway (see "Enumeration & timing" below), then throw `InvalidCredentialsException`
4. Verify PIN using `Hash::check()` — never fall back to `password`
5. If PIN incorrect: throw `InvalidCredentialsException` — same exception, same message as step 3
6. Check if user is active (`is_active == 'ACTIVE'`); if not, throw `AccountInactiveException`

**Then, per route, not shared:**
- Agent route: confirm the user holds AGENT **and** `agent_info.status == 'APPROVED'`; if not, 403. Issue `createToken('agent-login', ['agent'])`.
- User route: confirm the user holds USER; if not, 403. Issue `createToken('user-login', ['user'])`.

**Enumeration & timing — why steps 3 and 5 must be indistinguishable:**
"No such phone number" and "wrong PIN" must produce the *identical* exception type and message. If they differ, an attacker can confirm which phone numbers are registered without ever guessing a PIN. `InvalidCredentialsException` covers both branches — the distinction never crosses out of the service.

This also has a timing dimension: "not found" returns after one fast failed query, while "wrong PIN" costs a full bcrypt `Hash::check()`. Even with identical messages, response-time alone can leak which case occurred. Mitigate by running a dummy `Hash::check()` against a throwaway hash in the not-found branch too, so both paths cost roughly the same.

`is_active` is a different risk category — reaching that check already proves the attacker knows the correct phone+PIN, so there's nothing left to protect by disguising it. `AccountInactiveException` is allowed to be distinct.

Let each exception `render()` itself (Laravel lets an exception class define `render(Request $request): Response`) rather than having the controller catch and branch — keeps the controller to the success path only.

**What to Create:**
- `app/Http/Requests/Auth/PinLoginRequest.php` — shared validation
- `PinAuthService` with the shared credential-check method; each controller action layers its own role gate and token ability on top
- `Modules/Authentication/Exceptions/InvalidCredentialsException.php` and `AccountInactiveException.php`, each with their own `render()`
- Two controller actions (or two controllers) — resist collapsing into one with a `panel` parameter, per the note at the top of Step 2

**Validation Rules (PinLoginRequest):**
- `phone_number`: required, string, BD mobile format `/^01[3-9]\d{8}$/` (11 digits, starts `01`, operator prefix `3`–`9`)
- `pin`: required, `digits_between:5,10`

**Deferred:** phone number normalization (e.g. accepting `+8801...` and stripping to `01...` via `prepareForValidation()`) is explicitly deferred, not handled in `PinLoginRequest` yet. Revisit when registration or a real client integration forces the decision — until then, only the exact `01XXXXXXXXX` shape is accepted.

**What You Should Understand:**
- How to verify hashed data
- `createToken($name, array $abilities)` and what gets stored vs. what gets returned
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

**Added beyond the original spec:** an `is_active` check (step 4 in the implementation, between password verification and the role check), throwing `AccountInactiveException` — same reasoning as the PIN-login flow: an inactive account shouldn't be able to request a login OTP regardless of which track it authenticates through. The doc's original numbered list above didn't include this step; the implementation does, deliberately.

**Security note on the stub OTP delivery (step 9 below):** the current implementation logs the OTP code via `Log::info()` as a stand-in for real email delivery. This is acceptable for local development only — it must never ship to a shared, staging, or production log stream, since a logged OTP is a credential sitting outside the `otp_tokens` table's access controls. Replace with real mail dispatch before any non-local deployment.

7. Generate 6-digit OTP code
8. Save OTP to `otp_tokens` table with:
   - `otp_code`: generated code
   - `purpose`: 'LOGIN'
   - `expires_at`: now + 5 minutes
   - `user_id`: admin's user_id
9. Send OTP via email (stub for now)
10. Return success message

**What to Create (paths under `Modules/Authentication/`, not `app/`):**
- `Modules/Authentication/Http/Requests/AdminLoginRequest.php`
- `Modules/Authentication/Services/AdminAuthService.php`
- `Modules/Authentication/Services/OtpService.php` — OTP generation and validation, shared across LOGIN/SET_PIN/PASSWORD_RESET purposes
- New action on the existing `AuthController`, response is a plain `response()->json([...])` — no token issued at this stage, not worth a dedicated Resource class for a single two-field payload

**OtpService Responsibilities:**
- `generateOtp()` — Generate 6-digit random code (`random_int`, not `rand`/`mt_rand`)
- `saveOtp($user_id, $code, $purpose)` — Save to DB with expiry
- `validateOtp($email, $otp_code)` — Check if valid, not expired, not used, attempt count < max
- `markOtpAsUsed($otp_token)` — Set used_at timestamp
- `incrementAttempts($otp_token)` — atomic DB-level increment, not read-modify-write

**Decision — multiple live OTPs per user:** requesting a new OTP **invalidates** any previous unused OTP of the same `purpose` for that user (mark `used_at = now()` before creating the new one), rather than allowing several simultaneously-valid codes. Keeps `verify-otp`'s lookup unambiguous ("the one active OTP") and is better hygiene than leaving stale codes live.

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
    "token": "2|q8Y0...plaintext-once...",
    "token_type": "Bearer",
    "abilities": ["admin"]
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
   - Generate Sanctum token with the `admin` ability
   - Return user + token

**Critical ordering requirement, found via testing, not design review:** re-verifying `is_active`/role at this step (state can change between requesting an OTP and verifying it) is correct and worth doing — but it must happen **after** `OtpService::verify()` succeeds, never before. Checking account/role state before proving OTP possession turns `is_active` and role membership into an oracle: anyone who knows an admin's email, with no OTP and no password, could distinguish "no such email" / "account inactive" / "insufficient role" from the generic OTP failure, without ever proving they hold a valid code. `AdminAuthService::verifyOtp()` must call `$this->otpService->verify(...)` first; only on success does it re-check `is_active`/role. Regression-tested in `tests/Feature/Auth/VerifyOtpTest.php` (inactive account + no OTP issued → generic message, not account-status-revealing one).

**What to Create (paths under `Modules/Authentication/`, not `app/`):**
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

Sanctum has no distinct "refresh token" concept — there's one token type, and revocation is just deleting the database row. The endpoints below map onto that, not onto the JWT access/refresh-pair pattern.

### Endpoint 1: Refresh Token

**Route:** `POST /api/v1/auth/refresh-token`

**Request:** no body needed — this acts on the **currently authenticated token**, sent as the usual `Authorization: Bearer {token}` header.

**Response (200):**
```json
{
  "success": true,
  "message": "Token refreshed",
  "data": {
    "token": "3|r1Z2...plaintext-once...",
    "token_type": "Bearer",
    "abilities": ["user"]
  }
}
```

**Business Logic:**
1. Read the currently authenticated token: `$request->user()->currentAccessToken()`
2. Capture its `name` and `abilities` — the new token must carry the **same** ability, never escalate it
3. Delete the current token row (`->delete()`), revoking it immediately
4. Issue a new token with `createToken($name, $abilities)`
5. Return the new plaintext token

**Why this needs its own route at all, if the client could just keep using the old token:** rotating the token value periodically limits the blast radius of a leaked token — an intercepted token stops working once the legitimate client rotates it. This is a convenience/security endpoint, not a mechanism Sanctum requires you to have.

**What You Should Understand:**
- Sanctum tokens don't expire by default (`sanctum.expiration` config, null unless you set it) — rotation here is a deliberate choice, not compensating for built-in expiry
- `currentAccessToken()` vs. issuing a brand new, unrelated token — the ability must carry over exactly

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
1. Delete the current token: `$request->user()->currentAccessToken()->delete()`
2. Return success

**No blacklist table, no Redis, no frontend-only invalidation.** This is the one place the old JWT-shaped options in this doc were actively wrong for Sanctum — deleting the token row **is** the revocation. It takes effect immediately on the next request, since every request looks the row up by hash. There is no equivalent gap to fill.

**Worth deciding:** should logout revoke only the current token, or every token the user holds (`$request->user()->tokens()->delete()`)? Given a user may hold an `admin` token and a `user` token simultaneously from two different panels, revoking "logout from everywhere" by default would silently kill a session on another route the person didn't ask to log out of. Default to revoking only the current token; a separate "logout everywhere" action is a deliberate, different endpoint if you ever need it.

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

**Do not write a custom token-decoding middleware.** Sanctum already ships `auth:sanctum` — it hashes the bearer token, looks up the matching row, and sets `$request->user()`. That's the entire mechanism this project needs; a hand-rolled `Authenticate.php` that decodes anything would be solving a problem Sanctum doesn't have.

What you actually need to write, on top of the built-in guard:

### 1. Role + ability gates on every protected route

Every protected route needs **two** checks, per `CLAUDE.md` §9/§16 — not one:

```php
Route::middleware(['auth:sanctum', 'ability:admin'])->group(function () {
    // role check happens inside the controller/policy, or via a second middleware
});
```

`ability:admin` is Sanctum's built-in middleware — it calls `tokenCan('admin')` for you. The **role** check (does this user actually hold ADMIN/SUPER_ADMIN/MODERATOR) is a separate concern — decide whether that belongs in a Policy, a Gate, or a small custom middleware when we get to Task 4. Don't conflate "has the ability" with "has the role"; both must pass independently, and mixing them into one check is how someone eventually forgets the second half.

### 2. `EnsurePasswordChanged` middleware (custom, genuinely needed)

This one you do write — see Case B above. It checks `password_changed_at` on the authenticated user and blocks every route except `change-password` while it's NULL. This is project-specific business logic, not something Sanctum provides.

**What You Should Understand:**
- `auth:sanctum` guard — what it does on every request, and that there's no token "validation" step for you to write, only a hash lookup
- The difference between Sanctum's `ability:` middleware (checks the token) and a role check (checks the user) — both required, neither substitutes for the other
- Middleware pipeline ordering — `EnsurePasswordChanged` must run after `auth:sanctum` (it needs `$request->user()`) and before route-specific logic

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
    "abilities": ["..."]
  }
}
```

No `expires_in` — Sanctum tokens don't expire by default (see Step 1). Omit the field rather than hardcoding a number that isn't true yet; if you later configure `sanctum.expiration`, add it back then.

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
- No custom token-decoding middleware — `auth:sanctum` and Sanctum's built-in `ability:` middleware cover that
- `app/Http/Middleware/EnsurePasswordChanged.php` — blocks every route but change-password while `password_changed_at` is NULL
- Route-level ability gates via Sanctum's `abilities` / `ability` middleware

### Exceptions
- `app/Exceptions/ApiException.php` — shared abstract base, NOT module-specific. Defines `protected int $status`, `protected array $errors = []`, and `render(Request $request): JsonResponse` returning the project-wide `{"success": false, "message": ..., "errors": [...]}` envelope. Lives in `app/Exceptions/` rather than `Modules/Authentication/` because response-shaping is a cross-cutting HTTP concern other modules (Wallets, Transactions, ...) will need too — establishing this convention here so later modules extend it instead of re-deriving their own shape.
- `Modules/Authentication/Exceptions/InvalidCredentialsException.php extends ApiException` — status 401, fixed message `"Invalid phone number or PIN."`, thrown identically for "phone not found" and "PIN incorrect" (see enumeration note under Endpoint 2 & 3 above)
- `Modules/Authentication/Exceptions/AccountInactiveException.php extends ApiException` — thrown after credentials check out but `is_active != 'ACTIVE'`

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
- [x] Registration works (phone + PIN) — `tests/Feature/Auth/RegisterTest.php`, verifies User/Wallet/Cap/role/token creation end-to-end, plus `AgentInfo` only for AGENT registrations
- [x] User login works and returns a `user`-ability token — `tests/Feature/Auth/PinLoginTest.php`
- [x] Agent login works and returns an `agent`-ability token — `tests/Feature/Auth/PinLoginTest.php`
- [x] Agent login rejected when `agent_info.status` is not APPROVED — covers both "no `agent_info` row" and "row exists but `PENDING`"
- [x] Admin login works with email **and** with phone number — `tests/Feature/Auth/AdminLoginTest.php`, both identifier types tested explicitly
- [x] Admin OTP verification returns an `admin`-ability token — `tests/Feature/Auth/VerifyOtpTest.php`; required fixing a real pre-proof information leak (see note below)
- [x] Authenticating correctly but lacking the route's role returns 403 — `InsufficientRoleException`, tested

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
- [x] Invalid credentials return 401 — not-found and wrong-PIN verified byte-identical (anti-enumeration), `tests/Feature/Auth/PinLoginTest.php`
- [ ] OTP expires after 5 minutes and is single-use
- [ ] Rate limiting is active on all three login routes — **deliberately deferred**, revisit once all auth routes exist so one limiter policy can be applied consistently
- [x] No passwords or PINs in any API response — `AuthResource` whitelists fields explicitly (`id`/`name`/`phone_number`/`email`/`roles`), never serializes the model directly
- [ ] All tests pass — Step 2 complete: PIN-login (7/7) + registration (7/7), 16/16 passing; admin flows (Step 3) not yet written

---

## Key Learnings

After completing this task, you should understand:

✅ How Laravel Sanctum works, and why it was chosen over JWT for this project  
✅ Sanctum token abilities and `tokenCan()` for scoping  
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
