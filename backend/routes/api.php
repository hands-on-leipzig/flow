<?php

use App\Http\Controllers\Api\AfternoonController;
use App\Http\Controllers\Api\CalendarFeedController;
use App\Http\Controllers\Api\CarouselController;
use App\Http\Controllers\Api\CheckInController;
use App\Http\Controllers\Api\CockpitController;
use App\Http\Controllers\Api\ContaoController;
use App\Http\Controllers\Api\DrahtController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EventStaffingAssignmentController;
use App\Http\Controllers\Api\EventStaffingController;
use App\Http\Controllers\Api\EventTeamDataController;
use App\Http\Controllers\Api\EventTeamFieldController;
use App\Http\Controllers\Api\EventVolunteerFieldController;
use App\Http\Controllers\Api\EventVolunteerMealOptionController;
use App\Http\Controllers\Api\EventVolunteerCollectController;
use App\Http\Controllers\Api\EventVolunteerRosterController;
use App\Http\Controllers\Api\EventWorkspaceController;
use App\Http\Controllers\Api\ExtraBlockController;
use App\Http\Controllers\Api\LabelController;
use App\Http\Controllers\Api\LogoController;
use App\Http\Controllers\Api\MainTablesController;
use App\Http\Controllers\Api\MParameterController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\ParameterController;
use App\Http\Controllers\Api\PlanActivityController;
use App\Http\Controllers\Api\PlanCeremonyTimesController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\PlanExportController;
use App\Http\Controllers\Api\PlanGeneratorController;
use App\Http\Controllers\Api\PlanParameterController;
use App\Http\Controllers\Api\PlanPreviewController;
use App\Http\Controllers\Api\PlanQualityController;
use App\Http\Controllers\Api\PlanRoomTypeController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\PublicPlanController;
use App\Http\Controllers\Api\PublishController;
use App\Http\Controllers\Api\QualityController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\SharepointController;
use App\Http\Controllers\Api\StatisticController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\TeamPublicFormController;
use App\Http\Controllers\Api\UserAccessController;
use App\Http\Controllers\Api\UserRegionalPartnerController;
use App\Http\Controllers\Api\VisibilityController;
use App\Http\Controllers\Api\VolunteerPersonController;
use App\Http\Controllers\Api\VolunteerPublicFormController;
use App\Models\Event;
use App\Services\SeasonService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => ['pong' => true]);

Route::get('/profile', function (Illuminate\Http\Request $request) {
    return response()->json([
        'user' => $request->get('jwt'),
    ]);
});

// Public routes (no authentication required)
Route::get('/carousel/{event}/slideshows', [CarouselController::class, 'getPublicSlideshowForEvent']);
Route::get('/carousel/{event}/slide/{slide}', [CarouselController::class, 'getPublicSingleSlide']);
Route::get('/plans/action-now/{planId}', [PlanActivityController::class, 'actionNow']); // optional: ?room=24&point_in_time=YYYY-MM-DD HH:mm
Route::get('/plans/action-next/{planId}', [PlanActivityController::class, 'actionNext']); // optional: ?room=24&interval=15&point_in_time=...
Route::get('/plans/{planId}/visitor/roles', [PublicPlanController::class, 'roles']); // Public role picker for interactive plan
Route::get('/plans/{planId}/visitor/schedule', [PublicPlanController::class, 'schedule']); // Public role-filtered schedule
Route::get('/events/slug/{slug}', [EventController::class, 'getEventBySlug']); // Public event lookup by slug
Route::get('/events/public/{id}', [EventController::class, 'getPublicEventById']); // Public event lookup by id
Route::get('/events/{event}/team-coordinates', [DrahtController::class, 'getTeamsCoordinates']);
Route::get('/events', [EventController::class, 'index']); // Get list of current events
Route::get('/programs', [ProgramController::class, 'index']); // Catalog from m_first_program
Route::get('/publish/public-information/{eventId}', [PublishController::class, 'scheduleInformation']); // Public publication information
Route::get('/plans/public/{eventId}', [PlanController::class, 'getOrCreatePlanForEvent']); // Public plan lookup by event ID
Route::get('/events/{eventId}/logos', [LogoController::class, 'getEventLogos']); // Public logos for event
Route::get('/geocode', [EventController::class, 'geocodeAddress']); // Public geocoding endpoint
Route::post('/one-link-access', [PublishController::class, 'logOneLinkAccess']); // Public one-link access logging
Route::get('/calendar.ics', [CalendarFeedController::class, 'all']); // Public ICS subscription (all events in window)
Route::get('/calendar/{postfix}.ics', [CalendarFeedController::class, 'postfix'])
    ->where('postfix', '[A-Za-z0-9_]+');

