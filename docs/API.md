# Wallet Management System — Complete API Reference

Version: `v1`  
Base URL: `/api/v1`  
Protocol: HTTP / JSON  
Authentication: Bearer Token (Laravel Sanctum)  
Postman Collection: [postman_collection.json](postman_collection.json)

---

## 1. Response Standards

All API responses follow a consistent JSON envelope structure.

### Success Response
```json
{
  "success": true,
  "message": "Operation completed successfully.",
  "data": { ... }
}
```

### Paginated Success Response
```json
{
  "success": true,
  "data": {
    "items": [ ... ],
    "pagination": {
      "total": 100,
      "per_page": 15,
      "current_page": 1,
      "last_page": 7,
      "from": 1,
      "to": 15
    }
  }
}
```

### Error Response
```json
{
  "success": false,
  "message": "Descriptive error message.",
  "errors": {
    "field_name": [
      "Validation failure description."
    ]
  }
}
```

---

## 2. Authentication & Credential Endpoints

> **Token lifetime.** Every access token has an absolute expiry set when it is issued, based on the ability it carries (configurable in `config/auth_settings.php` → `tokens`): `admin` 8 hours, `agent` and `user` 12 hours, and the restricted `password-change` token 30 minutes. A token that has not been used for 15 minutes is also rejected (idle timeout). Both cases return **401**, and the client must log in again. Refreshing a token never extends its absolute expiry. Expired tokens are pruned daily by `sanctum:prune-expired`.
>
> **Secret delivery.** OTP codes, PIN reset links and staff invitations (with the temporary password) are delivered by email notification. They are never written to the application log.

### 2.0 Request Signup Code (Registration Step 1)
- **Method & Route:** `POST /api/v1/auth/register/request-otp`
- **Access:** Public. Limited to 3 codes per number and 10 per IP per 10 minutes (429).
- **Request Body:** `{ "phone_number": "01712345678" }`
- **Description:** Texts a 6-digit code to a number that has no account yet (OTP purpose `REGISTRATION`, `user_id` null, number in `target`). A new request invalidates the previous code. 422 if the number is invalid or already has an account.

---

### 2.1 Public Registration (Step 2)
- **Method & Route:** `POST /api/v1/auth/register`
- **Access:** Public (Rate limited: 5 req/min per IP)
- **Description:** Registers a new `USER` or `AGENT`. For users, provisions a wallet and caps. For agents, provisions a pending profile and an agent wallet. Wallets open at 0.00; when the signup bonus is enabled (6.1), its amount (default 50.00 BDT) is then credited as a `SIGNUP_BONUS` transaction in the same database transaction (see 5.12). Requires the code from 2.0 for this exact `phone_number`; the account starts with `is_verified = true`. A wrong or expired code returns 401 and nothing is created; attempts count toward the code's lockout.
- **Request Body:**
  ```json
  {
    "name": "Alice Rahman",
    "phone_number": "01712345678",
    "pin": "12345",
    "pin_confirmation": "12345",
    "role": "USER",
    "otp_code": "123456"
  }
  ```
- **Response (201 Created):**
  ```json
  {
    "success": true,
    "message": "User registered successfully",
    "data": {
      "user": {
        "id": 10,
        "name": "Alice Rahman",
        "phone_number": "01712345678",
        "email": null,
        "roles": ["USER"],
        "is_verified": false,
        "is_active": "ACTIVE"
      },
      "token": "10|sanctum_plain_text_token...",
      "token_type": "Bearer",
      "abilities": ["user"]
    }
  }
  ```
- **Error Codes:** 422 (Validation error, duplicate phone).

---

### 2.2 Customer PIN Login
- **Method & Route:** `POST /api/v1/auth/user/login`
- **Access:** Public (Rate limited: 5 req/min per identifier + IP)
- **Request Body:**
  ```json
  {
    "phone_number": "01712345678",
    "pin": "12345"
  }
  ```
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "Login successful",
    "data": {
      "user": {
        "id": 10,
        "name": "Alice Rahman",
        "phone_number": "01712345678",
        "roles": ["USER"],
        "is_verified": true,
        "is_active": "ACTIVE"
      },
      "token": "11|sanctum_token...",
      "token_type": "Bearer",
      "abilities": ["user"],
      "idle_timeout_minutes": 5
    }
  }
  ```
- **Error Codes:** 401 (Invalid credentials), 403 (Account inactive or missing USER role), 429 (Too many failed PIN attempts / Account locked).
- **Wrong panel:** one phone number holds one wallet type (as with bKash/Nagad). After a correct PIN, an agent account on this route gets 403 *"This number is registered as an agent account. Please sign in through the Agent portal; a personal wallet needs a separate number."* The agent route likewise says when a number is a customer account, awaiting approval, or suspended.
- **Login records:** every attempt on the three login routes (success or failure, panel, identifier, IP, user agent) is stored in `login_activities`; see 3.11.
- **`idle_timeout_minutes`:** the idle auto-logout for this panel (see 6.3). Clients should sign out after this long without interaction; the server rejects the token anyway once it has been unused for that long. `/auth/me` returns the same field.

> [!NOTE]
> **PIN Lockout & Account Enumeration Trade-off**: The 5-attempt PIN lockout counter (`pin-attempts:{userId}`) is shared atomically across both public PIN login and money-moving transaction endpoints. While an unknown phone number always returns 401, a registered phone number returns 429 when locked out after 5 consecutive failed attempts. This deliberate trade-off follows mobile financial service industry patterns (e.g., bKash) to prioritize account and wallet balance defense against credential brute-forcing.

---

### 2.3 Agent PIN Login
- **Method & Route:** `POST /api/v1/auth/agent/login`
- **Access:** Public (Requires approved agent profile; rate limited: 5 req/min per identifier + IP)
- **Request Body:**
  ```json
  {
    "phone_number": "01788776655",
    "pin": "54321"
  }
  ```
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "Login successful",
    "data": {
      "user": {
        "id": 12,
        "name": "Agent Store",
        "phone_number": "01788776655",
        "roles": ["AGENT"],
        "is_verified": true,
        "is_active": "ACTIVE"
      },
      "token": "12|sanctum_token...",
      "token_type": "Bearer",
      "abilities": ["agent"]
    }
  }
  ```
- **Error Codes:** 401 (Invalid credentials), 403 (Agent not approved or missing AGENT role).

---

### 2.4 Admin Login (Step 1: Request 2FA OTP)
- **Method & Route:** `POST /api/v1/auth/admin/login`
- **Access:** Public (Rate limited: 5 req/min)
- **Request Body:**
  ```json
  {
    "identifier": "admin@wallet.local",
    "password": "SecurePassword123!"
  }
  ```
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "OTP sent to your email. Please check your inbox.",
    "data": {
      "email": "admin@wallet.local"
    }
  }
  ```
- **Error Codes:** 401 (Invalid identifier or password), 403 (Inactive or non-admin account).

---

### 2.5 Admin Login (Step 2: Verify OTP)
- **Method & Route:** `POST /api/v1/auth/admin/verify-otp`
- **Access:** Public (Rate limited: 5 req/min)
- **Request Body:**
  ```json
  {
    "email": "admin@wallet.local",
    "otp_code": "481920"
  }
  ```
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "Login successful",
    "data": {
      "user": {
        "id": 1,
        "name": "Super Admin",
        "email": "admin@wallet.local",
        "roles": ["SUPER_ADMIN"],
        "permissions": ["users.view_any", "users.view", "..."],
        "is_active": "ACTIVE"
      },
      "token": "13|sanctum_token...",
      "token_type": "Bearer",
      "abilities": ["admin"]
    }
  }
  ```
