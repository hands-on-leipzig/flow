<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HelpActionStat;
use App\Models\MHelpAction;
use App\Models\MHelpScreen;
use App\Models\MHelpTopic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HelpController extends Controller
{
    public function catalog(): JsonResponse
    {
        $topics = MHelpTopic::query()->orderBy('sort_order')->orderBy('id')->get();
        $screens = MHelpScreen::query()->orderBy('sort_order')->orderBy('id')->get();
        $actions = MHelpAction::query()
            ->with(MHelpAction::payloadEagerLoad())
            ->leftJoin('help_action_stat', 'help_action_stat.help_action', '=', 'm_help_action.id')
            ->orderByDesc(DB::raw('COALESCE(help_action_stat.open_count, 0)'))
            ->orderBy('m_help_action.title')
            ->orderBy('m_help_action.id')
            ->select('m_help_action.*')
            ->get();

        return response()->json([
            'topics' => $topics->map(fn (MHelpTopic $topic) => $this->topicPayload($topic))->values(),
            'screens' => $screens->map(fn (MHelpScreen $screen) => $this->screenPayload($screen))->values(),
            'actions' => $actions->map(fn (MHelpAction $action) => $action->toApiPayload())->values(),
        ]);
    }

    public function show(string $key): JsonResponse
    {
        $screen = MHelpScreen::query()->where('key', $key)->first();
        if (! $screen) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $actions = $screen->actions()
            ->with(MHelpAction::payloadEagerLoad())
            ->orderBy('m_help_action.title')
            ->orderBy('m_help_action.id')
            ->get();

        return response()->json([
            ...$this->screenPayload($screen),
            'actions' => $actions->map(fn (MHelpAction $action) => $action->toApiPayload())->values(),
        ]);
    }

    public function open(int $id): JsonResponse
    {
        $action = MHelpAction::query()->findOrFail($id);
        $stat = HelpActionStat::bump($action->id, 'open_count');

        return response()->json($this->counterPayload($action, $stat));
    }

    public function feedback(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'helpful' => 'required|boolean',
            'previous' => 'nullable|boolean',
        ]);

        $action = MHelpAction::query()->findOrFail($id);
        $stat = HelpActionStat::replaceHelpful(
            $action->id,
            (bool) $validated['helpful'],
            array_key_exists('previous', $validated) ? $validated['previous'] : null
        );

        return response()->json($this->counterPayload($action, $stat));
    }

    /**
     * @return array<string, mixed>
     */
    private function topicPayload(MHelpTopic $topic): array
    {
        return [
            'id' => (int) $topic->id,
            'key' => $topic->key,
            'name' => $topic->name,
            'sort_order' => (int) $topic->sort_order,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function screenPayload(MHelpScreen $screen): array
    {
        return [
            'id' => (int) $screen->id,
            'key' => $screen->key,
            'name' => $screen->name,
            'route_path' => $screen->route_path,
            'description' => $screen->description,
            'must_do' => $screen->must_do,
            'can_do' => $screen->can_do,
            'sort_order' => (int) $screen->sort_order,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function counterPayload(MHelpAction $action, HelpActionStat $stat): array
    {
        return [
            'id' => (int) $action->id,
            'open_count' => (int) $stat->open_count,
            'helpful_yes' => (int) $stat->helpful_yes,
            'helpful_no' => (int) $stat->helpful_no,
        ];
    }
}
