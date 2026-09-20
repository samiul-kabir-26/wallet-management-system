# Task 2: Project Setup & Database Schema

**Objective:** Set up the Laravel project structure and create all necessary database migrations.

**Status:** Ready to start
**Estimated Duration:** 2-3 hours

---

## Prerequisites

✅ Completed: Requirements decisions finalized (10-REQUIREMENTS-DECISIONS.md)

Ensure you have:
- Laravel 11.x installed locally
- MySQL running locally
- Basic familiarity with Laravel migrations, models, and seeders
- Composer installed

---

## Task Breakdown
 
### Step 1: Project Database Configuration

**What to do:**
1. Verify your `.env` file has correct MySQL credentials:
   ```
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=wallet_system
   DB_USERNAME=root
   DB_PASSWORD=<your_password>
   ```

2. Create the MySQL database:
   ```bash
   mysql -u root -p -e "CREATE DATABASE wallet_system;"
   ```

3. Verify connection works:
   ```bash
   php artisan db:show
   ```

**What you should understand:**
- Why Laravel uses `.env` for environment-specific config.
- How Laravel connects to databases via the service container.

---

### Step 2: Create Core Migrations

You need to create these migration files (in order):

#### Migration 1: ROLES table 
- Table: `roles`
- Columns: `id` (PK), `name` (unique), `description`, `created_at`, `updated_at`
- Seed with 5 roles: SUPER_ADMIN, ADMIN, MODERATOR, AGENT, USER

**Command:**
```bash
php artisan make:migration create_roles_table
```

**What you should understand:**
- Migration up() method (creates table).
- Migration down() method (rolls back).
- How Laravel timestamps work.
- Enum vs string for role names (use string for flexibility).

---

#### Migration 2: USERS table (modified from original schema)
- Table: `users`
- Columns:
  - `id` (BIGINT UNSIGNED PK AUTO_INCREMENT)
  - `name` (string, required)
  - `email` (string, nullable, unique) — nullable for Users/Agents, required for Admins
  - `phone_number` (string, nullable, unique) — required for Users/Agents
  - `password` (string, nullable) — for Admins/Super Admins
  - `pin` (string, nullable) — for Users/Agents (hashed PIN, 5-10 digits numeric)
  - `user_type` (enum: ADMIN_TRACK, USER_TRACK) — quickly identify admin vs. regular users
  - `image` (string, nullable)
  - `address` (text, nullable)
  - `is_active` (enum: ACTIVE, INACTIVE, default ACTIVE)
  - `is_verified` (boolean, default false)
  - `is_deleted` (boolean, default false) — soft delete flag
  - `created_at`, `updated_at` (timestamps)

**Key points:**
- Both `email` and `phone_number` are nullable to support different user types.
- Both have unique indexes (but allow NULL).
- `pin` is nullable; only Users/Agents have it.
- `password` is nullable; only Admins have it.
- `user_type` distinguishes admin-track vs. user-track (allows quick filtering).

**Command:**
```bash
php artisan make:migration create_users_table
```

**What you should understand:**
- Nullable vs. required fields (business rules drive this).
- Unique constraints vs. unique indexes.
- Enum fields in Laravel migrations.
- Why `user_type` simplifies queries (no need to join to roles).

---

#### Migration 3: USER_ROLES table (junction)
- Table: `user_roles`
- Columns:
  - `id` (BIGINT UNSIGNED PK)
  - `user_id` (BIGINT UNSIGNED FK → users.id, ON DELETE CASCADE)
  - `role_id` (UNSIGNED INT FK → roles.id, ON DELETE CASCADE)
  - `assigned_at` (timestamp)
  - `assigned_by` (BIGINT UNSIGNED FK → users.id, nullable) — who assigned this role
  - `created_at`, `updated_at` (timestamps)

**Key points:**
- Many-to-many relationship between users and roles.
- `assigned_by` tracks audit: who assigned the role.
- ON DELETE CASCADE: if user deleted, roles deleted.

**Command:**
```bash
php artisan make:migration create_user_roles_table
```

**What you should understand:**
- Junction tables for many-to-many relationships.
- Foreign key constraints and cascade behavior.
- Why audit fields (assigned_by, assigned_at) matter.

---

