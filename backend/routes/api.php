<?php

use App\Http\Controllers\Api\CarouselController;
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
use App\Http\Controllers\Api\QualityController;
use App\Http\Controllers\Api\PublishController;
use App\Http\Controllers\Api\PlanExportController;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn() => ['pong' => true]);




Route::get('/profile', function (Illuminate\Http\Request $request) {
    return response()->json([
        'user' => $request->get('jwt'),
    ]);
});

// Public Carousel route
Route::get('/carousel/{event}/slideshows', [CarouselController::class, 'getPublicSlideshowForEvent']);
Route::get('/plans/action-now/{planId}', [PlanActivityController::class, 'actionNow']); // optional: ?point_in_time=YYYY-MM-DD HH:mm
Route::get('/plans/action-next/{planId}', [PlanActivityController::class, 'actionNext']); // optional: ?interval=15&point_in_time=...

// Draht API Simulator (for test environment)
if (app()->environment('local', 'staging')) {
    Route::any('/draht-simulator/{path?}', [DrahtSimulatorController::class, 'handle'])->where('path', '.*');
}

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
        Route::get('/{planId}/roles', [PlanPreviewController::class, 'previewRoles']);
        Route::get('/{planId}/teams', [PlanPreviewController::class, 'previewTeams']);
        Route::get('/{planId}/rooms', [PlanPreviewController::class, 'previewRooms']);
    });

    // PlanActivity controller
    Route::prefix('plans')->group(function () {
        Route::get('/activities/{planId}', [PlanActivityController::class, 'activities']);
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
    Route::get('/plans/{id}/extra-blocks-with-room-types', [ExtraBlockController::class, 'getBlocksForPlanWithRoomTypes']);
    Route::post('/plans/{id}/extra-blocks', [ExtraBlockController::class, 'storeOrUpdate']);
    Route::get('/insert-points', [ExtraBlockController::class, 'getInsertPoints']);
    Route::delete('/extra-blocks/{id}', [ExtraBlockController::class, 'delete']);

    // Event controller
    Route::get('/events/selectable', [EventController::class, 'getSelectableEvents']);
    Route::get('/events/{event}', [EventController::class, 'getEvent']);
    Route::put('/events/{event}', [EventController::class, 'update']);
    Route::get('/events/{event}/table-names', [EventController::class, 'getTableNames']);
    Route::put('/events/{id}/table-names', [EventController::class, 'updateTableNames']);

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

    // Logo controller
    Route::get('/logos', [LogoController::class, 'index']);
    Route::post('/logos', [LogoController::class, 'store']);
    Route::patch('/logos/{logo}', [LogoController::class, 'update']);
    Route::delete('/logos/{logo}', [LogoController::class, 'destroy']);
    Route::post('/logos/{logo}/toggle-event', [LogoController::class, 'toggleEvent']);
    Route::post('/logos/update-sort-order', [LogoController::class, 'updateSortOrder']);

    // Room controller
    Route::get('/events/{event}/rooms', [RoomController::class, 'index']);
    Route::get('/events/{event}/draht-data', [DrahtController::class, 'show']);
    Route::post('/rooms', [RoomController::class, 'store']);
    Route::put('/rooms/assign-types', [RoomController::class, 'assignRoomType']);
    Route::put('/rooms/{room}', [RoomController::class, 'update']);
    Route::delete('/rooms/{room}', [RoomController::class, 'destroy']);

    // Parameter controller
    Route::get('/parameter', [ParameterController::class, 'index']);
    Route::get('/parameter/condition', [ParameterController::class, 'listConditions']);
    Route::get('/parameter/lanes-options', [ParameterController::class, 'listLanesOptions']);
    Route::post('/parameter/condition', [ParameterController::class, 'addCondition']);
    Route::put('/parameter/condition/{id}', [ParameterController::class, 'updateCondition']);
    Route::delete('/parameter/condition/{id}', [ParameterController::class, 'deleteCondition']);
    Route::get('/parameters/visibility', [ParameterController::class, 'visibility']);

    Route::prefix('mparams')->group(function () {
        Route::get('/', [MParameterController::class, 'listMparameter']);
        Route::post('/reorder', [MParameterController::class, 'reorderMparameter']);  // !!! Reihenfolge in dieser Liste ist wichtig
        Route::post('/{id}', [MParameterController::class, 'updateMparameter']);
    });

    // User-Regional Partner relations admin routes
    Route::prefix('admin/user-regional-partners')->group(function () {
        Route::get('/', [UserRegionalPartnerController::class, 'index']);
        Route::get('/statistics', [UserRegionalPartnerController::class, 'statistics']);
        Route::get('/selection-data', [UserRegionalPartnerController::class, 'getSelectionData']);
        Route::post('/', [UserRegionalPartnerController::class, 'store']);
        Route::delete('/', [UserRegionalPartnerController::class, 'destroy']);
    });

    // DRAHT controller
    Route::get('/draht/events/{eventId}', [DrahtController::class, 'show']);
    
    // DRAHT admin routes
    Route::get('/admin/draht/sync-draht-regions', [DrahtController::class, 'getAllRegions']);
    Route::get('/admin/draht/sync-draht-events/{seasonId}', [DrahtController::class, 'getAllEventsAndTeams']);

    // Publish controller
    Route::prefix('publish')->group(function () {
        Route::get('/link/{planId}', [PublishController::class, 'linkAndQRcode']);      // Link und QR-Code holen, ggfs. generieren
        Route::get('/pdf/{planId}', [PublishController::class, 'PDFandPreview']);    // PDF mit Vorschau holen
        Route::post('/information/{eventId}', [PublishController::class, 'scheduleInformation']); // Infos nach Aussen   
        Route::get('/level/{eventId}', [PublishController::class, 'getPublicationLevel']);
        Route::post('/level/{eventId}', [PublishController::class, 'setPublicationLevel']);
        Route::get('/times/{planId}', [PublishController::class, 'importantTimes']); // Wichtige Zeiten für Aussenkommunikation
    });

    // Quality controller
    Route::prefix('quality')->group(function () {
        Route::post('/qrun', [QualityController::class, 'startQRun']);                   // Start eines neuen Runs
        Route::get('/qruns', [QualityController::class, 'listQRuns']);                    // Alle Runs auflisten
        Route::get('/qplans/{qRunId}', [QualityController::class, 'listQPlans']);          // Alle Pläne zu einem Run
        Route::get('/details/{qPlanId}', [QualityController::class, 'getQPlanDetails']);  // Einzelplan-Details
        Route::post('/rerun', [QualityController::class, 'rerunQPlans']);
        Route::delete('/delete/{qRunId}', [QualityController::class, 'deleteQRun']);        // Löschen eines Runs und aller zugehörigen Pläne
        Route::delete('/compress/{qRunId}', [QualityController::class, 'compressQRun']);    // Löschen nur der zugehörigen Pläne
    });

    // Statistic controller
    Route::prefix('stats')->group(function () {
        Route::get('/plans', [StatisticController::class, 'listPlans']);                  // Liste aller Pläne mit Events und Partnern
        Route::get('/totals', [StatisticController::class, 'totals']);                  // Summen
    });

});
