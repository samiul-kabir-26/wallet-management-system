# Learning Roadmap: Module-by-Module Implementation

## Overview

This roadmap breaks the project into modules and learning phases. Each phase builds on the previous one and teaches Laravel concepts progressively.

**Total estimated modules: 7**

---

## Phase 1: Foundation (Weeks 1-2)

### Module 1: Users & Authentication

**Concepts You'll Learn:**
- Eloquent models and migrations
- User authentication with Sanctum
- Password hashing (bcrypt)
- JWT-like tokens
- Form requests for validation
- API resources for responses
- Testing authentication flows

**What You'll Build:**
1. Create `users` migration with proper columns
2. Create User model with relationships
3. Create AuthController (login, register, logout)
4. Create Form Requests (LoginRequest, RegisterRequest)
5. Create API resources (UserResource)
6. Add routes with middleware
7. Write feature tests for auth flows

**Why This First:**
- Authentication is a prerequisite for everything else
- Teaches fundamental Laravel patterns
- All other modules depend on authenticated users
- Small scope, high confidence to start

**Files to Create:**
```
Modules/Authentication/
├── Models/User.php
├── Requests/LoginRequest.php
├── Requests/RegisterRequest.php
├── Resources/UserResource.php
├── Controllers/AuthController.php
├── Routes/api.php
├── Database/migrations/create_users_table.php
└── Tests/Feature/AuthTest.php
```

**Milestones:**
- [ ] User can register via API
- [ ] User can login via API
- [ ] User gets token on login
- [ ] Token allows access to protected routes
- [ ] User can logout (token revoked)
- [ ] Tests pass for all auth flows

---

### Module 2: Roles & Authorization

**Concepts You'll Learn:**
- Many-to-many relationships (users ↔ roles)
- Database constraints
- Authorization checks (middleware, policies, form requests)
- Role hierarchy

**What You'll Build:**
1. Create `roles` and `user_roles` migrations
2. Create Role model
3. Create custom middleware (AdminMiddleware, etc.)
4. Create authorization checks
5. Add role assignment logic

**Why After Auth:**
- Need users before assigning roles
- Authorization patterns used everywhere

**Files to Create:**
```
Modules/Roles/
├── Models/Role.php
├── Database/migrations/create_roles_table.php
├── Database/migrations/create_user_roles_table.php
├── Database/seeders/RoleSeeder.php
└── Tests/Feature/RoleTest.php

Modules/Authentication/Http/Middleware/
├── AdminMiddleware.php
├── AgentMiddleware.php
└── ApprovedAgentMiddleware.php
```

**Milestones:**
- [ ] Roles table seeded with SUPER_ADMIN, ADMIN, etc.
- [ ] Users can have multiple roles
- [ ] User::hasRole() works correctly
- [ ] Middleware checks roles
- [ ] Tests verify authorization rules

---

## Phase 2: Financial Foundation (Weeks 3-4)

### Module 3: Wallets

**Concepts You'll Learn:**
- One-to-one relationships
- Database constraints (unique)
- Decimal precision for money
- Wallet blocking logic
- Observer/event patterns (auto-create wallet on user creation)

**What You'll Build:**
1. Create `wallets` migration
2. Create Wallet model with User relationship
3. Create observer to auto-create wallet when user is created
4. Create WalletController (view own, admin view all)
5. Create blocking/unblocking logic
6. Create WalletPolicy

**Why This Phase:**
- Wallets are simpler than transactions (no movement)
- Teaches money-related database design
- Foundation for transaction logic

**Files to Create:**
```
Modules/Wallets/
├── Models/Wallet.php
├── Requests/BlockWalletRequest.php
├── Resources/WalletResource.php
├── Controllers/WalletController.php
├── Policies/WalletPolicy.php
├── Observers/WalletObserver.php
├── Routes/api.php
├── Database/migrations/create_wallets_table.php
└── Tests/Feature/WalletTest.php
```

**Milestones:**
- [ ] User wallet auto-created with BDT 50.00 balance
- [ ] User can view own wallet
- [ ] Admin can view all wallets
- [ ] Admin can block wallet
- [ ] Blocked wallet status reflects in wallet details
- [ ] Tests verify policies work

---

### Module 4: Caps (Daily & Monthly Limits)

**Concepts You'll Learn:**
- Database migrations for new tables
- Timezone handling
- Cap reset logic
- Checking caps before transactions

**What You'll Build:**
1. Create `caps` migration
2. Create Cap model with User relationship
3. Create Cap observer (auto-create caps when user created)
4. Create CapChecker service (logic to check/update caps)
5. Add cap reset logic (observer or middleware)