- **Error Codes:** 401 (Invalid/expired OTP, lockout after 5 attempts).

---

### 2.5b Authenticated User Profile (`/me`)
- **Method & Route:** `GET /api/v1/auth/me`
- **Access:** Authenticated (`ability:admin,agent,user`, `password.changed`)
- **Description:** Returns profile and effective permissions for current authenticated user.
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "data": {
      "user": {
        "id": 1,
        "name": "Super Admin",
        "email": "admin@wallet.local",
        "roles": ["SUPER_ADMIN"],
        "permissions": ["users.view_any", "users.create", "wallets.block", "..."],
        "is_active": "ACTIVE",
        "avatar_url": null,
        "must_change_password": false
      }
    }
  }
  ```
- **Notes:** `must_change_password` is `true` while the account holds a temporary staff password. Every endpoint called with an **admin token** (except 2.7) then returns 403 with `meta.code = "PASSWORD_CHANGE_REQUIRED"`; clients should send the user to the change-password form. Agent/customer sessions (PIN tokens) of the same person are not blocked: they were never opened with the temporary password.

---

### 2.6 Accept Staff Invitation (Case B Step 2)
- **Email link:** the invitation email links to the web page `/accept-invite/{user}?expires=…&signature=…`. The page posts that same query string to the API route below (the signature is for the API path), then calls 2.7 with the restricted token. Invitees can instead sign in on the Staff / Admin tab with the email and temporary password; they are sent to the change-password form first. The same pattern applies to PIN reset links (2.13): `/reset-pin/{user}?…` posts to `/api/v1/auth/reset-pin/{user}?…`.

- **Method & Route:** `POST /api/v1/auth/accept-invite/{user}`
- **Access:** Signed URL only (`signed` middleware)
- **Description:** Consumes a temporary signed URL generated during `grant-admin-access` or staff creation. Authenticates with temporary password and returns a restricted token.
- **Request Body:**
  ```json
  {
    "password": "TemporaryPassword123!"
  }
  ```
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "Please change your password to continue.",
    "data": {
      "user": {
        "id": 14,
        "name": "New Admin",
        "email": "newadmin@wallet.local",
        "roles": ["ADMIN"]
      },
      "token": "14|temp_token...",
      "token_type": "Bearer",
      "abilities": ["password-change"]
    }
  }
  ```
- **Error Codes:** 401 (Invalid temporary password), 403 (Invalid/tampered signature or expired link).

---

### 2.7 Mandatory Password Change (Case B Step 3)
- **Method & Route:** `POST /api/v1/auth/change-password`
- **Access:** Authenticated (`ability:password-change,admin`)
- **Description:** Changes user password, records `password_changed_at`, revokes restricted token, and issues full admin access token.
- **Request Body:**
  ```json
  {
    "current_password": "TemporaryPassword123!",
    "new_password": "NewPermanentPassword456!",
    "new_password_confirmation": "NewPermanentPassword456!"
  }
  ```
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "Password changed successfully.",
    "data": {
      "user": {
        "id": 14,
        "name": "New Admin",
        "email": "newadmin@wallet.local",
        "roles": ["ADMIN"]
      },
      "token": "15|admin_token...",
      "token_type": "Bearer",
      "abilities": ["admin"]
    }
  }
  ```
- **Error Codes:** 401 (Invalid current password), 422 (Password requirements not met or matches temporary password).
- **Notes:** Also used by signed-in staff to change their password at any time (admin token). Every existing session is revoked and the response carries the new token. Audited as `PASSWORD_CHANGED` (`replaced_temporary_password` in metadata).

---

### 2.7b Change PIN (Agent / Customer)
- **Method & Route:** `POST /api/v1/auth/change-pin`
- **Access:** Authenticated (`ability:agent,user`), throttled 10/min.
- **Request Body:** `{ "current_pin": "12345", "new_pin": "54321", "new_pin_confirmation": "54321" }`
- **Description:** Replaces a PIN the caller still knows. The current PIN check shares the login lockout counter (`pin-attempts:{id}`). The calling session stays signed in; every other token is revoked. Audited as `PIN_CHANGED`. A forgotten PIN still goes through the staff-initiated reset (2.12/2.13).
- **Error Codes:** 403 (admin token), 422 (wrong current PIN, with `meta.attempts_remaining`; or validation), 429 (PIN locked).

---

### 2.8 Admin Forgot Password (Request OTP)
- **Method & Route:** `POST /api/v1/auth/forgot-password`
- **Access:** Public (Rate limited: 5 req/min per IP)
- **Description:** Sends password reset OTP to administrative user's email. Employs anti-enumeration (returns 200 even if email is not found).
- **Request Body:**
  ```json
  {
    "email": "admin@wallet.local"
  }
  ```
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "If this email is associated with an active administrator, a reset OTP has been sent."
  }
  ```

---

### 2.9 Admin Reset Password (Verify OTP)
- **Method & Route:** `POST /api/v1/auth/reset-password`
- **Access:** Public (Rate limited: 5 req/min)
- **Description:** Verifies OTP and sets new password. Revokes all existing active sessions.
- **Request Body:**
  ```json
  {
    "email": "admin@wallet.local",
    "otp_code": "654321",
    "new_password": "FreshAdminPassword789!",
    "new_password_confirmation": "FreshAdminPassword789!"
  }
  ```
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "Password reset successfully. Please log in with your new password."
  }
  ```
- **Error Codes:** 401 (Invalid or expired OTP).

---

### 2.10 Admin Request PIN Setup OTP (Case A Step 1)
- **Method & Route:** `POST /api/v1/auth/set-pin/request-otp`
- **Access:** Admin only (`auth:sanctum`, `ability:admin`, `password.changed`)
- **Description:** Sends a 6-digit OTP to the admin's registered email address for PIN configuration.
- **Request Body:** `{}` (empty)
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "OTP sent to your email. Please check your inbox."
  }
  ```

---

### 2.11 Admin Set PIN (Case A Step 2)
- **Method & Route:** `POST /api/v1/auth/set-pin`
- **Access:** Admin only (`auth:sanctum`, `ability:admin`, `password.changed`)
- **Description:** Validates email OTP and sets 5-digit PIN on admin account without modifying existing role or active session.
- **Request Body:**
  ```json
  {
    "otp_code": "314159",
    "pin": "98765",
    "pin_confirmation": "98765"
  }
  ```
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "PIN set successfully."
  }
  ```
- **Error Codes:** 401 (Invalid or expired OTP), 422 (PIN formatting failure).

---

### 2.12 Staff Initiate Customer PIN Reset (Case C Step 1)
- **Method & Route:** `POST /api/v1/auth/pin-reset/initiate`
- **Access:** Admin/Staff only (`auth:sanctum`, `ability:admin`, `password.changed`)
- **Description:** Customer care initiates out-of-band PIN recovery for a customer by providing phone number and recovery email. Generates and sends a signed URL.
- **Request Body:**
  ```json
  {
    "phone_number": "01712345678",
    "email": "customer@example.com"
  }
  ```
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "PIN reset link generated and sent."
  }
  ```
