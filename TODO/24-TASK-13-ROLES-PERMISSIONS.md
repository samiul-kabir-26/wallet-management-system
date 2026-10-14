# Task 13: Dynamic Roles & Permissions (Admin-Managed)

**Status:** Not started
**Estimated Duration:** 14–22 hours
**Difficulty:** High. This is a security-critical change, because every admin authorization check in the system moves onto it.

---

## 0. Scope & Decisions Log

### What this feature is

Admins can **create roles** and **tick permission checkboxes** for each role, for example "View all transactions", "Block wallets" and "Approve agents". Staff then get access according to the permissions of the roles they hold, not according to hard-coded role names.

```text
Before:  Policy → $user->isAdmin()                  (role name hard-coded)
After:   Policy → $user->hasPermission('wallets.block')
                  AND token ability = admin           (data-driven)
```

### What it is NOT

- It is **not** a way for admins to invent new *capabilities*. A permission only means something if code checks it. The **permission catalog is defined in code** and seeded. Admins only choose *which* permissions a role gets.
- It is **not** a replacement for token abilities. CLAUDE.md §9/§16 still applies: **role/permission AND token ability**. A permission held by a user must never be exercisable through a PIN-issued token.
- It is **not** a replacement for ownership checks. "A user can view *their own* transaction" stays a policy rule based on wallet involvement. Permissions only cover *staff* reach ("view *any* transaction").

### Decisions I made for you (and why)

Revisit any of these deliberately. Don't drift away from them by accident.

| # | Decision | Reason |
|---|---|---|
| D1 | **Build it yourself. Do not install `spatie/laravel-permission`.** | §25 says to prefer built-in Laravel. This project needs to tie permissions to Sanctum token abilities, and it has a fixed, small catalog of about 20 permissions. That is roughly 2 tables, 1 model method and 1 `Gate::before`. Spatie adds guards, teams, wildcard permissions and its own cache layer, all of which you would need to learn *around*. It would also hide exactly the Gate/Policy mechanics you are meant to learn here. Revisit only if you later need per-user direct permissions or multi-tenant teams. |
| D2 | **The permission catalog lives in code** as a PHP enum (`Permission`), and is seeded into a `permissions` table. | Code is the source of truth for "what can be checked". The table exists so `role_permissions` has a real FK and the UI can list permissions with labels and groups. A seeder/command syncs enum → table (idempotent upsert). |
| D3 | **Only admin-panel roles are configurable.** Each role gets a `panel` column: `admin`, `agent` or `user`. Custom roles can only be created with `panel = admin`. | AGENT and USER are *panel-entry* roles with fixed, business-defined behaviour (approval, wallets, caps). Letting an admin tick "can top-up" on a custom role would bypass the wallet model. Customer and agent capabilities stay as they are today. |
| D4 | **The five built-in roles are system roles** (`is_system = true`). They cannot be deleted or renamed. **SUPER_ADMIN's permissions cannot be edited** (it implicitly has all of them). ADMIN's and MODERATOR's permissions *are* editable, but by SUPER_ADMIN only. | SUPER_ADMIN must stay a guaranteed break-glass role. Locking it prevents "admin removed everyone's ability to manage roles" lockouts. CLAUDE.md §4 already says MODERATOR is "configurable", which this delivers. |
| D5 | **SUPER_ADMIN bypass via `Gate::before`, guarded by `tokenCan('admin')`.** | This is the Laravel convention for super-users. The token guard is mandatory. Without it, a SUPER_ADMIN who also has a PIN would pass every gate from a `user` token. |
| D6 | **Anti-escalation rule: you can only grant what you hold.** When a staff member creates or edits a role, or assigns a role to someone, the resulting permission set must be a **subset of the actor's own permissions**. Nobody can edit a role they themselves hold. | Otherwise anyone with `roles.manage` can create an "everything" role and assign it to themselves or an accomplice. This replaces today's hard-coded rule "only SUPER_ADMIN can create ADMIN" with a general rule. |
| D7 | **Permission names use `domain.action` snake format** (`transactions.view_any`, `wallets.block`). Enum cases are TitleCase (`TransactionsViewAny`) per the project's PHP rules. | Readable in the DB, groups naturally by prefix for the checkbox UI, and matches policy method names. |
| D8 | **Roles are soft-retired, never hard-deleted once used.** A custom role with **zero** assignments may be deleted. A role with assignments must be unassigned first (409 otherwise). | §19: audit logs reference roles. Silently cascading `user_roles` deletes would strip access without an audit trail for each user. |
| D9 | **Permissions are resolved once per request and memoised on the User model.** Cross-request caching is deferred. | ~20 permissions over 1–3 roles is one small join. Cross-request caching adds invalidation bugs, and a stale cache in authorization is a *security* bug. Add caching only if profiling shows a need. |
| D10 | **The admin login response and `/me` return the user's effective permission list**, which the Vue app uses to show or hide UI. | UI convenience only. **The backend remains the sole authority.** Every hidden button must still be rejected server-side. |
| D11 | **Every role and permission change is audit-logged** through the existing `AuditLog` model (`role.created`, `role.permissions_synced` with a before/after diff, `role.deleted`, `role.assigned`, `role.revoked`). | CLAUDE.md §19. Changing a role's permissions silently changes the access of every user holding it, so it is the highest-impact admin action in the system. |

