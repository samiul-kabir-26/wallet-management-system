# Error Handling & Status Codes Reference

This document catalogs the standard error response structures, HTTP status codes, and domain exceptions used across the Wallet Management System API.

---

## 1. Standard Error Envelope

All API errors return a consistent JSON response structure:

```json
{
  "success": false,
  "message": "Human-readable error explanation",
  "errors": {
    "field_name": [
      "Specific validation or field error detail"
    ]
  }
}
```

- `success`: Always `false` on error.
- `message`: A descriptive message suitable for client display or logging.
- `errors`: Key-value map of field validation failures or empty object/array when not applicable.

---

## 2. HTTP Status Codes Reference

| HTTP Code | Name | Description |
|-----------|------|-------------|
| **400** | Bad Request | Malformed request or invalid payload syntax. |
| **401** | Unauthorized | Caller is unauthenticated, missing token, or provided invalid credentials/expired OTP. |
| **403** | Forbidden | Caller lacks required ability/role, wallet is blocked, or policy authorization failed. |
| **404** | Not Found | Target resource, route, user, or wallet does not exist. |
| **409** | Conflict | State conflict (e.g. approving an already approved agent, duplicate submission). |
| **422** | Unprocessable Entity | Form validation failure, insufficient balance, cap exceeded, or business rule rejection. |
| **429** | Too Many Requests | Rate limit threshold exceeded on throttled endpoints. |
| **500** | Internal Server Error | Unhandled server exception. In production, error messages and stack traces are suppressed. |

---

## 3. Domain Exception Catalog

### 3.1 Authentication & Security (`Modules/Authentication`)

| Exception | HTTP Code | Message | Scenario |
|---|---|---|---|
| `InvalidCredentialsException` | 401 | `Invalid credentials.` / `Invalid email/phone number or password.` | Incorrect PIN, email, or password provided. |
| `InvalidOtpException` | 401 | `Invalid OTP code.` / `Invalid or expired OTP.` / `Too many failed attempts. Please request a new OTP.` | Failed OTP verification, expired OTP, or lockout limit reached (5 attempts). |
| `AccountInactiveException` | 403 | `Your account is inactive.` | Account `is_active` status is `INACTIVE` or `SUSPENDED`. |
| `InsufficientRoleException` | 403 | `You do not have permission to access this resource.` | Caller lacks required role or agent profile is not approved. |
| `PasswordChangeRequiredException` | 403 | `Password change required before accessing this resource.` | Admin account has not satisfied initial password change requirement. |
| `UnverifiedAccountException` | 403 | `Account is not verified.` | Target user has `is_verified === false`. |

---

### 3.2 Wallets (`Modules/Wallets`)

| Exception | HTTP Code | Message | Scenario |
|---|---|---|---|
| `WalletBlockedException` | 403 | `This wallet is blocked and cannot perform operations.` | Attempting debit, transfer, cash-out, or withdrawal from a blocked wallet. |
| `WalletNotFoundException` | 404 | `Wallet not found.` | Target user has no associated wallet record. |
| `InsufficientBalanceException` | 422 | `Insufficient wallet balance for this operation.` | Debit amount exceeds current available balance. |

---

### 3.3 Transactions & Caps (`Modules/Transactions`)

| Exception | HTTP Code | Message | Scenario |
|---|---|---|---|
| `DailyCapsExceededException` | 422 | `Daily transaction cap of [X] BDT exceeded. Remaining available: [Y] BDT.` | Transaction amount causes cumulative daily outgoing total to exceed `daily_cap`. |
| `MonthlyCapsExceededException` | 422 | `Monthly transaction cap of [X] BDT exceeded. Remaining available: [Y] BDT.` | Transaction amount causes cumulative monthly outgoing total to exceed `monthly_cap`. |
| `InvalidRecipientException` | 422 | `Cannot transfer funds to your own wallet.` | Sender attempts P2P transfer targeting their own user ID. |
| `InsufficientBalanceException` | 422 | `Insufficient wallet balance to complete transfer.` / `Insufficient wallet balance to cover withdrawal amount and fee.` | Principal + fees exceed sender wallet balance. |

---

### 3.4 Users & Agents (`Modules/Users`)

| Exception | HTTP Code | Message | Scenario |
|---|---|---|---|
| `UserNotAnAgentException` | 404 | `Target user does not have an agent profile.` | Attempting agent actions on a user without an `agent_info` record. |
| `AgentNotApprovedException` | 409 | `Agent is not approved.` / `This action is only allowed for approved agents.` | Transaction attempted through an unapproved or suspended agent. |
| `AgentNotPendingException` | 409 | `Agent is not pending approval.` | Attempting to approve an agent whose status is already `APPROVED` or `SUSPENDED`. |

---

## 4. Validation Errors (HTTP 422)

When request validation fails in Form Requests (`FormRequest`), Laravel returns HTTP 422 with field-specific errors:

```json
{
  "message": "The phone number has already been taken.",
  "errors": {
    "phone_number": [
      "The phone number has already been taken."
    ],
    "pin": [
      "The pin field confirmation does not match."
    ]
  }
}
```

---

## 5. Production Error Masking (HTTP 500)

In non-production environments (`local`, `testing`), unhandled exceptions output complete debugging traces.

In **production** (`APP_ENV=production`), `bootstrap/app.php` automatically suppresses stack traces, SQL queries, and system paths, returning:

```json
{
  "success": false,
  "message": "An error occurred.",
  "errors": []
}
```
All underlying exception details are written securely to application log files (`storage/logs/laravel.log`) without client exposure.