- **Error Codes:** 404 (User not found), 409 (Email collision), 422 (Account has no existing PIN).

---

### 2.13 Customer Reset PIN via Signed Link (Case C Step 2)
- **Method & Route:** `POST /api/v1/auth/reset-pin/{user}`
- **Access:** Signed URL only (`signed` middleware)
- **Description:** Customer sets a new PIN using the recovery link provided by customer care. The link carries a `fingerprint` query parameter bound to the account's current PIN, so it works **once**: after a successful reset (or any other PIN change) the same link is rejected. A successful reset revokes every access token the account holds, clears the PIN lockout counter, and writes a `PIN_RESET_COMPLETED` audit entry.
- **Request Body:**
  ```json
  {
    "pin": "54321",
    "pin_confirmation": "54321"
  }
  ```
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "PIN reset successfully. Please log in with your new PIN."
  }
  ```
- **Error Codes:** 403 (Invalid/expired signed link, link already used, or account inactive), 422 (Validation failure, or account has no PIN).

---

### 2.14 Refresh Token
- **Method & Route:** `POST /api/v1/auth/refresh-token`
- **Access:** Authenticated (`auth:sanctum`)
- **Description:** Rotates the access token: the current token is revoked and a new one is issued with the same name, abilities, and **the same absolute expiry**. Refreshing never extends a session.
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "Token refreshed",
    "data": {
      "token": "16|new_token...",
      "token_type": "Bearer",
      "abilities": ["user"]
    }
  }
  ```

---

### 2.15 Logout
- **Method & Route:** `POST /api/v1/auth/logout`
- **Access:** Authenticated (`auth:sanctum`)
- **Description:** Revokes caller's current session token.
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "Logged out successfully."
  }
  ```

---

### 2.16 Logout From All Sessions
- **Method & Route:** `POST /api/v1/auth/logout-all`
- **Access:** Authenticated (`auth:sanctum`)
- **Description:** Revokes every access token the caller holds, on every device and panel.
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "Logged out from all sessions."
  }
  ```

---

## 3. User & Agent Management Endpoints

### 3.1 List All Users (Admin Audit)
- **Method & Route:** `GET /api/v1/users/all-users`
- **Access:** Admin only (`auth:sanctum`, `ability:admin`, `password.changed`)
- **Query Params:** `search`, `panel`, `role`, `is_active`, `is_verified`, `per_page` (1-100), `page`
  - `search` (max 100 characters) matches name, email or phone number, or the user ID written as `#123`. LIKE wildcards (`%`, `_`) are matched literally. Deleted users never match.
  - `panel` (`admin`, `agent`, `user`) returns users holding at least one role on that panel, so custom staff roles count as `admin`.
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "data": {
      "users": [
        {
          "id": 10,
          "name": "Alice Rahman",
          "phone_number": "01712345678",
          "email": null,
          "roles": ["USER"],
          "is_active": "ACTIVE",
          "is_verified": true,
          "created_at": "2026-10-01T08:00:00.000000Z"
        }
      ],
      "pagination": {
        "total": 1,
        "per_page": 15,
        "current_page": 1,
        "last_page": 1,
        "from": 1,
        "to": 1
      }
    }
  }
  ```

---

### 3.2 Admin Register User or Staff
- **Method & Route:** `POST /api/v1/users/register`
- **Access:** Admin only (`ability:admin`, `password.changed`). Registering `ADMIN` requires `SUPER_ADMIN` role. `SUPER_ADMIN` can never be registered through this endpoint, even by a `SUPER_ADMIN` (422, `role.not_in`).
- **Signup bonus:** `USER` and `AGENT` registrations receive the signup bonus exactly like self-registration (5.12).
- **Request Body:**
  ```json
  {
    "name": "Staff Admin",
    "phone_number": "01733445566",
    "role": "ADMIN",
    "email": "admin@wallet.local"
  }
  ```
- **Response (201 Created):**
  ```json
  {
    "success": true,
    "message": "User registered successfully",
    "data": {
      "user": {
        "id": 17,
        "name": "Staff Admin",
        "email": "admin@wallet.local",
        "phone_number": "01733445566",
        "roles": ["ADMIN"]
      }
    }
  }
  ```

---

### 3.3 View User Profile
- **Method & Route:** `GET /api/v1/users/{user}`
- **Access:** Authenticated caller viewing self, or Admin (`UserPolicy`)
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "data": {
      "user": {
        "id": 10,
        "name": "Alice Rahman",
        "phone_number": "01712345678",
        "roles": ["USER"],
        "is_active": "ACTIVE",
        "is_verified": true,
        "wallet": {
          "id": 5,
          "balance": 5050.00,
          "currency": "BDT"
        },
        "caps": {
          "daily_cap": 10000.00,
          "monthly_cap": 50000.00,
          "daily_used": 1000.00,
          "monthly_used": 1000.00
        }
      }
    }
  }
  ```
- **Error Codes:** 403 (Unauthorized viewing other user profile), 404 (User not found).

---

### 3.4 Recipient Lookup
- **Method & Route:** `GET /api/v1/users/lookup?phone_number={phone_number}`
- **Access:** Authenticated caller (`ability:admin,agent,user`, `password.changed`)
- **Throttle:** 30 requests per minute
- **Query Parameters:**
  - `phone_number` (string, required): 11-digit Bangladeshi mobile number (`^01[3-9]\d{8}$`)
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "data": {
      "name": "R***l K***r",
      "phone_number": "01712345678",
      "can_receive": true
    }
  }
  ```
- **Error Codes:** 404 (User not found), 422 (Validation error: invalid phone number format), 429 (Too many requests).
- **Security & Privacy Trade-Off:**
  - *Purpose:* Allows the sender or agent to confirm the recipient before money moves, preventing misdirected transfers (standard pattern in payment apps like bKash/Nagad).
  - *Privacy Trade-Off:* Revealing that a number is registered allows probing for account existence.
  - *Mitigations:*
    - Requires authentication (`ability:admin,agent,user`).
    - Strictly rate-limited to 30 requests per minute per caller.
    - Full recipient names are masked (`R***l K***r`) to protect privacy while still providing recognizable verification.
    - Soft-deleted users are treated as not found (404), identical to non-existent numbers.
    - Eligibility checks (`can_receive`) are derived centrally via `User::canReceiveTransfers()`.

---

### 3.5 Update User Profile
- **Method & Route:** `PATCH /api/v1/users/{user}`
- **Access:** Authenticated caller updating self, or staff holding `users.update` (`UserPolicy::update`). Only a `SUPER_ADMIN` may edit a `SUPER_ADMIN` account.
- **Notes:** `email`, `phone_number`, `password`, `role` and `image` are prohibited. Email and phone are login/recovery identifiers, so staff cannot change them here. Avatars are uploaded through 3.13.
- **Audit:** when staff edit someone else's profile and at least one field actually changes, a `PROFILE_UPDATED` audit entry is written against the user with `metadata.fields` and `metadata.changes.{field}.{before,after}`. Self-edits and submissions that change nothing are not audited.
- **Request Body:**
  ```json
  {
    "name": "Alice M. Rahman",
    "address": "123 Dhanmondi, Dhaka"
  }
  ```
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "data": {
      "user": {
        "id": 10,
        "name": "Alice M. Rahman",
        "phone_number": "01712345678",
        "address": "123 Dhanmondi, Dhaka"
      }
    }
  }
  ```