#### Migration 4: WALLETS table
- Table: `wallets`
- Columns:
  - `id` (BIGINT UNSIGNED PK)
  - `user_id` (BIGINT UNSIGNED FK → users.id, unique, ON DELETE CASCADE)
  - `balance` (DECIMAL(19, 2), default 50.00) — starting balance in BDT
  - `currency` (string, default 'BDT') — single currency for now
  - `is_blocked` (boolean, default false)
  - `blocked_by` (BIGINT UNSIGNED FK → users.id, nullable) — who blocked this wallet
  - `blocked_at` (timestamp, nullable) — when it was blocked
  - `created_at`, `updated_at` (timestamps)

**Key points:**
- Unique FK to users: one wallet per user.
- Decimal(19,2) for financial precision.
- `blocked_by` and `blocked_at` for audit trail.
- is_blocked check must happen before financial operations.

**Command:**
```bash
php artisan make:migration create_wallets_table
```

---

#### Migration 5: CAPS table
- Table: `caps`
- Columns:
  - `id` (BIGINT UNSIGNED PK)
  - `user_id` (BIGINT UNSIGNED FK → users.id, unique, ON DELETE CASCADE)
  - `daily_cap` (DECIMAL(19, 2), default 10000.00)
  - `monthly_cap` (DECIMAL(19, 2), default 50000.00)
  - `daily_used` (DECIMAL(19, 2), default 0.00)
  - `monthly_used` (DECIMAL(19, 2), default 0.00)
  - `created_at`, `updated_at` (timestamps)

**Key points:**
- Remove `last_reset_date` — reset logic handled by scheduled job.
- `daily_used` and `monthly_used` updated by transaction service.
- Scheduled job resets these at midnight UTC.

**Command:**
```bash
php artisan make:migration create_caps_table
```

---

#### Migration 6: AGENT_INFO table (modified)
- Table: `agent_info`
- Columns:
  - `id` (BIGINT UNSIGNED PK)
  - `user_id` (BIGINT UNSIGNED FK → users.id, unique, ON DELETE CASCADE)
  - `status` (enum: PENDING, APPROVED, SUSPENDED, DELETED, default PENDING) — replaces is_approved
  - `commission_rate` (DECIMAL(5, 2), default 1.00) — 1% by default
  - `total_commission` (DECIMAL(19, 2), default 0.00)
  - `approved_at` (timestamp, nullable)
  - `approved_by` (BIGINT UNSIGNED FK → users.id, nullable)
  - `suspended_at` (timestamp, nullable)
  - `suspended_by` (BIGINT UNSIGNED FK → users.id, nullable)
  - `created_at`, `updated_at` (timestamps)

**Key points:**
- `status` enum replaces boolean `is_approved` (enables PENDING → APPROVED → SUSPENDED → APPROVED flow).
- `suspended_at` and `suspended_by` track suspension history.
- Only agents have this record (created on agent registration).

**Command:**
```bash
php artisan make:migration create_agent_info_table
```

---

#### Migration 7: TRANSACTIONS table (modified)
- Table: `transactions`
- Columns:
  - `id` (BIGINT UNSIGNED PK)
  - `user_id` (BIGINT UNSIGNED FK → users.id, nullable) — generic user involved
  - `agent_id` (BIGINT UNSIGNED FK → users.id, nullable) — agent (for cash-in/cash-out)
  - `sender_id` (BIGINT UNSIGNED FK → users.id, nullable) — sender (for transfers)
  - `recipient_id` (BIGINT UNSIGNED FK → users.id, nullable) — recipient (for transfers/cash-in)
  - `initiated_by` (BIGINT UNSIGNED FK → users.id, required) — who initiated transaction
  - `type` (enum: TOP_UP, CASH_IN, CASH_OUT, TRANSFER, AGENT_WITHDRAWAL, COMMISSION_PAYOUT)
  - `amount` (DECIMAL(19, 2), required) — primary amount
  - `system_fee_amount` (DECIMAL(19, 2), default 0.00) — system fee (NEW)
  - `system_fee_rate` (DECIMAL(5, 2), default 0.00) — system fee rate as % (NEW)
  - `agent_commission_amount` (DECIMAL(19, 2), default 0.00) — agent commission amount (renamed from commission_amount)
  - `agent_commission_rate` (DECIMAL(5, 2), default 0.00) — agent commission rate as % (renamed)
  - `currency` (string, default 'BDT')
  - `description` (text, nullable)
  - `status` (enum: COMPLETED, FAILED, default COMPLETED)
  - `idempotency_key` (string, nullable, unique) — for retry safety (NEW)
  - `sender_wallet_balance_after` (DECIMAL(19, 2), nullable)
  - `recipient_wallet_balance_after` (DECIMAL(19, 2), nullable)
  - `agent_wallet_balance_after` (DECIMAL(19, 2), nullable)
  - `meta` (json, nullable) — for additional data
  - `created_at`, `updated_at` (timestamps)

