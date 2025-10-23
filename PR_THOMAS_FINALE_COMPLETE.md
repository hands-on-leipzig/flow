# Complete Finale Event Implementation and System Improvements

## 🎯 Overview

This PR implements a comprehensive 2-day "Finale" event generation system and includes multiple UI/UX improvements, parameter validation enhancements, and system news functionality.

## 🚀 Major Features

### 1. Finale Event Generation System
- **2-day Finale events** with special logic for `event.level = 3`
- **Day 1**: Live Challenge judging + Robot Game test rounds (TR1, TR2)
- **Day 2**: Main competition with Challenge judging + Robot Game rounds + Finals
- **Automatic parameter detection** when `event.level = 3` sets `g_finale = true`
- **Validation**: Requires exactly 25 Challenge teams for finale events

### 2. System News Feature
- **Database tables**: `m_news` and `news_user` for notification system
- **Admin interface**: Create, view, and manage system news with read statistics
- **User notifications**: Modal dialogs for unread news with email integration
- **FIFO delivery**: Shows oldest unread news first

### 3. Parameter Validation System
- **Backend validation**: Min/max/step constraints with proper error handling
- **Frontend validation**: Real-time validation with visual feedback
- **Time validation**: 5-minute step enforcement for time inputs
- **Team parameter handling**: Skip validation for `_teams` parameters

### 4. UI/UX Improvements
- **Visibility Rules Management**: Replaced Perl CGI with native Vue.js/Laravel
- **Robot-Game Preview**: Chronological ordering and team diversity metrics
- **Quality Plans**: Added Q6 Duration metric with h:mm formatting
- **PDF Generation**: Fixed page breaks and added timestamps

## 📁 Files Changed

### Backend Core
- `backend/app/Core/FinaleGenerator.php` - **NEW**: Complete 2-day finale generation
- `backend/app/Core/PlanGeneratorCore.php` - Refactored for reusable one-day generation
- `backend/app/Core/ChallengeGenerator.php` - Finale-specific logic and timing
- `backend/app/Core/RobotGameGenerator.php` - Finale team positioning and break logic
- `backend/app/Support/PlanParameter.php` - Parameter validation and time handling

### Backend Controllers
- `backend/app/Http/Controllers/Api/NewsController.php` - **NEW**: System news API
- `backend/app/Http/Controllers/Api/VisibilityController.php` - **NEW**: Visibility rules API
- `backend/app/Http/Controllers/Api/PlanPreviewController.php` - Enhanced preview endpoints
- `backend/app/Http/Controllers/Api/PlanExportController.php` - PDF generation improvements

### Backend Services
- `backend/app/Services/QualityEvaluatorService.php` - Added Q6 duration calculation
- `backend/app/Services/PlanGeneratorService.php` - Finale validation logic

### Frontend Components
- `frontend/src/components/molecules/SystemNews.vue` - **NEW**: Admin news management
- `frontend/src/components/atoms/NewsModal.vue` - **NEW**: User news notifications
- `frontend/src/components/molecules/Visibility.vue` - **NEW**: Native visibility management
- `frontend/src/components/molecules/ParameterField.vue` - Enhanced validation
- `frontend/src/components/molecules/Preview.vue` - Improved preview views
- `frontend/src/components/Schedule.vue` - Finale parameter filtering
- `frontend/src/App.vue` - News notification integration

### Database
- `backend/database/migrations/2025_10_21_101547_add_q6_duration_to_q_plan_table.php` - **NEW**
- `backend/database/migrations/2025_10_21_120706_create_m_news_table.php` - **NEW**
- `backend/database/migrations/2025_10_21_120956_create_news_user_table.php` - **NEW**

## 🔧 Technical Implementation

### Finale Event Logic
```php
// Automatic finale detection
$this->add('g_finale', ((int)$event->level === 3), 'boolean');

// Finale validation
if ($params->get('g_finale')) {
    if ($params->get('c_teams') != 25) {
        throw new RuntimeException('Finale event requires exactly 25 Challenge teams');
    }
}
```

