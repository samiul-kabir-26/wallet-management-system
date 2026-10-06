# Task 4: Module 2 — Users & Roles Management

**Status:** Ready after Task 3  
**Estimated Duration:** 3-4 hours  
**Difficulty:** Medium

---

## Objective

Implement user management, role assignment, and authorization system.

Users must be:
- Registered by SUPER_ADMIN or ADMIN
- Assigned roles via the USER_ROLES junction table
- Subject to authorization checks

---

## What You'll Learn

- Role-based access control (RBAC)
- Laravel Policies for authorization
- User management endpoints
- Agent approval workflow
- Pagination and filtering

---

## Step 1: Understand Authorization Concepts

### Three Authorization Mechanisms in Laravel

1. **Middleware** — Route-level checks
   ```php
   Route::post('/users', 'UserController@store')->middleware('admin');
   ```
   - Simple role checks (is user admin?)
   - Not flexible for complex rules

2. **Gates** — Application-level authorization
   ```php
   Gate::define('approve-agent', function ($user) {
       return $user->hasRole('ADMIN');
   });
   ```
   - Centralized permission logic
   - Used: `Gate::authorize('approve-agent')`

3. **Policies** — Model-level authorization
   ```php
   class UserPolicy {
       public function update(User $user, User $target) {
           return $user->id === $target->id || $user->isAdmin();
       }
   }
   ```
   - Methods tied to models
   - Used: `$this->authorize('update', $user)`

**For this project:** Use Policies for model-level checks

---

## Step 2: User Registration (Super Admin/Admin Only)

### Endpoint: Register New User

**Route:** `POST /api/v1/users/register`