---

### 3.6 Grant Admin Access to Existing User (Case B Step 1)
- **Method & Route:** `PATCH /api/v1/users/{user}/grant-admin-access`
- **Access:** SUPER_ADMIN only (`auth:sanctum`, `ability:admin`, `password.changed`)
- **Description:** Elevates user to administrator, assigns temporary password, attaches email, and generates signed invitation link. Target phone must be verified.
- **Request Body:**
  ```json
  {
    "email": "promoted.staff@wallet.local",
    "role": "ADMIN"
  }
  ```
  `role` must be an admin-panel role. `SUPER_ADMIN` is rejected with 422 (`role.not_in`).
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "Admin access granted and invitation sent.",
    "data": {
      "email": "promoted.staff@wallet.local"
    }
  }
  ```
- **Error Codes:** 403 (Caller not SUPER_ADMIN), 422 (Phone unverified, email collision, or `role` is `SUPER_ADMIN`).

---

### 3.7 Approve Pending Agent
- **Method & Route:** `PATCH /api/v1/users/{user}/approve-agent`
- **Access:** Admin only (`ability:admin`, `password.changed`)
- **Request Body (Optional):**
  ```json
  {
    "commission_rate": 0.025
  }
  ```
  *(If omitted, dynamically defaults to `agent_commission_rate` global setting).*
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "Agent approved successfully",
    "data": {
      "agent": {
        "user_id": 12,
        "status": "APPROVED",
        "commission_rate": 0.025,
        "total_commission": 0.00,
        "approved_at": "2026-10-07T12:00:00.000000Z",
        "approved_by": 1
      }
    }
  }
  ```
- **Error Codes:** 404 (User is not an agent), 409 (Agent is not pending approval).

---

### 3.8 Suspend Approved Agent
- **Method & Route:** `PATCH /api/v1/users/{user}/suspend-agent`
- **Access:** Admin only (`ability:admin`, `password.changed`)
- **Request Body:** `reason` is required, 3–500 characters.
  ```json
  {
    "reason": "Suspicious counter transaction patterns"
  }
  ```
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "Agent suspended successfully",
    "data": {
      "agent": {
        "user_id": 12,
        "status": "SUSPENDED",
        "suspended_at": "2026-10-07T12:30:00.000000Z",
        "suspended_by": 1
      }
    }
  }
  ```
- **Error Codes:** 404 (User is not an agent), 409 (Agent is not in APPROVED status), 422 (Missing or invalid `reason`).

---

### 3.9 Assign Additional Role to User ("Become an Agent" Flow)
- **Method & Route:** `POST /api/v1/users/{user}/assign-role`
- **Access:** Admin only (`ability:admin`, `password.changed`, `UserPolicy::assignRole`). Separation of duties strictly enforced: administrators cannot assign roles to themselves (`403 Forbidden`).
- **Request Body:**
  ```json
  {
    "role": "AGENT"
  }
  ```
  *(Whitelisted strictly to `"AGENT"` and `"USER"`. Any other role is rejected with 422).*
- **Behavior & Security:**
  - **Account Status:** Target user must have `is_active === 'ACTIVE'`. Inactive or suspended accounts are rejected with `403 Forbidden`.
  - **Concurrency Safety:** Acquires a pessimistic row lock (`lockForUpdate`) on the target user inside a database transaction to prevent race conditions and duplicate wallet creation.
  - **Idempotency & Audit Trust:** If the user already holds the requested role, the endpoint returns `200 OK` as an idempotent no-op without creating duplicate wallets or recording spurious `ROLE_ASSIGNED` audit log rows.
  - **If role is `AGENT`:** attaches `AGENT` role, initializes `agent_info` with status `PENDING`, ensures a transaction cap record exists, creates a secondary `AGENT` wallet with starting balance `0.00` BDT, and writes an immutable `ROLE_ASSIGNED` `AuditLog`.
  - **If role is `USER`:** attaches `USER` role, initializes transaction caps, creates a secondary `USER` wallet with starting balance `0.00` BDT, and writes an immutable `ROLE_ASSIGNED` `AuditLog`.
  - **Credentials Note:** Assigning a role attaches permissions and wallets, but provisions no credentials. A target account without an established PIN cannot log in on agent or customer panels until setting a PIN via the PIN onboarding/reset flow (Case A).
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "Role assigned successfully",
    "data": {
      "user": {
        "id": 10,
        "name": "Alice Rahman",
        "phone_number": "01712345678",
        "roles": ["USER", "AGENT"],
        "is_active": "ACTIVE",
        "is_verified": true,
        "wallets": [
          { "id": 5, "kind": "USER", "balance": "50.00", "currency": "BDT" },
          { "id": 12, "kind": "AGENT", "balance": "0.00", "currency": "BDT" }
        ],
        "agent_info": {
          "user_id": 10,
          "status": "PENDING"
        }
      }
    }
  }
  ```
- **Error Codes:** 401 (Unauthenticated), 403 (Caller is not admin, target is inactive, or admin attempting self-assignment), 422 (Role not in AGENT, USER).

---

### 3.10 Mark Phone Verified
- **Method & Route:** `PATCH /api/v1/users/{user}/verify-phone`
- **Access:** Admin only **and** `users.verify_phone`. Staff cannot verify their own phone.
- **Description:** Records that staff confirmed out of band (call, NID check) that the phone number belongs to this person. Granting admin access (3.6) requires a verified phone. Idempotent; audited once as `PHONE_VERIFIED`.
- **Error Codes:** 403, 422 (account has no phone number).

---

### 3.11 User Login Records
- **Method & Route:** `GET /api/v1/users/{user}/login-activity`
- **Access:** Admin only **and** `users.view_activity`.
- **Response:** paginated `{ id, panel, identifier, status (SUCCESS|FAILED), failure_reason, ip_address, user_agent, created_at }`, newest first. Admin password-step failures are recorded; admin success is recorded when the OTP is verified.

---

### 3.12 User Audit Trail
- **Method & Route:** `GET /api/v1/users/{user}/audit-trail`
- **Access:** Admin only **and** `users.view_activity`.
- **Response:** paginated audit entries the user performed, entries recorded against the user, and entries recorded against any of the user's wallets: `{ id, action, actor { id, name }, auditable_type, auditable_id, metadata, created_at }`.

---

### 3.13 My Account (self-service, any panel)
All routes require `ability:admin,agent,user` and `password.changed`. They act on the signed-in account only and return `{ data: { user } }` (the 3.3 shape plus `avatar_url`).

