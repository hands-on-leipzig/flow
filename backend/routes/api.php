<?php

use App\Http\Controllers\Api\DrahtController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\ExtraBlockController;
use App\Http\Controllers\Api\LogoController;
use App\Http\Controllers\Api\ParameterController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\PlanParameterController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\PreviewController;
use App\Http\Controllers\Api\QualityController;
use App\Services\VolumeTest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn() => ['pong' => true]);

Route::get('/profile', function (Illuminate\Http\Request $request) {
    return response()->json([
        'user' => $request->get('jwt'),
    ]);
});

// TODO
//Route::get('/quality/debug/{qPlanId}', [QualityController::class, 'debug']);
Route::post('/quality/start-run', [QualityController::class, 'startRun']);
Route::get('/quality/qrun/{id}', function ($id) {
    (new VolumeTest())->generateQPlans((int)$id);
    return 'Fertig';
});
Route::get('/quality/', [PreviewController::class, 'roles']);

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

    Route::get('/plans/{id}/parameters', [PlanParameterController::class, 'getParametersForPlan']);

    Route::post('/plans/{id}/parameters', [PlanParameterController::class, 'updateParameter']);
    Route::get('/plans/{id}/extra-blocks', [ExtraBlockController::class, 'getBlocksForPlan']);
    Route::post('/plans/{id}/extra-blocks', [ExtraBlockController::class, 'storeOrUpdate']);
    Route::post('/plans', [PlanController::class, 'create']);

    Route::get('/plans/{plan}/schedule/roles', [PreviewController::class, 'roles']);
    Route::get('/plans/{plan}/schedule/teams', [PreviewController::class, 'teams']);
    Route::get('/plans/{plan}/schedule/rooms', [PreviewController::class, 'rooms']);

    Route::get('/events/{event}/plans', [PlanController::class, 'getPlansByEvent']);
    Route::get('/events/{event}/teams', [TeamController::class, 'index']);
    Route::put('/events/{event}/teams', [TeamController::class, 'update']);
    Route::get('/events/selectable', [EventController::class, 'getSelectableEvents']);
    Route::get('/events/{event}', [EventController::class, 'getEvent']);
    Route::put('/events/{event}', [EventController::class, 'update']);

    Route::get('/logos', [LogoController::class, 'index']);
    Route::post('/logos', [LogoController::class, 'store']);
    Route::patch('/logos/{logo}', [LogoController::class, 'update']);
    Route::delete('/logos/{logo}', [LogoController::class, 'destroy']);
    Route::post('/logos/{logo}/toggle-event', [LogoController::class, 'toggleEvent']);

    Route::get('/events/{event}/rooms', [RoomController::class, 'index']);
    Route::get('/events/{event}/draht-data', [DrahtController::class, 'show']);
    Route::post('/rooms', [RoomController::class, 'store']);
    Route::put('/rooms/assign-types', [RoomController::class, 'assignRoomType']);
    Route::put('/rooms/{room}', [RoomController::class, 'update']);
    Route::delete('/rooms/{room}', [RoomController::class, 'destroy']);

    Route::get('/parameter', [ParameterController::class, 'index']);
    Route::get('/parameter/condition', [ParameterController::class, 'listConditions']);
    Route::get('/parameter/lanes-options', [ParameterController::class, 'listLanesOptions']);
    Route::post('/parameter/condition', [ParameterController::class, 'addCondition']);
    Route::put('/parameter/condition/{id}', [ParameterController::class, 'updateCondition']);
    Route::delete('/parameter/condition/{id}', [ParameterController::class, 'deleteCondition']);

    Route::get('/draht/events/{eventId}', [DrahtController::class, 'show']);

    Route::get('/insert-points', [ExtraBlockController::class, 'getInsertPoints']);
    Route::delete('/extra-blocks/{id}', [ExtraBlockController::class, 'delete']);

    // admin routes
    Route::get('/admin/draht/sync-draht-regions', [DrahtController::class, 'getAllRegions']);
    Route::get('/admin/draht/sync-draht-events/{seasonId}', [DrahtController::class, 'getAllEventsAndTeams']);

    
});
