# Task 2 Review — Issues Found Before Starting Task 3

**Reviewed:** 2026-09-20
**Scope:** 10 migrations, 10 models, 3 seeders, `config/admin.php`
**Verdict:** Schema design is sound. Execution has **5 blockers** that will break Task 3 on the first endpoint you write, plus 6 important issues and 6 minor ones.

All findings below were verified against the live database and the running application, not just read from the source.

---

## Severity Summary

| # | Issue | Severity | Breaks |
|---|-------|----------|--------|
| 1 | `AgentInfo` model points at a non-existent table | BLOCKER | Every agent query |
| 2 | 9 of 10 models have no `$fillable` / `$guarded` | BLOCKER | Every `create()` / `update()` |
| 3 | `User` `$fillable` is missing `phone_number` and `pin` | BLOCKER | User registration |
| 4 | `pin` is not hidden — leaks in API responses | BLOCKER (security) | Every user response |
| 5 | `UserFactory` references columns that do not exist | BLOCKER | Every test you write |
| 6 | Missing UNIQUE on `wallets.user_id`, `caps.user_id`, `agent_info.user_id` | IMPORTANT | Data integrity |
| 7 | Missing UNIQUE on `user_roles(user_id, role_id)` | IMPORTANT | Duplicate roles |
| 8 | Commission rate stored in two different scales | IMPORTANT | Money correctness |
| 9 | `DECIMAL(5,2)` cannot express rates like 2.5% | IMPORTANT | Money correctness |
| 10 | No `$casts` — money comes back as strings | IMPORTANT | Money correctness |
| 11 | Super admin has no wallet / cap row | IMPORTANT | Task 5 assumptions |
| 12 | `User::roles()` does not return roles | MINOR | Readability, Task 4 |
| 13 | `RoleSeeder` writes NULL timestamps | MINOR | Auditability |
| 14 | `SystemSetting.value` is JSON with no cast | MINOR | Task 7 |
| 15 | `is_deleted` bool instead of Laravel SoftDeletes | MINOR | Convention |
| 16 | `roles.description` is NOT NULL | MINOR | Flexibility |
| 17 | Missing query indexes for history / OTP lookups | MINOR | Performance later |
| 18 | `user_type` column contradicts multi-role requirement | BLOCKER | Task 3 auth model |
| 19 | No `password_changed_at` — forced password change impossible | BLOCKER | Task 3 Case B |
| 20 | `otp_tokens.purpose` enum has no `SET_PIN` value | BLOCKER | Task 3 Case A |

> **Issues 18 and 19 were added on 2026-09-20** after the authentication requirements were clarified (three login routes, multi-role users, cross-track credentials). See `CLAUDE.md` section 9 for the authoritative model.

---

# BLOCKERS — fix before writing any Task 3 code

## 1. `AgentInfo` resolves to table `agent_infos`, which does not exist
 
Verified:

```
(new App\Models\AgentInfo)->getTable()  =>  "agent_infos"
actual table                            =>  "agent_info"
```

**Why:** Laravel derives the table name by pluralising the class name in StudlyCase, then snake_casing it. `AgentInfo` -> `AgentInfos` -> `agent_infos`. Your migration created `agent_info` (singular), so every single query against this model will throw `Table 'laravel.agent_infos' doesn't exist`.

**Where to fix:** `app/Models/AgentInfo.php`.

**What to do:** Declare the table name explicitly on the model. Laravel 13 gives you two ways — the classic `protected $table` property, or the `#[Table]` attribute (you are already using attributes on `User`, so pick one style and stay consistent across the project).

**Alternative:** rename the table to `agent_infos` in the migration. I would not — `agent_info` reads better, and being explicit on the model is the normal Laravel escape hatch for exactly this case.

---

## 2. Nine models have no `$fillable` or `$guarded` — mass assignment silently discards everything

Verified:

```
(new App\Models\Wallet)->getFillable()  =>  []
(new App\Models\Wallet)->getGuarded()   =>  ["*"]
```

`["*"]` is Eloquent's default guard. With it in place, `isFillable()` returns `false` for every attribute, so:

```php
Wallet::create(['user_id' => 5, 'balance' => 100]);
// inserts a row with NO user_id and NO balance
```

