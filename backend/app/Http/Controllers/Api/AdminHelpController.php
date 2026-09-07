<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MHelpAction;
use App\Models\MHelpActionStep;
use App\Models\MHelpScreen;
use App\Models\MHelpTopic;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminHelpController extends Controller
{
    public function topics(): JsonResponse
    {
        $topics = MHelpTopic::query()->orderBy('sort_order')->orderBy('id')->get();

        return response()->json($topics->map(fn (MHelpTopic $topic) => $this->topicPayload($topic))->values());
    }

    public function storeTopic(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => 'required|string|max:64|unique:m_help_topic,key',
            'name' => 'required|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $max = (int) MHelpTopic::query()->max('sort_order');
        $topic = MHelpTopic::query()->create([
            'key' => $validated['key'],
            'name' => $validated['name'],
            'sort_order' => $validated['sort_order'] ?? ($max + 1),
        ]);

        return response()->json($this->topicPayload($topic), 201);
    }

    public function updateTopic(Request $request, int $id): JsonResponse
    {
        $topic = MHelpTopic::query()->findOrFail($id);
        $validated = $request->validate([
            'key' => 'sometimes|required|string|max:64|unique:m_help_topic,key,'.$topic->id,
            'name' => 'sometimes|required|string|max:255',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        if (array_key_exists('name', $validated)) {
            $validated['name'] = $this->nullIfEmpty($validated['name']) ?? $topic->name;
        }

        $topic->fill($validated);
        $topic->save();

        return response()->json($this->topicPayload($topic->fresh()));
    }

    public function destroyTopic(int $id): JsonResponse
    {
        $topic = MHelpTopic::query()->findOrFail($id);

        try {
            $topic->delete();
        } catch (QueryException $e) {
            return response()->json(['error' => 'Topic still has actions'], 409);
        }

        return response()->json(['ok' => true]);
    }

    public function reorderTopics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:m_help_topic,id',
        ]);

        $this->applyOrder(MHelpTopic::class, $validated['ids']);

        return response()->json(['ok' => true]);
    }

    public function screens(): JsonResponse
    {
        $screens = MHelpScreen::query()->orderBy('sort_order')->orderBy('id')->get();

        return response()->json($screens->map(fn (MHelpScreen $screen) => $this->screenPayload($screen))->values());
    }

    public function updateScreen(Request $request, int $id): JsonResponse
    {
        $screen = MHelpScreen::query()->findOrFail($id);

        foreach (['key', 'name', 'route_path', 'sort_order'] as $locked) {
            if ($request->exists($locked)) {
                throw ValidationException::withMessages([
                    $locked => 'This field cannot be changed',
                ]);
            }
        }

        $validated = $request->validate([
            'description' => 'nullable|string',
            'must_do' => 'nullable|string',
            'can_do' => 'nullable|string',
        ]);

        if ($request->exists('description')) {
            $screen->description = $this->nullIfEmpty($validated['description'] ?? null);
        }
        if ($request->exists('must_do')) {
            $screen->must_do = $this->nullIfEmpty($validated['must_do'] ?? null);
        }
        if ($request->exists('can_do')) {
            $screen->can_do = $this->nullIfEmpty($validated['can_do'] ?? null);
        }
        $screen->save();

        return response()->json($this->screenPayload($screen->fresh()));
    }

    public function actions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'screen_id' => 'required|integer|exists:m_help_screen,id',
        ]);

        $actions = MHelpAction::query()
            ->where('help_screen', $validated['screen_id'])
            ->with([
                'steps' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                'screen',
                'topic',
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json($actions->map(fn (MHelpAction $action) => $this->actionPayload($action))->values());
    }

    public function storeAction(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'help_screen' => 'required|integer|exists:m_help_screen,id',
            'help_topic' => 'required|integer|exists:m_help_topic,id',
            'title' => 'required|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $max = (int) MHelpAction::query()->where('help_screen', $validated['help_screen'])->max('sort_order');
        $action = MHelpAction::query()->create([
            'help_screen' => $validated['help_screen'],
            'help_topic' => $validated['help_topic'],
            'title' => $validated['title'],
            'sort_order' => $validated['sort_order'] ?? ($max + 1),
        ]);

        $action->load([
            'steps' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'screen',
            'topic',
        ]);

        return response()->json($this->actionPayload($action), 201);
    }

    public function updateAction(Request $request, int $id): JsonResponse
    {
        $action = MHelpAction::query()->findOrFail($id);
        $validated = $request->validate([
            'help_topic' => 'sometimes|required|integer|exists:m_help_topic,id',
            'title' => 'sometimes|required|string|max:255',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        $action->fill($validated);
        $action->save();
        $action->load([
            'steps' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'screen',
            'topic',
        ]);

        return response()->json($this->actionPayload($action));
    }

    public function destroyAction(int $id): JsonResponse
    {
        $action = MHelpAction::query()->findOrFail($id);
        $action->delete();

        return response()->json(['ok' => true]);
    }

    public function reorderActions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'help_screen' => 'required|integer|exists:m_help_screen,id',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:m_help_action,id',
        ]);

        $count = MHelpAction::query()
            ->where('help_screen', $validated['help_screen'])
            ->whereIn('id', $validated['ids'])
            ->count();
        if ($count !== count($validated['ids'])) {
            throw ValidationException::withMessages([
                'ids' => 'All actions must belong to the given screen',
            ]);
        }

        $this->applyOrder(MHelpAction::class, $validated['ids']);

        return response()->json(['ok' => true]);
    }

    public function storeStep(Request $request, int $id): JsonResponse
    {
        $action = MHelpAction::query()->findOrFail($id);
        $validated = $request->validate([
            'body' => 'required|string',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $max = (int) MHelpActionStep::query()->where('help_action', $action->id)->max('sort_order');
        $step = MHelpActionStep::query()->create([
            'help_action' => $action->id,
            'body' => $validated['body'],
            'sort_order' => $validated['sort_order'] ?? ($max + 1),
        ]);

        return response()->json($this->stepPayload($step), 201);
    }

    public function updateStep(Request $request, int $id): JsonResponse
    {
        $step = MHelpActionStep::query()->findOrFail($id);
        $validated = $request->validate([
            'body' => 'sometimes|required|string',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        $step->fill($validated);
        $step->save();

        return response()->json($this->stepPayload($step->fresh()));
    }

    public function destroyStep(int $id): JsonResponse
    {
        $step = MHelpActionStep::query()->findOrFail($id);
        $step->delete();

        return response()->json(['ok' => true]);
    }

    public function reorderSteps(Request $request, int $id): JsonResponse
    {
        $action = MHelpAction::query()->findOrFail($id);
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:m_help_action_step,id',
        ]);

        $count = MHelpActionStep::query()
            ->where('help_action', $action->id)
            ->whereIn('id', $validated['ids'])
            ->count();
        if ($count !== count($validated['ids'])) {
            throw ValidationException::withMessages([
                'ids' => 'All steps must belong to the given action',
            ]);
        }

        $this->applyOrder(MHelpActionStep::class, $validated['ids']);

        return response()->json(['ok' => true]);
    }

    /**
     * @param  class-string  $model
     * @param  list<int>  $ids
     */
    private function applyOrder(string $model, array $ids): void
    {
        foreach (array_values($ids) as $index => $id) {
            $model::query()->where('id', $id)->update(['sort_order' => $index + 1]);
        }
    }

    private function nullIfEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
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
                ->map(fn ($step) => $this->stepPayload($step))
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stepPayload(MHelpActionStep $step): array
    {
        return [
            'id' => (int) $step->id,
            'help_action' => (int) $step->help_action,
            'body' => $step->body,
            'sort_order' => (int) $step->sort_order,
        ];
    }
}
