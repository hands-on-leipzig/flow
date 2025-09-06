<?php

use App\Http\Controllers\Api\DrahtController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\ExtraBlockController;
use App\Http\Controllers\Api\LogoController;
use App\Http\Controllers\Api\ParameterController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\MParameterController;
use App\Http\Controllers\Api\PlanParameterController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\StatisticController;
use App\Http\Controllers\Api\QualityController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn() => ['pong' => true]);

Route::get('/profile', function (Illuminate\Http\Request $request) {
    return response()->json([
        'user' => $request->get('jwt'),
    ]);
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

    // Plan controller
    Route::prefix('plans')->group(function () { 
        Route::post('/create', [PlanController::class, 'create']);
        Route::get('/event/{eventId}', [PlanController::class, 'getOrCreatePlanForEvent']); 
        Route::get('/preview/{planId}/roles', [PlanController::class, 'previewRoles']);  
        Route::get('/preview/{planId}/teams', [PlanController::class, 'previewTeams']);  
        Route::get('/preview/{planId}/rooms', [PlanController::class, 'previewRooms']);         Route::get('/activities/{planId}', [PlanController::class, 'activities']);     
        Route::get('/action-now/{planId}',  [PlanController::class, 'actionNow']);           // optional: ?point_in_time=YYYY-MM-DD HH:mm
        Route::get('/action-next/{planId}', [PlanController::class, 'actionNext']);          // optional: ?interval=15&point_in_time=...
        Route::get('/action/next/{planId}/{interval?}', [PlanController::class, 'actionNext']); 
        Route::post('/{planId}/generate', [PlanController::class, 'generate']);
        Route::get('/{planId}/status', [PlanController::class, 'status']);    
    });    


    // PlanParameter controller
//    Route::get('/plans/{id}/copy-default', [PlanParameterController::class, 'insertParamsFirst']);
    Route::get('/plans/{id}/parameters', [PlanParameterController::class, 'getParametersForPlan']);
    Route::post('/plans/{id}/parameters', [PlanParameterController::class, 'updateParameter']);
    

    // ExtraBlock controller
    Route::get('/plans/{id}/extra-blocks', [ExtraBlockController::class, 'getBlocksForPlan']);
    Route::post('/plans/{id}/extra-blocks', [ExtraBlockController::class, 'storeOrUpdate']);
    Route::get('/insert-points', [ExtraBlockController::class, 'getInsertPoints']);
    Route::delete('/extra-blocks/{id}', [ExtraBlockController::class, 'delete']);

    // Event controller
    Route::get('/events/selectable', [EventController::class, 'getSelectableEvents']);
    Route::get('/events/{event}', [EventController::class, 'getEvent']);
    Route::put('/events/{event}', [EventController::class, 'update']);
    Route::get('/events/{event}/table-names', [EventController::class, 'getTableNames']);
    Route::put('/events/{id}/table-names', [EventController::class, 'updateTableNames']);

    // Team controller
    Route::get('/events/{event}/teams', [TeamController::class, 'index']);
    Route::put('/events/{event}/teams', [TeamController::class, 'update']);

    // Logo controller
    Route::get('/logos', [LogoController::class, 'index']);
    Route::post('/logos', [LogoController::class, 'store']);
    Route::patch('/logos/{logo}', [LogoController::class, 'update']);
    Route::delete('/logos/{logo}', [LogoController::class, 'destroy']);
    Route::post('/logos/{logo}/toggle-event', [LogoController::class, 'toggleEvent']);

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
    Route::get('/parameters/visibility', [ParameterController::class, 'visibilty']);

    Route::prefix('mparams')->group(function () {
        Route::get('/', [MParameterController::class, 'listMparameter']);              
        Route::post('/reorder', [MParameterController::class, 'reorderMparameter']);  // !!! Reihenfolge in dieser Liste ist wichtig
        Route::post('/{id}', [MParameterController::class, 'updateMparameter']);      
    });

    // DRAHT controller
    Route::get('/draht/events/{eventId}', [DrahtController::class, 'show']);
    // DRAHT admin routes
    Route::get('/admin/draht/sync-draht-regions', [DrahtController::class, 'getAllRegions']);
    Route::get('/admin/draht/sync-draht-events/{seasonId}', [DrahtController::class, 'getAllEventsAndTeams']);

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