**Why your seeders worked anyway:** `php artisan db:seed` wraps the whole run in `Model::unguarded()`. Mass assignment protection is switched off for the duration of seeding only. The moment you call `Wallet::create()` from a service in Task 3, it silently writes an empty row. This is the single most likely source of "why is my data empty" confusion in the next module.

**Affected:** `Role`, `Wallet`, `Cap`, `AgentInfo`, `Transaction`, `SystemSetting`, `OtpToken`, `AuthProvider`, `UserRole`. (`User` has a `#[Fillable]` attribute — see issue 3.)

**What to decide first:** `$fillable` (allow-list) or `$guarded` (deny-list)?

For a financial system, use **`$fillable`**. On `Wallet` and `Transaction` especially, an allow-list means a stray `balance` or `status` in a request payload can never reach the database. A deny-list means you have to remember to block every dangerous column forever.

**Non-negotiable:** `Wallet.balance`, `Cap.daily_used`, `Cap.monthly_used`, `AgentInfo.total_commission`, and `AgentInfo.status` must **never** be mass-assignable. Those change only through service-layer logic that you control.

---

## 3. `User` `$fillable` is missing the fields registration actually needs

Verified:

```
(new App\Models\User)->getFillable()  =>  ["name", "email", "password"]
```

Your Task 3 registration endpoint accepts `name`, `phone_number`, `pin`, `role`. Two of those four are not fillable, so a user will be created with `phone_number = NULL` and `pin = NULL` — an account nobody can ever log in to, created without any error being raised.

**Where:** the `#[Fillable([...])]` attribute at the top of `app/Models/User.php`.

**What to add:** `phone_number`, `pin`, `image`, `address`.

**What to deliberately leave out:** `is_active`, `is_verified`, `is_deleted`, `password_changed_at`. Those are state transitions — an admin activating an account, a verification flow completing, a forced password change being satisfied. If they are fillable, a crafted registration payload can self-verify, self-activate, or skip the forced password change. Set them explicitly in your service instead.

**Note:** `user_type` is being dropped — see issue 18. Do not add it to fillable.

---

## 4. SECURITY: `pin` is not hidden, so it will appear in API responses

Current:

```php
#[Hidden(['password', 'remember_token'])]
```

`pin` is absent. `remember_token` does not exist as a column (see issue 5). So the hidden list protects one real field and one imaginary one, while leaving the users' PIN hashes exposed.

**Impact:** any endpoint that returns a `User` model directly — `GET /api/v1/users/:id`, the user object inside your login response, an eager-loaded `$transaction->sender` — serialises the bcrypt PIN hash into JSON. Offline cracking of a 5-digit numeric PIN is trivial once the hash leaks.

**Where:** `app/Models/User.php`.

**What to do:** add `pin` to the hidden list and drop `remember_token`.

**Defence in depth:** `$hidden` protects you only when something serialises the model. Your API Resources (Task 9) are the real boundary — an explicit allow-list in `UserResource` means a future `$hidden` mistake cannot leak anything. Do both.

---

## 5. `UserFactory` writes columns that do not exist

`database/factories/UserFactory.php` still has Laravel's stock definition:

```php
'email_verified_at' => now(),
'remember_token'    => Str::random(10),
```

Your `users` table (verified via `DESCRIBE users`) has neither column. This is the exact error that broke your seeder earlier:

```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'email_verified_at'
```

You worked around it by switching `SuperAdminSeeder` to `User::create()`, which was the right call — but the factory itself is still broken, and **every feature test in Task 3 depends on it.** You cannot write `tests/Feature/Auth/LoginTest.php` without a working `User::factory()`.

**Where:** `database/factories/UserFactory.php`.

**What to do:**
- Remove `email_verified_at` and `remember_token`.
- Add the fields your schema actually requires: `phone_number`, `pin`, `user_type`, `is_active`, `is_verified`.
- Delete or rewrite the `unverified()` state — it sets `email_verified_at`, which no longer exists. Your equivalent is `is_verified => false`.
- Add factory **states** for the shapes you will test constantly: an admin-track user (email + password, no PIN), a user-track user (phone + PIN, no email), and an approved agent. States are how you avoid repeating six lines of setup in forty tests.