### Problems in the current code this task must fix along the way

| # | Location | Problem |
|---|---|---|
| P1 | `app/Models/Role.php` → `userRoles()` | `hasMany(Role::class)` should be `hasMany(UserRole::class)`. It is a latent bug, currently unused. |
| P2 | `AdminAuthService`, `SetPinService`, `PinResetService`, `AccountInviteService` | Hard-coded `hasRole(['SUPER_ADMIN','ADMIN','MODERATOR'])`. A custom staff role (for example "Auditor") would be **unable to log in** on the admin route. These checks must become "holds any role with `panel = admin`". |
| P3 | `User::isStaff()` / `isStaffWithAdminAccess()` | Same hard-coded list. They should derive from `roles.panel`. |
| P4 | `RegisterUserRequest`, `UserService::register`, `UserController::index` | `Rule::in(['USER','AGENT','ADMIN','MODERATOR'])` and `required_if:role,ADMIN,MODERATOR`. Custom staff roles aren't accepted. Staff-ness must come from the role's `panel`, not its name. |
| P5 | `UserController` line ~85: "only SUPER_ADMIN may create ADMIN" | This becomes the general anti-escalation rule (D6). |
| P6 | `resources/js/stores/auth.ts` → `isAdmin` computed from role names | This must become permission-based. |
| P7 | `SystemSettingPolicy::viewAny` | It returns true for any token ability, so it isn't a staff check. Leave the behaviour alone, but note it, since settings *update* will move to a permission. |

---

## 1. Concepts to Learn First (≈2 hours)

Read these in the Laravel docs (use `search-docs`) before touching code:

- [ ] **Gates vs Policies** (re-read). A Gate is a closure keyed by an ability name. A Policy groups abilities per model. Both run through `Gate::allows()` / `$user->can()`.
- [ ] **`Gate::before` / `Gate::after`.** `before` returning `true` short-circuits *every* check. Returning `null` falls through. Understand why returning `false` from `before` is dangerous.
- [ ] **Policy `before()` method** vs global `Gate::before`. Know when each runs.
- [ ] **`Gate::define` with a dynamic list.** Defining one gate per enum case in a loop lets `$user->can('wallets.block')` work without a policy.
- [ ] **`can` middleware** (`->can('roles.manage')`) vs `authorize()` in a Form Request vs `$this->authorize()` in a controller. Decide which layer each check belongs to (see §4).
- [ ] **Eloquent `belongsToMany` with `sync()` and `syncWithPivotValues()`**, the return value of `sync()` (attached/detached/updated, which you need for the audit diff), and pivot timestamps.
- [ ] **Backed enums in PHP 8.1+**: `cases()`, `from()`, `tryFrom()`, and adding methods such as `label()` and `group()` to an enum.
- [ ] **`Rule::enum()`** and **`Rule::exists()`** validation rules.
- [ ] **`upsert()`**, for idempotent seeding of the catalog.

---

## 2. Permission Catalog (agree on this before coding)

Derived from **today's policies and routes**, so nothing is invented. Mark each line ✅ or ❌, or rename it, before step 3.

| Group | Permission | Replaces today's check | Default: ADMIN |
|---|---|---|---|
| Users | `users.view_any` | `UserPolicy::viewAny` (isAdmin) | ✅ |
| Users | `users.view` | `UserPolicy::view` (admin branch) | ✅ |
| Users | `users.create` | `UserPolicy::create` (USER/AGENT + staff creation via D6) | ✅ |
| Users | `users.update` | `UserPolicy::update` (admin branch) | ✅ |
| Users | `users.assign_role` | `UserPolicy::assignRole` | ✅ |
| Users | `users.grant_admin_access` | `grant-admin-access` route / `AccountInviteService` | ❌ *(SA only by default)* |
| Agents | `agents.approve` | `UserPolicy::approveAgent` | ✅ |
| Agents | `agents.suspend` | `UserPolicy::suspendAgent` | ✅ |
| Wallets | `wallets.view_any` | `WalletPolicy::viewAny` / `view` admin branch | ✅ |
| Wallets | `wallets.block` | `WalletPolicy::block` | ✅ |
| Wallets | `wallets.unblock` | `WalletPolicy::unblock` | ✅ |
| Transactions | `transactions.view_any` | `TransactionPolicy::viewAny` / `view` staff branch, `TransactionResource` staff fields | ✅ |
| Settings | `settings.update` | `SystemSettingPolicy::update` | ✅ |
| Auth | `pin_reset.initiate` | `PinResetService` role check | ✅ |
| Roles | `roles.view` | *(new)* | ✅ |
| Roles | `roles.manage` | *(new)*: create/update/delete roles, sync permissions | ❌ *(SA only by default)* |

