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

### 2.1 Public Registration
- **Method & Route:** `POST /api/v1/auth/register`
- **Access:** Public (Rate limited: 5 req/min per IP)
- **Description:** Registers a new `USER` or `AGENT`. For users, provisions a wallet (50.00 BDT bonus) and caps. For agents, provisions a pending profile.
- **Request Body:**
  ```json
  {
    "name": "Alice Rahman",
    "phone_number": "01712345678",
    "pin": "12345",
    "pin_confirmation": "12345",
    "role": "USER"
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
      "abilities": ["user"]
    }
  }
  ```
- **Error Codes:** 401 (Invalid credentials), 403 (Account inactive or missing USER role), 429 (Throttled).

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
        "is_active": "ACTIVE"
      }
    }
  }
  ```

---

### 2.6 Accept Staff Invitation (Case B Step 2)
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
- **Description:** Customer sets a new PIN using the recovery link provided by customer care.
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
- **Error Codes:** 403 (Invalid/expired signed link), 422 (Validation failure).

---

### 2.14 Refresh Token
- **Method & Route:** `POST /api/v1/auth/refresh-token`
- **Access:** Authenticated (`auth:sanctum`)
- **Description:** Reissues access token preserving current abilities.
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

## 3. User & Agent Management Endpoints

### 3.1 List All Users (Admin Audit)
- **Method & Route:** `GET /api/v1/users/all-users`
- **Access:** Admin only (`auth:sanctum`, `ability:admin`, `password.changed`)
- **Query Params:** `role`, `is_active`, `is_verified`, `per_page` (1-100), `page`
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
- **Access:** Admin only (`ability:admin`, `password.changed`). Registering `ADMIN` requires `SUPER_ADMIN` role.
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

### 3.4 Update User Profile
- **Method & Route:** `PATCH /api/v1/users/{user}`
- **Access:** Authenticated caller updating self, or Admin (`UserPolicy`)
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

### 3.5 Grant Admin Access to Existing User (Case B Step 1)
- **Method & Route:** `PATCH /api/v1/users/{user}/grant-admin-access`
- **Access:** SUPER_ADMIN only (`auth:sanctum`, `ability:admin`, `password.changed`)
- **Description:** Elevates user to administrator, assigns temporary password, attaches email, and generates signed invitation link. Target phone must be verified.
- **Request Body:**
  ```json
  {
    "email": "promoted.staff@wallet.local"
  }
  ```
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
- **Error Codes:** 403 (Caller not SUPER_ADMIN), 422 (Phone unverified or email collision).

---

### 3.6 Approve Pending Agent
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

### 3.7 Suspend Approved Agent
- **Method & Route:** `PATCH /api/v1/users/{user}/suspend-agent`
- **Access:** Admin only (`ability:admin`, `password.changed`)
- **Request Body:**
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
- **Error Codes:** 404 (User is not an agent), 409 (Agent is not in APPROVED status).

---

### 3.8 Assign Additional Role to User ("Become an Agent" Flow)
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
- **Query Params:** `is_blocked`, `user_id`, `per_page`, `page`
- **Response (200 OK):** Paginated wallet list with total, per_page, current_page.

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
- **Error Codes:** 403 (Wallet blocked), 422 (Validation error).

---

### 5.2 P2P Balance Transfer
- **Method & Route:** `POST /api/v1/transactions/transfer`
- **Access:** User only (`ability:user`, `password.changed`)
- **Request Body:**
  ```json
  {
    "recipient_id": 11,
    "amount": 1000.00,
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
- **Error Codes:** 403 (Wallet blocked), 422 (Insufficient balance, cap exceeded, self-transfer).

---

### 5.3 Agent Cash-In
- **Method & Route:** `POST /api/v1/transactions/cash-in`
- **Access:** User only (`ability:user`, `password.changed`)
- **Request Body:**
  ```json
  {
    "agent_phone": "01812345678",
    "amount": 500.00,
    "idempotency_key": "cashin-uuid-9012"
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
        "recipient_wallet_balance_after": 4550.00
      }
    }
  }
  ```
- **Error Codes:** 403 (Agent unapproved or wallet blocked), 422 (Agent insufficient balance).

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
- **Error Codes:** 403 (Agent unapproved or wallet blocked), 422 (Insufficient balance or cap exceeded).

---

### 5.5 Agent Withdrawal
- **Method & Route:** `POST /api/v1/transactions/agent/withdrawal`
- **Access:** Agent only (`ability:agent`, `password.changed`)
- **Request Body:**
  ```json
  {
    "amount": 1500.00,
    "idempotency_key": "withdraw-uuid-7890"
  }
  ```
- **Response (201 Created):**
  ```json
  {
    "success": true,
    "message": "Withdrawal completed successfully.",
    "data": {
      "transaction": {
        "id": 106,
        "user_id": 12,
        "agent_id": 12,
        "type": "AGENT_WITHDRAWAL",
        "amount": 1500.00,
        "status": "COMPLETED",
        "sender_wallet_balance_after": 600.00
      }
    }
  }
  ```
- **Error Codes:** 403 (Wallet blocked or unapproved), 422 (Insufficient balance).

---

### 5.6 User Transaction History
- **Method & Route:** `GET /api/v1/transactions/history`
- **Access:** Authenticated (`ability:admin,agent,user`, `password.changed`)
- **Query Params:** `type`, `status`, `from_date`, `to_date`, `per_page`, `page`
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
- **Query Params:** `type`, `status`, `user_id`, `sender_id`, `recipient_id`, `agent_id`, `from_date`, `to_date`, `per_page`, `page`
- **Response (200 OK):** Full paginated system transactions list with eager-loaded user/sender/recipient/agent relations.

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
        "agent_commission_rate": 0.02
      }
    }
  }
  ```

---

### 6.2 Update Global System Settings
- **Method & Route:** `PATCH /api/v1/system-settings`
- **Access:** Admin only (`ability:admin`)
- **Request Body:**
  ```json
  {
    "system_fee_rate": 0.04,
    "agent_commission_rate": 0.015
  }
  ```
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
- **Error Codes:** 403 (Non-admin token), 422 (Rate must be between 0 and 1).

---

## 7. Dynamic Roles & Permissions Endpoints

Granular permission catalog and dynamic admin-panel role management. Access requires an admin token (`ability:admin`) and the respective catalog permission (defense-in-depth).

### 7.1 List Permissions Catalog
- **Method & Route:** `GET /api/v1/permissions`
- **Access:** Authenticated admin staff (`roles.view`)
- **Description:** Returns the complete 16-permission catalog flat list and grouped by UI category (`Users`, `Agents`, `Wallets`, `Transactions`, `Settings`, `Auth`, `Roles`). `is_super_admin_only: true` means the permission is implicit for SUPER_ADMIN only and can never be attached to a role.
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

