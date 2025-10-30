# Fix Parameter Validation for Team Parameters and Time Inputs

## 🐛 Problem

Plan generation was failing with the error:
```
[2025-10-23 16:46:00] local.ERROR: Generation failed {"plan_id":1012,"error":"A non-numeric value encountered"}
```

## 🔍 Root Cause Analysis

1. **Time Parameter Validation Issue**: The validation logic was trying to apply numeric step validation to time strings like "09:00", causing "A non-numeric value encountered" errors.

2. **Team Parameter Validation Issue**: Team parameters (like `e2_teams`) were being validated against minimum values even when the frontend sends 0 for disabled programs, causing validation failures.

## ✅ Solution

### 1. Time Parameter Validation
- Added special handling for `type === 'time'` parameters
- Converts time strings (HH:MM) to minutes for proper validation
- Validates step constraints correctly for time parameters
- Example: "09:00" with step=5 validates that minutes (0) are multiples of 5 ✅

### 2. Team Parameter Validation
- **Skip validation** for all parameters ending with `_teams`
- These parameters are used for support plan checking elsewhere in the code
- Allows frontend to send team parameters even when programs are disabled
- Examples: `c_teams`, `e1_teams`, `e2_teams` are all skipped

### 3. Frontend Time Validation
- Added 5-minute step validation for time inputs in `ParameterField.vue`
- Real-time validation with visual feedback
- German error messages: "Nur 5-Min-Schritte erlaubt."
- Consistent with backend validation

## 🧪 Testing

- ✅ Plan 1012 now loads parameters successfully
- ✅ Plan 1012 generation completes without errors
- ✅ Time validation works correctly (5-minute steps)
- ✅ Team parameters don't cause validation failures
- ✅ Frontend time inputs validate 5-minute steps

## 📁 Files Changed

### Backend
- `backend/app/Support/PlanParameter.php` - Fixed parameter validation logic
- `backend/app/Support/PlanParameter.php` - Added time parameter validation
- `backend/app/Support/PlanParameter.php` - Skip validation for `_teams` parameters

### Frontend  
- `frontend/src/components/molecules/ParameterField.vue` - Added time validation
- `frontend/src/components/molecules/ParameterField.vue` - Added 5-minute step validation
- `frontend/src/components/molecules/ParameterField.vue` - German error messages

## 🎯 Impact

- **Fixes plan generation failures** for plans with time parameters and team parameters
- **Improves user experience** with real-time frontend validation
- **Maintains consistency** between frontend and backend validation
- **Handles common pattern** where frontend sends all parameters for consistency

## 🔧 Technical Details

### Time Parameter Validation
```php
private function validateTimeParameter(object $param, mixed $value): void
{
    $valueMinutes = $this->timeToMinutes($value);
    // Validate step formula for time: minutes must be multiples of step
    if ($param->step !== null && $param->step > 0) {
        if ($valueMinutes % $param->step !== 0) {
            throw new RuntimeException("Parameter '{$param->name}' value {$value} does not follow step formula (step: {$param->step} minutes).");
        }
    }
}
```

### Team Parameter Skip
```php
// Skip validation for all team parameters - they are used for support plan checking elsewhere
if (str_ends_with($param->name, '_teams')) {
    return;
}
```

### Frontend Time Validation
```javascript
function validateTimeValue(timeValue) {
    const timeRegex = /^([0-1]?[0-9]|2[0-3]):([0-5][0-9])$/;
    if (!timeRegex.test(timeValue)) {
        validationError.value = 'Ungültiges Zeitformat (hh:mm)';
        return false;
    }
    
    const [, , minutes] = timeValue.match(timeRegex);
    const minutesNum = parseInt(minutes, 10);
    
    if (minutesNum % 5 !== 0) {
        validationError.value = 'Nur 5-Min-Schritte erlaubt.';
        return false;
    }
    
    return true;
}
```

## ✅ Verification

- [x] Plan generation works for plan_id 1012
- [x] Time parameters validate correctly with step constraints
- [x] Team parameters are skipped from validation
- [x] Frontend time inputs validate 5-minute steps
- [x] German error messages are displayed correctly
- [x] No regression in existing functionality
