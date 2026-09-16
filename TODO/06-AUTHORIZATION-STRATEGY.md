# Authorization Strategy

## Overview

Authorization determines **what an authenticated user can do**.

Roles in this system:
```
SUPER_ADMIN
ADMIN
MODERATOR
AGENT
USER
```

---

## Authorization Tools in Laravel

| Tool | Purpose | When to Use |
|------|---------|------------|
| **Middleware** | Route-level access | Only authenticated, only admin routes |
| **Gates** | Simple permission checks | Is user a specific role? |
| **Policies** | Resource-level access | Can user view/edit this specific transaction? |
| **Form Requests** | Input validation + authorization | Validate input AND check if user can perform action |

---

## Implementation Approach for This Project

### Layer 1: Middleware (Route Protection)

Protect entire routes based on authentication status.

```php
// In routes/api.php

// Public routes (anyone)
Route::post('/auth/login', ...);
Route::post('/auth/register', ...);

// Protected (authenticated users only)
Route::middleware('auth:sanctum')->group(function () {
  Route::get('/user/profile', ...);
  Route::get('/wallets/me', ...);
  
  // Admin-only routes
  Route::middleware(\App\Http\Middleware\AdminMiddleware::class)->prefix('admin')->group(function () {
    Route::get('/users', ...);
    Route::patch('/wallets/{id}/block', ...);
  });
  
  // Agent-only routes
  Route::middleware(\App\Http\Middleware\AgentMiddleware::class)->prefix('agent')->group(function () {
    Route::post('/withdrawal', ...);
  });
});
```

### Layer 2: Form Request Authorization

Validate that the authenticated user is allowed to perform this specific action.

```php
// app/Modules/Transactions/Requests/TransferRequest.php
class TransferRequest extends FormRequest {
  public function authorize(): bool {
    // User must be a USER or AGENT
    return auth()->user()->hasRole('USER', 'AGENT');
  }
  
  public function rules(): array {
    return [
      'amount' => 'required|numeric|min:1',
      'recipient_id' => 'required|exists:users,id',
      'description' => 'nullable|string|max:255',
    ];
  }
}
```

### Layer 3: Controller Logic

Let the controller handle straightforward business logic.

```php
class TransactionController extends Controller {
  public function transfer(TransferRequest $request, TransactionService $service) {
    // Form Request already validated input and authorized user
    // Service handles business logic
    $transaction = $service->transfer(
      auth()->id(),
      $request->recipient_id,
      $request->amount,
      $request->description
    );
    
    return new TransactionResource($transaction);
  }
}
```

### Layer 4: Policies (Resource-Level Access)

Allow fine-grained control over specific resources.

```php
// app/Modules/Transactions/Policies/TransactionPolicy.php
class TransactionPolicy {
  // Can user view this specific transaction?
  public function view(User $user, Transaction $transaction): bool {
    // User can view if:
    // 1. They initiated it
    // 2. They're a party to it (sender/recipient)
    // 3. They're an admin
    
    return $user->id === $transaction->initiated_by_id ||
           $user->id === $transaction->sender_id ||
           $user->id === $transaction->recipient_id ||
           $user->hasRole('ADMIN', 'SUPER_ADMIN');
  }
}
```

In controller:
```php
public function show($id) {
  $transaction = Transaction::findOrFail($id);
  
  // Throws 403 if policy denies
  $this->authorize('view', $transaction);
  
  return new TransactionResource($transaction);
}
```

### Layer 5: Service Layer Assertions

Business logic should verify authorization where appropriate.

```php
// app/Modules/Agents/Services/AgentService.php
public function approve(int $agentId, User $approver): Agent {
  // Only SUPER_ADMIN or ADMIN can approve
  if (!$approver->hasRole('SUPER_ADMIN', 'ADMIN')) {
    throw new UnauthorizedException('Only admins can approve agents');
  }
  
  $agent = Agent::findOrFail($agentId);
  $agent->is_approved = true;
  $agent->approved_at = now();
  $agent->approved_by = $approver->id;
  $agent->save();
  
  return $agent;
}
```

---

## Authorization Rules by Role

### SUPER_ADMIN