**Related cleanup in `User.php`:** the `casts()` method still casts `email_verified_at`, and the class docblock still documents `email_verified_at` and `remember_token` as properties. Both are now fiction. Remove them so the model stops describing a schema you do not have.

---

## 18. `user_type` contradicts the multi-role requirement — drop the column

**Added 2026-09-20 following the auth requirements clarification.**

`users.user_type` is a single-valued enum (`ADMIN_TRACK` / `USER_TRACK`). The confirmed requirement is that one user may hold **multiple roles across both tracks** — an admin who sets a PIN to also use the agent and user panels. That user is simultaneously both tracks. One column, one value, two truths.

Expanding it to three values (`ADMIN_TRACK` / `AGENT_TRACK` / `USER_TRACK`) does not help: the same user is still two of them at once.

**Why the column has nothing left to store:** `password` and `pin` already answer which tracks an account can authenticate on, and they cannot drift out of sync with themselves.

| Question | Answered by |
|---|---|
| Can they authenticate as admin? | `password` is set |
| Can they authenticate as agent or user? | `pin` is set |
| Agent panel or user panel? | **role** — the credential is identical for both |

Keeping `user_type` alongside those columns creates a denormalised duplicate. Someone sets a PIN, forgets to update `user_type`, and the column now lies about the account.

**Where to fix:** `database/migrations/..._create_users_table.php` — remove the column. Then remove every reference to it: the `#[Fillable]` attribute on `User`, `UserFactory`, `SuperAdminSeeder`.

**What replaces it:** nothing stored. Each login route checks the credential it owns and the role it requires. See `CLAUDE.md` section 9 for the per-route gate table.

---

## 19. No `password_changed_at` — the forced password change cannot be enforced

**Added 2026-09-20 following the auth requirements clarification.**

Case B in the confirmed requirements: an admin grants admin access to an existing user by assigning an email and a **temporary password**, then issuing a one-time signed URL. The user follows the link, logs in, and must change the password before doing anything else.

Nothing in your schema can express "this password is temporary and must be replaced." Without it:

- The admin who set the password knows it indefinitely.
- The user is never compelled to change it.
- You cannot distinguish a fresh invite from an established account.

**Where to fix:** `database/migrations/..._create_users_table.php` — add a nullable timestamp, `password_changed_at`.

**Semantics:** NULL means "this password was set by someone else and has not been replaced." While it is NULL on an account that has a password, every route except *change password* must be rejected. Set it on the user's own password change, and on normal self-service registration.

**Why a timestamp rather than a `must_change_password` boolean:** the timestamp answers the same question (NULL or not) and also gives you password age, which you will want for rotation policy and for incident forensics. A boolean throws that away for no saving.

**Not fillable.** See issue 3 — this is a state transition, set explicitly in your service. If it is mass-assignable, an invite-acceptance payload can mark itself as already changed and skip the whole flow.

---

## 20. `otp_tokens.purpose` has no value for the set-PIN flow

**Added 2026-09-20 following the auth requirements clarification.**

The enum is `['LOGIN', 'PASSWORD_RESET']`. Case A — an admin setting a PIN to gain agent/user access — requires OTP verification to the registered email before the PIN may be set. Neither existing value describes that.

**Where to fix:** `database/migrations/..._create_otp_tokens_table.php` — add `SET_PIN`.

**Why reuse `otp_tokens` rather than a signed URL here:** the table already gives you expiry, single-use tracking via `used_at`, and attempt limiting via `attempt_count` / `max_attempts`. A signed URL has none of those — it is replayable until it expires. For a step that gates the creation of a new credential, the attempt limiting is worth having.

**Why the PIN *reset* flow (Case C) does not need an enum value:** that one uses a signed URL deliberately, because the link is emailed to an address customer care has just attached and the user follows it in a browser. Different delivery, different mechanism. See `CLAUDE.md` section 9.

---

# IMPORTANT — fix before the module that depends on them

## 6. The unique constraints you intended were silently ignored

You wrote this in `caps` and `agent_info`:

```php
$table->foreign('user_id')->references('id')->on('users')->onDelete('cascade')->unique();
```

Verified result — `information_schema.STATISTICS` shows only a plain non-unique FK index on all three tables:

```
wallets     wallets_user_id_foreign      user_id   INDEX    <- not unique
caps        caps_user_id_foreign         user_id   INDEX    <- not unique
agent_info  agent_info_user_id_foreign   user_id   INDEX    <- not unique
```

**Why:** `->foreign()` returns a `ForeignKeyDefinition`, not a `ColumnDefinition`. Chaining `->unique()` onto it sets a property that the MySQL grammar never reads when compiling the constraint. No error, no warning, no unique index. `wallets` never even attempted it.

**Why it matters:** your schema documents "one wallet per user" and "one cap row per user" as invariants, and Task 5's `WalletService` will do `Wallet::where('user_id', $id)->lockForUpdate()->first()`. If a bug or a race ever creates a second wallet row for one user, `first()` silently picks one of them and locks it — the other wallet's balance is now invisible and unlocked. That is how money goes missing. A database-level UNIQUE makes the bug impossible instead of merely unlikely.

**What to do:** make it a real column-level unique index. The idiomatic forms are `$table->unsignedBigInteger('user_id')->unique();` at definition time, or a separate `$table->unique('user_id');` line. `$table->foreignId('user_id')->constrained()->cascadeOnDelete()` is the modern shorthand for the FK itself, but the `unique()` still belongs on the **column**, never on the foreign key.

**How to apply it:** you have no production data, so editing the three migrations and re-running `migrate:fresh --seed` is cleanest. Once you have real data, this would have to be a new `ALTER TABLE` migration instead — worth internalising the difference now.

**Verify afterwards** with the query at the bottom of this document. Do not assume the chain worked this time either — check.

## 7. `user_roles` allows the same role to be assigned twice

There is no unique constraint on `(user_id, role_id)`. Nothing stops a user from holding `ADMIN` three times. Your Task 4 code will then see `$user->roles` return duplicates, and any `count()`-based permission logic gets interesting.

**What to do:** add a composite unique index on the pair. Keep `assigned_at` / `assigned_by` out of it — those are audit metadata, not identity.

## 8. Commission rate is stored in two incompatible scales

Two places describe the same 1% commission:

| Location | Value | Implied meaning |
|---|---|---|
| `agent_info.commission_rate` default | `1.00` | 1.00 = 1 percent |
| `system_settings.agent_commission_rate` seed | `0.01` | 0.01 = 1 percent (a fraction) |

`CLAUDE.md` section 13 is explicit: values are `0 - 1`, where `0.01 = 1%`. So the `agent_info` default is wrong by a factor of 100.

**Why this is dangerous:** Task 6 computes `commission = amount * rate`. Pull the rate from `agent_info` and a 5,000 BDT transaction pays **5,000 BDT** in commission instead of 50. The system would be insolvent within one transaction, and nothing in the code would look wrong.

**What to do:** pick one representation, write it down in `CLAUDE.md`, and make the schema match. The fraction (`0.01`) is what your requirements already specify, so change the `agent_info.commission_rate` default to `0.01`. Then, in Task 6, have exactly one function convert rate to amount so the convention lives in one place.

**Design question worth answering now:** when an admin changes the system commission rate, do in-flight and historical transactions keep their original rate? Your `transactions` table already stores `agent_commission_rate` per row, which says yes — rates are snapshotted at transaction time. That is the correct design. Make sure Task 6 actually writes that column rather than joining back to `agent_info` at read time.

## 9. `DECIMAL(5,2)` cannot represent the rates you will want

`system_fee_rate`, `agent_commission_rate` (on `transactions`) and `commission_rate` (on `agent_info`) are all `DECIMAL(5,2)` — two decimal places.

If rates are fractions, the smallest expressible non-zero rate is `0.01` (1%). A 2.5% fee is `0.025`, which MySQL rounds to `0.03`. You cannot express 1.5% either — and your own Task 4 spec has an example request with `"commission_rate": 1.5`.

**What to do:** widen the scale. `DECIMAL(6,4)` holds `0.0250` comfortably and leaves room for rates above 1 if you ever need them. Money **amounts** stay `DECIMAL(19,2)` — that part is right.

## 10. No `$casts` — MySQL returns DECIMAL columns as PHP strings

None of the models define casts. So:

```php
$wallet->balance;            // "1000.50"  (string, not float)
$wallet->balance - 500;      // PHP juggles to float — precision risk
$wallet->is_blocked;         // 1 (int)
$transaction->meta;          // raw JSON string, not an array
$otp->expires_at;            // string, so ->isPast() does not exist
```

**Why it matters:** the whole reason you chose `DECIMAL(19,2)` was to avoid float rounding. Reading it into a PHP float at the boundary throws that guarantee away. Laravel's `decimal:2` cast keeps the value as a precise string and formats it consistently.

**What to add, per model:**
- `Wallet`: `balance => 'decimal:2'`, `is_blocked => 'boolean'`, `blocked_at => 'datetime'`
- `Cap`: all four amount columns `decimal:2`
- `Transaction`: every amount `decimal:2`, every rate `decimal:4` (after issue 9), `meta => 'array'`
- `AgentInfo`: `commission_rate`, `total_commission`, plus `approved_at` / `suspended_at` as `datetime`
- `OtpToken`: `expires_at` / `used_at` as `datetime` — you need `->isPast()` in Task 3
- `UserRole`: `assigned_at => 'datetime'`

**Worth knowing for Task 6:** even with casts, doing arithmetic in PHP on money is a trade-off. The safest pattern for balance updates is to let the *database* do the arithmetic inside a locked transaction (`balance = balance - ?`) rather than read-modify-write in PHP. You will get to this in Task 5 — just be aware the cast is a correctness improvement, not a complete solution.

## 11. The super admin has no wallet and no cap row

Verified live counts: `wallets = 0`, `caps = 0`, while `users = 1`.

Your Task 5 spec says `GET /api/v1/wallets/me` finds the wallet by `user_id`. Logged in as the super admin you seeded, that returns null and the endpoint 500s unless you handle it.

**The real question:** should admins have wallets at all? Two defensible answers:

1. **No.** Admins never transact. `wallets/me` should 404 for admin-track users, and that is a deliberate, tested behaviour.
2. **Yes, uniformly.** Every user gets a wallet; admin wallets simply stay at zero. Fewer null checks everywhere.

Either is fine — but decide now and record it in `CLAUDE.md`, because Task 5 and Task 6 both branch on it. Whichever you choose, `SuperAdminSeeder` should match, so the seeded state is a valid state.

**Separately:** wherever you land, creating a user, wallet, cap, and role assignment is a four-row operation that must be all-or-nothing. Wrap it in `DB::transaction()` — in the seeder and in your Task 3 registration service. A user with no wallet is a broken account.

---

# MINOR — worth fixing, not urgent

## 12. `User::roles()` does not return roles

```php
public function roles(): HasMany { return $this->hasMany(UserRole::class); }
```

`$user->roles` returns `UserRole` pivot rows. So `$user->roles->pluck('name')` returns a collection of nulls, because `UserRole` has no `name` column — and your Task 4 spec calls for exactly that expression in `UserResource`.

**The Laravel-native tool here is `belongsToMany`:**

```php
// conceptually — you write it
return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot('assigned_at', 'assigned_by')
            ->withTimestamps();
```

That gives you `$user->roles` as actual `Role` models, `$user->roles->pluck('name')` working as written, plus `attach()` / `detach()` / `sync()` for assignment — and `withPivot` keeps your audit columns reachable via `$role->pivot->assigned_by`.

**Keep the `hasMany(UserRole::class)` too**, renamed to something honest like `roleAssignments()`, for when you want to query the audit trail directly. Two relationships, two clear purposes.

**Performance note for Task 4:** the `hasRole()` helper you will write runs a query every call. Authorisation checks call it constantly. Eager-load with `->load('roles')` once per request, or memoise the result on the model instance — otherwise a single request with a few policy checks fires a dozen identical queries.

## 13. `RoleSeeder` writes NULL timestamps

`Role::insert()` is a query-builder call. It bypasses Eloquent entirely, so `created_at` and `updated_at` stay NULL, and model events never fire.

For a five-row reference table this is cosmetic. But `firstOrCreate()` (or `upsert()`) would set timestamps *and* make the seeder re-runnable, which is what bit you with the duplicate-key error earlier. Making seeders idempotent means `db:seed` is always safe to run — worth doing for all three.

## 14. `SystemSetting.value` is a JSON column holding bare numbers