**Decisions agreed upon:**

- [x] **Q1: MODERATOR role removal:** The built-in `MODERATOR` role is completely removed. System built-in roles are: `SUPER_ADMIN`, `ADMIN`, `AGENT`, `USER`. Granular viewing/editing (`users.view_any`, `users.view`, etc.) are permissions that can be granted to custom roles.
- [x] **Q2: Unified user creation:** Keep `users.create` unified for both customer and staff accounts. The D6 anti-escalation rule restricts which roles an actor is allowed to assign.
- [x] **Q3: Separate wallet block / unblock:** Kept separate as `wallets.block` and `wallets.unblock` in the catalog. Granted to `ADMIN` by default; custom roles can configure either or both.
- [x] **Q4: `set-pin`:** Kept as "any admin-panel role", not in the catalog.

> **Rule for the migration:** Built-in roles are `SUPER_ADMIN`, `ADMIN`, `AGENT`, `USER`. `ADMIN` holds the default permissions above; `SUPER_ADMIN` bypasses via `Gate::before`.

---

## 3. Database

### 3.1 New table: `permissions`

- [x] `id`
- [x] `name`: string, **unique**. Matches the enum backing value.
- [x] `group`: string. Used for checkbox grouping in the UI (Users, Wallets…).
- [x] `description`: string, nullable. The human label shown next to the checkbox.
- [x] timestamps
- [x] Think: why not let the API create rows in this table? (Answer: D2.)

### 3.2 New table: `role_permissions` (pivot)

