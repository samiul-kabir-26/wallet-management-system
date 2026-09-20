# Task 9: API Resources & Documentation

**Status:** Ready after Task 8  
**Estimated Duration:** 2-3 hours  
**Difficulty:** Easy

---

## Objective

Create consistent API response formatting and comprehensive endpoint documentation.

---

## Step 1: API Resources

Create Laravel Resource classes to format all responses consistently.

### Response Format

**Success:**
```json
{
  "success": true,
  "message": "Operation successful",
  "data": {...},
  "meta": {
    "timestamp": "2026-09-20T10:30:00Z"
  }
}
```

**Error:**
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "email": ["Email already exists"]
  }
}
```

### Create Resources

- `app/Http/Resources/UserResource.php`
- `app/Http/Resources/WalletResource.php`
- `app/Http/Resources/TransactionResource.php`
- `app/Http/Resources/AgentResource.php`
- `app/Http/Resources/RoleResource.php`
- `app/Http/Resources/SettingsResource.php`
- `app/Http/Resources/AuthResource.php`

### Example Resource

```php
class UserResource extends JsonResource {
    public function toArray($request) {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'roles' => $this->roles->pluck('name'),
            'is_active' => $this->is_active,
        ];
    }
}
```

---

## Step 2: Pagination

**Standard Pagination Response:**
```json
{
  "success": true,
  "data": {
    "items": [...],
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

**Implement in controllers:**
```php
$users = User::paginate(15);
return UserResource::collection($users);
```

---

## Step 3: API Documentation

### Create docs/API.md

Document all endpoints:

```markdown
# Wallet Management System API

## Authentication Endpoints

### POST /api/v1/auth/register
Register new user

**Request:**
{
  "name": "John Doe",
  "phone_number": "01712345678",
  "pin": "123456",
  "role": "USER"
}

**Response (201):**
{
  "success": true,
  "data": {
    "user": {...},
    "token": "..."
  }
}

---

[Repeat for all endpoints...]
```

### Document For Each Endpoint

- Method & URL
- Authorization required
- Request body (with example)
- Response (with example)
- Status codes
- Error scenarios
- Rate limiting (if applicable)

### Tools (Optional)

Use OpenAPI/Swagger for interactive docs:
- Install `laravel-swagger` package
- Generate OpenAPI spec
- Host Swagger UI at /api/docs

---

## Files to Create

### Resources
- `app/Http/Resources/UserResource.php`
- `app/Http/Resources/WalletResource.php`
- `app/Http/Resources/TransactionResource.php`
- `app/Http/Resources/AgentResource.php`
- `app/Http/Resources/RoleResource.php`
- `app/Http/Resources/SettingsResource.php`
- `app/Http/Resources/AuthResource.php`

### Documentation
- `docs/API.md` — Complete API reference
- `docs/ERRORS.md` — Error codes and meanings
- `docs/AUTHENTICATION.md` — Auth flow explanation
- `docs/EXAMPLES.md` — Usage examples

---

## Checklist

- [ ] All endpoints return consistent format
- [ ] Sensitive data excluded from responses
- [ ] Pagination implemented on list endpoints
- [ ] API documentation complete
- [ ] All endpoints documented
- [ ] Example requests/responses provided
- [ ] Error codes documented
- [ ] Authentication methods documented