You seed `'value' => 0.05` into a `json` column with no cast on the model. MySQL stores it as the JSON number `0.05`; reading it back without a cast gives you the string `"0.05"`.

A `json` column is the right call if settings will later hold structured values (`{"BDT": 0.05, "USD": 0.03}`). If they will only ever be scalars, a `string` column is simpler and sorts predictably. Decide before Task 7, and add the matching cast either way. Note that `'value' => 'array'` will break on scalar JSON — if you keep the JSON column with scalar values, cast accordingly or read it through an accessor.

## 15. `is_deleted` boolean instead of Laravel's SoftDeletes

Your schema uses a boolean flag. Laravel's convention is a nullable `deleted_at` timestamp plus the `SoftDeletes` trait, which gives you automatic global scoping (`->withTrashed()`, `->restore()`), so you cannot *forget* to filter.

With a boolean flag, every query you ever write needs a manual `where('is_deleted', false)`. Miss one in an auth lookup and deleted users can log in. You also lose the "when was this deleted" audit data, which matters in a financial system.

**Not urgent** — but if you are going to switch, switching now (before any code queries `users`) is dramatically cheaper than after Task 3.

## 16. `roles.description` is NOT NULL with no default

`$table->text('description')` requires a value on every insert. `RoleSeeder` supplies one, so nothing breaks today — but any future role created without a description will fail. Make it `->nullable()`.

## 17. Missing indexes for queries you are about to write

MySQL auto-creates indexes on FK columns, so your joins are covered. These are not:

- `transactions(created_at)` — Task 6's history endpoint sorts by it on every request.
- `transactions(user_id, created_at)` — the actual "my transactions, newest first" access pattern; a composite beats two singles here.
- `otp_tokens(user_id, purpose, expires_at)` — Task 3 looks up the newest valid OTP for a user on every verification.

**Do not add these yet.** Add them in Task 6 and Task 3 respectively, when you have the real query in front of you and can confirm the column order matches how you actually filter. Indexes guessed in advance are usually the wrong shape. Noted here so you do not forget.

---

# Suggested Fix Order

Roughly 60–90 minutes, and it clears the path for all of Task 3.

**Round 1 — migrations** (then one `migrate:fresh --seed`)

| # | Item | Issue | Status |
|---|---|---|---|
| 1 | `agent_info`, `caps`, `wallets`: column-level `unique()` on `user_id` | #6 | ✅ done |
| 2 | `user_roles`: composite unique on `(user_id, role_id)` | #7 | ❌ not started |
| 3 | `agent_info.commission_rate` default -> `0.01` | #8 | ✅ done |
| 4 | **Rate** columns -> `DECIMAL(6,4)` on `transactions` and `agent_info` | #9 | ❌ inverted — see below |
| 5 | `roles.description` -> nullable | #16 | ✅ done |
| 6 | `users`: drop `user_type` | #18 | ❌ not started |
| 7 | `users`: add nullable `password_changed_at` | #19 | ❌ not started |
| 8 | `otp_tokens.purpose`: add `SET_PIN` to the enum | #20 | ❌ not started |

**On item 4 — the fix was applied to the wrong columns.** `transactions.amount` and `transactions.system_fee_amount` were widened from `19,2` to `19,4`; the three *rate* columns were left at `5,2`.

An **amount** is a quantity of money. BDT subdivides into 100 poisha, so `19,2` is the currency's actual granularity, not a limitation. A **rate** is a multiplier — `0.025` means 2.5% — and never touches a wallet directly.

Widening amounts is actively harmful: `wallets.balance` remains `decimal(19,2)`, so a `decimal(19,4)` transaction amount can record a value the wallet cannot hold. The invariant *sum of transaction amounts equals change in balance* then stops holding, and every rounding at the wallet boundary loses fractions that never reconcile.

Revert `amount` and `system_fee_amount` to `19,2`. Change `transactions.system_fee_rate`, `transactions.agent_commission_rate`, and `agent_info.commission_rate` to `6,4` — two digits before the point, four after, max `99.9999`, stores `0.0250` exactly. Leave every other `19,2` column alone.