| Method & Route | Body | Notes |
|---|---|---|
| `GET /api/v1/users/me` | none | Own profile. |
| `PATCH /api/v1/users/me` | `name`, `address` | `email`, `phone_number` and `image` are prohibited (422). |
| `POST /api/v1/users/me/avatar` | multipart `avatar` | JPG/PNG/WebP, max 2 MB, 64–4000 px per side; SVG is refused. Stored on the `public` disk under `avatars/` (needs `php artisan storage:link`); the previous file is deleted. Throttled 10/min. |
| `DELETE /api/v1/users/me/avatar` | none | Deletes the file and clears `avatar_url`. |
| `POST /api/v1/users/me/phone` | `phone_number` (`01[3-9]XXXXXXXX`, unique) | For accounts **without** a phone (409 otherwise). Texts a 6-digit code to that number (OTP purpose `ADD_PHONE`, number kept in `otp_tokens.target`). Nothing is saved yet. Limited to 3 per 10 minutes per account **and** per destination number. |
| `POST /api/v1/users/me/phone/confirm` | `otp_code` | Saves the number the code was sent to, marked verified, audited as `PHONE_ADDED`. 422 if another account took the number meanwhile, 409 if a phone was already set. Staff can then sign in to the admin panel with this number and their password (2.4); the OTP step (2.5) uses the `email` returned by 2.4. Changing an existing number is a support task. |
| `POST /api/v1/users/me/phone-verification` | none | Texts a 6-digit code (OTP purpose `PHONE_VERIFICATION`, expires per `auth_settings.otp.expiry_minutes`) and invalidates any earlier code. Limited to 3 per 10 minutes per account (429). 409 if already verified, 422 if the account has no phone. |
| `POST /api/v1/users/me/phone-verification/confirm` | `otp_code` | Sets `is_verified`, audited as `PHONE_VERIFIED` with `metadata.method = "OTP"` (staff verification in 3.10 records `"STAFF"`). 401 wrong/expired code (attempts are counted and lock the code), 409 already verified. |

**SMS delivery:** no SMS gateway is configured yet. `SmsChannel` writes each message to the log channel named by `SMS_LOG_CHANNEL` (default log), so in development the code appears in `storage/logs/laravel.log` as `SMS message`.

---

### 3.14 Change Agent Commission Rate
- **Method & Route:** `PATCH /api/v1/users/{user}/commission-rate`
- **Access:** Admin only (`ability:admin`, `password.changed`) **and** `agents.update_commission` (`UserPolicy::updateCommission`). ADMIN holds it by default. Staff cannot change their own rate (403).
- **Request Body:** `commission_rate` is required, between 0 and 1, at most 4 decimal places (`0.0125` = 1.25%).
  ```json
  {
    "commission_rate": 0.015
  }
  ```
- **Response (200 OK):** `{ "success": true, "message": "Commission rate updated successfully", "data": { "agent": { ... } } }`, the same agent shape as 3.7.
- **Behavior & Rules:**
  - Locks the `agent_info` row inside a database transaction.
  - Applies to approved and suspended agents. A pending agent's rate is set when they are approved (3.7), so pending agents are refused with 409.
  - Only future cash-outs use the new rate. Past transactions keep the `agent_commission_rate` they were charged at.
  - Writes an `AGENT_COMMISSION_UPDATED` audit entry with `previous_rate` and `new_rate`. Submitting the current rate is a no-op and writes no audit entry.
- **Error Codes:** 403 (Missing permission, non-admin token, or own account), 404 (User is not an agent), 409 (Agent is pending approval), 422 (Validation error).

---

## 4. Wallet Management Endpoints

### 4.1 View Current Wallet
- **Method & Route:** `GET /api/v1/wallets/me`
- **Access:** Any authenticated user (`ability:admin,agent,user`)
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "data": {
      "wallet": {
        "id": 5,
        "user_id": 10,
        "balance": 2030.00,
        "currency": "BDT",
        "is_blocked": false
      }
    }
  }
  ```

---

### 4.2 List All Wallets
- **Method & Route:** `GET /api/v1/wallets/admin/all`
- **Access:** Admin only (`ability:admin`, `password.changed`)
- **Query Params:** `is_blocked`, `user_id`, `search` (owner name, phone or email), `per_page`, `page`
- **Response (200 OK):** Paginated wallet list. Each item includes `owner: { id, name, phone_number, email }`.

---

### 4.3 Block Wallet
- **Method & Route:** `PATCH /api/v1/wallets/{wallet}/block`
- **Access:** Admin only (`ability:admin`, `password.changed`)
- **Request Body:**
  ```json
  {
    "reason": "Account under investigation"
  }
  ```
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "Wallet blocked successfully",
    "data": {
      "wallet": {
        "id": 5,
        "is_blocked": true,
        "blocked_by": 1,
        "blocked_at": "2026-10-07T13:00:00.000000Z"
      }
    }
  }
  ```

---