Can:
- ✓ Create/manage ADMIN users
- ✓ Approve agents
- ✓ Suspend agents
- ✓ View all users
- ✓ View all transactions
- ✓ Block/unblock wallets
- ✓ Change system settings
- ✓ Access all admin functions

### ADMIN

Can:
- ✓ Approve agents
- ✓ Suspend agents
- ✓ View all users
- ✓ View all transactions
- ✓ Block/unblock wallets
- ✓ Change system settings
- ✗ Cannot create/manage other ADMINs
- ✗ Cannot delete users or transactions

### MODERATOR

Can:
- ✓ View all transactions (audit trail)
- ✓ Flag suspicious transactions
- ✗ Cannot approve agents (ADMIN only)
- ✗ Cannot modify system settings
- ✗ Cannot view sensitive user data

### AGENT

Can:
- ✓ View own wallet
- ✓ Withdraw from own wallet
- ✓ Participate in cash-in/cash-out (if approved)
- ✓ View transactions they participated in
- ✗ Cannot approve themselves
- ✗ Cannot view other agents' wallets
- ✗ Cannot access admin panel

### USER

Can:
- ✓ View own profile
- ✓ View own wallet
- ✓ Transfer to other users
- ✓ Top-up (deposit)
- ✓ Cash-in/cash-out via agents (if agent approved)
- ✓ View own transactions
- ✗ Cannot view other users' wallets
- ✗ Cannot view other users' transactions

---

## Implementation Map

### Middleware for Route Groups

```php
// app/Http/Middleware/AdminMiddleware.php
class AdminMiddleware {
  public function handle($request, $next) {
    if (!auth()->user()?->hasRole('ADMIN', 'SUPER_ADMIN')) {
      abort(403, 'Only admins can access this.');
    }
    return $next($request);
  }
}

// app/Http/Middleware/AgentMiddleware.php
class AgentMiddleware {
  public function handle($request, $next) {
    if (!auth()->user()?->hasRole('AGENT')) {
      abort(403, 'Only agents can access this.');
    }
    return $next($request);
  }
}

// app/Http/Middleware/ApprovedAgentMiddleware.php
class ApprovedAgentMiddleware {
  public function handle($request, $next) {
    $user = auth()->user();
    if (!$user?->hasRole('AGENT') || !$user->agent_info?->is_approved) {
      abort(403, 'Only approved agents can perform this action.');
    }
    return $next($request);
  }
}
```

### Form Request Examples

```php
// CashInRequest - user makes cash-in transaction
class CashInRequest extends FormRequest {
  public function authorize(): bool {
    // Any USER or AGENT can initiate (further checks in service)
    return auth()->user()->hasRole('USER', 'AGENT');
  }
  
  public function rules(): array {
    return [
      'amount' => 'required|numeric|min:1',
      'agent_id' => 'required|exists:users,id',
      'description' => 'nullable|string',
    ];
  }
}

// ApproveAgentRequest - admin approves agent
class ApproveAgentRequest extends FormRequest {
  public function authorize(): bool {
    // Only ADMIN and SUPER_ADMIN
    return auth()->user()?->hasRole('ADMIN', 'SUPER_ADMIN') ?? false;
  }
  
  public function rules(): array {
    return [
      // No specific input beyond the user ID in URL
    ];
  }
}

// ChangeSystemSettingsRequest
class ChangeSystemSettingsRequest extends FormRequest {
  public function authorize(): bool {
    return auth()->user()?->hasRole('ADMIN', 'SUPER_ADMIN') ?? false;
  }
  
  public function rules(): array {
    return [
      'transaction_fee' => 'numeric|between:0,1',
      'agent_commission' => 'numeric|between:0,1',
    ];
  }
}
```

### Policies

