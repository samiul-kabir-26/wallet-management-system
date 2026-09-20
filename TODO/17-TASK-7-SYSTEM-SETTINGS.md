# Task 7: System Settings Management

**Status:** Ready after Task 6  
**Estimated Duration:** 1-2 hours  
**Difficulty:** Easy

---

## Objective

Implement dynamic system configuration for fees and commissions.

---

## Step 1: Settings Endpoints

### Endpoint 1: Get All Settings

**Route:** `GET /api/v1/system-settings`

**Response (200):**
```json
{
  "success": true,
  "data": {
    "settings": {
      "system_fee_rate": 0.05,
      "agent_commission_rate": 0.01
    }
  }
}
```

**Authorization:** Any authenticated user

**Logic:**
- Fetch all settings from DB
- Format as key-value pairs
- Cache in Redis or file cache

---

### Endpoint 2: Update Settings

**Route:** `PATCH /api/v1/system-settings`

**Request:**
```json
{
  "system_fee_rate": 0.07,
  "agent_commission_rate": 0.015
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "Settings updated successfully",
  "data": {
    "settings": {
      "system_fee_rate": 0.07,
      "agent_commission_rate": 0.015
    }
  }
}
```

**Authorization:** ADMIN or SUPER_ADMIN

**Logic:**
1. Authorize user
2. Validate input:
   - system_fee_rate: decimal, 0-1 (0-100%)
   - agent_commission_rate: decimal, 0-1
3. For each setting:
   - Update or create SystemSetting record
   - Set updated_by = current user id
   - Set updated_at = now()
4. Invalidate cache
5. Return updated settings

---

## Step 2: Caching Strategy

### Cache Settings

**File:** `app/Services/Settings/SettingsService.php`

```php
public function getSettings() {
    return Cache::remember('system_settings', 3600, function () {
        return SystemSetting::pluck('value', 'key')->toArray();
    });
}

public function updateSetting($key, $value) {
    SystemSetting::updateOrCreate(
        ['key' => $key],
        ['value' => $value, 'updated_by' => auth()->id()]
    );
    
    Cache::forget('system_settings');
}
```

**Cache Duration:** 1 hour (or 10 minutes for more frequent changes)

---

## Files to Create

### Services
- `app/Services/Settings/SettingsService.php`

### Controllers
- `app/Http/Controllers/Settings/SettingsController.php`

### Requests
- `app/Http/Requests/Settings/UpdateSettingsRequest.php`

### Policies
- `app/Policies/SystemSettingPolicy.php`

### Resources
- `app/Http/Resources/SettingsResource.php`

### Tests
- `tests/Feature/Settings/GetSettingsTest.php`
- `tests/Feature/Settings/UpdateSettingsTest.php`

---

## Checklist

- [ ] Any user can view settings
- [ ] Only ADMIN can update settings
- [ ] Settings cached for performance
- [ ] Updated settings used in transaction calculations
- [ ] Audit trail recorded (updated_by, updated_at)
- [ ] All tests pass
