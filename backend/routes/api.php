<?php

use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\LogoController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\PlanParameterController;
use App\Http\Controllers\Api\RoomController;
use Illuminate\Http\Request;
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
        return response()->json([
            'selected_event' => $request->user()?->selection_event
        ]);
    });

    Route::post('/user/select-event', function (Request $request) {
        $validated = $request->validate([
            'event_id' => 'required|integer|exists:event,id',
        ]);

        $user = $request->user();
        $user->selection_event = $validated['event_id'];
        $user->save();

        return response()->json(['status' => 'ok']);
    });

    Route::get('/plans/{id}/parameters', [PlanParameterController::class, 'getParametersForPlan']);
    Route::post('/plans/{id}/parameters', [PlanParameterController::class, 'updateParameter']);

    Route::get('/api/events/{event}/plans', [PlanController::class, 'getPlansByEvent']);
    Route::get('/events/selectable', [EventController::class, 'getSelectableEvents']);
    Route::get('/events/{event}', [EventController::class, 'getEvent']);

    Route::get('/logos', [LogoController::class, 'index']);
    Route::post('/logos', [LogoController::class, 'store']);
    Route::patch('/logos/{logo}', [LogoController::class, 'update']);
    Route::delete('/logos/{logo}', [LogoController::class, 'destroy']);
    Route::post('/logos/{logo}/toggle-event', [LogoController::class, 'toggleEvent']);

    Route::get('/events/{event}/rooms', [RoomController::class, 'index']);
    Route::post('/rooms', [RoomController::class, 'store']);
    Route::put('/rooms/assign-types', [RoomController::class, 'assignRoomType']);
    Route::put('/rooms/{room}', [RoomController::class, 'update']);
    Route::delete('/rooms/{room}', [RoomController::class, 'destroy']);
});
