<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MHelpAction;
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

    public function actions(): JsonResponse
    {
        $actions = MHelpAction::query()
            ->with(MHelpAction::payloadEagerLoad())
            ->orderBy('title')
            ->orderBy('id')
            ->get();

        return response()->json($actions->map(fn (MHelpAction $action) => $action->toApiPayload())->values());
    }

    public function storeAction(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'help_topic' => 'required|integer|exists:m_help_topic,id',
            'title' => 'required|string|max:255',
            'body' => 'nullable|string',
        ]);

        $max = (int) MHelpAction::query()->max('sort_order');
        $action = MHelpAction::query()->create([
            'help_topic' => $validated['help_topic'],
            'title' => $validated['title'],
            'body' => $this->nullIfEmpty($validated['body'] ?? null),
            'sort_order' => $max + 1,
        ]);

        $action->load(MHelpAction::payloadEagerLoad());

        return response()->json($action->toApiPayload(), 201);
    }

    public function updateAction(Request $request, int $id): JsonResponse
    {
        $action = MHelpAction::query()->findOrFail($id);

        foreach (['open_count', 'helpful_yes', 'helpful_no', 'help_screen_ids'] as $locked) {
            if ($request->exists($locked)) {
                throw ValidationException::withMessages([
                    $locked => 'This field cannot be changed',
                ]);
            }
        }

        $validated = $request->validate([
            'help_topic' => 'sometimes|required|integer|exists:m_help_topic,id',
            'title' => 'sometimes|required|string|max:255',
            'body' => 'nullable|string',
        ]);

        if (array_key_exists('body', $validated)) {
            $validated['body'] = $this->nullIfEmpty($validated['body'] ?? null);
        }

        $action->fill($validated);
        $action->save();
        $action->load(MHelpAction::payloadEagerLoad());

        return response()->json($action->toApiPayload());
    }

    public function destroyAction(int $id): JsonResponse
    {
        $action = MHelpAction::query()->findOrFail($id);
        $action->delete();

        return response()->json(['ok' => true]);
    }

    public function assignAction(Request $request, int $screenId): JsonResponse
    {
        $screen = MHelpScreen::query()->findOrFail($screenId);
        $validated = $request->validate([
            'help_action' => 'required|integer|exists:m_help_action,id',
        ]);

        $created = ! $screen->actions()->where('m_help_action.id', $validated['help_action'])->exists();
        if ($created) {
            $screen->actions()->attach($validated['help_action']);
        }

        $action = MHelpAction::query()
            ->with(MHelpAction::payloadEagerLoad())
            ->findOrFail($validated['help_action']);

        return response()->json($action->toApiPayload(), $created ? 201 : 200);
    }

    public function unassignAction(int $screenId, int $actionId): JsonResponse
    {
        $screen = MHelpScreen::query()->findOrFail($screenId);
        $screen->actions()->detach($actionId);

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
}
