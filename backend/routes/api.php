<?php

use App\Http\Controllers\Api\CarouselController;
use App\Http\Controllers\Api\ContaoController;
use App\Http\Controllers\Api\DrahtController;
use App\Http\Controllers\Api\DrahtSimulatorController;
use App\Http\Controllers\Api\EventController;
use App\Models\Event;
use App\Http\Controllers\Api\ExtraBlockController;
use App\Http\Controllers\Api\LogoController;
use App\Http\Controllers\Api\ParameterController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\PlanGeneratorController;
use App\Http\Controllers\Api\PlanPreviewController;
use App\Http\Controllers\Api\PlanActivityController;
use App\Http\Controllers\Api\PlanRoomTypeController;
use App\Http\Controllers\Api\MParameterController;
use App\Http\Controllers\Api\PlanParameterController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\StatisticController;
use App\Http\Controllers\Api\UserRegionalPartnerController;
use App\Http\Controllers\Api\MainTablesController;
use App\Http\Controllers\Api\QualityController;
use App\Http\Controllers\Api\PublishController;
use App\Http\Controllers\Api\PlanExportController;
use App\Http\Controllers\Api\VisibilityController;
use App\Http\Controllers\Api\NewsController;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn() => ['pong' => true]);


Route::get('/profile', function (Illuminate\Http\Request $request) {
    return response()->json([
        'user' => $request->get('jwt'),
    ]);
});


// Public routes (no authentication required)
Route::get('/carousel/{event}/slideshows', [CarouselController::class, 'getPublicSlideshowForEvent']);
Route::get('/plans/action-now/{planId}', [PlanActivityController::class, 'actionNow']); // optional: ?point_in_time=YYYY-MM-DD HH:mm
Route::get('/plans/action-next/{planId}', [PlanActivityController::class, 'actionNext']); // optional: ?interval=15&point_in_time=...
Route::get('/events/slug/{slug}', [EventController::class, 'getEventBySlug']); // Public event lookup by slug
Route::get('/publish/public-information/{eventId}', [PublishController::class, 'scheduleInformation']); // Public publication information
Route::get('/plans/public/{eventId}', [PlanController::class, 'getOrCreatePlanForEvent']); // Public plan lookup by event ID

// Draht API Simulator (for test environment)
if (app()->environment('local', 'staging')) {
    Route::any('/draht-simulator/{path?}', [DrahtSimulatorController::class, 'handle'])->where('path', '.*');
}

Route::prefix('contao')->group(function () {
    Route::get('/test', [ContaoController::class, 'testConnection']);
    Route::get('/score', [ContaoController::class, 'getScore']);
});