- [x] `role_id`: FK → `roles.id`, **cascade on delete** (a role's grants die with the role).
- [x] `permission_id`: FK → `permissions.id`, **restrict** on delete (a permission removed from code must be unassigned deliberately).
- [x] **Composite unique** `(role_id, permission_id)`. Think about why this matters with concurrent "save" clicks.
- [x] `granted_by`: nullable FK → `users.id`, `set null` on delete.
- [x] timestamps
- [x] Decide: is a surrogate `id` needed, or is the composite key enough? (Mirror whatever you did for `user_roles` for consistency.)

### 3.3 Alter `roles`

- [x] Add `panel`: string/enum `admin|agent|user`, **not null**. Backfill: SUPER_ADMIN/ADMIN/MODERATOR → `admin`, AGENT → `agent`, USER → `user`.
- [x] Add `is_system`: boolean, default false. Backfill true for the 5 built-ins.
- [x] Add `created_by`: nullable FK → users.
- [x] `name` is already unique. Decide on a **naming rule** for custom roles (uppercase snake like the built-ins? max length?) and enforce it in validation.
- [x] Consider `display_name` vs `name`. Is `name` the immutable machine key, with the label separate? Recommended: yes. Changing a role's display label must not break code that references built-in names.
- [x] Write the migration so `down()` cleanly reverses it.
- [x] **Do not** edit the original `create_roles_table` migration. Add a new migration. These migrations have run on other machines.

### 3.4 Seeding

- [x] `PermissionSeeder`: upserts every `Permission::cases()` into `permissions` (idempotent, safe to rerun on deploy).
- [x] Update `RoleSeeder`: set `panel` and `is_system`, and change it from `insert` to `upsert`. Today it would crash on rerun because of the unique `name`.
- [x] `RolePermissionSeeder`: grants the **default** matrix from §2 to ADMIN and MODERATOR. SUPER_ADMIN gets **no rows** (it bypasses via D5). Think about why storing rows for it would be misleading.
- [x] Register order in `DatabaseSeeder`: Roles → Permissions → RolePermissions → SuperAdmin.
- [x] Optional: an Artisan command `permissions:sync` that runs the permission upsert in production deploys without running demo seeders.

### 3.5 Model updates

- [x] Fix **P1** (`Role::userRoles()`).
- [x] `Role::permissions()`: `belongsToMany(Permission::class, 'role_permissions')` with pivot `granted_by` and timestamps.
- [x] `Role::users()`: inverse of `User::roles()`.
- [x] `Permission` model, plus `Permission::roles()`.
- [x] Casts on `Role`: `is_system` → bool, `panel` → a `RolePanel` enum if you create one.
- [x] Scopes on `Role`: `scopeAdminPanel()`, `scopeCustom()` (`is_system = false`).
- [x] Factories: `RoleFactory` (with `adminPanel()`, `system()` states) and `PermissionFactory`. You'll need them in tests.

---

## 4. Architecture — Where Each Piece Lives

Decide the module first.

- [x] **Module:** create `Modules/Roles/` (CLAUDE.md §26 already lists `Roles`). Roles are currently scattered across `Modules/Users` (`assign-role`) and `app/Models`. Decide whether role *assignment* moves into `Modules/Roles` or stays in Users, write the reason down, and stick with it.
  - Recommended: **role definition and permission management** go in `Modules/Roles`. **Assigning a role to a user** stays in `Modules/Users`, but calls a `Roles` service for the escalation check.

| Layer | File (proposed) | Responsibility | Must NOT do |
|---|---|---|---|
| Enum | `Modules/Roles/Enums/Permission.php` | The catalog: cases, `label()`, `group()` | Hit the database |
| Enum | `Modules/Roles/Enums/RolePanel.php` | `Admin`, `Agent`, `User` | — |
| Model | `app/Models/Permission.php` (models currently live in `app/Models`, so follow that) | Relationships only | Authorization logic |
| Model method | `User::permissions()` (collection), `User::hasPermission(Permission\|string)` | Resolve effective permissions from roles, memoised per request | Check token ability (keep that in the gate layer, so each method stays one concept) |
| Model method | `User::hasAdminPanelRole()` | Replaces `isStaff()` (P3) | — |
| Provider | `AppServiceProvider` (or a `Modules/Roles` provider) | `Gate::before` (SA bypass + `tokenCan('admin')`) and `Gate::define` per permission, each requiring `tokenCan('admin')` | Business logic |
| Policies | existing `UserPolicy`, `WalletPolicy`, `TransactionPolicy`, `SystemSettingPolicy`, plus new `RolePolicy` | Swap `isAdmin()` for permission checks. Keep ownership branches. | Hard-code role names |
| Form Requests | `StoreRoleRequest`, `UpdateRoleRequest`, `SyncRolePermissionsRequest` | Input shape, `authorize()` via the policy | The escalation check (that's business logic, see the service) |
| Service | `Modules/Roles/Services/RoleService.php` | create/update/delete role, sync permissions in a DB transaction, **anti-escalation check**, audit logging | Know about HTTP |
| Exceptions | `PermissionEscalationException`, `SystemRoleImmutableException`, `RoleInUseException` | Domain errors → 403/422/409 via your existing `ApiException` pattern | — |
| Resources | `RoleResource`, `PermissionResource` | Output shape (role + grouped permissions + `users_count`) | — |
| Controllers | `RoleController`, `PermissionController` | Thin: validate → service → resource | Contain escalation logic |
| Routes | `Modules/Roles/routes.php`, registered in `routes/api.php` under `v1/roles` and `v1/permissions` | Middleware `auth:sanctum`, `ability:admin`, `password.changed` | — |

### 4.1 The two-part check, restated for this feature

```text
Can staff member X do "wallets.block"?
  1. Token ability is `admin`               ← Sanctum (route middleware + inside the gate)
  2. X is SUPER_ADMIN → yes                 ← Gate::before (only if 1 holds)
  3. Some role X holds has wallets.block    ← User::hasPermission
```

- [x] Write this decision tree in a PHPDoc on the gate registration, so the next reader understands why `tokenCan('admin')` appears inside the gate even though routes also check it (defence in depth: policies are reused from routes with `ability:admin,agent,user`, such as `GET /transactions/{id}`).

---

## 5. API Design

All endpoints sit under `/api/v1`, with `auth:sanctum`, `ability:admin` and `password.changed`.

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/permissions` | `roles.view` | The full catalog, **grouped** (`{ group, permissions: [{name, description}] }`). This drives the checkbox grid. |
| GET | `/roles` | `roles.view` | Paginated. Include `panel`, `is_system`, `permissions_count`, `users_count`. Filter by `panel`. |
| GET | `/roles/{role}` | `roles.view` | Role and its permission names |
| POST | `/roles` | `roles.manage` | `{ name, display_name?, description?, permissions: string[] }`. `panel` is forced to `admin` (D3). |
| PATCH | `/roles/{role}` | `roles.manage` | Name/description only. 403 if `is_system`. |
| PUT | `/roles/{role}/permissions` | `roles.manage` | **Full replacement** (`sync`), not add/remove. Idempotent, and it matches the checkbox UI exactly. |
| DELETE | `/roles/{role}` | `roles.manage` | 403 if system, 409 if assigned (D8). |
| GET | `/auth/me` *(or extend login response)* | any admin token | Adds `permissions: string[]` (D10). |

- [x] Decide on `PUT` vs `PATCH` for the permissions endpoint, and write down why PUT (full replacement) fits checkboxes.
- [x] Validation for `permissions.*`: `Rule::enum(Permission::class)`, `distinct`, and an array that may be empty (a role with zero permissions is valid but useless; decide whether to allow it).
- [x] Status codes: 201 create, 200 update, 204 delete, 403 escalation or system role, 409 role in use, 422 validation.
- [x] Use the existing response envelope (`success`, `message`, `data`/`errors`). Check `docs/API.md` and `ApiException` for the established shape.
- [x] Rate-limit `roles.manage` writes? They are low volume. Decide, but at least make sure they're covered by the general API limiter.

---

## 6. Business Rules (service layer) — the security core

Implement each rule in `RoleService`, and give each one a test (§9).

- [x] **R1 — System role immutability:** you cannot rename or delete an `is_system` role. You cannot change SUPER_ADMIN's permissions. Only SUPER_ADMIN can change ADMIN's and MODERATOR's permissions.
- [x] **R2 — Panel restriction:** new roles are always `panel = admin`. Permissions can only be attached to admin-panel roles.
- [x] **R3 — Anti-escalation on role create/update:** `requested_permissions ⊆ actor_effective_permissions`. SUPER_ADMIN passes trivially.
  - [x] Edge case: when *removing* a permission the actor doesn't hold from a role, is that allowed? (Recommended: **no**. You can't touch what you can't grant. Otherwise someone can sabotage roles above them.) Compare `added ∪ removed ⊆ actor_permissions`.
- [x] **R4 — No self-modification:** the actor cannot edit or sync the permissions of any role they currently hold. This stops someone widening their own access, and stops accidental self-lockout.
- [x] **R5 — Anti-escalation on role assignment** (`POST /users/{user}/assign-role`, existing): the assigned role's permissions ⊆ the actor's permissions. **Only SUPER_ADMIN can assign SUPER_ADMIN.** This replaces P5.
- [x] **R6 — Revocation safety:** never allow the system to end up with zero active SUPER_ADMINs. That probably belongs in role revocation, if you have it, so check whether revoke exists. If it doesn't, note it as a future task.
- [x] **R7 — Delete only if unused** (D8).
- [x] **R8 — Atomicity:** the permission sync and its audit row go in **one `DB::transaction`**. Lock the role row (`lockForUpdate`) so two admins saving checkboxes at the same time can't interleave and produce an audit diff that doesn't match the final state.
- [x] **R9 — Audit:** `metadata` holds `{ added: [...], removed: [...], before: [...], after: [...] }`. Role assignment audits record `{ role, assigned_to, assigned_by }`.

Think through and write down the answer to each of these:

- [x] When a role loses a permission, users holding it lose access **on their next request** (permissions aren't cached, D9). Is that acceptable for in-flight sessions? (Yes. Write down why.)
- [x] Should existing admin tokens be revoked when a user's roles change? (Probably not needed, because the check is live per request. Contrast this with JWT claims, where it *would* be needed. That is a good Node.js-vs-Sanctum lesson.)

---

## 7. Refactor Existing Authorization (one module at a time)

Do this **after** §3–§6 work and their tests pass. Migrate one policy per commit, with tests green each time.

- [x] Add `User::hasPermission()` and `hasAdminPanelRole()`, and register the gates.
- [x] **P3:** make `isStaff()` derive from `roles.panel = admin`. Keep the method name for now to limit churn, or rename it everywhere. Pick one.
- [x] **P2:** replace the hard-coded staff lists in `AdminAuthService` (×4), `SetPinService` (×2) and `PinResetService` with `hasAdminPanelRole()` / `can('pin_reset.initiate')`.
- [x] `AccountInviteService`: `hasRole('SUPER_ADMIN')` → `can('users.grant_admin_access')`.
- [x] `UserPolicy`: viewAny, view (admin branch), create, update (admin branch), approveAgent (keep the `!==` self check), suspendAgent, assignRole. Remove `createAdmin` once R5 covers it.
- [x] `WalletPolicy`: viewAny, view (admin branch), block, unblock.
- [x] `TransactionPolicy`: `viewAny` and the staff branch of `view`.
- [x] `TransactionResource` and `TransactionController` (`isStaffWithAdminAccess()` decides field visibility and history scope) → `can('transactions.view_any')`. Check these carefully: they decide **what data leaks**, not just whether access is allowed.
- [x] `SystemSettingPolicy::update` → `settings.update`.
- [x] **P4:** `RegisterUserRequest` / `UserService::register` / `UserController::index` accept any existing role by name (`Rule::exists('roles','name')`). Derive "needs email + password, no PIN" from `role.panel === admin` instead of `in_array($role, ['ADMIN','MODERATOR'])`.
- [x] `grep` for leftovers: `hasRole(`, `isAdmin(`, `isSuperAdmin(`, `'SUPER_ADMIN'`, `'MODERATOR'`. Every remaining hit should be either a panel-entry check (AGENT/USER login), the SA bypass, or a seeder.
- [x] Decide what happens to `User::isAdmin()`. Delete it once there are no callers, so nobody reaches for it again.

---

## 8. Frontend (Vue Admin Panel)

Read `inertia-vue-development` and the existing `pages/Admin/*.vue` pages first, and follow their patterns (stores, toasts, confirm modals, skeletons).

### 8.1 Auth store

- [x] Add `permissions: string[]` to `stores/auth.ts`, filled from the login/me response.
- [x] Add a `can(permission: string): boolean` helper. Make SUPER_ADMIN return true.
- [x] **P6:** replace role-name-based `isAdmin` usages with `can(...)` where they gate features.
- [x] Think: what happens to the UI when permissions change mid-session? (Answer: the backend returns 403. Show the toast and refetch `/me`.)

### 8.2 Navigation & guards

- [x] Hide sidebar items the user can't use (Wallets → `wallets.view_any`, Settings → `settings.update`, Roles → `roles.view`).
- [x] Route guard: redirect to the dashboard with a toast if a user lands on a page they lack permission for.
- [x] Hide action buttons (Block, Approve, Assign role) per permission. **Remember: this is UX, not security.**

### 8.3 Roles list page (`pages/Admin/Roles.vue`)

- [x] Table: name, panel badge, "System" badge, #permissions, #users, actions.
- [x] Hide or disable Edit/Delete for system roles, and for roles the current user holds (R4).
- [x] Delete with a confirm modal. Show the 409 message when the role is in use.

### 8.4 Role editor — the checkbox grid

- [x] Fetch `GET /permissions` (grouped) and `GET /roles/{id}`.
- [x] One card per **group**, with a checkbox per permission, its description as helper text, and a **"select all in group"** checkbox with an indeterminate state.
- [x] **Disable checkboxes for permissions the current user doesn't hold** (R3), with a tooltip: "You can't grant a permission you don't have."
- [x] Hold local state as a `Set<string>` or `string[]`, and show a "N unsaved changes" indicator by diffing against the server state.
- [x] Save sends `PUT /roles/{id}/permissions` with the full list. Map a 403 to a clear toast.
- [x] Warn before saving: "This will change access for N users." Use `users_count`.
- [x] Create-role form: name and description plus the same checkbox grid. Panel is fixed to Admin (don't show a selector).
- [x] Validation with Vee-Validate, mirroring backend rules (the name format).

### 8.5 Users page

- [x] The role picker in `Users.vue` / `RegisterStaffForm.vue` currently hard-codes `'ADMIN' | 'MODERATOR'`. Load assignable admin-panel roles from `GET /roles?panel=admin` instead.
- [x] Only list roles the actor is allowed to assign (or let the backend reject them and show the error). Pick one approach and be consistent.

### 8.6 Wayfinder

- [x] Regenerate Wayfinder routes after adding controllers, and use them rather than hard-coded URLs, if that's what the existing admin pages do.

---

## 9. Tests

Use Pest and follow the existing layout (`tests/Feature/<Module>`, `tests/Unit/Policies`). Use factories. Each item is one test unless noted.

### 9.1 Regression — existing access is unchanged (write these FIRST, before refactoring)

- [x] For each existing protected endpoint, assert today's matrix (SA / ADMIN / MODERATOR / AGENT token / USER token) → status. Run it before §7, and it must still pass after §7. This is your safety net. A Pest **dataset** keeps it compact.

### 9.2 Gate / permission resolution (unit)

- [x] A user with a role that has `wallets.block` → `can` is true with an admin token.
- [x] The same user with a **`user` token** → false. **This is the most important test in the task.**
- [x] A SUPER_ADMIN with an admin token → true for every permission.
- [x] A SUPER_ADMIN with a `user` token → false for admin permissions.
- [x] A user with two roles → the union of their permissions.
- [x] A user with no admin-panel role → false.
- [x] Permissions are queried once per request (assert the query count) to cover D9 memoisation.

### 9.3 Role CRUD (feature)

- [x] Create a role with valid permissions → 201, rows in DB, audit row written.
- [x] Create with an unknown permission → 422.
- [x] Create with a duplicate name → 422.
- [x] The created role always has `panel = admin`, even if the request sends `panel: user`.
- [x] Rename or delete a system role → 403.
- [x] Delete a role that has users → 409. Delete an unused custom role → 204.
- [x] Without `roles.manage` → 403. Without `roles.view` → 403 on GET.
- [x] Sync permissions → the exact set is persisted (added and removed), and the audit diff is correct.

### 9.4 Anti-escalation (feature) — security-critical

- [x] An ADMIN with `roles.manage` creates a role containing a permission they lack → 403.
- [x] An ADMIN removes a permission they lack from another role → 403 (R3 edge case).
- [x] An ADMIN edits the permissions of a role they hold → 403 (R4).
- [x] An ADMIN changes SUPER_ADMIN's permissions → 403. An ADMIN changes ADMIN's permissions → 403 (R1). A SUPER_ADMIN changes ADMIN's permissions → 200.
- [x] An ADMIN assigns a role whose permissions exceed their own → 403 (R5).
- [x] An ADMIN assigns SUPER_ADMIN → 403.
- [x] Everything above with a PIN-issued token → 403 (the token-scoping rule).

### 9.5 Custom role end-to-end

- [x] Create an "Auditor" role with only `transactions.view_any`, then assign it to a user who has a password.
- [x] That user **can log in on the admin route** (P2 fixed).
- [x] They can `GET /transactions/admin/all` and **cannot** block wallets, change settings or view `/roles`.
- [x] `TransactionResource` shows staff-only fields to them (P-refactor of field visibility).
- [x] Remove `transactions.view_any` from the role → the next request is 403 (no stale cache).

### 9.6 Seeders / migration

- [x] Running `PermissionSeeder` twice doesn't duplicate rows.
- [x] Every `Permission::cases()` value exists in the table after seeding.
- [x] The backfill migration sets `panel` and `is_system` correctly for the 5 built-ins. Put this in `tests/Feature/Migrations` alongside the existing tests.

---

## 10. Documentation & Wrap-up

- [x] Update `docs/API.md` with the new endpoints, the `permissions` field in the login/me response, and the new error codes.
- [x] Update `docs/AUTHENTICATION.md` / the authorization docs with the permission + token-ability decision tree (§4.1).
- [x] Add the new endpoints to `docs/postman_collection.json`.
- [x] Update **CLAUDE.md §4** (the role capability table becomes "default permissions; configurable") and **§16** (role checks become permission checks). Do this only after the implementation is reviewed.
- [x] Add this file to `TODO/README.md`.
- [x] Run `vendor/bin/pint --dirty --format agent` and `phpstan`, plus the full test suite.

---

## 11. Suggested Implementation Order

Each step should be a reviewable commit. **Send each one for review before moving on.**

```text
 1. Answer Q1–Q4 and finalise the §2 catalog                 (no code)
 2. Write §9.1 regression tests against CURRENT behaviour     (safety net)
 3. Permission + RolePanel enums
 4. Migrations: permissions, role_permissions, alter roles (+ backfill)
 5. Models, relationships, factories; fix P1
 6. Seeders (upsert) + default matrix
 7. User::hasPermission / hasAdminPanelRole + Gate::before + Gate::define  → §9.2 tests
 8. Refactor policies & services one at a time (§7)                       → §9.1 still green
 9. RoleService with R1–R9 + exceptions                                   → §9.4 tests
10. Form Requests, Resources, Controllers, routes                         → §9.3 tests
11. Permissions in login/me response
12. Custom-role end-to-end                                                → §9.5 tests
13. Vue: auth store can(), nav/guards
14. Vue: Roles list + checkbox editor
15. Vue: Users page role picker
16. Docs + CLAUDE.md update
```

**First small task:** step 1. Go through §2 and answer Q1–Q4, then send me your final permission list. Don't create any files until we have agreed on it.

---

## 12. Code Review Fixes & Edge Cases Resolved

All 11 review items and architectural improvements have been resolved and verified:

1. **`grant-admin-access` Anti-Escalation & UI Fix (Item 1):**
   - Added `role` validation (`Rule::exists('roles', 'name')->where('panel', 'admin')`) and email validation (`Rule::unique('users', 'email')->ignore($targetUserId)`) to `GrantAdminAccessRequest`.
   - Injected `RoleService` into `AccountInviteService`, enforced `assertCanAssignRole($caller, $role)`, attached the requested role, and recorded an `AuditLog` entry (`user.admin_access_granted`).
   - Added email input to the Elevate modal in `Users.vue` with pre-fill from `elevateUser.email`, loading states, and inline error banner.

2. **Guarded `syncPermissions` for System and Panel Boundaries (Item 2):**
   - In `RoleService::syncPermissions`: checks before the transaction ensure only admin-panel roles (`panel === RolePanel::Admin`) can hold catalog permissions.
   - Non-super admins cannot sync permissions for any system role (`is_system === true`).
   - Modifying `SUPER_ADMIN` permissions is rejected.
   - Self-modification of held roles is rejected (R4).

3. **Frontend Type-Safe Permissions & Dead Names Fix (Item 3):**
   - Created `resources/js/types/permissions.ts` defining strict `PermissionName` union and `PERMISSIONS` array.
   - Typed `authStore.can(permission: PermissionName)` and `useAuthGuard(role, permission: PermissionName)`.
   - Replaced `users.verify_agent` with `agents.approve` and `agents.suspend` in `AdminLayout.vue` and `Agents.vue`.
   - Replaced `settings.view` in `Settings.vue` and `AdminLayout.vue` with open admin panel access, gating the save button behind `settings.update`.
   - Fixed `CLAUDE.md` line 197 table header and line 1023 agent approval description.

4. **MODERATOR Migration & Continuity Handling (Item 4):**
   - Updated `database/migrations/2026_10_12_100002_add_panel_and_system_to_roles_table.php`: if `MODERATOR` has active user assignments, it is preserved as a custom admin role with baseline permissions (`pin_reset.initiate`, `transactions.view_any`) and recorded in `audit_logs`.
   - Added migration docblock documenting the one-way nature of the data transition.
   - Updated `CLAUDE.md` §9 Case C to specify "staff holding `pin_reset.initiate`".

5. **Scoped `Gate::before` for SUPER_ADMIN (Item 5):**
   - Updated `AppServiceProvider::configurePermissionsAndGates`: `Gate::before` only bypasses catalog permissions (`PermissionEnum::tryFrom($ability) !== null`).
   - Policy methods (such as `UserPolicy::approveAgent`, `assignRole`, `delete`) are not bypassed, preserving self-checks and domain rules even for `SUPER_ADMIN`.

6. **Decoupled and Idempotent Seeders (Item 6):**
   - `RolePermissionSeeder` now only attaches the default matrix to `ADMIN` if `$adminRole->permissions()->count() === 0`, preserving manual customizations.
   - Decoupled `RolePermissionSeeder` from `RoleSeeder`.
   - `DatabaseSeeder` executes `PermissionSeeder`, then `RoleSeeder`, then `RolePermissionSeeder` in clean sequence.

7. **Least Privilege Panel Default (Item 7):**
   - Removed default `'admin'` from `roles.panel` migration column, using nullable -> backfill -> not null.
   - Updated `RoleFactory` definition default to `RolePanel::User` and `is_system => false`.

8. **Cleaned Up `UserResource` (Item 8):**
   - Removed `permissions` property from `UserResource`, eliminating N+1 query overhead on user listings and preventing internal permission exposure.

9. **Moved Role Assignment Anti-Escalation to Service (Item 9):**
   - Moved `RoleService::assertCanAssignRole` from `UserController::register` into `UserService::register` inside the database transaction.

10. **Guarded Platform Transactions Page (Item 10):**
    - Updated `Transactions.vue` to use `useAuthGuard('ADMIN', 'transactions.view_any')`.

11. **Maintainability & Architecture Cleanups (Item 11):**
    - Simplified `User` model memoization to standard instance properties `$effectivePermissionsMemo` and `$roleNamesMemo`, removing `spl_object_hash`.
    - Standardized `AuthController::me` to return `new AuthResource($user)`.
    - Updated `PermissionController::index` to authorize via `RolePolicy::viewAny`.
    - `RoleController::index`: validated `panel` enum, capped `per_page` at 100, and preloaded relation counts.
    - Added `per_page: 100` to role fetches in `Users.vue` and `RegisterStaffForm.vue`.
    - Removed fallback database queries from `RoleResource`.

---

## 13. Security & Migration Follow-Up Fixes (Items A–D)

1. **Item A — `grant-admin-access` Account Takeover Defense:**
   - **Service Boundary:** `AccountInviteService::grant` rejects targets that already hold an admin-panel role or already have a password set (`UserAlreadyHasStaffAccessException`, HTTP 409).
   - **Self-Elevation Rejected:** Enforced caller ≠ target (`InsufficientRoleException`, HTTP 403).
   - **Concurrency Safety:** Inside the transaction, targets are locked via `lockForUpdate()` and re-checked against existing credentials.
   - **Policy Alignment:** Added `UserPolicy::grantAdminAccess()` to enforce `users.grant_admin_access` and self-grant prevention at the request layer.

2. **Item B — MODERATOR Migration Reliability on MySQL:**
   - Separated schema DDL and data transitions into two independent migrations:
     - `2026_10_12_100002_add_panel_and_system_to_roles_table.php`: adds `panel`, `is_system`, `created_by` columns with `Schema::hasColumn` idempotent guards.
     - `2026_10_12_100003_migrate_legacy_moderator_role.php`: handles data conversion for legacy `MODERATOR`.
   - Fixed columns written during migration:
     - `permissions`: writes `name`, `group` ('Transactions', 'Auth'), `description`, and timestamps (no non-existent `label`).
     - `audit_logs`: writes append-only record with `created_at` (no non-existent `updated_at`).
   - Added comprehensive migration test: `tests/Feature/Migrations/ModeratorMigrationTest.php`.

3. **Item C — Role Assignment for Staff & Anti-Escalation:**
   - Extended `UserService::assignRole` and `AssignRoleRequest` to support admin-panel roles for existing staff.
   - Enforced that staff role assignment requires the target to already have a password (`UserRequiresPasswordForStaffRoleException`, HTTP 422).
   - Enforced `RoleService::assertCanAssignRole` anti-escalation subset check on staff role assignment.
   - Preserved credentials: staff role assignment never alters email, password, or sets temporary credentials.

4. **Item D — Smaller Code Review Items:**
   - **TestCase Seeding Decoupling:** Removed `seed()` override in `TestCase.php`. Added explicit `seedRolesAndPermissions()` helper and called it across tests that require permission bindings.
   - **Users.vue Dead Read Removed:** Replaced `data.invitation_link` and copyable link snippet with clear confirmation that temporary credentials and invite links were dispatched to the staff member's email address.
   - **TypeScript Types Drift Protection:** Added `tests/Unit/Roles/PermissionCatalogTest.php` asserting `resources/js/types/permissions.ts` matches `Permission::cases()` 1:1.
   - **Gate::before Order Optimized:** In `AppServiceProvider`, evaluated `PermissionEnum::tryFrom($ability) !== null` before `tokenCan('admin')` and `isSuperAdmin()`.
   - **Documentation Cleared of MODERATOR:** Updated `docs/AUTHENTICATION.md`, `docs/presentation.html`, and `docs/API.md`.