**Why After Wallets:**
- Caps are checked during transactions (which come next)
- Simpler to understand than full transactions
- Sets up foundation for transaction validation

**Files to Create:**
```
Modules/Users/
├── Models/Cap.php
├── Services/CapChecker.php
├── Observers/CapObserver.php
├── Database/migrations/create_caps_table.php
└── Tests/Feature/CapTest.php
```

**Milestones:**
- [ ] Caps auto-created when user is created
- [ ] Daily cap resets at midnight in user's timezone
- [ ] Monthly cap resets on first of month
- [ ] CapChecker correctly identifies cap violations
- [ ] Tests verify cap logic under various scenarios

---

### Module 5: Agents & Approval

**Concepts You'll Learn:**
- One-to-one relationships (agent_info → user)
- Approval workflow
- Commission calculation
- Agent status (approved/suspended)

**What You'll Build:**
1. Create `agent_info` migration
2. Create AgentInfo model
3. Create agent approval endpoints
4. Create AgentService (approve, suspend)
5. Create authorization checks (only admin can approve)
6. Add commission calculation logic

**Why This Phase:**
- Agents are involved in most transaction types
- Simpler than full transaction flow
- Teaches service layer for business logic

**Files to Create:**
```
Modules/Agents/
├── Models/AgentInfo.php
├── Requests/ApproveAgentRequest.php
├── Services/AgentService.php
├── Controllers/AgentController.php
├── Database/migrations/create_agent_info_table.php
└── Tests/Feature/AgentTest.php
```

**Milestones:**
- [ ] AgentInfo auto-created when user with AGENT role is created
- [ ] Admin can approve agent via PATCH /user/:id/approve-agent
- [ ] Approved agents show is_approved = true
- [ ] Only approved agents can participate in certain transactions
- [ ] Commission rate is stored and used in calculations

---

## Phase 3: System Settings & Transactions (Weeks 5-6)

### Module 6: System Settings

**Concepts You'll Learn:**
- Configuration management
- Audit trails (who changed what)
- Decimal rates (0.01 = 1%)
- Soft updates (recording changes)

**What You'll Build:**
1. Create `system_settings` migration
2. Create SystemSetting model
3. Create SystemSettingController (get/update)
4. Create authorization (admin only)
5. Add audit trail (record who changed settings and when)

**Why Here:**
- Small module, prepares for transaction fee/commission logic
- Teaches how to handle rates and percentages
- Documentation of changes is important for finance

**Files to Create:**
```
Modules/SystemSettings/
├── Models/SystemSetting.php
├── Requests/UpdateSystemSettingRequest.php
├── Resources/SystemSettingResource.php
├── Controllers/SystemSettingController.php
├── Database/migrations/create_system_settings_table.php
└── Tests/Feature/SystemSettingTest.php
```

**Milestones:**
- [ ] System settings stored in database
- [ ] Admin can view current settings
- [ ] Admin can update fee/commission rates
- [ ] Updated by field shows who made the change
- [ ] Tests verify authorization

---

### Module 7: Transactions (The Main Event) (Weeks 7-8)

**Concepts You'll Learn:**
- Complex database transactions
- Row-level locking (SELECT FOR UPDATE)
- Multiple validation checks
- Financial consistency invariants
- Comprehensive testing including concurrency
- Exception handling for financial errors
- Audit trails for transactions

**This Is The Hardest Module**

**What You'll Build:**
1. Create `transactions` migration (with all financial fields)
2. Create Transaction model
3. Create domain exceptions (InsufficientBalance, WalletBlocked, etc.)
4. Create TransactionService with business logic
5. Implement each transaction type:
   - TOP_UP (simple, user only)
   - TRANSFER (two wallets, fees, caps)
   - CASH_IN (user → agent, commission)
   - CASH_OUT (agent → user, fees)
   - AGENT_WITHDRAWAL (agent only)
6. Create Form Requests for each type (with authorization)
7. Create TransactionController (one endpoint per type)
8. Create API resources for responses
9. Implement row-level locking in service
10. Comprehensive testing (including race conditions)

**Why Last:**
- Depends on all other modules
- Most complex logic
- Needs all foundations in place

