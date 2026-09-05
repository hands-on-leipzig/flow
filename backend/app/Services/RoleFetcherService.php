<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Distinct catalog roles that have schedule work in a plan
 * (activity → ATD → m_visibility → m_role). True inverse of
 * ActivityFetcherService's $roles filter, always excluding free blocks.
 */
class RoleFetcherService
{
    /**
     * @return Collection<int, object>
     */
    public function fetchRoles(int $plan, bool $include_past = false): Collection
    {
        $q = DB::table('activity as a')
            ->join('activity_group as ag', 'a.activity_group', '=', 'ag.id')
            ->join('m_activity_type_detail as atd', 'a.activity_type_detail', '=', 'atd.id')
            ->join('m_visibility as v', 'v.activity_type_detail', '=', 'atd.id')
            ->join('m_role as r', 'r.id', '=', 'v.role')
            ->leftJoin('m_first_program as fp', 'r.first_program', '=', 'fp.id')
            ->leftJoin('extra_block as peb', 'a.extra_block', '=', 'peb.id')
            ->join('plan as p', 'p.id', '=', 'ag.plan')
            ->join('event as e', 'e.id', '=', 'p.event')
            ->where('ag.plan', $plan)
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

        return $q
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
            ->distinct()
            ->orderByRaw('CASE WHEN r.first_program IS NULL THEN 0 ELSE 1 END')
            ->orderBy('fp.sequence')
            ->orderBy('r.sequence')
            ->get();
    }
}