**Key points:**
- Separate `system_fee_amount/rate` and `agent_commission_amount/rate` for clarity.
- `idempotency_key` prevents duplicate charges on retry.
- `type` enum matches endpoints (TOP_UP, CASH_IN, CASH_OUT, TRANSFER, AGENT_WITHDRAWAL, COMMISSION_PAYOUT).
- Remove the generic `total_amount` (confusing) — calculate from amount + fees.

**Command:**
```bash
php artisan make:migration create_transactions_table
```

---

#### Migration 8: SYSTEM_SETTINGS table
- Table: `system_settings`
- Columns:
  - `id` (BIGINT UNSIGNED PK)
  - `key` (string, unique) — e.g., 'system_fee_rate', 'agent_commission_rate'
  - `value` (string/json) — the setting value
  - `description` (text, nullable)
  - `updated_by` (BIGINT UNSIGNED FK → users.id, nullable) — who last updated
  - `created_at`, `updated_at` (timestamps)

**Key points:**
- Key-value store for system-wide settings.
- Settings: `system_fee_rate` (5%), `agent_commission_rate` (1%)
- `updated_by` tracks audit.

**Command:**
```bash
php artisan make:migration create_system_settings_table
```

---

#### Migration 9: OTP_TOKENS table (for admin 2FA)
- Table: `otp_tokens`
- Columns:
  - `id` (BIGINT UNSIGNED PK)
  - `user_id` (BIGINT UNSIGNED FK → users.id, ON DELETE CASCADE)
  - `otp_code` (string) — 6-digit code
  - `purpose` (enum: LOGIN, PASSWORD_RESET) — why OTP was sent
  - `expires_at` (timestamp) — OTP valid until this time (5 minutes)
  - `used_at` (timestamp, nullable) — when OTP was used (NULL = unused)
  - `attempt_count` (int, default 0) — number of incorrect attempts
  - `max_attempts` (int, default 5) — max incorrect attempts before rejection
  - `created_at`, `updated_at` (timestamps)

**Key points:**
- Single-use: once `used_at` is set, OTP can't be used again.
- Expiration: query only `where expires_at > NOW()`.
- Rate limiting: reject if `attempt_count >= max_attempts`.

**Command:**
```bash
php artisan make:migration create_otp_tokens_table
```

---

#### Migration 10: AUTH_PROVIDERS table (unchanged from original, for future OAuth)
- Table: `auth_providers`
- Columns:
  - `id` (BIGINT UNSIGNED PK)
  - `user_id` (BIGINT UNSIGNED FK → users.id, ON DELETE CASCADE)
  - `provider` (string) — e.g., 'google', 'github'
  - `provider_id` (string) — provider's user ID
  - `created_at`, `updated_at` (timestamps)

**Command:**
```bash
php artisan make:migration create_auth_providers_table
```

---

### Step 3: Review & Run Migrations

**What to do:**

1. **Review each migration file** to ensure:
   - Table names are correct
   - Column types match requirements
   - Foreign key constraints are correct
   - Indexes are appropriate (unique, FK)
   - Comments explain complex columns

2. **Run migrations:**
   ```bash
   php artisan migrate
   ```

3. **Verify database structure:**
   ```bash
   php artisan db:show
   mysql -u root -p wallet_system -e "SHOW TABLES;"
   ```

**What you should understand:**
- How `php artisan migrate` executes all pending migrations in order.
- Why migration order matters (roles → users → user_roles → etc.).
- How to inspect the database after migration.

---

## Step 4: Create Models (Empty Shell)

Create empty models for each table (no relationships yet, just the class):

**Models to create:**
1. `Role`
2. `User`
3. `Wallet`
4. `Cap`
5. `AgentInfo`
6. `Transaction`
7. `SystemSetting`
8. `OtpToken`
9. `AuthProvider`

