<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EvaluateQuality;

class QualityController extends Controller
{
    public function debug(int $id)
    {
        $service = new EvaluateQuality();
        $data = $service->debugDump($id);
        return response()->json($data);
    }
}