### 4.4 Unblock Wallet
- **Method & Route:** `PATCH /api/v1/wallets/{wallet}/unblock`
- **Access:** Admin only (`ability:admin`, `password.changed`)
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "Wallet unblocked successfully",
    "data": {
      "wallet": {
        "id": 5,
        "is_blocked": false,
        "blocked_by": null,
        "blocked_at": null
      }
    }
  }
  ```

---

## 5. Financial Transaction Endpoints

### 5.1 Balance Top-Up
- **Method & Route:** `POST /api/v1/transactions/top-up`
- **Access:** User only (`ability:user`, `password.changed`)
- **Request Body:**
  ```json
  {
    "amount": 5000.00,
    "pin": "12345",
    "idempotency_key": "topup-uuid-1234",
    "description": "Bank transfer deposit"
  }
  ```
- **Response (201 Created):**
  ```json
  {
    "success": true,
    "message": "Top-up completed successfully.",
    "data": {
      "transaction": {
        "id": 101,
        "user_id": 10,
        "type": "TOP_UP",
        "amount": 5000.00,
        "status": "COMPLETED",
        "idempotency_key": "topup-uuid-1234",
        "recipient_wallet_balance_after": 5050.00
      }
    }
  }
  ```
- **Behavior & Rules:**
  - **Simulation:** `TOP_UP` stands in for a bank or card payment gateway and creates new e-money. In a production system the credit would only be applied after a verified gateway callback.
  - Requires the user's transaction PIN (shared lockout counter with PIN login).
- **Error Codes:** 403 (Wallet blocked), 422 (Validation error or wrong PIN), 429 (PIN locked out).

---

### 5.2 P2P Balance Transfer
- **Method & Route:** `POST /api/v1/transactions/transfer`
- **Access:** User only (`ability:user`, `password.changed`)
- **Request Body:**
  ```json
  {
    "recipient_phone": "01712345678",
    "amount": 1000.00,
    "pin": "12345",
    "idempotency_key": "transfer-uuid-5678",
    "description": "Dinner split"
  }
  ```
- **Response (201 Created):**
  ```json
  {
    "success": true,
    "message": "Transfer completed successfully.",
    "data": {
      "transaction": {
        "id": 102,
        "user_id": 10,
        "sender_id": 10,
        "recipient_id": 11,
        "type": "TRANSFER",
        "amount": 1000.00,
        "status": "COMPLETED",
        "idempotency_key": "transfer-uuid-5678",
        "sender_wallet_balance_after": 4050.00
      }
    }
  }
  ```
- **Error Codes:** 403 (Wallet blocked), 409 (Idempotency key reused with different parameters), 422 (Wrong PIN, insufficient balance, cap exceeded, self-transfer), 429 (PIN locked out).

---

### 5.3 Customer Cash-In (Deposit via Agent)
- **Method & Route:** `POST /api/v1/transactions/agent/cash-in`
- **Access:** Agent only (`ability:agent`, `password.changed`)
- **Request Body:**
  ```json
  {
    "customer_phone": "01712345678",
    "amount": 500.00,
    "pin": "12345",
    "idempotency_key": "cashin-uuid-9012",
    "description": "Cash-in deposit at store"
  }
  ```
- **Response (201 Created):**
  ```json
  {
    "success": true,
    "message": "Cash-in completed successfully.",
    "data": {
      "transaction": {
        "id": 103,
        "type": "CASH_IN",
        "amount": 500.00,
        "system_fee_amount": 0.00,
        "agent_commission_amount": 0.00,
        "agent_wallet_balance_after": 1500.00
      }
    }
  }
  ```
- **Behavior & Rules:**
  - Initiated by the agent using their own authentication token and PIN.
  - Requires an approved, active agent profile.
  - Moves float 1:1 from agent wallet to customer user wallet with zero fee and no commission (0.00).
  - Agent cannot cash-in to their own account.
  - Recipient must be an active, non-deleted user with an active wallet.
- **Error Codes:** 403 (Unapproved agent, user token access, or wallet blocked), 404 (Old user route removed), 409 (Idempotency key reused with different parameters, or agent status pending/suspended), 422 (Agent insufficient float, wrong PIN, self cash-in, or customer not found), 429 (PIN locked out).

---

### 5.4 Agent Cash-Out (Withdrawal via Agent)
- **Method & Route:** `POST /api/v1/transactions/cash-out`
- **Access:** User only (`ability:user`, `password.changed`)
- **Description:** Debits principal + system fee from user wallet; credits principal + commission to agent wallet. Writes CASH_OUT row and linked COMMISSION_PAYOUT row atomically.
- **Request Body:**
  ```json
  {
    "agent_phone": "01812345678",
    "amount": 2000.00,
    "pin": "12345",
    "idempotency_key": "cashout-uuid-3456"
  }
  ```
- **Response (201 Created):**
  ```json
  {
    "success": true,
    "message": "Cash-out completed successfully.",
    "data": {
      "transaction": {
        "id": 104,
        "user_id": 10,
        "sender_id": 10,
        "agent_id": 12,
        "type": "CASH_OUT",
        "amount": 2000.00,
        "system_fee_amount": 100.00,
        "agent_commission_amount": 50.00,
        "status": "COMPLETED",
        "sender_wallet_balance_after": 1950.00
      }
    }
  }
  ```
- **Error Codes:** 403 (Agent unapproved or wallet blocked), 409 (Idempotency key reused with different parameters), 422 (Wrong PIN, insufficient balance or cap exceeded), 429 (PIN locked out).

---

### 5.5 Agent Withdrawal
- **Method & Route:** `POST /api/v1/transactions/agent/withdrawal`
- **Access:** Agent only (`ability:agent`, `password.changed`)
- **Request Body:**
  ```json
  {
    "amount": 1500.00,
    "pin": "12345",
    "idempotency_key": "withdraw-uuid-7890"
  }
  ```
- **Response (201 Created):**
  ```json
  {
    "success": true,
    "message": "Withdrawal request submitted and awaiting approval.",
    "data": {
      "transaction": {
        "id": 106,
        "user_id": 12,
        "agent_id": 12,
        "type": "AGENT_WITHDRAWAL",
        "amount": 1500.00,
        "status": "PENDING",
        "sender_wallet_balance_after": 600.00
      }
    }
  }
  ```
- **Behavior & Rules:** the amount is debited (held) immediately so it cannot be spent twice. The transaction stays `PENDING` until staff approve it (5.10, `COMPLETED`) or reject it (5.11, `REJECTED`, amount returned).
- **Error Codes:** 403 (Wallet blocked or unapproved), 409 (Idempotency key reused with different parameters), 422 (Wrong PIN, insufficient balance), 429 (PIN locked out).

---

### 5.5b Current Cap Usage
- **Method & Route:** `GET /api/v1/transactions/caps`
- **Access:** User only (`ability:user`, `password.changed`)
- **Description:** Returns the caller's daily and monthly spending caps as they apply right now.
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "data": {
      "caps": {
        "daily_limit": 10000.00,
        "daily_used": 1500.00,
        "daily_remaining": 8500.00,
        "monthly_limit": 50000.00,
        "monthly_used": 3200.00,
        "monthly_remaining": 46800.00
      }
    }
  }
  ```
- **Behavior & Rules:**
  - Caps count the transaction **amount** (not the fee) of outgoing cash-outs and transfers.
  - Periods roll over at midnight in the business timezone (`config/transactions.php` → `business_timezone`, default `Asia/Dhaka`), not midnight UTC.
  - Rollover is lazy: the first cash-out or transfer of a new day (or month) resets the counter under the same row lock that checks the cap, so correctness never depends on a scheduled job. Usage from an earlier period is reported as `0` even before that first transaction. A daily `ResetStaleCaps` job only tidies stored values.

---

### 5.5c Fee Quote
- **Method & Route:** `GET /api/v1/transactions/quote?type=CASH_OUT&amount=1000`
- **Access:** User only (`ability:user`, `password.changed`)
- **Description:** Previews the fee and total debit using the same fee calculator the transaction uses, so the confirm dialog shows what will actually be charged. `type` is `CASH_OUT` or `TRANSFER` (transfers have no fee).
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "data": {
      "quote": { "type": "CASH_OUT", "amount": 1000.00, "fee_rate": 0.05, "fee_amount": 50.00, "total": 1050.00 }
    }
  }
  ```
- **Note:** a quote is informational. If the fee rate changes between the quote and the submit, the transaction uses the rate at submit time.

---

### 5.6 User Transaction History
- **Method & Route:** `GET /api/v1/transactions/history`
- **Access:** Authenticated (`ability:admin,agent,user`, `password.changed`)
- **Query Params:** `type` (`TOP_UP`, `CASH_IN`, `CASH_OUT`, `TRANSFER`, `AGENT_WITHDRAWAL`, `COMMISSION_PAYOUT`, `FLOAT_ISSUE`, `SIGNUP_BONUS`), `status`, `from_date`, `to_date`, `per_page`, `page`
- **Description:** Paginated transactions list scoped directly to the authenticated caller's active wallet panel (determined by token ability: `user` -> USER wallet, `agent` -> AGENT wallet; admins view unscoped history).
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "data": {
      "transactions": [
        {
          "id": 102,
          "user_id": 10,
          "sender_id": 10,
          "recipient_id": 11,
          "initiated_by": 10,
          "type": "TRANSFER",
          "amount": 1000.00,
          "system_fee_amount": 0.00,
          "system_fee_rate": 0.0000,
          "agent_commission_amount": 0.00,
          "agent_commission_rate": 0.0000,
          "currency": "BDT",
          "description": "Dinner split",
          "status": "COMPLETED",
          "idempotency_key": "transfer-uuid-5678",
          "sender_wallet_balance_after": 4050.00,
          "meta": null,
          "created_at": "2026-10-11T12:00:00.000000Z",
          "updated_at": "2026-10-11T12:00:00.000000Z"
        }
      ],
      "pagination": {
        "total": 1,
        "per_page": 15,
        "current_page": 1,
        "last_page": 1,
        "from": 1,
        "to": 1
      }
    }
  }
  ```
  > [!NOTE]
  > - **Balance Privacy:** For non-admin viewers, counterparty `*_balance_after` fields are omitted across all responses (`recipient_wallet_balance_after` is omitted when viewed by sender, and `sender_wallet_balance_after` is omitted when viewed by recipient).
  > - **Internal IDs:** Internal database foreign keys (`sender_wallet_id`, `recipient_wallet_id`, `agent_wallet_id`) are omitted for regular users and only exposed to administrative staff.
  > - **Staff Access:** Admins and moderators with `admin` token ability receive all balance slots and internal wallet IDs.