// Check-In reception (PIN session; public)
Route::prefix('check-in/{slug}')->group(function () {
    Route::get('/bootstrap', [CheckInController::class, 'bootstrap']);
    Route::post('/session', [CheckInController::class, 'openSession']);
    Route::get('/search', [CheckInController::class, 'search']);
    Route::get('/overview', [CheckInController::class, 'overview']);
    Route::get('/roster', [CheckInController::class, 'roster']);
    Route::get('/organizer', [CheckInController::class, 'organizerContact']);
    Route::get('/share', [CheckInController::class, 'share']);
    Route::get('/{subjectType}/{subjectId}', [CheckInController::class, 'show'])
        ->where('subjectType', 'team|volunteer');
    Route::post('/{subjectType}/{subjectId}/check-in', [CheckInController::class, 'checkIn'])
        ->where('subjectType', 'team|volunteer');
    Route::post('/{subjectType}/{subjectId}/no-show', [CheckInController::class, 'noShow'])
        ->where('subjectType', 'team|volunteer');
    Route::patch('/{subjectType}/{subjectId}/note', [CheckInController::class, 'updateNote'])
        ->where('subjectType', 'team|volunteer');
});

// Volunteer public data entry (email lookup + save; public; OTP token deferred)
Route::get('/public-volunteer-form/{slug}/lookup', [VolunteerPublicFormController::class, 'lookup']);
Route::post('/public-volunteer-form/{slug}/save', [VolunteerPublicFormController::class, 'save']);
Route::get('/public-team-form/{slug}/lookup', [TeamPublicFormController::class, 'lookup']);
Route::get('/public-team-form/{slug}/team/{team}', [TeamPublicFormController::class, 'team']);
Route::post('/public-team-form/{slug}/save', [TeamPublicFormController::class, 'save']);

// Cockpit app (PIN session; public)
Route::prefix('cockpit/{slug}')->group(function () {
    Route::get('/bootstrap', [CockpitController::class, 'bootstrap']);
    Route::post('/session', [CockpitController::class, 'openSession']);
    Route::get('/rounds', [CockpitController::class, 'getRounds']);
    Route::put('/rounds', [CockpitController::class, 'saveRounds']);
    Route::get('/organizer', [CockpitController::class, 'organizerContact']);
    Route::get('/phonebook', [CockpitController::class, 'phonebook']);
    Route::get('/timeshift/bootstrap', [CockpitController::class, 'timeshiftBootstrap']);
    Route::post('/timeshift/shift', [CockpitController::class, 'timeshiftShift']);
    Route::get('/stage-presentations/bootstrap', [CockpitController::class, 'stagePresentationsBootstrap']);
    Route::put('/stage-presentations/selection', [CockpitController::class, 'stagePresentationsSaveSelection']);
    Route::put('/stage-presentations/lock', [CockpitController::class, 'stagePresentationsSetLock']);
});

Route::prefix('contao')->group(function () {
    Route::get('/test', [ContaoController::class, 'testConnection']);
    Route::get('/score', [ContaoController::class, 'getScore']);
});