**Request Body:**
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "secure_password",
  "phone_number": "01712345678",
  "role": "USER",
  "address": "123 Main St"
}
```

**Response (201):**
```json
{
  "success": true,
  "message": "User registered successfully",
  "data": {
    "user": {
      "id": 3,
      "name": "John Doe",
      "email": "john@example.com",
      "phone_number": "01712345678",
      "roles": ["USER"]
    }
  }
}
```

**Authorization:**
- Only SUPER_ADMIN or ADMIN can register users
- Only SUPER_ADMIN can register ADMIN users

**Business Logic:**
1. Authorize user (check if ADMIN or SUPER_ADMIN)
2. Validate input using Form Request
3. Check role being assigned:
   - If ADMIN: only SUPER_ADMIN can assign (authorize again)
4. Create User record. **There is no `user_type` column** (see `CLAUDE.md` §9) — the credential you set *is* the track:
   - ADMIN / MODERATOR → set `password`, leave `pin` NULL
   - AGENT / USER → set `pin`, leave `password` NULL
   - Hash whichever one applies
5. Create Wallet (auto or explicit)
6. Create Cap record
7. If AGENT: Create AgentInfo (PENDING status)
8. Assign role via UserRole
9. Return created user

**What to Create:**
- `app/Http/Requests/Users/RegisterUserRequest.php` — Validation
- `app/Services/Users/UserService.php` — Business logic
- `app/Http/Controllers/Users/UserController.php` — Endpoint
- `app/Http/Resources/UserResource.php` — Format response
- `app/Policies/UserPolicy.php` — Authorization checks

**RegisterUserRequest Validation:**
- `name`: required, string, min 2, max 255
- `email`: required + unique when role is ADMIN or MODERATOR
- `password`: required with email, min 8
- `phone_number`: required + unique when role is AGENT or USER
- `pin`: required with phone_number, numeric only, 5–10 digits
- `role`: required, in ['USER', 'AGENT', 'ADMIN', 'MODERATOR']
- `address`: optional, string

**What You Should Understand:**
- Form Request authorization checks
- Conditional validation driven by role (`required_if`, `Rule::requiredIf`)
- Why credential presence replaces a stored track column
- Conditional field requirements

**Note on adding a second track later:** this endpoint creates an account on **one** track. Giving an existing user credentials for the other track is a separate, deliberately-gated flow — `set-pin` and the signed-invite path in Task 3, Step 3B. Do not let this endpoint set both `password` and `pin` in one call; that would bypass the re-authentication those flows require.

---

## Step 3: User Management Endpoints

### Endpoint 1: List All Users

**Route:** `GET /api/v1/users/all-users`

**Query Parameters:**
```
?role=AGENT&is_active=ACTIVE&page=1&per_page=15
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "users": [
      {...},
      {...}
    ],
    "pagination": {
      "total": 50,
      "per_page": 15,
      "current_page": 1,
      "last_page": 4,
      "from": 1,
      "to": 15
    }
  }
}
```

**Authorization:** ADMIN or SUPER_ADMIN

**Business Logic:**
1. Authorize user
2. Build query:
   - Filter by role (if provided)
   - Filter by is_active (if provided)
   - Filter by is_verified (if provided)
3. Paginate (default 15 per page)
4. Return users with pagination meta

---

### Endpoint 2: Get User Details

**Route:** `GET /api/v1/users/:id`

**Response (200):**
```json
{
  "success": true,
  "data": {
    "user": {
      "id": 3,
      "name": "John Doe",
      "email": "john@example.com",
      "phone_number": "01712345678",
      "roles": ["USER"],
      "wallet": {...},
      "caps": {...},
      "agent_info": {...}  // if agent
    }
  }
}
```

**Authorization:**
- Users can view own profile
- ADMIN/SUPER_ADMIN can view any user
- Use Policy: `$this->authorize('view', User::find($id))`

---

### Endpoint 3: Update User

**Route:** `PATCH /api/v1/users/:id`

**Request Body:**
```json
{
  "name": "Jane Doe",
  "address": "456 Oak Ave",
  "image": "profile.jpg"
}
```

**Authorization:**
- Users can update own profile (name, address, image)
- ADMIN can update any user
- Cannot update: email, phone_number, password, role (separate endpoint)

**What You Should Understand:**
- Partial updates (PATCH vs. PUT)
- Authorization checks in Policy
- Field-level restrictions

---

## Step 4: Agent Approval Workflow

### Endpoint 1: Approve Agent

**Route:** `PATCH /api/v1/users/:id/approve-agent`

**Request Body:**
```json
{
  "commission_rate": 1.5
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "Agent approved successfully",
  "data": {
    "agent": {
      "user_id": 5,
      "status": "APPROVED",
      "commission_rate": 1.5,
      "approved_at": "2026-09-20T10:30:00Z",
      "approved_by": 1
    }
  }
}
```

**Authorization:** ADMIN or SUPER_ADMIN

**Business Logic:**
1. Authorize user
2. Find agent by user_id
3. Verify agent status is PENDING (can't approve if already approved)
4. Update AgentInfo:
   - status → APPROVED
   - commission_rate → provided rate (or default 1%)
   - approved_at → now()
   - approved_by → current user id
5. Return updated agent info

**What to Create:**
- Add approve method to `UserService`
- Add endpoint to `UserController`
- Add authorization check to `UserPolicy`

---

### Endpoint 2: Suspend Agent

**Route:** `PATCH /api/v1/users/:id/suspend-agent`

**Request Body:**
```json
{
  "reason": "Suspicious activity"
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "Agent suspended successfully",
  "data": {
    "agent": {
      "user_id": 5,
      "status": "SUSPENDED",
      "suspended_at": "2026-09-20T10:35:00Z",
      "suspended_by": 1
    }
  }
}
```

**Authorization:** ADMIN or SUPER_ADMIN

**Business Logic:**
1. Authorize user
2. Find agent by user_id
3. Verify agent status is APPROVED (can only suspend approved agents)
4. Update AgentInfo:
   - status → SUSPENDED
   - suspended_at → now()
   - suspended_by → current user id
5. Return updated agent info

**Note:** What about pending transactions? Should they be paused? For now: just update status, don't process pending txns.

---

## Step 4B: Staff-Initiated Credential Actions

Two endpoints live in this module but belong to flows specified in Task 3. Build them here; the flow logic is in `TODO/13-TASK-3-AUTHENTICATION.md` Step 3B.

| Endpoint | Flow | Who |
|---|---|---|
| `PATCH /api/v1/users/:id/grant-admin-access` | Case B — user gains admin access | SUPER_ADMIN only |
| `POST /api/v1/auth/pin-reset/initiate` | Case C — PIN reset | MODERATOR and above |

Note the second identifies the account by **phone number in the body**, not by an ID in the path — customer care has the caller quoting their number, and a lookup step first buys nothing. It lives under `/auth` rather than `/users` for that reason.

**The rule that governs both:** staff initiate a credential change, but never choose the credential value. `grant-admin-access` sets a temporary password the user must replace; `initiate-pin-reset` sends a link through which the user sets their own PIN.

This is not ceremony. The same PIN authorizes login and transactions, so a staff member who knew a user's PIN could spend that user's balance with the audit trail pointing at the victim.

### Customer care = MODERATOR — decided

`CLAUDE.md` §4 now grants MODERATOR "Can Initiate PIN Reset". It remains read-only for everything else.

**Policy implication:** this is MODERATOR's single write capability. When writing `UserPolicy`, give it its own method rather than folding it into a general `isStaff()` or `canManageUsers()` helper — a broad predicate will silently widen MODERATOR's reach the next time someone adds a staff action behind the same check.

Your test suite should assert the boundary explicitly: a MODERATOR can initiate a PIN reset, and cannot register a user, approve an agent, block a wallet, or change settings.

---

## Step 5: Role & Permission System

### Create UserPolicy

**File:** `app/Policies/UserPolicy.php`

**Methods:**
```php
public function viewAny(User $user) {
    // Can list users? ADMIN or SUPER_ADMIN
}

public function view(User $user, User $target) {
    // Can view user? Own user or admin
}

