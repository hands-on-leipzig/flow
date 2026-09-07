<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MHelpAction;
use App\Models\MHelpScreen;
use App\Models\MHelpTopic;
use Illuminate\Http\JsonResponse;

class HelpController extends Controller
{
    public function catalog(): JsonResponse
    {
        $topics = MHelpTopic::query()->orderBy('sort_order')->orderBy('id')->get();
        $screens = MHelpScreen::query()->orderBy('sort_order')->orderBy('id')->get();
        $actions = MHelpAction::query()
            ->with([
                'steps' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                'screen',
                'topic',
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'topics' => $topics->map(fn (MHelpTopic $topic) => $this->topicPayload($topic))->values(),
            'screens' => $screens->map(fn (MHelpScreen $screen) => $this->screenPayload($screen))->values(),
            'actions' => $actions->map(fn (MHelpAction $action) => $this->actionPayload($action))->values(),
        ]);
    }

    public function show(string $key): JsonResponse
    {
        $screen = MHelpScreen::query()->where('key', $key)->first();
        if (! $screen) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $actions = MHelpAction::query()
            ->where('help_screen', $screen->id)
            ->with([
                'steps' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                'screen',
                'topic',
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            ...$this->screenPayload($screen),
            'actions' => $actions->map(fn (MHelpAction $action) => $this->actionPayload($action))->values(),
        ]);
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
     * @return array<string, mixed>
     */
    private function actionPayload(MHelpAction $action): array
    {
        $screen = $action->screen;
        $topic = $action->topic;

        return [
            'id' => (int) $action->id,
            'help_screen' => (int) $action->help_screen,
            'help_topic' => (int) $action->help_topic,
            'title' => $action->title,
            'sort_order' => (int) $action->sort_order,
            'screen' => $screen ? [
                'key' => $screen->key,
                'name' => $screen->name,
                'route_path' => $screen->route_path,
            ] : null,
            'topic' => $topic ? [
                'key' => $topic->key,
                'name' => $topic->name,
            ] : null,
            'steps' => $action->steps
                ->map(fn ($step) => [
                    'id' => (int) $step->id,
                    'help_action' => (int) $step->help_action,
                    'body' => $step->body,
                    'sort_order' => (int) $step->sort_order,
                ])
                ->values(),
        ];
    }
}