**Command for each:**
```bash
php artisan make:model Role
php artisan make:model User
php artisan make:model Wallet
php artisan make:model Cap
php artisan make:model AgentInfo
php artisan make:model Transaction
php artisan make:model SystemSetting
php artisan make:model OtpToken
php artisan make:model AuthProvider
```

**What you should understand:**
- How Laravel models map to database tables (automatic by convention).
- The `$table` property (if table name doesn't match model name exactly).
- The `$guarded` or `$fillable` properties (mass-assignment protection).
- Why you don't write business logic yet in models.

---

## Step 5: Define Model Relationships (No Logic)

In each model, define the relationships **without any business logic**.

**Examples of relationships to define:**

**User model:**
```
- hasMany(UserRole)
- hasOne(Wallet)
- hasOne(Cap)
- hasOne(AgentInfo)
- hasMany(Transaction) [as sender, recipient, agent, etc.]
```

**Wallet model:**
```
- belongsTo(User)
```

**AgentInfo model:**
```
- belongsTo(User)
```

**Transaction model:**
```
- belongsTo(User) [as user_id]
- belongsTo(User, 'sender_id')
- belongsTo(User, 'recipient_id')
- belongsTo(User, 'agent_id')
- belongsTo(User, 'initiated_by')
```

**What you should understand:**
- HasOne, HasMany, BelongsTo relationships.
- How foreign keys map to relationships.
- Why relationships simplify queries (no manual joins).

---

## Step 6: Create Database Seeder

Create a `DatabaseSeeder` and sub-seeders:

**What to seed:**

1. **RoleSeeder** — Create all 5 roles:
   - SUPER_ADMIN
   - ADMIN
   - MODERATOR
   - AGENT
   - USER
 
2. **SuperAdminSeeder** — Create one SUPER_ADMIN user:
   - Name: "Super Admin"
   - Email: "superadmin@wallet.local"
   - Password: hashed (use `Hash::make()`)
   - user_type: "ADMIN_TRACK"
   - is_verified: true
   - is_active: "ACTIVE"
   - Assign SUPER_ADMIN role via USER_ROLES

3. **SystemSettingsSeeder** — Create initial settings:
   - system_fee_rate: 5% (0.05)
   - agent_commission_rate: 1% (0.01)

**Commands:**
```bash
php artisan make:seeder RoleSeeder
php artisan make:seeder SuperAdminSeeder
php artisan make:seeder SystemSettingsSeeder
```

**What you should understand:**
- How seeders populate initial data.
- Why seeders use Eloquent models (not raw SQL).
- How `Hash::make()` hashes passwords for secure storage.
- The DatabaseSeeder orchestrates multiple seeders.

---

## Step 7: Run Seeders

**What to do:**

1. **Run the seeder:**
   ```bash
   php artisan db:seed
   ```

2. **Verify data was seeded:**
   ```bash
   mysql -u root -p wallet_system -e "SELECT * FROM roles;"
   mysql -u root -p wallet_system -e "SELECT * FROM users;"
   mysql -u root -p wallet_system -e "SELECT * FROM system_settings;"
   ```

**What you should understand:**
- How `php artisan db:seed` runs all seeders.
- Why seeding is useful for local development and testing.

---

## Checklist

Before moving to the next task, verify:

- ✅ MySQL database created and connected
- ✅ All 10 migrations created and run successfully
- ✅ Database schema matches requirements (verify with `SHOW TABLES`, `DESCRIBE`)
- ✅ All 9 models created
- ✅ Relationships defined in models (no logic yet)
- ✅ RoleSeeder, SuperAdminSeeder, SystemSettingsSeeder created
- ✅ Database seeded successfully
- ✅ Can query seeded data (roles, super admin user, system settings)

---

## Next Steps

Once complete, I will:
1. Review your migrations and models
2. Provide feedback on structure
3. Introduce **Step 4: Create Models with Relationships** (if needed)
4. Begin **Module 1: Authentication Setup**

Submit evidence of completion:
- Screenshot of `php artisan migrate` output
- Output of `mysql ... SHOW TABLES;`
- Output of seeded data queries

---

## Important Notes

- **Don't skip the verification step.** Verify the database structure matches the requirements.
- **Don't add business logic yet.** Models should just define relationships.
- **Ask questions if migrations fail.** Common issues: syntax errors, FK constraint problems, duplicate names.
- **Keep migrations small and focused.** One table per migration makes rollback easier.

Ready to start? Create the first migration and let me know when you hit any issues!

