<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AfternoonController extends Controller
{
    public function blocks(): JsonResponse
    {
        $blocks = DB::table('m_activity_type_detail as d')
            ->leftJoin('m_first_program as p', 'd.first_program', '=', 'p.id')
            ->whereNotNull('d.chain')
            ->orderBy('d.sequence')
            ->orderBy('d.id')
            ->get([
                'd.id',
                'd.code',
                'd.name',
                'd.name_preview',
                'd.chain',
                'd.sequence',
                'd.first_program',
                'p.name as program',
            ]);

        return response()->json(['blocks' => $blocks]);
    }
}
