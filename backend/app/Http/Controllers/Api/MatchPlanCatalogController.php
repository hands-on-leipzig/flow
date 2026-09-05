<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FirstProgram;
use App\Models\MMatch;
use App\Services\MatchPlanPairingQuality;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MatchPlanCatalogController extends Controller
{
    public function __construct(
        private readonly MatchPlanPairingQuality $pairingQuality,
    ) {}

    public function programs(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $programs = FirstProgram::query()
            ->whereNotNull('max_match_rounds')
            ->orderBy('sequence')
            ->get([
                'id',
                'name',
                'display_name',
                'letter',
                'sequence',
                'max_match_rounds',
                'color_hex',
            ]);

        return response()->json(['programs' => $programs]);
    }

    public function keys(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $keys = MMatch::query()
            ->select('first_program', 'teams', 'lanes', 'tables')
            ->selectRaw('COUNT(*) as match_count')
            ->selectRaw('MAX(round) as max_round')
            ->selectRaw('MAX(comment) as comment')
            ->groupBy('first_program', 'teams', 'lanes', 'tables')
            ->orderBy('first_program')
            ->orderBy('teams')
            ->orderBy('lanes')
            ->orderBy('tables')
            ->get();

        return response()->json(['keys' => $keys]);
    }

    public function show(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $data = $request->validate([
            'first_program' => 'required|integer|exists:m_first_program,id',
            'teams' => 'required|integer|min:2',
            'lanes' => 'required|integer|min:1',
            'tables' => 'required|integer|in:2,4',
        ]);

        $maxRounds = $this->maxMatchRounds((int) $data['first_program']);
        if ($maxRounds === null) {
            return response()->json(['error' => 'Program does not support match plans'], 422);
        }

        $matches = MMatch::query()
            ->where('first_program', $data['first_program'])
            ->where('teams', $data['teams'])
            ->where('lanes', $data['lanes'])
            ->where('tables', $data['tables'])
            ->orderBy('round')
            ->orderBy('match_no')
            ->get();

        $comment = $matches->isNotEmpty()
            ? (string) ($matches->first()->comment ?? '')
            : '';

        return response()->json([
            'exists' => $matches->isNotEmpty(),
            'first_program' => (int) $data['first_program'],
            'teams' => (int) $data['teams'],
            'lanes' => (int) $data['lanes'],
            'tables' => (int) $data['tables'],
            'comment' => $comment,
            'max_match_rounds' => $maxRounds,
            'matches' => $matches,
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $data = $request->validate([
            'first_program' => 'required|integer|exists:m_first_program,id',
            'teams' => 'required|integer|min:2',
            'lanes' => 'required|integer|min:1',
            'tables' => 'required|integer|in:2,4',
            'comment' => 'nullable|string|max:5000',
            'matches' => 'required|array|min:1',
            'matches.*.round' => 'required|integer|min:0',
            'matches.*.match_no' => 'required|integer|min:1',
            'matches.*.table_1' => 'required|integer|in:1,2,3,4',
            'matches.*.table_2' => 'required|integer|in:1,2,3,4',
            'matches.*.table_1_team' => 'required|integer|min:0',
            'matches.*.table_2_team' => 'required|integer|min:0',
        ]);

        $firstProgram = (int) $data['first_program'];
        $teams = (int) $data['teams'];
        $lanes = (int) $data['lanes'];
        $tables = (int) $data['tables'];
        $comment = isset($data['comment']) ? trim((string) $data['comment']) : '';
        $comment = $comment === '' ? null : $comment;
        $maxRounds = $this->maxMatchRounds($firstProgram);
        if ($maxRounds === null) {
            return response()->json(['error' => 'Program does not support match plans'], 422);
        }

        $error = $this->validateMatchStructure($data['matches'], $teams, $tables, $maxRounds);
        if ($error !== null) {
            return response()->json(['error' => $error], 422);
        }

        $rows = [];
        foreach ($data['matches'] as $match) {
            $rows[] = [
                'first_program' => $firstProgram,
                'teams' => $teams,
                'lanes' => $lanes,
                'tables' => $tables,
                'comment' => $comment,
                'round' => (int) $match['round'],
                'match_no' => (int) $match['match_no'],
                'table_1' => (int) $match['table_1'],
                'table_2' => (int) $match['table_2'],
                'table_1_team' => (int) $match['table_1_team'],
                'table_2_team' => (int) $match['table_2_team'],
            ];
        }

        DB::transaction(function () use ($firstProgram, $teams, $lanes, $tables, $rows) {
            MMatch::query()
                ->where('first_program', $firstProgram)
                ->where('teams', $teams)
                ->where('lanes', $lanes)
                ->where('tables', $tables)
                ->delete();
            MMatch::query()->insert($rows);
        });

        $saved = MMatch::query()
            ->where('first_program', $firstProgram)
            ->where('teams', $teams)
            ->where('lanes', $lanes)
            ->where('tables', $tables)
            ->orderBy('round')
            ->orderBy('match_no')
            ->get();

        return response()->json([
            'exists' => true,
            'first_program' => $firstProgram,
            'teams' => $teams,
            'lanes' => $lanes,
            'tables' => $tables,
            'comment' => $comment ?? '',
            'max_match_rounds' => $maxRounds,
            'matches' => $saved,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $data = $request->validate([
            'first_program' => 'required|integer|exists:m_first_program,id',
            'teams' => 'required|integer|min:2',
            'lanes' => 'required|integer|min:1',
            'tables' => 'required|integer|in:2,4',
        ]);

        $deleted = MMatch::query()
            ->where('first_program', $data['first_program'])
            ->where('teams', $data['teams'])
            ->where('lanes', $data['lanes'])
            ->where('tables', $data['tables'])
            ->delete();

        return response()->json([
            'success' => true,
            'deleted' => $deleted,
        ]);
    }

    public function quality(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $data = $request->validate([
            'teams' => 'required|integer|min:2',
            'tables' => 'required|integer|in:2,4',
            'matches' => 'required|array',
            'matches.*.round' => 'required|integer|min:0',
            'matches.*.table_1' => 'required|integer',
            'matches.*.table_2' => 'required|integer',
            'matches.*.table_1_team' => 'required|integer|min:0',
            'matches.*.table_2_team' => 'required|integer|min:0',
        ]);

        $result = $this->pairingQuality->evaluate(
            $data['matches'],
            (int) $data['teams'],
            (int) $data['tables'],
        );

        return response()->json($result);
    }

    /**
     * @param  list<array<string, mixed>>  $matches
     */
    private function validateMatchStructure(array $matches, int $teams, int $tables, int $maxRounds): ?string
    {
        $expectedPerRound = (int) ceil($teams / 2);
        $byRound = [];

        foreach ($matches as $match) {
            $round = (int) $match['round'];
            $matchNo = (int) $match['match_no'];
            $t1 = (int) $match['table_1'];
            $t2 = (int) $match['table_2'];
            $team1 = (int) $match['table_1_team'];
            $team2 = (int) $match['table_2_team'];

            if ($round > $maxRounds) {
                return "Round {$round} exceeds max_match_rounds ({$maxRounds})";
            }
            if ($team1 > $teams || $team2 > $teams) {
                return 'Team number exceeds teams count';
            }

            $pairOk = ($t1 === 1 && $t2 === 2) || ($t1 === 3 && $t2 === 4);
            if (! $pairOk) {
                return 'Each match must use table pair 1+2 or 3+4';
            }
            if ($tables === 2 && ! ($t1 === 1 && $t2 === 2)) {
                return 'With 2 tables only pair 1+2 is allowed';
            }

            $byRound[$round][$matchNo] = true;
        }

        if (! isset($byRound[0]) || ! isset($byRound[1])) {
            return 'Rounds 0 (TR) and 1 are required';
        }

        foreach ($byRound as $round => $nos) {
            if (count($nos) !== $expectedPerRound) {
                return "Round {$round} must have exactly {$expectedPerRound} matches";
            }
            for ($i = 1; $i <= $expectedPerRound; $i++) {
                if (! isset($nos[$i])) {
                    return "Round {$round} is missing match_no {$i}";
                }
            }
        }

        return null;
    }

    private function maxMatchRounds(int $firstProgramId): ?int
    {
        $value = FirstProgram::query()->where('id', $firstProgramId)->value('max_match_rounds');

        return $value === null ? null : (int) $value;
    }

    private function denyUnlessAdmin(Request $req): ?JsonResponse
    {
        $user = $req->user();
        if (! $user || ! $user->isFlowAdmin()) {
            return response()->json(['error' => 'Forbidden - admin role required'], 403);
        }

        return null;
    }
}
