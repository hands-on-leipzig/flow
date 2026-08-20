<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ProgramCatalog;
use Illuminate\Http\JsonResponse;

class ProgramController extends Controller
{
    public function index(): JsonResponse
    {
        $programs = ProgramCatalog::attachable()->map(fn ($program) => [
            'id' => $program->id,
            'name' => $program->name,
            'display_name' => $program->display_name,
            'letter' => $program->letter,
            'sequence' => $program->sequence,
            'color_hex' => $program->color_hex,
            'logo_stem' => $program->logo_stem,
            'logo_white' => $program->logo_white,
        ])->values();

        return response()->json($programs);
    }
}