---

### 5.7 Inspect Single Transaction
- **Method & Route:** `GET /api/v1/transactions/{transaction}`
- **Access:** Participant wallet owner (`TransactionPolicy`) or Admin/Staff
- **Description:** Single transaction details. Non-admin viewers only see the balance after field matching their active wallet slot, and internal wallet IDs are omitted.
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "data": {
      "transaction": {
        "id": 102,
        "user_id": 10,
        "sender_id": 10,
        "recipient_id": 11,
        "type": "TRANSFER",
        "amount": 1000.00,
        "system_fee_amount": 0.00,
        "status": "COMPLETED",
        "sender_wallet_balance_after": 4050.00
      }
    }
  }
  ```
- **Error Codes:** 403 (Caller wallet not involved in transaction or cross-panel token mismatch), 404 (Transaction not found).

---

### 5.8 Admin Audit (All System Transactions)
- **Method & Route:** `GET /api/v1/transactions/admin/all`
- **Access:** Admin only (`ability:admin`, `password.changed`)
- **Query Params:** `type` (any value listed in 5.6), `status` (`COMPLETED`, `PENDING`, `FAILED`, `REJECTED`), `search`, `party_id`, `user_id`, `sender_id`, `recipient_id`, `agent_id`, `from_date`, `to_date`, `per_page`, `page`
  - `search` matches the name, phone or email of any party, the `external_reference`, or the transaction ID (`123` or `#123`). LIKE wildcards are matched literally.
  - `party_id` returns transactions where that user is the user, sender, recipient or agent.
- **Response (200 OK):** Full paginated system transactions list. For staff each item includes `parties: { sender, recipient, agent }` with `{ id, name, phone_number }`.

---

### 5.9 Admin Float Issuance
- **Method & Route:** `POST /api/v1/transactions/admin/float-issue`
- **Access:** Admin only (`ability:admin`, `password.changed`) **and** the `agents.issue_float` permission. ADMIN does not hold it by default; SUPER_ADMIN can assign it to a custom role (e.g. FINANCE).
- **Description:** Credits e-money float to an approved agent's AGENT wallet against a confirmed external bank deposit. Writes a `FLOAT_ISSUE` transaction (`initiated_by` = admin) and a `FLOAT_ISSUED` audit log entry.
- **Request Body:** (`agent_id` or `agent_phone` is required)
  ```json
  {
    "agent_id": 42,
    "amount": "5000.00",
    "external_reference": "BANK-SLIP-1001",
    "idempotency_key": "float-uuid-1234",
    "description": "Cheque deposit branch #42"
  }
  ```
- **Response (201 Created):**
  ```json
  {
    "success": true,
    "message": "Float issued successfully.",
    "data": {
      "transaction": {
        "type": "FLOAT_ISSUE",
        "amount": "5000.00",
        "external_reference": "BANK-SLIP-1001",
        "status": "COMPLETED"
      }
    }
  }
  ```
- **Behavior & Rules:**
  - `external_reference` is unique across all transactions (enforced by a database unique index), so one bank deposit can never be credited twice — including by two concurrent requests.
  - An admin cannot issue float to themselves.
  - The agent must be approved, and their agent wallet must not be blocked.
  - Amount limits come from `config/transactions.php` → `limits.float_issue`.
- **Error Codes:** 403 (Missing permission, non-admin token, or wallet blocked), 409 (Idempotency key reused with different parameters, or agent not approved), 422 (Validation error, reused external reference, or self-issuance).

---

### 5.10 Approve Agent Withdrawal
- **Method & Route:** `PATCH /api/v1/transactions/admin/withdrawals/{transaction}/approve`
- **Access:** Admin only (`ability:admin`, `password.changed`) **and** `agents.approve_withdrawal`. Staff cannot review their own withdrawal.
- **Request Body:** `{ "external_reference": "BEFTN-20261014-0042", "note": "optional" }` — the bank transfer reference, unique across all transactions.
- **Behavior:** the funds were already held at request time, so only the status changes (`PENDING` → `COMPLETED`). The review is stored in `meta.review` and audited as `WITHDRAWAL_APPROVED`.
- **Error Codes:** 403 (Missing permission, non-admin token, own withdrawal), 409 (Not a pending agent withdrawal), 422 (Missing or reused reference).

---

### 5.11 Reject Agent Withdrawal
- **Method & Route:** `PATCH /api/v1/transactions/admin/withdrawals/{transaction}/reject`
- **Access:** Same as 5.10.
- **Request Body:** `{ "reason": "Bank account name does not match" }` (5–500 characters)
- **Behavior:** returns the held amount to the agent wallet under a row lock and marks the transaction `REJECTED`. The refund goes through even if the wallet was blocked after the request, because the money never left the platform. A `REJECTED` row nets to zero in the ledger. Audited as `WITHDRAWAL_REJECTED` with balances before and after.
- **Error Codes:** 403, 409 (already reviewed — a second rejection never refunds twice), 422 (Missing reason).

---

### 5.12 Signup Bonus (`SIGNUP_BONUS`)
There is no endpoint for this. The bonus is credited by `SignupBonusService::credit()` during registration (2.1 and 3.2).
- **Ledger:** wallets open at 0.00 and the bonus is written as a `COMPLETED` `SIGNUP_BONUS` transaction with no sender: `recipient_id` and `initiated_by` are the new user, `recipient_wallet_balance_after` is set, fee and commission are zero. `meta` is `{ "source": "SYSTEM", "source_label": "bKash", "wallet_kind": "USER" | "AGENT" }` and the description is `Signup bonus from bKash`. A wallet's balance therefore always equals the sum of its ledger rows.
- **Atomicity:** it runs inside the registration database transaction with the wallet row locked, so the account and its bonus commit or roll back together.
- **One per wallet:** the idempotency key `signup-bonus:{wallet_id}` (unique per initiator) means a second call returns the original transaction instead of paying again.
- **Settings:** `signup_bonus_enabled` and `signup_bonus_amount` (6.1). Nothing is credited when the bonus is disabled or the amount is 0. Defaults and the `source_label` come from `config/transactions.php` → `signup_bonus` (`SIGNUP_BONUS_*` env variables).
- **Not credited:** wallets opened later through role assignment (3.9) and wallets that existed before this feature (they were never backfilled).
- **Caps:** the bonus is incoming money and does not count toward daily or monthly caps.

---

## 6. System Settings Endpoints