Route::middleware(['keycloak'])->group(function () {
    // For testing: manually trigger round write
    Route::put('/contao/write-rounds', [ContaoController::class, 'writeRoundsEndpoint']);

    Route::get('/environment', function () {
        return response()->json([
            'environment' => app()->environment(),
            'is_dev' => app()->environment('local'),
            'is_test' => app()->environment('staging', 'testing'),
            'is_prod' => app()->environment('production'),
        ]);
    });

    Route::get('/user', fn (Request $r) => $r->input('keycloak_user'));
    Route::get('/user/me', [UserAccessController::class, 'me']);
    Route::get('/user/access', [UserAccessController::class, 'overview']);
    Route::get('/user/access/events/{eventId}/users', [UserAccessController::class, 'eventUsers']);
    Route::get('/user/access/users', [UserAccessController::class, 'searchUsers']);
    Route::post('/user/access/grants', [UserAccessController::class, 'grant']);
    Route::delete('/user/access/grants', [UserAccessController::class, 'revoke']);
    Route::get('/user/regional-partners', function (Request $request) {
        $user = $request->user();
        if (! $user) {
            return response()->json(['regional_partners' => []]);
        }

        // Use fully qualified column names to avoid ambiguity with pivot table's 'id' column
        // The pivot table 'user_regional_partner' also has an 'id' column, so we need to specify the table
        $regionalPartners = $user->regionalPartners()
            ->select('regional_partner.id', 'regional_partner.name', 'regional_partner.region')
            ->get();

        return response()->json(['regional_partners' => $regionalPartners]);
    });
    Route::get('/user/selected-event', function (Request $request) {
        $user = $request->user();
        $eventId = $user?->selection_event;
        if (! $eventId) {
            return response()->json(['selected_event' => null]);
        }

        $event = Event::find($eventId);
        // Past seasons are allowed (view/switch via event modal). Only clear if missing or no access.
        if (! $event || ! $user->hasEventAccess($event->id)) {
            $user->selection_event = null;
            $user->selection_regional_partner = null;
            $user->save();

            return response()->json([
                'selected_event' => null,
                'cleared_stale_season' => true,
            ]);
        }

        $controller = new EventController;

        return $controller->getEvent($eventId);
    });

    Route::delete('/user/selected-event', function (Request $request) {
        $user = $request->user();
        $user->selection_event = null;
        $user->selection_regional_partner = null;
        $user->save();

        return response()->json(['status' => 'ok']);
    });

    Route::post('/user/select-event', function (Request $request) {
        $validated = $request->validate([
            'event' => 'required|integer|exists:event,id',
            'regional_partner' => 'required|integer|exists:regional_partner,id',
        ]);

        $user = $request->user();
        $event = Event::find($validated['event']);

        if (! $event) {
            return response()->json(['error' => 'Event not found'], 404);
        }

        if ((int) $event->regional_partner !== (int) $validated['regional_partner']) {
            return response()->json([
                'error' => 'regional_partner does not match the event',
            ], 422);
        }

        if (! $user->hasEventAccess($event->id)) {
            return response()->json(['error' => 'Forbidden - no access to this event'], 403);
        }

        $user->selection_event = $validated['event'];
        $user->selection_regional_partner = $validated['regional_partner'];
        $user->save();

        return response()->json(['status' => 'ok']);
    });

    // Plan controller (Basis-Funktionen)
    Route::prefix('plans')->group(function () {
        Route::post('/create', [PlanController::class, 'create']);
        Route::get('/event/{eventId}', [PlanController::class, 'getOrCreatePlanForEvent']);
        Route::patch('/{id}/lock', [PlanController::class, 'updateLock']);
        Route::post('/sync-team-plan/{eventId}', [PlanController::class, 'syncTeamPlanForEvent']);
        Route::delete('/{id}', [PlanController::class, 'delete']);
        Route::get('/{planId}/roles', [PlanPreviewController::class, 'planRoles']);
    });

    // Preview controller
    Route::prefix('plans/preview')->group(function () {
        Route::get('/{planId}/overview', [PlanPreviewController::class, 'previewOverview']);
        Route::get('/{planId}/roles-grid', [PlanPreviewController::class, 'previewRolesGrid']);
        Route::get('/{planId}/teams-grid', [PlanPreviewController::class, 'previewTeamsGrid']);
        Route::get('/{planId}/robot-game', [PlanPreviewController::class, 'previewRobotGame']);
        Route::get('/{planId}/activities', [PlanPreviewController::class, 'previewActivities']);
    });

    // PlanActivity controller
    Route::prefix('plans')->group(function () {
        Route::get('/{planId}/room-types', [PlanRoomTypeController::class, 'listRoomTypes']);
    });

    // Generator controller
    Route::prefix('plans')->group(function () {
        Route::post('/{planId}/generate', [PlanGeneratorController::class, 'generate']);
        Route::get('/{planId}/status', [PlanGeneratorController::class, 'status']);
        Route::post('/{planId}/generate-lite', [PlanGeneratorController::class, 'generateLite']);
    });

    // PlanExport controller
    Route::get('/export/pdf/{planId}', [PlanExportController::class, 'exportPdf']);

    // PlanParameter controller
    // Route::get('/plans/{id}/copy-default', [PlanParameterController::class, 'insertParamsFirst']);
    Route::get('/plans/{planId}/ceremony-times', [PlanCeremonyTimesController::class, 'show']);
    Route::get('/plans/{id}/parameters', [PlanParameterController::class, 'getParametersForPlan']);
    Route::get('/plans/{id}/non-default-parameters', [PlanParameterController::class, 'getNonDefaultParameter']);
    Route::post('/plans/{id}/parameters', [PlanParameterController::class, 'updateParameter']);

    // ExtraBlock controller
    Route::get('/plans/{id}/extra-blocks', [ExtraBlockController::class, 'getBlocksForPlan']);
    Route::post('/plans/{id}/extra-blocks', [ExtraBlockController::class, 'storeOrUpdate']);
    Route::delete('/extra-blocks/{id}', [ExtraBlockController::class, 'delete']);

    Route::get('/plans/{planId}/extra-blocks/slot', [ExtraBlockController::class, 'slotIndex']);
    Route::post('/plans/{planId}/extra-blocks/slot', [ExtraBlockController::class, 'slotStore']);
    Route::get('/plans/{planId}/extra-blocks/slot/{extraBlock}/teams', [ExtraBlockController::class, 'slotTeamAssignments']);
    Route::patch('/plans/{planId}/extra-blocks/slot/{extraBlock}/teams/{programId}/{teamNumberPlan}', [ExtraBlockController::class, 'slotUpdateTeamStart']);
    Route::get('/plans/{planId}/extra-blocks/slot/{extraBlock}/teams/{programId}/{teamNumberPlan}/activities', [ExtraBlockController::class, 'slotTeamActivities']);
    Route::put('/plans/{planId}/extra-blocks/slot/{extraBlock}', [ExtraBlockController::class, 'slotUpdate']);
    Route::delete('/plans/{planId}/extra-blocks/slot/{extraBlock}', [ExtraBlockController::class, 'slotDestroy']);

    // Event controller
    Route::get('/events/selectable', [EventController::class, 'getSelectableEvents']);
    Route::get('/events/create-data', [EventController::class, 'getCreateEventData']);
    Route::post('/events', [EventController::class, 'store']);
    Route::get('/events/{eventId}', [EventController::class, 'getEvent']);
    Route::put('/events/{eventId}', [EventController::class, 'update']);
    Route::post('/events/{eventId}/check-attention', [EventController::class, 'checkAttention']);
    Route::get('/table-names/{eventId}', [EventController::class, 'getTableNames']);
    Route::put('/table-names/{eventId}', [EventController::class, 'updateTableNames']);

    // Carousel controller
    Route::get('/slides/{slide}', [CarouselController::class, 'getSlide']);
    Route::put('/slides/{slide}', [CarouselController::class, 'updateSlide']);
    Route::delete('/slides/{slide}', [CarouselController::class, 'deleteSlide']);
    Route::get('/slideshow/{event}', [CarouselController::class, 'getAllSlideshows']);
    Route::put('/slideshow/{slideshow}/updateOrder', [CarouselController::class, 'updateSlideshowOrder']);
    Route::put('/slideshow/{slideshow}', [CarouselController::class, 'updateSlideshow']);
    Route::delete('/slideshow/{slideshow}', [CarouselController::class, 'deleteSlideshow']);
    Route::put('/slideshow/{slideshow}/add', [CarouselController::class, 'addSlide']);
    Route::post('/slideshow/{event}', [CarouselController::class, 'generateSlideshow']);

    // Update public rounds (only authenticated access)
    Route::get('/contao/rounds/{eventId}', [ContaoController::class, 'getRoundsToShowEndpoint']);
    Route::put('/contao/rounds/{eventId}', [ContaoController::class, 'saveRoundsToShow']);

    // Team controller
    Route::post('/events/{event}/teams/sync', [TeamController::class, 'sync']);
    Route::get('/events/{event}/teams/people/export', [TeamController::class, 'exportPeople']);
    Route::get('/events/{event}/teams', [TeamController::class, 'index']);
    Route::put('/events/{event}/teams', [TeamController::class, 'update']);
    Route::post('/events/{event}/teams/update-order', [TeamController::class, 'updateOrder']);
    Route::delete('/teams/{team}', [TeamController::class, 'destroy']);

    Route::get('/events/{event}/team-fields', [EventTeamFieldController::class, 'index']);
    Route::post('/events/{event}/team-fields', [EventTeamFieldController::class, 'store']);
    Route::put('/events/{event}/team-fields/public-form', [EventTeamFieldController::class, 'replacePublicForm']);
    Route::put('/events/{event}/team-fields/check-in-show', [EventTeamFieldController::class, 'replaceCheckInShow']);
    Route::patch('/events/{event}/team-fields/{field}', [EventTeamFieldController::class, 'update']);
    Route::delete('/events/{event}/team-fields/{field}', [EventTeamFieldController::class, 'destroy']);
    Route::get('/events/{event}/team-data', [EventTeamDataController::class, 'index']);
    Route::patch('/events/{event}/teams/{team}/team-data', [EventTeamDataController::class, 'update']);
    Route::get('/events/{event}/team-data/export', [EventTeamDataController::class, 'exportXlsx']);

    // Volunteer staffing (pool + roster)
    Route::get('/events/{event}/volunteers', [VolunteerPersonController::class, 'index']);
    Route::post('/events/{event}/volunteers', [VolunteerPersonController::class, 'store']);
    Route::post('/events/{event}/volunteers/import', [VolunteerPersonController::class, 'import']);
    Route::get('/events/{event}/volunteers/export', [VolunteerPersonController::class, 'exportXlsx']);
    Route::put('/volunteers/{volunteer}', [VolunteerPersonController::class, 'update']);
    Route::delete('/volunteers/{volunteer}', [VolunteerPersonController::class, 'destroy']);
    Route::get('/events/{event}/volunteer-fields', [EventVolunteerFieldController::class, 'index']);
    Route::post('/events/{event}/volunteer-fields', [EventVolunteerFieldController::class, 'store']);
    Route::put('/events/{event}/volunteer-fields/public-form', [EventVolunteerFieldController::class, 'replacePublicForm']);
    Route::put('/events/{event}/volunteer-fields/check-in-show', [EventVolunteerFieldController::class, 'replaceCheckInShow']);
    Route::patch('/events/{event}/volunteer-fields/{field}', [EventVolunteerFieldController::class, 'update']);
    Route::delete('/events/{event}/volunteer-fields/{field}', [EventVolunteerFieldController::class, 'destroy']);
    Route::get('/events/{event}/volunteer-meal-options', [EventVolunteerMealOptionController::class, 'index']);
    Route::put('/events/{event}/volunteer-meal-options', [EventVolunteerMealOptionController::class, 'replace']);
    Route::get('/events/{event}/volunteer-collect', [EventVolunteerCollectController::class, 'show']);
    Route::patch('/events/{event}/volunteer-collect', [EventVolunteerCollectController::class, 'update']);
    Route::get('/events/{event}/volunteer-roster', [EventVolunteerRosterController::class, 'index']);
    Route::get('/events/{event}/volunteer-roster/export', [EventVolunteerRosterController::class, 'exportXlsx']);
    Route::post('/events/{event}/volunteer-roster', [EventVolunteerRosterController::class, 'store']);
    Route::patch('/events/{event}/volunteer-roster/{volunteer}/detail', [EventVolunteerRosterController::class, 'updateDetail']);
    Route::patch('/events/{event}/volunteer-roster/{volunteer}/custom', [EventVolunteerRosterController::class, 'updateCustom']);
    Route::delete('/events/{event}/volunteer-roster/{volunteer}', [EventVolunteerRosterController::class, 'destroy']);
    Route::post('/events/{event}/ensure-workspace', [EventWorkspaceController::class, 'ensure']);
    Route::get('/events/{event}/staffing', [EventStaffingController::class, 'index']);
    Route::post('/events/{event}/staffing/sync', [EventStaffingController::class, 'sync']);
    Route::post('/events/{event}/staffing/groups/{group}/assignments', [EventStaffingAssignmentController::class, 'store']);
    Route::delete('/events/{event}/staffing/groups/{group}/assignments/{volunteer}', [EventStaffingAssignmentController::class, 'destroy']);
    Route::post('/events/{event}/staffing/local-roles', [EventStaffingAssignmentController::class, 'storeLocalRole']);
    Route::put('/events/{event}/staffing/local-roles/{role}', [EventStaffingAssignmentController::class, 'updateLocalRole']);
    Route::delete('/events/{event}/staffing/local-roles/{role}', [EventStaffingAssignmentController::class, 'destroyLocalRole']);

    Route::prefix('logos')->group(function () {
        Route::get('/', [LogoController::class, 'index']);
        Route::post('/', [LogoController::class, 'store']);
        Route::patch('/{logo}', [LogoController::class, 'update']);
        Route::delete('/{logo}', [LogoController::class, 'destroy']);
        Route::post('/{logo}/toggle-event', [LogoController::class, 'toggleEvent']);
        Route::post('/update-sort-order', [LogoController::class, 'updateSortOrder']);
    });

    Route::get('/events/{event}/rooms', [RoomController::class, 'index']);
    Route::get('/sharepoint/status', [SharepointController::class, 'status']);
    Route::get('/sharepoint/documents', [SharepointController::class, 'listDocuments']);
    Route::get('/sharepoint/documents-file-link', [SharepointController::class, 'getFileLink']);
    Route::get('/sharepoint/documents-file-stream', [SharepointController::class, 'streamFile']);

    Route::get('/events/{event}/draht-data', [DrahtController::class, 'show']);
    Route::get('/draht/people/{drahtEventId}', [DrahtController::class, 'getPeople']);
    Route::post('/rooms', [RoomController::class, 'store']);
    Route::put('/rooms/assign-types', [RoomController::class, 'assignRoomType']);
    Route::put('/rooms/assign-teams', [RoomController::class, 'assignTeam']);
    Route::put('/rooms/update-sequence', [RoomController::class, 'updateRoomSequence']);
    Route::put('/rooms/{room}', [RoomController::class, 'update']);
    Route::delete('/rooms/{room}', [RoomController::class, 'destroy']);

    Route::get('/room-types/{planId}', [PlanRoomTypeController::class, 'listRoomTypes']);

    Route::prefix('parameter')->group(function () {
        Route::get('/', [ParameterController::class, 'index']);
        Route::get('/condition', [ParameterController::class, 'listConditions']);
        Route::get('/lanes-options', [ParameterController::class, 'listLanesOptions']);
        Route::get('/afternoon-programs', [ParameterController::class, 'afternoonPrograms']);
        Route::post('/condition', [ParameterController::class, 'addCondition']);
        Route::put('/condition/{id}', [ParameterController::class, 'updateCondition']);
        Route::delete('/condition/{id}', [ParameterController::class, 'deleteCondition']);
    });
    Route::get('/plans/{planId}/afternoon/blocks', [AfternoonController::class, 'blocks']);
    Route::put('/plans/{planId}/afternoon/blocks', [AfternoonController::class, 'updateOrder']);

    Route::prefix('mparams')->group(function () {
        Route::get('/', [MParameterController::class, 'listMparameter']);
        Route::post('/reorder', [MParameterController::class, 'reorderMparameter']);  // !!! Reihenfolge in dieser Liste ist wichtig
        Route::post('/{id}', [MParameterController::class, 'updateMparameter']);
    });

    Route::prefix('admin/user-regional-partners')->group(function () {
        Route::get('/', [UserRegionalPartnerController::class, 'index']);
        Route::get('/statistics', [UserRegionalPartnerController::class, 'statistics']);
        Route::get('/selection-data', [UserRegionalPartnerController::class, 'getSelectionData']);
        Route::post('/', [UserRegionalPartnerController::class, 'store']);
        Route::delete('/', [UserRegionalPartnerController::class, 'destroy']);
    });

    Route::prefix('admin/plan-quality')->group(function () {
        Route::get('/events', [PlanQualityController::class, 'listEvents']);
        Route::post('/evaluate/{planId}', [PlanQualityController::class, 'evaluatePlan']);
    });

    Route::prefix('admin/main-tables')->group(function () {
        Route::get('/', [MainTablesController::class, 'index']);
        Route::get('/export', [MainTablesController::class, 'export']);
        Route::post('/create-pr', [MainTablesController::class, 'createPR']);
        Route::get('/{table}/schema', [MainTablesController::class, 'schema']);
        Route::get('/{table}/count', [MainTablesController::class, 'getCount']);
        Route::get('/{table}/columns', [MainTablesController::class, 'getTableColumns']);
        Route::get('/{table}', [MainTablesController::class, 'getTableData']);
        Route::post('/{table}', [MainTablesController::class, 'store']);
        Route::put('/{table}/{id}', [MainTablesController::class, 'update']);
        Route::delete('/{table}/{id}', [MainTablesController::class, 'destroy']);
    });

    Route::get('/seasons', function () {
        $seasons = DB::table('m_season')
            ->select('id', 'name', 'year')
            ->orderBy('year', 'desc')
            ->get()
            ->toArray(); // Convert Collection to array

        return response()->json($seasons);
    });
    Route::get('/current-season', function () {
        return response()->json(['id' => SeasonService::currentSeasonId()]);
    });

    Route::get('/draht/events/{eventId}', [DrahtController::class, 'show']);
    Route::get('/admin/draht/sync-draht-regions', [DrahtController::class, 'getAllRegions']);
    Route::get('/admin/draht/sync-draht-events/{seasonId}', [DrahtController::class, 'getAllEventsAndTeams']);

    Route::prefix('admin/calendar')->group(function () {
        Route::get('/feeds', [CalendarFeedController::class, 'feeds']);
        Route::post('/rebuild', [CalendarFeedController::class, 'rebuildWindow']);
        Route::get('/feeds/{key}', [CalendarFeedController::class, 'preview'])
            ->where('key', '[A-Za-z0-9_]+');
    });

    Route::prefix('publish')->group(function () {
        Route::get('/link/{eventId}', [PublishController::class, 'linkAndQRcode']);      // Link und QR-Code holen, ggfs. generieren
        Route::post('/regenerate/{eventId}', [PublishController::class, 'regenerateLinkAndQRcode']); // Link und QR-Code neu generieren (Admin)
        Route::post('/regenerate-season/{seasonId}', [PublishController::class, 'regenerateLinksForSeason']); // Links für alle Events einer Saison regenerieren (Admin)
        Route::post('/information/{eventId}', [PublishController::class, 'scheduleInformation']); // Infos nach Aussen
        Route::get('/level/{eventId}', [PublishController::class, 'getPublicationLevel']);
        Route::post('/level/{eventId}', [PublishController::class, 'setPublicationLevel']);
        Route::get('/helper-search/{eventId}', [PublishController::class, 'getPublicHelperSearch']);
        Route::post('/helper-search/{eventId}', [PublishController::class, 'setPublicHelperSearch']);
        Route::get('/volunteer-data-entry/{eventId}', [PublishController::class, 'getPublicVolunteerDataEntry']);
        Route::post('/volunteer-data-entry/{eventId}', [PublishController::class, 'setPublicVolunteerDataEntry']);
        Route::get('/team-data-entry/{eventId}', [PublishController::class, 'getPublicTeamDataEntry']);
        Route::post('/team-data-entry/{eventId}', [PublishController::class, 'setPublicTeamDataEntry']);
        Route::get('/pdf_download/{type}/{eventId}', [PublishController::class, 'download']);
        Route::get('/pdf_preview/{type}/{eventId}', [PublishController::class, 'preview']);
    });

    Route::prefix('events/{event}/check-in')->group(function () {
        Route::get('/settings', [CheckInController::class, 'getSettings']);
        Route::put('/settings', [CheckInController::class, 'updateSettings']);
        Route::post('/reset', [CheckInController::class, 'reset']);
    });

    Route::prefix('events/{event}/cockpit')->group(function () {
        Route::get('/settings', [CockpitController::class, 'getSettings']);
        Route::put('/settings', [CockpitController::class, 'updateSettings']);
        Route::post('/reset', [CockpitController::class, 'reset']);
    });

    Route::prefix('export')->group(function () {
        Route::get('/pdf_preview/{eventId}', [PublishController::class, 'preview']);    // PDF mit Vorschau holen
        Route::get('/ready/{eventId}', [PlanExportController::class, 'dataReadiness']);
        Route::get('/available-roles/{eventId}', [PlanExportController::class, 'availableRoles']);
        Route::get('/available-team-programs/{eventId}', [PlanExportController::class, 'availableTeamPrograms']);
        Route::get('/match-teams/{planId}/{round}', [PlanExportController::class, 'matchTeams']);
        Route::get('/match-plan/{planId}', [PlanExportController::class, 'matchPlanPdf']);
        Route::get('/moderator-match-plan/{planId}', [PlanExportController::class, 'moderatorMatchPlanPdf']);
        Route::get('/slot-assignments/{planId}', [PlanExportController::class, 'slotAssignmentsPdf']);
        Route::get('/team-list/{planId}', [PlanExportController::class, 'teamListPdf']);
        Route::get('/event-overview/{planId}', [PlanExportController::class, 'eventOverviewPdf']);
        Route::match(['get', 'post'], '/pdf_download/{type}/{eventId}', [PlanExportController::class, 'download']);
        Route::get('/worker-shifts/{eventId}', [PlanExportController::class, 'workerShifts']);
        Route::get('/csv/room-utilization/{eventId}', [PlanExportController::class, 'roomUtilizationCsv']);
        Route::match(['get', 'post'], '/name-tags/{eventId}', [LabelController::class, 'nameTagsPdf']);
        Route::post('/volunteer-labels/{eventId}', [LabelController::class, 'volunteerLabelsPdf']);
    });

    // Quality controller
    Route::prefix('quality')->group(function () {
        Route::post('/qrun', [QualityController::class, 'startQRun']);                   // Start eines neuen Runs
        Route::get('/qruns', [QualityController::class, 'listQRuns']);                    // Alle Runs auflisten
        Route::get('/qplans/{qRunId}', [QualityController::class, 'listQPlans']);          // Alle Pläne zu einem Run
        Route::get('/details/{qPlanId}', [QualityController::class, 'getQPlanDetails']);  // Einzelplan-Details (by QPlan ID)
        Route::get('/details-by-plan/{planId}', [QualityController::class, 'getQPlanDetailsByPlan']); // Details mit Auto-Generierung (by Plan ID)
        Route::post('/rerun', [QualityController::class, 'rerunQPlans']);
        Route::delete('/delete/{qRunId}', [QualityController::class, 'deleteQRun']);        // Löschen eines Runs und aller zugehörigen Pläne
        Route::delete('/preview-runs', [QualityController::class, 'deletePreviewQRuns']); // Preview/ReRun (selection null), ohne Event-Pläne
        // compress endpoint removed (no longer needed)
    });

    // Statistic controller
    Route::get('/stats/one-link-access', [StatisticController::class, 'oneLinkAccess']);
    Route::get('/stats/one-link-access/{eventId}', [StatisticController::class, 'oneLinkAccessChart']);
    Route::prefix('stats')->group(function () {
        Route::get('/plans', [StatisticController::class, 'listPlans']);                  // Liste aller Pläne mit Events und Partnern
        Route::get('/totals', [StatisticController::class, 'totals']);                  // Summen
        Route::get('/draht-check/{eventId}', [StatisticController::class, 'checkDrahtIssue']); // Check DRAHT for single event
        Route::delete('/orphans/{type}/cleanup', [StatisticController::class, 'cleanupOrphans']);
        Route::get('/timeline/{planId}', [StatisticController::class, 'timeline']);      // Timeline data for a plan
        Route::get('/extra-blocks/{planId}', [StatisticController::class, 'getExtraBlocksDetails']); // Extra blocks details for a plan
    });

    // Visibility controller
    Route::prefix('visibility')->group(function () {
        Route::get('/roles', [VisibilityController::class, 'getRoles']);
        Route::get('/activity-types', [VisibilityController::class, 'getActivityTypes']);
        Route::get('/activity-type-categories', [VisibilityController::class, 'getActivityTypeCategories']);
        Route::get('/matrix', [VisibilityController::class, 'getMatrix']);
        Route::post('/toggle', [VisibilityController::class, 'toggleVisibility']);
        Route::post('/bulk-toggle', [VisibilityController::class, 'bulkToggle']);
    });

    // News controller
    Route::prefix('news')->group(function () {
        Route::get('/unread', [NewsController::class, 'getUnreadNews']);
        Route::post('/{id}/mark-read', [NewsController::class, 'markAsRead']);
    });

    // Admin news routes (protected by api/admin path check in middleware)
    Route::prefix('admin/news')->group(function () {
        Route::get('/', [NewsController::class, 'index']);
        Route::post('/', [NewsController::class, 'store']);
        Route::delete('/{id}', [NewsController::class, 'destroy']);
        Route::get('/{id}/stats', [NewsController::class, 'stats']);
    });

    // Admin external API routes (applications and API keys)
    Route::prefix('admin/applications')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\ApplicationController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\ApplicationController::class, 'store']);
        Route::get('/{id}', [\App\Http\Controllers\Api\ApplicationController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\Api\ApplicationController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\ApplicationController::class, 'destroy']);

        // API key management
        Route::post('/{applicationId}/api-keys', [\App\Http\Controllers\Api\ApplicationController::class, 'createApiKey']);
        Route::put('/{applicationId}/api-keys/{apiKeyId}', [\App\Http\Controllers\Api\ApplicationController::class, 'updateApiKey']);
        Route::delete('/{applicationId}/api-keys/{apiKeyId}', [\App\Http\Controllers\Api\ApplicationController::class, 'deleteApiKey']);
    });

    // Admin helper functions routes
    Route::prefix('admin/helpers')->group(function () {
        Route::post('/logos/cleanup-orphaned', [LogoController::class, 'cleanupOrphanedLogos']); // Admin: Clean up orphaned logos
    });

    Route::prefix('admin/sharepoint')->group(function () {
        Route::get('/', [SharepointController::class, 'getAdminConfig']);
        Route::put('/', [SharepointController::class, 'updateAdminConfig']);
        Route::post('/test', [SharepointController::class, 'testConnection']);
    });
});
