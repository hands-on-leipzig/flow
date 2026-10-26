<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EventAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScorAdminController extends Controller
{
    public function __construct(
        private EventAccessService $access,
    ) {}

    public function events(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';

        $events = DB::table('event as e')
            ->leftJoin('regional_partner as rp', 'e.regional_partner', '=', 'rp.id')
            ->when(mb_strlen($q) >= 2, function ($query) use ($like) {
                $query->where(function ($inner) use ($like) {
                    $inner->where('e.name', 'like', $like)
                        ->orWhere('rp.name', 'like', $like);
                });
            })
            ->orderByDesc('e.date')
            ->limit(40)
            ->get([
                'e.id',
                'e.name',
                'e.date',
                'rp.name as regional_partner_name',
            ]);

        return response()->json(['events' => $events]);
    }

    public function log(): JsonResponse
    {
        return response()->json(['log' => $this->access->logRecent()]);
    }
}