```php
// app/Modules/Wallets/Policies/WalletPolicy.php
class WalletPolicy {
  public function view(User $user, Wallet $wallet): bool {
    // User can view their own wallet
    // Admins can view any wallet
    return $user->id === $wallet->user_id ||
           $user->hasRole('ADMIN', 'SUPER_ADMIN');
  }
  
  public function block(User $user, Wallet $wallet): bool {
    // Only admins
    return $user->hasRole('ADMIN', 'SUPER_ADMIN');
  }
}

// app/Modules/Users/Policies/UserPolicy.php
class UserPolicy {
  public function view(User $user, User $target): bool {
    // Users can view their own profile
    // Admins can view any profile
    return $user->id === $target->id ||
           $user->hasRole('ADMIN', 'SUPER_ADMIN');
  }
  
  public function update(User $user, User $target): bool {
    // Users can update their own profile
    // Admins can update any profile
    // But cannot edit their own role
    if ($user->id === $target->id) {
      // Self-edit allowed, but role cannot change
      return true;
    }
    return $user->hasRole('ADMIN', 'SUPER_ADMIN');
  }
  
  public function approveAgent(User $user, User $agent): bool {
    // Only ADMIN and SUPER_ADMIN
    return $user->hasRole('ADMIN', 'SUPER_ADMIN');
  }
}

// app/Modules/Transactions/Policies/TransactionPolicy.php
class TransactionPolicy {
  public function view(User $user, Transaction $transaction): bool {
    return $user->id === $transaction->initiated_by_id ||
           $user->id === $transaction->sender_id ||
           $user->id === $transaction->recipient_id ||
           $user->id === $transaction->agent_id ||
           $user->hasRole('ADMIN', 'SUPER_ADMIN');
  }
  
  public function delete(User $user, Transaction $transaction): bool {
    // Transactions cannot be deleted (audit trail)
    return false;
  }
}
```

### Service Layer Authorization

```php
// app/Modules/Agents/Services/AgentService.php
class AgentService {
  public function approve(int $agentId, User $approver): Agent {
    if (!$approver->hasRole('ADMIN', 'SUPER_ADMIN')) {
      throw new UnauthorizedException(
        'Only admins can approve agents.'
      );
    }
    
    $agent = User::findOrFail($agentId);
    if (!$agent->hasRole('AGENT')) {
      throw new InvalidArgumentException(
        'User is not an agent.'
      );
    }
    
    // Approve logic...
  }
}
```

---

## Authorization Decision Tree

### Can user view transaction?

```
Is transaction initiated by user?
  YES → Allow
  NO → Does user = sender/recipient/agent?
    YES → Allow
    NO → Is user admin?
      YES → Allow
      NO → Deny (403)
```

### Can user approve agent?

```
Is user SUPER_ADMIN or ADMIN?
  YES → Allow
  NO → Deny (403)
```

### Can user transfer to another user?

```
Is user authenticated?
  NO → Deny (401)
  YES → Does user have sufficient balance?
    NO → Deny (422 with error)
    YES → Is user blocked?
      YES → Deny (422 with error)
      NO → Is daily cap exceeded?
        YES → Deny (422 with error)
        NO → Allow
```

---

## Testing Authorization

```php
// tests/Feature/Transactions/TransferTest.php
class TransferTest extends TestCase {
  public function test_user_can_transfer_to_another_user() {
    $user = User::factory()->create();
    $recipient = User::factory()->create();
    
    $this->actingAs($user)
      ->postJson('/api/v1/transactions/transfer', [
        'amount' => 100,
        'recipient_id' => $recipient->id,
      ])
      ->assertStatus(201);
  }
  
  public function test_user_cannot_view_other_users_transactions() {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $transaction = Transaction::factory()->for($user1, 'initiator')->create();
    
    $this->actingAs($user2)
      ->getJson("/api/v1/transactions/{$transaction->id}")
      ->assertStatus(403);
  }
  
  public function test_admin_can_approve_agent() {
    $admin = User::factory()->admin()->create();
    $agent = User::factory()->agent()->create();
    
    $this->actingAs($admin)
      ->patchJson("/api/v1/user/{$agent->id}/approve-agent")
      ->assertStatus(200);
  }
  
  public function test_user_cannot_approve_agent() {
    $user = User::factory()->create();
    $agent = User::factory()->agent()->create();
    
    $this->actingAs($user)
      ->patchJson("/api/v1/user/{$agent->id}/approve-agent")
      ->assertStatus(403);
  }
}
```

---

## Summary

Authorization flows through:

1. **Middleware** - Route-level (is user authenticated?)
2. **Form Request** - Action-level (is user authorized for this action?)
3. **Policy** - Resource-level (can user access this specific resource?)
4. **Service** - Business logic level (additional checks if needed)

This layered approach provides security without redundancy.
