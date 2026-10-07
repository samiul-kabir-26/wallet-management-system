# Authentication & Credential Architecture

This document describes the authentication mechanisms, credential transitions, and security controls across all roles in the Wallet Management System.

---

## 1. Overview & Architecture

The API utilizes **Laravel Sanctum** token-based authentication. No session cookies or CSRF tokens are required for API requests. All endpoints under `/api/v1` authenticate using HTTP `Authorization: Bearer <token>` headers.

### Token Abilities & Scopes
Every issued access token is explicitly scoped with one or more abilities matching the caller's role and state:
- `user`: Granted to standard customers upon PIN login or registration. Can execute customer financial operations (`top-up`, `transfer`, `cash-in`, `cash-out`) and view personal wallet/history.
- `agent`: Granted to verified, approved agents upon PIN login. Can execute `agent/withdrawal`, accept cash-in/cash-out, and view agent history.
- `admin`: Granted to administrative staff (`SUPER_ADMIN`, `ADMIN`, `MODERATOR`) after completing password + 2FA OTP verification and satisfying mandatory password changes.
- `password-change`: Restricted ability issued to invited staff upon accepting an invitation; can only call `POST /api/v1/auth/change-password`.

---

## 2. Customer & Agent Authentication (PIN Flow)

Customers (`USER`) and Agents (`AGENT`) authenticate using their **phone number** and a **5-digit numeric PIN**.

### 2.1 Registration
1. Client submits `POST /api/v1/auth/register` with `name`, `phone_number` (valid BD prefix `013`-`019`), `pin`, `pin_confirmation`, and `role`.
2. PIN is hashed using `bcrypt` via Laravel's `Hash::make()` with auto-generated salts.
3. If `role` is `USER`:
   - An initial wallet with 50.00 BDT signup bonus is provisioned.
   - Standard transaction caps are created (10,000 BDT daily, 50,000 BDT monthly).
   - An access token with ability `['user']` is returned.
4. If `role` is `AGENT`:
   - An initial wallet (0.00 BDT) and a pending `AgentInfo` record are provisioned with status `PENDING`.
   - An access token with ability `['agent']` is returned, but transactional routes remain blocked until approved by an administrator.

### 2.2 PIN Login & Anti-Timing Defense
1. Client calls `POST /api/v1/auth/user/login` or `POST /api/v1/auth/agent/login`.
2. To mitigate **account enumeration via timing attacks**, if the account does not exist or has no PIN, `PinAuthService` executes a dummy `Hash::check()` against a precomputed valid bcrypt hash:
   ```php
   private const string DUMMY_HASH = '$2y$12$xn5OszU/1GZvtORdddVo2.dXALwyzJcbpB6JlSmyvPK.DUrKdJFra';
   ```
   This ensures uniform CPU cycle consumption regardless of account existence.
3. Once authenticated:
   - For agents: Verifies `agent_info.status === 'APPROVED'`.
   - An access token with appropriate scope (`user` or `agent`) is issued.

---

## 3. Administrative Authentication (Password + 2FA OTP)

Administrative users (`SUPER_ADMIN`, `ADMIN`, `MODERATOR`) require a two-step multi-factor authentication flow.

### Step 1: Identifier + Password Verification
- **Endpoint:** `POST /api/v1/auth/admin/login`
- **Body:** `identifier` (email or phone) and `password`.
- Validates credentials and verifies that the account holds an administrative role and `is_active === 'ACTIVE'`.
- Generates a cryptographically secure 6-digit numeric OTP valid for 5 minutes (`auth_settings.otp.expiry_minutes`).
- Invalidates any prior active OTPs for the user.
- Dispatches the OTP to the admin's email and returns a confirmation message.

### Step 2: OTP Verification & Token Issuance
- **Endpoint:** `POST /api/v1/auth/admin/verify-otp`
- **Body:** `email` and `otp_code`.
- Validates the token with timing-safe string comparison (`hash_equals()`).
- Enforces lockout after 5 failed attempts (`max_attempts`).
- Upon success, marks the OTP as used (`used_at`) and issues an admin token with ability `['admin']`.

---

## 4. Multi-Credential Transitions (Cases A, B, and C)

Because role and credential are fundamentally distinct (holding an admin role does not inherently mean a user has a PIN, and having a PIN does not inherently mean a user has admin access), credentials transition across three well-defined cases.

### 4.1 Case A — Admin Self-Service PIN Setup via OTP
An existing administrator already proved identity via password + 2FA, but needs a second credential (PIN) to access customer/agent features. A stolen admin token must not allow minting a PIN without email verification.

