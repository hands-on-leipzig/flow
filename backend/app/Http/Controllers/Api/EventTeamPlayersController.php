<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Team;
use App\Support\ProgramCatalog;
use App\Support\TeamDataIndex;
use App\Support\TeamPlayers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Team members (Teammitglieder) of a team, read from and written to DRAHT.
 * Out-of-range ages are reported, not rejected: the regional partner may allow exceptions.
 */
class EventTeamPlayersController extends Controller
{
    public function __construct(
        private readonly DrahtController $drahtController,
    ) {}

    public function show(Event $event, Team $team): JsonResponse
    {
        $resolved = $this->resolveDrahtTeam($event, $team);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $response = $this->drahtController->getPeople($resolved['draht_event_id']);
        if ($response->getStatusCode() !== 200) {
            return response()->json(['error' => 'Teammitglieder konnten nicht aus DRAHT geladen werden.'], 502);
        }

        $people = $response->getData(true);
        $teamData = is_array($people) ? ($people[(string) $resolved['team_number_hot']] ?? null) : null;
        if (! is_array($teamData)) {
            return response()->json(['error' => 'Team ist in DRAHT nicht für diese Veranstaltung angemeldet.'], 404);
        }

        return response()->json($this->payload($team, $teamData));
    }

    public function update(Request $request, Event $event, Team $team): JsonResponse
    {
        $resolved = $this->resolveDrahtTeam($event, $team);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $validated = $request->validate([
            'players' => ['present', 'array'],
            'players.*.firstname' => ['nullable', 'string', 'max:255'],
            'players.*.name' => ['nullable', 'string', 'max:255'],
            'players.*.gender' => ['nullable', 'string', Rule::in(TeamPlayers::GENDERS)],
            'players.*.birthday' => ['nullable', 'date_format:Y-m-d', 'before:today'],
        ]);

        $players = [];
        foreach ($validated['players'] as $player) {
            $firstname = trim((string) ($player['firstname'] ?? ''));
            $name = trim((string) ($player['name'] ?? ''));
            $birthday = $player['birthday'] ?? null;
            if ($firstname === '' && $name === '' && $birthday === null) {
                continue;
            }
            $players[] = [
                'firstname' => $firstname,
                'name' => $name,
                'gender' => (string) ($player['gender'] ?? ''),
                'birthday' => $birthday,
            ];
        }

        $result = $this->drahtController->putTeamPlayers(
            $resolved['draht_event_id'],
            $resolved['team_number_hot'],
            $players,
        );
        if (! $result['ok'] || $result['data'] === null) {
            $status = in_array($result['status'], [400, 404], true) ? 422 : 502;

            return response()->json([
                'error' => $result['message'] ?? 'Teammitglieder konnten nicht in DRAHT gespeichert werden.',
            ], $status);
        }

        return response()->json($this->payload($team, $result['data']) + [
            'team' => TeamDataIndex::teamPayload($event, $team),
        ]);
    }

    /**
     * @return array{draht_event_id: int, team_number_hot: int}|JsonResponse
     */
    private function resolveDrahtTeam(Event $event, Team $team): array|JsonResponse
    {
        if ((int) $team->event !== (int) $event->id) {
            return response()->json(['error' => 'Team gehört nicht zu dieser Veranstaltung.'], 404);
        }

        $teamNumberHot = (int) ($team->team_number_hot ?? 0);
        $drahtEventId = ProgramCatalog::drahtId($event, (int) $team->first_program);
        if ($teamNumberHot <= 0 || $drahtEventId === null || $drahtEventId <= 0) {
            return response()->json(['error' => 'Team ist nicht mit DRAHT verknüpft.'], 404);
        }

        return ['draht_event_id' => $drahtEventId, 'team_number_hot' => $teamNumberHot];
    }

    /**
     * @param  array<string, mixed>  $teamData  DRAHT team entry
     * @return array<string, mixed>
     */
    private function payload(Team $team, array $teamData): array
    {
        $rule = TeamPlayers::ageRule($teamData['player_age_rule'] ?? null);
        $players = array_map(
            fn (array $player) => $player + ['age_out_of_range' => TeamPlayers::ageOutOfRange($player['birthday'], $rule)],
            TeamPlayers::serializeList($teamData['players'] ?? null),
        );
        $maxPlayers = (int) ($teamData['max_players'] ?? 0);

        return [
            'team_id' => $team->id,
            'players' => $players,
            'max_players' => $maxPlayers > 0 ? $maxPlayers : null,
            'player_age_rule' => $rule,
        ];
    }
}
