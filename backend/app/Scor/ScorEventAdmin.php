<?php

namespace App\Scor;

use App\Enums\FirstProgram;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FLOW-Admin tools for one event: release, reset the award table, wipe every sheet.
 */
final class ScorEventAdmin
{
    public function __construct(private ScorLifecycle $lifecycle = new ScorLifecycle()) {}

    /**
     * @return array{events: list<array<string, mixed>>}
     */
    public function list(User $user, string $query = ''): array
    {
        if (!$user->isFlowAdmin()) {
            throw new ScorAccessException('Das geht nur als FLOW-Admin.');
        }
        $q = trim($query);
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
        $from = now('Europe/Berlin')->subDays(21)->toDateString();
        $hasPartner = Schema::hasTable('regional_partner');

        $rows = DB::table('event as e')
            ->when($hasPartner, fn ($builder) => $builder->leftJoin('regional_partner as rp', 'e.regional_partner', '=', 'rp.id'))
            ->whereExists(function ($sub) {
                $sub->from('event_program')
                    ->whereColumn('event_program.event', 'e.id')
                    ->where('event_program.first_program', FirstProgram::FUTURE_8->value);
            })
            ->when(mb_strlen($q) >= 2, function ($builder) use ($like, $hasPartner) {
                $builder->where(function ($inner) use ($like, $hasPartner) {
                    $inner->where('e.name', 'like', $like);
                    if ($hasPartner) {
                        $inner->orWhere('rp.name', 'like', $like);
                    }
                });
            }, function ($builder) use ($from) {
                $builder->where(function ($inner) use ($from) {
                    $inner->whereNull('e.date')->orWhereDate('e.date', '>=', $from);
                });
            })
            ->orderBy('e.date')
            ->limit(80)
            ->get($hasPartner
                ? ['e.id', 'e.name', 'e.date', 'rp.name as regional_partner_name']
                : ['e.id', 'e.name', 'e.date']);

        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $life = $this->lifecycleRows($ids);
        $counts = $this->counts($ids);

        $events = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $state = $life[$id] ?? null;
            $events[] = [
                'id' => $id,
                'name' => $row->name,
                'date' => $row->date,
                'regional_partner' => $hasPartner ? $row->regional_partner_name : null,
                'closed' => $state?->closed_at !== null,
                'released' => $state?->released_at !== null,
                'referee_sheets' => $counts[$id]['referee'] ?? 0,
                'jury_sheets' => $counts[$id]['jury'] ?? 0,
                'awards' => $counts[$id]['awards'] ?? 0,
            ];
        }

        return ['events' => $events];
    }

    public function release(User $user, int $eventId): array
    {
        return $this->lifecycle->release($user, $eventId);
    }

    public function withdrawRelease(User $user, int $eventId): array
    {
        return $this->lifecycle->withdrawRelease($user, $eventId);
    }

    public function resetTable(User $user, int $eventId): array
    {
        return $this->lifecycle->resetTable($user, $eventId);
    }

    public function resetEvaluations(User $user, int $eventId): array
    {
        return $this->lifecycle->resetEvaluations($user, $eventId);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, object>
     */
    private function lifecycleRows(array $ids): array
    {
        if ($ids === [] || !Schema::hasTable('scor_event')) {
            return [];
        }
        $rows = [];
        foreach (DB::table('scor_event')->whereIn('event', $ids)->get() as $row) {
            $rows[(int) $row->event] = $row;
        }

        return $rows;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{referee: int, jury: int, awards: int}>
     */
    private function counts(array $ids): array
    {
        $counts = [];
        if ($ids === []) {
            return $counts;
        }
        if (Schema::hasTable('scor_sheet')) {
            $sheets = DB::table('scor_sheet')
                ->whereIn('event', $ids)
                ->select('event', 'kind', DB::raw('count(*) as n'))
                ->groupBy('event', 'kind')
                ->get();
            foreach ($sheets as $row) {
                $id = (int) $row->event;
                $counts[$id][$row->kind === 'jury' ? 'jury' : 'referee'] = (int) $row->n;
            }
        }
        if (Schema::hasTable('scor_award')) {
            $awards = DB::table('scor_award')
                ->whereIn('event', $ids)
                ->select('event', DB::raw('count(*) as n'))
                ->groupBy('event')
                ->get();
            foreach ($awards as $row) {
                $counts[(int) $row->event]['awards'] = (int) $row->n;
            }
        }

        return $counts;
    }
}
