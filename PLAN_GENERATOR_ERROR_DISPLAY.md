# Plan: Display Generator Errors in UI

## Current Situation

**Frontend (`Schedule.vue`):**
- `runGeneratorOnce()` function (lines 433-444) calls the generator API
- Errors are only logged to console with `console.error()`
- No user-visible error feedback
- Generator errors happen silently in the background

**Backend Error Responses:**
1. **422 Unprocessable Entity**: Plan configuration not supported
   - Response: `{'error': "Plan {id} not supported"}`
   - Triggered when `PlanGeneratorService::isSupported()` returns false
   - Checks include:
     - Finale events must have exactly 25 Challenge teams
     - Challenge plans must match supported configurations (teams/lanes/tables)
     - Explore plans must match supported configurations (teams/lanes)

2. **404 Not Found**: Plan doesn't exist
   - Response: `{'error': "Plan {id} not found"}`

3. **500 Internal Server Error**: Generation failed due to exception
   - Response: `{'error': 'Generation failed'}`
   - Triggered when `PlanGeneratorCore::generate()` throws exceptions
   - Could be parameter validation errors, runtime errors, etc.

4. **Polling Timeout**: `pollUntilReady()` throws Error after 60 seconds
   - Message: "Timeout: Plan generation took too long"

## Proposed Solution

### 1. Error State Management

Add reactive refs to store error state:
```typescript
const generatorError = ref<string | null>(null)
const errorDetails = ref<string | null>(null) // Optional detailed error message
```

### 2. Enhanced Error Handling in `runGeneratorOnce()`

Modify the function to:
- Catch HTTP errors from the generate endpoint
- Extract error messages from response data
- Set error state appropriately
- Handle different error scenarios:
  - 422: "Unterstützte Konfiguration nicht gefunden"
  - 404: "Plan nicht gefunden"
  - 500: "Fehler bei der Generierung"
  - Network errors: "Verbindungsfehler"
  - Timeout: Show timeout error

### 3. Error Display Component

Add error display in the template:
- Replace or overlay the Preview component when error occurs
- Show clear error message in German
- Include error details if available
- Provide action buttons:
  - "Schließen" / "Dismiss" to clear error
  - Optionally: "Erneut versuchen" / "Retry" button

### 4. Error Styling

- Use alert/error styling (red background, icon)
- Make it prominent but not blocking
- Consider toast-style notification at top or inline error box

### 5. Error Clearing

- Clear error when:
  - User clicks dismiss/close
  - New generation starts successfully
  - User navigates away

### 6. Status Polling Error Handling

Update `pollUntilReady()` to:
- Check for `generator_status === 'failed'` in status response
- Handle this as an error case
- Set appropriate error message

## Implementation Details

### Files to Modify

1. **`frontend/src/components/Schedule.vue`**
   - Add error state refs
   - Modify `runGeneratorOnce()` to catch and set errors
   - Update `pollUntilReady()` to check for failed status
   - Add error display in template
   - Clear errors appropriately

### Error Message Mapping

| Backend Error | User-Facing Message (German) |
|--------------|------------------------------|
| "Plan {id} not supported" | "Die aktuelle Konfiguration wird nicht unterstützt. Bitte überprüfen Sie die Anzahl der Teams, Spuren und Tische." |
| "Plan {id} not found" | "Plan nicht gefunden." |
| "Generation failed" | "Fehler bei der Plan-Generierung. Bitte versuchen Sie es erneut oder kontaktieren Sie den Support." |
| Network errors | "Verbindungsfehler. Bitte überprüfen Sie Ihre Internetverbindung." |
| Timeout | "Die Generierung dauert zu lange. Bitte versuchen Sie es erneut." |
| Status = 'failed' | "Die Generierung ist fehlgeschlagen." |

### UI Placement Options

**Option A: Alert Banner (Recommended)**
- Fixed position at top of page or above Preview
- Non-blocking, can be dismissed
- Similar to existing toast notification

**Option B: Replace Preview Area**
- Show error instead of Preview component
- More prominent
- Requires user action to clear

**Option C: Inline with Loading State**
- Show error where `isGenerating` loader currently shows
- Consistent with current loading UI
- User sees it immediately

**Recommendation**: Option A (Alert Banner) - visible but doesn't block the UI

## Testing Scenarios

1. Test with unsupported plan configuration (422 error)
2. Test with invalid plan ID (404 error)
3. Test with generation exception (500 error)
4. Test with network failure
5. Test with timeout scenario
6. Test error clearing when retrying
7. Test error display during parameter update-triggered regeneration

## Additional Considerations

- Should errors persist across navigation? (Probably not)
- Should we log errors to a backend error tracking system? (Future enhancement)
- Should we show retry button or just dismiss? (Start with dismiss only)
- Consider internationalization if needed later

