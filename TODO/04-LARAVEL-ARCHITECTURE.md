# Laravel Architecture Mapping

## How This Project Maps to Laravel

### Request Lifecycle

```
HTTP Request
  ↓
Route (api.php)
  ↓
Controller (handles HTTP)
  ↓
Form Request (validation + authorization)
  ↓
Service/Domain Logic (business rules)
  ↓
Eloquent Model (persistence)
  ↓
Database
  ↓
API Resource (response formatting)
  ↓
HTTP Response (JSON)
```

Each layer has a responsibility:

| Layer | Responsibility | Examples |
|-------|-----------------|----------|
| **Routes** | Map HTTP to controllers | `/api/v1/transactions/transfer` → `TransactionController@transfer` |
| **Middleware** | Request/response interception | Authentication, CORS, rate limiting |
| **Form Request** | Input validation + authorization | "Is this user allowed to transfer?" |
| **Controller** | HTTP handling only | Receive request, call service, return response |
| **Service** | Business logic | Calculate fees, check caps, update balance |
| **Model** | Database mapping | Relationships, attributes, mutators |
| **Policy** | Authorization rules | "Can user view this transaction?" |
| **Resource** | Response formatting | Serialize model to JSON |

### Module Structure in Laravel

Recommended per-module file structure:

```
app/Modules/Transactions/
├── Models/
│   └── Transaction.php
├── Requests/
│   ├── TransferRequest.php
│   ├── CashInRequest.php
│   └── CashOutRequest.php
├── Resources/
│   ├── TransactionResource.php
│   └── TransactionCollection.php
├── Services/
│   ├── TransactionService.php
│   ├── FeeCalculator.php
│   └── CommissionCalculator.php
├── Policies/
│   └── TransactionPolicy.php
├── Exceptions/
│   ├── InsufficientBalanceException.php
│   ├── WalletBlockedException.php
│   └── CapExceededException.php
├── Events/
│   └── TransactionCompleted.php
├── Listeners/
│   └── RecordTransactionAudit.php
├── Controllers/
│   └── TransactionController.php
├── Routes/
│   └── api.php
└── Database/
    └── migrations/
        └── 202X_XX_XX_create_transactions_table.php
```

**Key principle:** Everything related to transactions is self-contained here.

---

## Cross-Module Communication

### Problem
Module A needs Module B's functionality. How do they talk?

### Solution: Service Interfaces

**Bad (tight coupling):**
```php
// In TransactionService
$wallet = Wallet::find($userId); // Direct model access from another module
$wallet->balance -= $amount;
$wallet->save();
```

**Good (loose coupling):**
```php
// In Wallets/Services/WalletService.php
interface WalletServiceContract {
  public function deductBalance(User $user, decimal $amount): bool;
  public function getBalance(User $user): decimal;
}

// In Transactions/Services/TransactionService.php
class TransactionService {
  public function __construct(private WalletServiceContract $wallets) {}
  
  public function transfer($fromId, $toId, $amount) {
    $this->wallets->deductBalance($fromId, $amount);
    $this->wallets->addBalance($toId, $amount);
  }
}
```

**Benefits:**
- Transactions module doesn't know about Wallets models
- Easy to swap implementations (test doubles)
- Changes to Wallets don't break Transactions

### Service Container (Dependency Injection)

Register in `app/Providers/AppServiceProvider.php`:

```php
public function register()
{
  $this->app->bind(
    \App\Modules\Wallets\Services\WalletServiceContract::class,
    \App\Modules\Wallets\Services\WalletService::class
  );
}
```

Laravel automatically injects when you type-hint:

```php
public function __construct(WalletServiceContract $wallets) {}
```

---

## Authorization: Middleware vs Policies vs Gates

### Middleware
**Use for:** Broad access control (only authenticated users, admin-only routes)

```php
// In routes
Route::middleware('auth:api')->get('/user/profile', ...);
Route::middleware('admin')->get('/admin/users', ...);
```

```php
// In app/Http/Middleware/AdminMiddleware.php
public function handle($request, $next) {
  if (!auth()->user()->isAdmin()) {
    abort(403);
  }
  return $next($request);
}
```

### Policies
**Use for:** Resource-level authorization (can user edit this transaction?)

```php
// In app/Modules/Transactions/Policies/TransactionPolicy.php
public function view(User $user, Transaction $transaction): bool {
  return $user->id === $transaction->initiated_by_id ||
         $user->isAdmin();
}
```

In controller:
```php
$this->authorize('view', $transaction); // Throws 403 if not allowed
```

