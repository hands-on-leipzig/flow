<?php

namespace App\Http\Controllers\External;

use Illuminate\Http\Request;

class HealthController extends BaseController
{
    /**
     * Health check endpoint
     * 
     * @OA\Get(
     *     path="/api/external/health",
     *     summary="Health check",
     *     tags={"Health"},
     *     security={{"apiKey": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Service is healthy",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="status", type="string", example="ok"),
     *                 @OA\Property(property="timestamp", type="string")
     *             )
     *         )
     *     )
     * )
     */
    public function check(Request $request)
    {
        return $this->success([
            'status' => 'ok',
            'version' => '1.0.0',
        ], 'Service is healthy');
    }
}