**Round 2 — models**
1. `AgentInfo`: declare the table name (#1)
2. All models: `$fillable`, excluding money and state columns (#2)
3. `User`: add `phone_number`, `pin`, `image`, `address` to fillable — **not** `user_type` (dropped) and **not** `password_changed_at` (#3, #18, #19)
4. `User`: add `pin` to hidden, drop `remember_token`; remove the `email_verified_at` cast and the stale docblock (#4, #5)
5. All models: `$casts` — add `password_changed_at => 'datetime'` on `User` (#10, #19)
6. `User`: add `belongsToMany` roles, rename the `hasMany` to `roleAssignments()` (#12)

**Round 3 — factories and seeders**
7. Rewrite `UserFactory` for your real schema, add states — remove `user_type` (#5, #18)
8. Decide the admin-wallet question, record it in `CLAUDE.md`, update `SuperAdminSeeder`, wrap in `DB::transaction()` (#11)
9. `SuperAdminSeeder`: set `password_changed_at` — the seeded super admin is not a pending invite (#19)
10. Make all three seeders idempotent with `firstOrCreate` (#13)

**Round 4 — verify**
15. Re-run the verification block below and confirm every line
16. `migrate:fresh --seed` from clean, twice in a row — the second run must succeed, which proves idempotency

---

# Verification Commands

After the fixes, confirm the constraints actually landed. The whole reason issue 6 existed is that the code *looked* correct.

```bash
# Unique indexes — wallets/caps/agent_info user_id must show UNIQUE,
# user_roles must show a composite (user_id,role_id) UNIQUE
./vendor/bin/sail artisan tinker --execute="
foreach (DB::select(\"SELECT TABLE_NAME tn, INDEX_NAME i, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) c, NON_UNIQUE nu FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='laravel' AND TABLE_NAME IN ('wallets','caps','agent_info','user_roles') GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE\") as \\\$r)
  echo str_pad(\\\$r->tn,12).str_pad(\\\$r->c,22).(\\\$r->nu ? 'index' : 'UNIQUE').PHP_EOL;"

# Model wiring — table name, fillable, hidden, casts
./vendor/bin/sail artisan tinker --execute="
echo (new App\Models\AgentInfo)->getTable().PHP_EOL;
echo json_encode((new App\Models\User)->getFillable()).PHP_EOL;
echo json_encode((new App\Models\User)->getHidden()).PHP_EOL;
echo json_encode((new App\Models\Wallet)->getFillable()).PHP_EOL;"

# The factory must work — this is what your Task 3 tests depend on
./vendor/bin/sail artisan tinker --execute="dump(App\Models\User::factory()->make()->toArray());"

# Seeded state
./vendor/bin/sail artisan tinker --execute="
foreach (['roles','users','user_roles','wallets','caps','system_settings'] as \\\$t)
  echo str_pad(\\\$t,18).DB::table(\\\$t)->count().PHP_EOL;"
```

**Expected after fixes:**
- `AgentInfo` table -> `agent_info`
- `User` hidden includes `pin`, excludes `remember_token`
- `User::factory()->make()` returns your real columns and no `email_verified_at`
- `wallets` / `caps` / `agent_info` `user_id` -> UNIQUE
- `user_roles` -> a UNIQUE on `user_id,role_id`

---

# What Was Done Well

Worth saying, because the design decisions here are better than the execution bugs suggest:

- **`DECIMAL(19,2)` for money.** The single most important schema decision in a wallet system, and you got it right without being told.
- **Rate and amount snapshots on `transactions`.** Storing `system_fee_rate` / `agent_commission_rate` / `*_balance_after` per row means history stays truthful when settings change later. Many production systems get this wrong and can never reconstruct old statements.
- **`idempotency_key` unique from day one.** Verified present and correctly UNIQUE. Retrofitting idempotency after launch is painful.
- **`status` enum over `is_approved` boolean.** PENDING -> APPROVED -> SUSPENDED -> APPROVED is a real state machine; a boolean could not express it.
- **Audit columns throughout** — `assigned_by`, `approved_by`, `suspended_by`, `blocked_by`, `updated_by`, each with a matching timestamp.
- **Considered FK cascade rules.** `blocked_by` is `SET NULL` while `user_id` is `CASCADE` — that distinction is deliberate and correct. Deleting an admin must not delete the wallets they once blocked.

The blockers are all Eloquent-convention issues, which is exactly what you would expect coming from Node.js. None of them are design mistakes.