public function create(User $user) {
    // Can register user? ADMIN or SUPER_ADMIN
}

public function createAdmin(User $user) {
    // Can register ADMIN? SUPER_ADMIN only
}

public function update(User $user, User $target) {
    // Can update user? Own user or admin
}

public function approveAgent(User $user, User $agent) {
    // Can approve agent? ADMIN or SUPER_ADMIN
}

public function suspendAgent(User $user, User $agent) {
    // Can suspend agent? ADMIN or SUPER_ADMIN
}
```

**Helper Methods Needed:**
- `$user->hasRole($role)` — Check if user has role
- `$user->isAdmin()` — Check if ADMIN or SUPER_ADMIN
- `$user->isSuperAdmin()` — Check if SUPER_ADMIN

**Where to add these?** Either:
- Option A: Add methods directly to User model
- Option B: Create UserRole model and use `$user->roles()->where('name', $role)->exists()`
- Option C: Cache roles in a collection on the model

**Recommended:** Option B (use relationships)

---

## Step 6: Helper Methods on User Model

### Add to User Model

```php
// Check if has specific role
public function hasRole($roleName): bool {
    return $this->roles()
        ->where('name', $roleName)
        ->exists();
}

// Check if is admin
public function isAdmin(): bool {
    return $this->hasRole('ADMIN') || $this->hasRole('SUPER_ADMIN');
}

// Check if is super admin
public function isSuperAdmin(): bool {
    return $this->hasRole('SUPER_ADMIN');
}

// Get all role names as array
public function roleNames(): array {
    return $this->roles()->pluck('name')->toArray();
}
```

**What You Should Understand:**
- Lazy loading vs. eager loading queries
- Performance implications of repeated queries
- Caching strategies for roles

---

## Step 7: Testing

### Feature Tests

**File:** `tests/Feature/Users/RegisterTest.php`

Test scenarios:
```
✅ ADMIN registers USER
✅ ADMIN registers AGENT
❌ ADMIN tries to register ADMIN (403)
✅ SUPER_ADMIN registers ADMIN
✅ User gets wallet + caps on registration
✅ Agent gets agent_info (PENDING) on registration
❌ Duplicate email (409)
❌ Duplicate phone (409)
```

**File:** `tests/Feature/Users/AuthorizationTest.php`

Test scenarios:
```
❌ Regular user cannot list users (403)
✅ ADMIN can list users
✅ User can view own profile
❌ User cannot view another user's profile (403)
✅ ADMIN can view any user's profile
```

**File:** `tests/Feature/Users/ApprovalTest.php`

Test scenarios:
```
❌ Regular user cannot approve agent (403)
✅ ADMIN approves agent
✅ Agent status changes to APPROVED
✅ approved_at and approved_by recorded
✅ Commission rate updated
❌ Cannot approve already-approved agent
❌ ADMIN can suspend agent
✅ Agent status changes to SUSPENDED
```

---

## Files to Create (Summary)

### Controllers
- `app/Http/Controllers/Users/UserController.php`

### Requests
- `app/Http/Requests/Users/RegisterUserRequest.php`
- `app/Http/Requests/Users/UpdateUserRequest.php`
- `app/Http/Requests/Users/ApproveAgentRequest.php`
- `app/Http/Requests/Users/SuspendAgentRequest.php`

### Services
- `app/Services/Users/UserService.php`

### Policies
- `app/Policies/UserPolicy.php`

### Resources
- `app/Http/Resources/UserResource.php`
- `app/Http/Resources/AgentResource.php` (optional, separate from UserResource)

### Models (Updates)
- Update `app/Models/User.php` with role checking methods

### Routes
- Update `routes/api.php` with user routes

### Tests
- `tests/Feature/Users/RegisterTest.php`
- `tests/Feature/Users/AuthorizationTest.php`
- `tests/Feature/Users/ApprovalTest.php`

---

## Checklist

Before moving to Task 5, verify:

- [ ] SUPER_ADMIN and ADMIN can register users
- [ ] Only SUPER_ADMIN can register ADMIN users
- [ ] User registration creates wallet + caps
- [ ] Agent registration creates agent_info (PENDING)
- [ ] List users endpoint works with pagination
- [ ] Filtering by role, is_active works
- [ ] Users can view own profile
- [ ] ADMIN can view any profile
- [ ] Authorization denies unauthorized users (403)
- [ ] ADMIN can approve agents
- [ ] Approved agent has status=APPROVED, approval timestamps
- [ ] ADMIN can suspend agents
- [ ] Cannot approve already-approved agents
- [ ] All tests pass

---

## Key Learnings

After completing this task, you should understand:

✅ Role-based access control (RBAC)  
✅ Laravel Policies  
✅ User authorization checks  
✅ Query pagination  
✅ Resource formatting  
✅ Conditional validation  

---

## Next Task

Once complete and all tests pass, proceed to **Task 5: Wallet Management**.