### Gates
**Use for:** Simple role/permission checks (is user an agent?)

```php
// In AuthServiceProvider
Gate::define('is-agent', function(User $user) {
  return $user->hasRole('AGENT');
});
```

In controller:
```php
if (Gate::allows('is-agent')) { ... }
```

### For This Project

| Check | Where | How |
|-------|-------|-----|
| User is authenticated | Middleware | `auth:api` |
| User is admin | Middleware | Custom middleware |
| User can view own wallet | Policy | `$this->authorize('view', $wallet)` |
| User can transfer | Form Request | `authorize()` method |
| User is approved agent | Form Request or Service | Custom validation/check |

---

## Exception Handling

Create domain-specific exceptions:

```php
// app/Modules/Wallets/Exceptions/InsufficientBalanceException.php
class InsufficientBalanceException extends Exception {
  public function __construct(
    public readonly User $user,
    public readonly decimal $requested,
    public readonly decimal $available
  ) {
    parent::__construct(
      "Insufficient balance. Requested: {$requested}, Available: {$available}"
    );
  }
}
```

In controller:
```php
try {
  $this->transactions->transfer($request->validated());
} catch (InsufficientBalanceException $e) {
  return response()->json([
    'error' => 'insufficient_balance',
    'message' => $e->getMessage(),
    'available' => $e->available,
    'requested' => $e->requested,
  ], 422);
}
```

Benefits:
- Specific error handling per scenario
- Stack trace clarity
- Easy to test error conditions

---

## Database Transactions

Critical for wallet operations:

```php
// Bad: Multiple DB calls, not atomic
$from->wallet->balance -= $amount;
$from->wallet->save();
$to->wallet->balance += $amount;
$to->wallet->save();

// Good: Atomic
DB::transaction(function() use ($from, $to, $amount) {
  $from->wallet->balance -= $amount;
  $from->wallet->save();
  
  $to->wallet->balance += $amount;
  $to->wallet->save();
});
```

With row locking (prevents race conditions):

```php
DB::transaction(function() use ($from, $to, $amount) {
  $fromWallet = Wallet::where('user_id', $from->id)
    ->lockForUpdate()
    ->first();
  
  $toWallet = Wallet::where('user_id', $to->id)
    ->lockForUpdate()
    ->first();
  
  // Now these operations are safe from concurrent requests
  $fromWallet->balance -= $amount;
  $fromWallet->save();
  
  $toWallet->balance += $amount;
  $toWallet->save();
});
```

---

## API Resources (Response Formatting)

Don't serialize models directly. Use resources:

```php
// Bad
return response()->json($transaction->toArray());
// Leaks internal fields, format depends on model

// Good
return new TransactionResource($transaction);
```

Resource:
```php
// app/Modules/Transactions/Resources/TransactionResource.php
class TransactionResource extends JsonResource {
  public function toArray($request) {
    return [
      'id' => $this->id,
      'type' => $this->type,
      'amount' => $this->amount,
      'status' => $this->status,
      'created_at' => $this->created_at->toIso8601String(),
      // Hide: internal fields, passwords, etc.
    ];
  }
}
```

---

## Testing Architecture

Each module has its own test suite:

```
tests/
├── Feature/
│   ├── Auth/
│   │   └── LoginTest.php
│   ├── Transactions/
│   │   ├── TransferTest.php
│   │   ├── CashInTest.php
│   │   └── CashOutTest.php
│   └── Wallets/
│       └── BlockWalletTest.php
└── Unit/
    ├── Services/
    │   ├── TransactionServiceTest.php
    │   └── FeeCalculatorTest.php
    └── Models/
        └── UserTest.php
```

Feature tests (most important): Test the entire request → response flow with database.

Unit tests: Test isolated logic (calculators, validators).

---

## Configuration & Environment

**Environment variables** in `.env`:

```
APP_ENV=local
APP_DEBUG=true
DB_CONNECTION=mysql
JWT_SECRET=xxx
AGENT_COMMISSION_RATE=0.01
TRANSACTION_FEE_RATE=0.02
```

**Access in code:**
```php
config('app.env')
env('JWT_SECRET')
config('services.commission_rate')
```

Keep secrets in `.env`, never commit them.

---

## Summary

Your Laravel architecture should:

✅ Separate HTTP handling from business logic
✅ Use services for cross-module calls
✅ Use policies for fine-grained authorization
✅ Use database transactions for financial operations
✅ Use API resources for response formatting
✅ Keep modules loosely coupled via interfaces
✅ Test at module boundaries