Route::middleware(['keycloak'])->group(function () {
    Route::get('/user', fn(Request $r) => $r->input('keycloak_user'));
    Route::get('/user/selected-event', function (Request $request) {
        $eventId = $request->user()?->selection_event;
        Log::info($eventId);
        if (!$eventId) {
            return response()->json(['selected_event' => null]);
        }

        $controller = new EventController();
        return $controller->getEvent($eventId);
    });

    Route::post('/user/select-event', function (Request $request) {
        $validated = $request->validate([
            'event' => 'required|integer|exists:event,id',
            'regional_partner' => 'required|integer|exists:regional_partner,id',
        ]);

        $user = $request->user();
        $user->selection_event = $validated['event'];
        $user->selection_regional_partner = $validated['regional_partner'];
        $user->save();

        return response()->json(['status' => 'ok']);
    });

    // Plan controller (Basis-Funktionen)
    Route::prefix('plans')->group(function () {
        Route::post('/create', [PlanController::class, 'create']);
        Route::get('/event/{eventId}', [PlanController::class, 'getOrCreatePlanForEvent']);
        Route::post('/sync-team-plan/{eventId}', [PlanController::class, 'syncTeamPlanForEvent']);
        Route::delete('/{id}', [PlanController::class, 'delete']);
    });

    // Preview controller
    Route::prefix('plans/preview')->group(function () {
        Route::get('/{planId}/overview', [PlanPreviewController::class, 'previewOverview']);
        Route::get('/{planId}/roles', [PlanPreviewController::class, 'previewRoles']);
        Route::get('/{planId}/teams', [PlanPreviewController::class, 'previewTeams']);
        Route::get('/{planId}/rooms', [PlanPreviewController::class, 'previewRooms']);
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
    Route::get('/plans/{id}/parameters', [PlanParameterController::class, 'getParametersForPlan']);
    Route::post('/plans/{id}/parameters', [PlanParameterController::class, 'updateParameter']);


    // ExtraBlock controller
    Route::get('/plans/{id}/extra-blocks', [ExtraBlockController::class, 'getBlocksForPlan']);
    // Route::get('/plans/{id}/extra-blocks-with-room-types', [ExtraBlockController::class, 'getBlocksForPlanWithRoomTypes']); kann weg Thomas 2024-10-07
    Route::post('/plans/{id}/extra-blocks', [ExtraBlockController::class, 'storeOrUpdate']);
    Route::get('/insert-points', [ExtraBlockController::class, 'getInsertPoints']);
    Route::delete('/extra-blocks/{id}', [ExtraBlockController::class, 'delete']);

    // Event controller
    Route::get('/events/selectable', [EventController::class, 'getSelectableEvents']);
    Route::get('/events/create-data', [EventController::class, 'getCreateEventData']);
    Route::post('/events', [EventController::class, 'store']);
    Route::get('/events/{eventId}', [EventController::class, 'getEvent']);
    Route::put('/events/{eventId}', [EventController::class, 'update']);
    Route::get('/table-names/{eventId}', [EventController::class, 'getTableNames']);
    Route::put('/table-names/{eventId}', [EventController::class, 'updateTableNames']);

    // Carousel controller
    Route::get('/slides/{slide}', [CarouselController::class, 'getSlide']);
    Route::put('/slides/{slide}', [CarouselController::class, 'updateSlide']);
    Route::delete('/slides/{slide}', [CarouselController::class, 'deleteSlide']);
    Route::get('/slideshow/{event}', [CarouselController::class, 'getAllSlideshows']);
    Route::put('/slideshow/{slideshow}/updateOrder', [CarouselController::class, 'updateSlideshowOrder']);
    Route::put('/slideshow/{slideshow}', [CarouselController::class, 'updateSlideshow']);
    Route::put('/slideshow/{slideshow}/add', [CarouselController::class, 'addSlide']);
    Route::post('/slideshow/{event}', [CarouselController::class, 'generateSlideshow']);

    // Team controller
    Route::get('/events/{event}/teams', [TeamController::class, 'index']);
    Route::put('/events/{event}/teams', [TeamController::class, 'update']);
    Route::post('/events/{event}/teams/update-order', [TeamController::class, 'updateOrder']);

    Route::prefix('logos')->group(function () {
        Route::get('/', [LogoController::class, 'index']);
        Route::post('/', [LogoController::class, 'store']);
        Route::patch('/{logo}', [LogoController::class, 'update']);
        Route::delete('/{logo}', [LogoController::class, 'destroy']);
        Route::post('/{logo}/toggle-event', [LogoController::class, 'toggleEvent']);
        Route::post('/update-sort-order', [LogoController::class, 'updateSortOrder']);
    });


    Route::get('/events/{event}/rooms', [RoomController::class, 'index']);
    Route::get('/events/{event}/draht-data', [DrahtController::class, 'show']);
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
        Route::post('/condition', [ParameterController::class, 'addCondition']);
        Route::put('/condition/{id}', [ParameterController::class, 'updateCondition']);
        Route::delete('/condition/{id}', [ParameterController::class, 'deleteCondition']);
    });
    Route::get('/parameters/visibility', [ParameterController::class, 'visibility']);

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

    Route::prefix('admin/main-tables')->group(function () {
        Route::get('/', [MainTablesController::class, 'index']);
        Route::get('/export', [MainTablesController::class, 'export']);
        Route::post('/create-pr', [MainTablesController::class, 'createPR']);
        Route::post('/import', [MainTablesController::class, 'import']);
        Route::get('/{table}', [MainTablesController::class, 'getTableData']);
        Route::get('/{table}/count', [MainTablesController::class, 'getCount']);
        Route::get('/{table}/columns', [MainTablesController::class, 'getTableColumns']);
        Route::post('/{table}', [MainTablesController::class, 'store']);
        Route::put('/{table}/{id}', [MainTablesController::class, 'update']);
        Route::delete('/{table}/{id}', [MainTablesController::class, 'destroy']);
    });

    Route::get('/draht/events/{eventId}', [DrahtController::class, 'show']);
    Route::get('/admin/draht/sync-draht-regions', [DrahtController::class, 'getAllRegions']);
    Route::get('/admin/draht/sync-draht-events/{seasonId}', [DrahtController::class, 'getAllEventsAndTeams']);

    Route::prefix('publish')->group(function () {
        Route::get('/link/{eventId}', [PublishController::class, 'linkAndQRcode']);      // Link und QR-Code holen, ggfs. generieren
        Route::post('/regenerate/{eventId}', [PublishController::class, 'regenerateLinkAndQRcode']); // Link und QR-Code neu generieren (Admin)
        Route::post('/information/{eventId}', [PublishController::class, 'scheduleInformation']); // Infos nach Aussen
        Route::get('/level/{eventId}', [PublishController::class, 'getPublicationLevel']);
        Route::post('/level/{eventId}', [PublishController::class, 'setPublicationLevel']);
        Route::get('/times/{planId}', [PublishController::class, 'importantTimes']); // Wichtige Zeiten für Aussenkommunikation
        Route::get('/pdf_download/{type}/{eventId}', [PublishController::class, 'download']);
        Route::get('/pdf_preview/{type}/{eventId}', [PublishController::class, 'preview']);
    });

    Route::prefix('export')->group(function () {
        Route::get('/pdf_preview/{eventId}', [PublishController::class, 'preview']);    // PDF mit Vorschau holen
        Route::match(['get', 'post'], '/pdf_download/{type}/{eventId}', [PlanExportController::class, 'download']);
        Route::get('/ready/{eventId}', [PlanExportController::class, 'dataReadiness']);
        Route::get('/available-roles/{eventId}', [PlanExportController::class, 'availableRoles']);
        Route::get('/available-team-programs/{eventId}', [PlanExportController::class, 'availableTeamPrograms']);
        Route::get('/event-overview/{planId}', [PlanExportController::class, 'eventOverviewPdf']);
        Route::get('/worker-shifts/{eventId}', [PlanExportController::class, 'workerShifts']);
    });


    // Quality controller
    Route::prefix('quality')->group(function () {
        Route::post('/qrun', [QualityController::class, 'startQRun']);                   // Start eines neuen Runs
        Route::get('/qruns', [QualityController::class, 'listQRuns']);                    // Alle Runs auflisten
        Route::get('/qplans/{qRunId}', [QualityController::class, 'listQPlans']);          // Alle Pläne zu einem Run
        Route::get('/details/{qPlanId}', [QualityController::class, 'getQPlanDetails']);  // Einzelplan-Details
        Route::post('/rerun', [QualityController::class, 'rerunQPlans']);
        Route::delete('/delete/{qRunId}', [QualityController::class, 'deleteQRun']);        // Löschen eines Runs und aller zugehörigen Pläne
        // compress endpoint removed (no longer needed)
    });

    // Statistic controller
    Route::prefix('stats')->group(function () {
        Route::get('/plans', [StatisticController::class, 'listPlans']);                  // Liste aller Pläne mit Events und Partnern
        Route::get('/totals', [StatisticController::class, 'totals']);                  // Summen
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
});
