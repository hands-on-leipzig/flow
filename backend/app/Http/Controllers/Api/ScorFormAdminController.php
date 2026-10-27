<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Scor\ScorAccessException;
use App\Scor\ScorFormStore;
use App\Services\SeasonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScorFormAdminController extends Controller
{
    public function __construct(private ScorFormStore $forms) {}

    public function show(Request $request): JsonResponse
    {
        $seasonId = (int) ($request->query('season') ?: SeasonService::currentSeasonId());
        $seasons = DB::table('m_season')->orderByDesc('year')->get(['id', 'name', 'year']);

        try {
            $document = $this->forms->document($seasonId);
        } catch (ScorAccessException $e) {
            return response()->json(['error' => $e->getMessage()], $e->status);
        }

        return response()->json([
            'seasons' => $seasons,
            ...$document,
        ]);
    }

    public function update(Request $request, int $form): JsonResponse
    {
        $validated = $request->validate([
            'sections' => 'present|array',
            'sections.*.title' => 'nullable|string|max:200',
            'sections.*.kind' => 'required|string|max:16',
            'sections.*.note' => 'nullable|string|max:2000',
            'sections.*.items' => 'present|array',
            'sections.*.items.*.item_type' => 'required|string|max:16',
            'sections.*.items.*.label' => 'nullable|string|max:500',
            'sections.*.items.*.hint' => 'nullable|string|max:2000',
            'sections.*.items.*.core_value' => 'sometimes|boolean',
            'sections.*.items.*.config' => 'nullable|array',
        ]);

        try {
            return response()->json($this->forms->replace($form, $validated['sections']));
        } catch (ScorAccessException $e) {
            return response()->json(['error' => $e->getMessage()], $e->status);
        }
    }
}