### 6.1 View Global System Settings
- **Method & Route:** `GET /api/v1/system-settings`
- **Access:** Authenticated (`ability:admin,agent,user`)
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "data": {
      "settings": {
        "system_fee_rate": 0.05,
        "agent_commission_rate": 0.02,
        "signup_bonus_enabled": true,
        "signup_bonus_amount": 50
      }
    }
  }
  ```
- **Note:** `signup_bonus_enabled` and `signup_bonus_amount` always report the values in force. When staff have not stored an override, they come from `config/transactions.php` → `signup_bonus`.

---

### 6.2 Update Global System Settings
- **Method & Route:** `PATCH /api/v1/system-settings`
- **Access:** Admin only (`ability:admin`) **and** `settings.update`
- **Request Body:** send at least one of the keys below (422 `settings` otherwise). The legacy aliases `transactionFee` and `agentCommission` are also accepted.
  ```json
  {
    "system_fee_rate": 0.04,
    "agent_commission_rate": 0.015,
    "signup_bonus_enabled": true,
    "signup_bonus_amount": 75.00
  }
  ```
  | Key | Rule |
  |---|---|
  | `system_fee_rate`, `agent_commission_rate` | number, 0 to 1 |
  | `signup_bonus_enabled` | boolean |
  | `signup_bonus_amount` | number, 0 to `signup_bonus.max_amount` (default 1000), at most 2 decimal places |
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "Settings updated successfully",
    "data": {
      "settings": {
        "system_fee_rate": 0.04,
        "agent_commission_rate": 0.015
      }
    }
  }
  ```
- **Error Codes:** 403 (Non-admin token or missing `settings.update`), 422 (Rate outside 0–1, invalid bonus amount, or no setting provided).
- **Effect:** bonus changes apply to registrations from that moment on. Wallets that already received a bonus are not adjusted.
- **Audit:** each update writes a `SETTINGS_UPDATED` audit entry with the before and after value of every changed key.

---

### 6.3 Session Idle Timeouts
- **Routes:** `GET /api/v1/system-settings/session-timeouts` (any admin token) and `PATCH /api/v1/system-settings/session-timeouts` (`settings.manage_session_timeouts`)
- **Request Body (PATCH):** any of `{ "admin": 15, "agent": 5, "user": 3 }` — whole minutes from 1 to 120.
- **Response (200 OK):** `{ "success": true, "data": { "session_timeouts": { "admin": 15, "agent": 5, "user": 3 } } }`
- **Behavior:** a token unused for longer than its panel's timeout is rejected with 401 on the next request. Defaults are 5 minutes per panel (`config/auth_settings.php` → `tokens.idle_timeout_minutes`); changes are stored in `system_settings` and take effect for every existing session immediately. Audited as `SESSION_TIMEOUTS_UPDATED`. Tokens without a panel ability (password-change) use `tokens.default_idle_timeout_minutes`.

---

## 7. Dynamic Roles & Permissions Endpoints

Granular permission catalog and dynamic admin-panel role management. Access requires an admin token (`ability:admin`) and the respective catalog permission (defense-in-depth).

### 7.1 List Permissions Catalog
- **Method & Route:** `GET /api/v1/permissions`
- **Access:** Authenticated admin staff (`roles.view`)
- **Description:** Returns the complete permission catalog (22 permissions, defined in `Modules\Roles\Enums\Permission`) as a flat list and grouped by UI category (`Users`, `Agents`, `Wallets`, `Transactions`, `Settings`, `Auth`, `Roles`). `is_super_admin_only: true` means the permission is implicit for SUPER_ADMIN only and can never be attached to a role.
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "data": {
      "permissions": [
        { "id": 1, "name": "users.view_any", "label": "View any user", "description": "View any user", "group": "Users", "is_super_admin_only": false },
        { "id": 3, "name": "users.grant_admin_access", "label": "Grant admin access to users", "description": "Grant admin access to users", "group": "Users", "is_super_admin_only": true }
      ],
      "grouped": {
        "Users": [
          { "id": 1, "name": "users.view_any", "label": "View any user", "description": "View any user", "group": "Users", "is_super_admin_only": false },
          { "id": 3, "name": "users.grant_admin_access", "label": "Grant admin access to users", "description": "Grant admin access to users", "group": "Users", "is_super_admin_only": true }
        ]
      }
    }
  }
  ```

---

### 7.2 List Roles
- **Method & Route:** `GET /api/v1/roles`
- **Access:** Authenticated admin staff (`roles.view`)
- **Query Params:**
  - `panel` (optional, string: `admin`, `agent`, `user`)
  - `per_page` (optional, integer: 1-100)
  - `page` (optional, integer)
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "data": {
      "roles": [
        {
          "id": 1,
          "name": "SUPER_ADMIN",
          "description": "Platform super administrator",
          "panel": "admin",
          "is_system": true,
          "users_count": 1,
          "permissions_count": 0
        }
      ]
    }
  }
  ```

---

### 7.3 Create Custom Role
- **Method & Route:** `POST /api/v1/roles`
- **Access:** Admin staff with `roles.manage` (Subject to anti-escalation)
- **Description:** Creates a new custom role with `panel = admin`.
- **Request Body:**
  ```json
  {
    "name": "AUDITOR",
    "description": "Audit transactions and review logs only",
    "permissions": ["transactions.view_any", "wallets.view_any"]
  }
  ```
- **Response (201 Created):**
  ```json
  {
    "success": true,
    "message": "Role created successfully.",
    "data": {
      "role": {
        "id": 5,
        "name": "AUDITOR",
        "description": "Audit transactions and review logs only",
        "panel": "admin",
        "is_system": false,
        "permissions": [ ... ]
      }
    }
  }
  ```
- **Error Codes:**
  - `403 Forbidden`: Anti-escalation violation (e.g. attempting to grant permissions actor does not possess).
  - `422 Unprocessable Content`: Role name invalid format or duplicate.

---

### 7.4 Get Role Details
- **Method & Route:** `GET /api/v1/roles/{role}`
- **Access:** Admin staff (`roles.view`)
- **Response (200 OK):** Detailed role model including eager-loaded `permissions`.

---

### 7.5 Update Role Meta
- **Method & Route:** `PUT /api/v1/roles/{role}`
- **Access:** Admin staff with `roles.manage`
- **Request Body:**
  ```json
  {
    "name": "AUDITOR_SENIOR",
    "description": "Senior audit staff"
  }
  ```
- **Response (200 OK):** Updated role resource.
- **Error Codes:**
  - `403 Forbidden`: Attempting to rename a system role (`is_system = true`).

---

### 7.6 Sync Role Permissions
- **Method & Route:** `PUT /api/v1/roles/{role}/permissions`
- **Access:** Admin staff with `roles.manage` (Subject to anti-escalation)
- **Description:** Atomically synchronizes permissions for a role with audit logging.
- **Request Body:**
  ```json
  {
    "permissions": ["transactions.view_any"]
  }
  ```
- **Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "Role permissions updated successfully.",
    "data": {
      "role": { "id": 5, "name": "AUDITOR", "permissions": [ ... ] }
    }
  }
  ```
- **Error Codes:**
  - `403 Forbidden`: Escalation attempt (`added ∪ removed ⊈ actor_permissions`), self-modification attempt, or modifying system role without super-admin.

---

### 7.7 Delete Role
- **Method & Route:** `DELETE /api/v1/roles/{role}`
- **Access:** Admin staff with `roles.manage`
- **Response (204 No Content)**
- **Error Codes:**
  - `403 Forbidden`: Attempting to delete a system role (`is_system = true`) or a role the actor currently holds.
  - `409 Conflict`: Role is currently assigned to one or more users (`users_count > 0`).