1. **Request OTP:**
   - **Endpoint:** `POST /api/v1/auth/set-pin/request-otp`
   - **Access:** Admin token (`ability:admin`, `password.changed`)
   - Generates a 6-digit numeric OTP with `purpose = 'SET_PIN'`, valid for 5 minutes.
   - Dispatches OTP to the admin's **registered** email address (never a caller-supplied address).
2. **Submit PIN with OTP:**
   - **Endpoint:** `POST /api/v1/auth/set-pin`
   - **Access:** Admin token (`ability:admin`, `password.changed`)
   - **Body:** `{ "otp_code": "123456", "pin": "98765", "pin_confirmation": "98765" }`
   - Validates OTP, hashes PIN with `Hash::make()`, marks OTP used, and preserves the admin session.

---

### 4.2 Case B — User Elevated to Admin via Signed Invite
An existing customer or new staff member is granted administrative permissions. This is the highest-risk transition and enforces a temporary password and mandatory first-login password change.

1. **Grant Access & Send Invite:**
   - **Endpoint:** `PATCH /api/v1/users/{user}/grant-admin-access`
   - **Access:** SUPER_ADMIN only (`ability:admin`, `password.changed`)
   - **Requirements:** Target user's phone must be verified.
   - Sets temporary password, leaves `password_changed_at = NULL`, attaches administrative role, and generates a temporary signed URL:
     ```
     POST /api/v1/auth/accept-invite/{user}?expires=...&signature=...
     ```
2. **Accept Invitation:**
   - **Endpoint:** `POST /api/v1/auth/accept-invite/{user}` (signed URL)
   - **Body:** `{ "password": "<temporary_password>" }`
   - Issues a restricted access token with ability `['password-change']`.
3. **Mandatory Password Change:**
   - **Endpoint:** `POST /api/v1/auth/change-password`
   - **Access:** Restricted token (`ability:password-change`)
   - **Body:** `{ "current_password": "...", "new_password": "...", "new_password_confirmation": "..." }`
   - Sets `password_changed_at = now()`, revokes restricted token, and issues a full `['admin']` token.

---

### 4.3 Case C — Staff-Initiated Customer PIN Reset
There is no self-service PIN recovery for customers to prevent SIM-swap attacks. When a user forgets their PIN, customer care verifies identity out-of-band and initiates a signed recovery link.

1. **Initiate PIN Reset:**
   - **Endpoint:** `POST /api/v1/auth/pin-reset/initiate`
   - **Access:** Admin only (`ability:admin`, `password.changed`)
   - **Body:** `{ "phone_number": "01712345678", "email": "customer@example.com" }`
   - Identifies user by phone number, attaches contact email, and generates a temporary signed recovery URL:
     ```
     POST /api/v1/auth/reset-pin/{user}?expires=...&signature=...
     ```
2. **Set New PIN via Signed Link:**
   - **Endpoint:** `POST /api/v1/auth/reset-pin/{user}` (signed URL)
   - **Body:** `{ "pin": "54321", "pin_confirmation": "54321" }`
   - Validates 5-digit PIN, hashes with `bcrypt`, updates user record, and invalidates the signed URL.

---

### 4.4 Admin Password Reset via Email OTP
Self-service password recovery for administrative accounts:
1. **Request Reset OTP:**
   - **Endpoint:** `POST /api/v1/auth/forgot-password`
   - **Body:** `{ "email": "admin@wallet.local" }`
   - Anti-enumeration: Silently returns success even if email is unassociated with an admin role.
   - Generates 6-digit OTP with `purpose = 'PASSWORD_RESET'`.
2. **Verify OTP & Reset Password:**
   - **Endpoint:** `POST /api/v1/auth/reset-password`
   - **Body:** `{ "email": "admin@wallet.local", "otp_code": "123456", "new_password": "...", "new_password_confirmation": "..." }`
   - Sets new password, updates `password_changed_at = now()`, and purges all active tokens.

---

## 5. Security & Rate Limiting Controls

### 5.1 Route Throttling
Named rate limiters are configured in `app/Providers/AppServiceProvider.php`:
- **`login`:** 5 attempts per minute per `identifier|IP`.
- **`register`:** 5 requests per minute per IP.
- **`forgot-password`:** 5 requests per minute per IP.
When exceeded, the API responds with HTTP 429 and standardized JSON:
```json
{
  "success": false,
  "message": "Too many attempts. Please try again later.",
  "errors": []
}
```

### 5.2 Session Revocation
- **Logout:** `POST /api/v1/auth/logout` deletes only the current `personal_access_tokens` record, allowing multi-device isolation without invalidating concurrent sessions.
- **Password Reset:** When an administrator resets their password via `POST /api/v1/auth/reset-password`, all existing tokens are purged (`$user->tokens()->delete()`).