### Day 1 Activities
- **Briefings**: Coach, Jury, Referee briefings
- **Opening**: Small opening ceremony
- **Live Challenge**: 5 rounds of LC judging with interleaved test rounds
- **Test Rounds**: TR1 (with LC Round 1), TR2 (with LC Round 4)
- **Referee Debrief**: After TR2
- **Parties**: Team and volunteer parties

### Day 2 Activities
- **Main Competition**: 5 rounds of Challenge judging
- **Robot Game**: 3 regular rounds (R1, R2, R3)
- **Finals**: Round of 16, Quarter-finals, Semi-finals, Finals
- **Awards**: Final awards ceremony

### Parameter Validation
```php
// Time parameter validation
private function validateTimeParameter(object $param, mixed $value): void
{
    $valueMinutes = $this->timeToMinutes($value);
    if ($param->step !== null && $param->step > 0) {
        if ($valueMinutes % $param->step !== 0) {
            throw new RuntimeException("Parameter '{$param->name}' value {$value} does not follow step formula (step: {$param->step} minutes).");
        }
    }
}

// Skip team parameters
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

## 🎨 UI/UX Enhancements

### System News
- **Admin Panel**: Create, view, delete news with read statistics
- **User Experience**: Modal notifications with email integration
- **Email Integration**: Pre-populated subject "Frage zu <news title>"

### Visibility Rules
- **Native Implementation**: Replaced Perl CGI with Vue.js/Laravel
- **Dynamic Filtering**: By FIRST Program and Activity Type
- **Sticky Headers**: Improved navigation
- **Color Coding**: Visual program indicators

### Robot-Game Preview
- **Chronological Ordering**: Matches sorted by `match_no`
- **Team Diversity Metrics**: Tables and opponents per team
- **Enhanced Display**: Activity group names and room types

### Quality Plans
- **Q6 Duration Metric**: Overall event duration calculation
- **Display Format**: h:mm format in quality plans table
- **Database Integration**: New `q6_duration` column

## 🧪 Testing

### Finale Events
- ✅ 2-day generation with proper date handling
- ✅ Day 1: LC judging + TR1/TR2 integration
- ✅ Day 2: Challenge judging + Robot Game + Finals
- ✅ Parameter validation for 25 teams requirement
- ✅ Team positioning for finale events

### Parameter Validation
- ✅ Time parameters with 5-minute step validation
- ✅ Team parameters skipped from validation
- ✅ Frontend real-time validation
- ✅ German error messages

### System News
- ✅ Admin news management
- ✅ User notification system
- ✅ Read statistics tracking
- ✅ Email integration

### UI Improvements
- ✅ Visibility rules management
- ✅ Robot-Game preview enhancements
- ✅ Quality plans Q6 metric
- ✅ PDF generation improvements

## 📊 Impact

- **New Event Type**: Complete 2-day Finale event support
- **Enhanced UX**: Native visibility management and news system
- **Better Validation**: Comprehensive parameter validation
- **Improved Previews**: Better chronological ordering and metrics
- **Quality Metrics**: Additional Q6 duration tracking

## 🔄 Migration Notes

- **Database**: New migrations for news system and Q6 metric
- **Parameters**: `r_quarter_final` renamed to `r_final_8`
- **Validation**: Enhanced parameter validation system
- **Frontend**: New components for news and visibility management

## ✅ Verification Checklist

- [x] Finale events generate correctly with 25 teams
- [x] Day 1 and Day 2 activities are properly scheduled
- [x] Parameter validation works for all parameter types
- [x] System news functionality works end-to-end
- [x] Visibility rules management is fully functional
- [x] Robot-Game preview shows correct chronological order
- [x] Quality plans display Q6 duration metric
- [x] PDF generation includes timestamps and proper page breaks
- [x] Frontend time validation enforces 5-minute steps
- [x] All German text is properly translated