**Files to Create:**
```
Modules/Transactions/
├── Models/Transaction.php
├── Services/
│   ├── TransactionService.php
│   ├── FeeCalculator.php
│   └── CommissionCalculator.php
├── Requests/
│   ├── TopUpRequest.php
│   ├── TransferRequest.php
│   ├── CashInRequest.php
│   ├── CashOutRequest.php
│   └── AgentWithdrawalRequest.php
├── Resources/TransactionResource.php
├── Controllers/TransactionController.php
├── Policies/TransactionPolicy.php
├── Exceptions/
│   ├── InsufficientBalanceException.php
│   ├── WalletBlockedException.php
│   ├── CapExceededException.php
│   └── AgentNotApprovedException.php
├── Routes/api.php
├── Database/migrations/create_transactions_table.php
└── Tests/Feature/
    ├── TopUpTest.php
    ├── TransferTest.php
    ├── CashInTest.php
    ├── CashOutTest.php
    ├── AgentWithdrawalTest.php
    └── TransactionConcurrencyTest.php
```

**Milestones:**
- [ ] TOP_UP adds balance to user wallet
- [ ] TRANSFER deducts from sender, adds to recipient
- [ ] Fees are calculated correctly
- [ ] Commission is calculated and recorded
- [ ] Caps are checked and updated
- [ ] Blocked wallets cannot transact
- [ ] Unapproved agents cannot participate
- [ ] Transaction records capture all details
- [ ] Concurrent transfers handled correctly (one succeeds, one fails if overdraw)
- [ ] Tests pass for all transaction types
- [ ] Race condition tests pass

---

## Timeline Overview

```
Week 1-2: Auth + Roles
  ✓ API authentication works
  ✓ Role assignment works

Week 3-4: Wallets + Caps
  ✓ Wallets auto-created
  ✓ Cap logic works

Week 5: Agents + Settings
  ✓ Agent approval flow
  ✓ System settings configurable

Week 6-8: Transactions
  ✓ All transaction types work
  ✓ Financial invariants hold
  ✓ Concurrent requests handled

Week 9+: Testing, documentation, deployment
```

---

## Learning Objectives Per Module

### Module 1: Users & Auth
- [ ] Migrations (create tables)
- [ ] Models (define relationships, attributes)
- [ ] Sanctum token authentication
- [ ] Form Requests (validation + authorization)
- [ ] API Resources (JSON formatting)
- [ ] Feature tests

### Module 2: Roles & Authorization
- [ ] Many-to-many relationships
- [ ] Custom middleware
- [ ] Policies for authorization
- [ ] Database constraints and foreign keys

### Module 3: Wallets
- [ ] One-to-one relationships
- [ ] Money handling (DECIMAL precision)
- [ ] Observers (auto-create wallet)
- [ ] Policies for resource access
- [ ] Soft deletes / status fields

### Module 4: Caps
- [ ] Timezone handling in Laravel
- [ ] Background logic (resets)
- [ ] Service classes (CapChecker)
- [ ] Testing time-dependent logic

### Module 5: Agents
- [ ] Approval workflows
- [ ] Service layer business logic
- [ ] Commission calculations
- [ ] Status tracking

### Module 6: System Settings
- [ ] Configuration management
- [ ] Audit fields (updated_by)
- [ ] Decimal rates handling
- [ ] Admin endpoints

### Module 7: Transactions
- [ ] Database transactions (DB::transaction())
- [ ] Row-level locking (lockForUpdate)
- [ ] Complex validation
- [ ] Exception handling
- [ ] Financial consistency testing
- [ ] Concurrency testing
- [ ] Audit trails

---

## Dependencies

```
Module 1 (Auth)
  ↓
Module 2 (Roles)
  ↓
Module 3 (Wallets) ──┬
                      ├─→ Module 7 (Transactions)
Module 4 (Caps) ─────┤
  ↓                   │
Module 5 (Agents) ───┘
  ↓
Module 6 (Settings)
  ↓
Module 7 (Transactions)
```

---

## Decision Points

After each module, we'll review:
- Does the implementation match Laravel conventions?
- Are there architectural issues to fix?
- Is the code testable?
- Are there security concerns?

You might need to refactor as you learn. That's normal and expected.

---

## First Task (Next Step)

**Don't start Module 1 yet.**

First, we need to clarify the database schema ambiguities in `03-REQUIREMENTS-ASSESSMENT.md`.

**Your first task:**
Review `03-REQUIREMENTS-ASSESSMENT.md` and answer:

1. **Cash-in/out fees:** Who pays commission?
2. **Failed transactions:** Count toward caps?
3. **Agent withdrawal:** From wallet or commission?
4. **Transaction types:** Confirm my mapping is correct
5. **Transaction visibility:** Who can see what?

Once these are clarified, you're ready for Module 1.
