<?php

namespace App\Services;

use App\Enums\FirstProgram;
use App\Support\ProgramPresence;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Distinct catalog roles that have a job on a plan
 * (activity → ATD.role), plus Publikum per event program.
 * Visibility is not used here — ActivityFetcher applies it after a role is chosen.
 */
class RoleFetcherService
{
    public const JOINT_AUDIENCE_ROLE_ID = 14;

    /**
     * @return Collection<int, object>
     */
    public function fetchRoles(int $plan, bool $include_past = false): Collection
    {
        $ids = array_values(array_unique([
            ...$this->jobRoleIds($plan, $include_past),
            ...$this->audienceRoleIds($plan),
        ]));

        if ($ids === []) {
            return collect();
        }

        return DB::table('m_role as r')
            ->leftJoin('m_first_program as fp', 'r.first_program', '=', 'fp.id')
            ->whereIn('r.id', $ids)
            ->select([
                'r.id',
                'r.name',
                'r.name_short',
                'r.sequence',
                'r.first_program',
                'r.differentiation_parameter',
                'r.preview_matrix',
                'r.pdf_export',
                'r.public_plan',
                'r.staffable',
                'r.group_label',
                'fp.name as first_program_name',
                'fp.display_name as first_program_display_name',
                'fp.color_hex',
                'fp.logo_stem',
                'fp.logo_white',
                'fp.sequence as first_program_sequence',
            ])
            ->orderByRaw('CASE WHEN r.first_program IS NULL THEN 0 ELSE 1 END')
            ->orderBy('fp.sequence')
            ->orderBy('r.sequence')
            ->get();
    }

    /**
     * @return list<int>
     */
    private function jobRoleIds(int $plan, bool $include_past): array
    {
        $q = DB::table('activity as a')
            ->join('activity_group as ag', 'a.activity_group', '=', 'ag.id')
            ->join('m_activity_type_detail as atd', 'a.activity_type_detail', '=', 'atd.id')
            ->leftJoin('extra_block as peb', 'a.extra_block', '=', 'peb.id')
            ->join('plan as p', 'p.id', '=', 'ag.plan')
            ->join('event as e', 'e.id', '=', 'p.event')
            ->where('ag.plan', $plan)
            ->whereNotNull('atd.role')
            ->where(function ($sub) {
                $sub->whereNull('a.extra_block')
                    ->orWhere('peb.type', '<>', 'free');
            });

        if (! $include_past) {
            $q->whereColumn('a.start', '>=', 'e.date');

            $driver = DB::connection()->getDriverName();
            if ($driver === 'sqlite') {
                $q->whereRaw(
                    "date(a.start) <= date(e.date, '+' || (COALESCE(e.days, 1) - 1) || ' days')"
                );
            } else {
                $q->whereRaw(
                    'DATE(a.start) <= DATE(e.date) + INTERVAL (COALESCE(e.days, 1) - 1) DAY'
                );
            }
        }

        return $q->distinct()->pluck('atd.role')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return list<int>
     */
    private function audienceRoleIds(int $plan): array
    {
        $ids = [self::JOINT_AUDIENCE_ROLE_ID];
        $eventId = (int) (DB::table('plan')->where('id', $plan)->value('event') ?? 0);
        foreach (ProgramPresence::attachedProgramIds($eventId) as $programId) {
            $fp = FirstProgram::tryFrom($programId);
            $roleId = $fp?->audienceRoleId();
            if ($roleId !== null) {
                $ids[] = $roleId;
            }
        }

        return array_values(array_unique($ids));
    }
}
