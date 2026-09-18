<?php

declare(strict_types=1);

namespace App\Print;

use App\Services\PublicPlanService;

final class RoleSheetAssembler
{
    public function __construct(private PublicPlanService $publicPlan) {}

    /**
     * @param  list<int>  $roleIds
     * @return array{
     *     title_short: string,
     *     title_long: string,
     *     sections: list<array{
     *         subject: string,
     *         noshow: bool,
     *         color_hex: string,
     *         logo_stem: ?string,
     *         ablauf: list<array{start:string,end:string,room:string,action:string,strike:list<string>}>,
     *         zusaetzlich?: list<array{start:string,end:string,room:string,action:string,strike:list<string>}>
     *     }>
     * }
     */
    public function assemble(int $planId, array $roleIds): array
    {
        $payload = $this->publicPlan->getRoles($planId);
        $wanted = [];
        foreach ($roleIds as $id) {
            $wanted[(int) $id] = true;
        }

        $sections = [];
        foreach (RoleSheetCatalog::filterRoles($payload['roles'] ?? []) as $role) {
            $roleId = (int) ($role['id'] ?? 0);
            if (! isset($wanted[$roleId])) {
                continue;
            }
            foreach ($role['options'] ?? [] as $option) {
                $section = $this->sectionForOption($planId, $role, $option);
                if ($section !== null) {
                    $sections[] = $section;
                }
            }
        }

        $titleShort = (string) ($payload['title_short'] ?? '');
        $titleLong = (string) ($payload['title_long'] ?? $payload['event_name'] ?? $titleShort);

        return [
            'title_short' => $titleShort,
            'title_long' => $titleLong,
            'sections' => $sections,
        ];
    }

    /**
     * @param  array<string, mixed>  $role
     * @param  array<string, mixed>  $option
     * @return array<string, mixed>|null
     */
    private function sectionForOption(int $planId, array $role, array $option): ?array
    {
        $parameter = $option['parameter'] ?? $role['differentiation_parameter'] ?? null;
        $parameter = $parameter !== null && $parameter !== '' ? (string) $parameter : '';
        $value = $option['value'] ?? null;
        $selfTeam = $parameter === 'team' && $value !== null && $value !== '' ? (int) $value : null;
        $selfTable = $parameter === 'table' && $value !== null && $value !== '' ? (int) $value : null;
        $roleName = (string) ($role['name'] ?? '');
        $programId = isset($role['first_program']) && $role['first_program'] !== null
            ? (int) $role['first_program']
            : null;

        $query = [
            'expired' => 'yes',
            'role' => (int) $role['id'],
        ];
        if ($parameter !== '' && $value !== null && in_array($parameter, ['team', 'lane', 'table'], true)) {
            $query[$parameter] = (int) $value;
        }

        $schedule = $this->publicPlan->getSchedule($planId, $query);
        $ablauf = [];
        $extra = [];
        foreach ($schedule['groups'] ?? [] as $group) {
            foreach ($group['activities'] ?? [] as $activity) {
                if (! is_array($activity)) {
                    continue;
                }
                $row = $this->row($activity, $roleName, $parameter, $selfTeam, $selfTable, $programId, $option);
                if (self::isPunctual($activity)) {
                    $ablauf[] = $row;
                } else {
                    $extra[] = $row;
                }
            }
        }

        if ($ablauf === []) {
            return null;
        }

        $section = [
            'subject' => self::subject($roleName, $role, $option),
            'noshow' => (bool) ($option['noshow'] ?? false),
            'color_hex' => (string) ($role['color_hex'] ?? '888888'),
            'logo_stem' => isset($role['logo_stem']) && $role['logo_stem'] !== ''
                ? (string) $role['logo_stem']
                : null,
            'ablauf' => $ablauf,
        ];
        if ($extra !== []) {
            $section['zusaetzlich'] = $extra;
        }

        return $section;
    }

    /**
     * @param  array<string, mixed>  $role
     * @param  array<string, mixed>  $option
     */
    private static function subject(string $roleName, array $role, array $option): string
    {
        $optionLabel = trim((string) ($option['label'] ?? ''));
        if (trim((string) ($role['group_label'] ?? '')) !== '') {
            return $optionLabel !== '' ? $optionLabel : $roleName;
        }

        return $optionLabel !== '' ? $roleName.': '.$optionLabel : $roleName;
    }

    /**
     * @param  array<string, mixed>  $activity
     * @param  array<string, mixed>  $option
     * @return array{start:string,end:string,room:string,action:string,strike:list<string>}
     */
    private function row(
        array $activity,
        string $roleName,
        string $roleParam,
        ?int $selfTeam,
        ?int $selfTable,
        ?int $programId,
        array $option,
    ): array {
        $action = RoleSheetCells::action($activity, $roleName, $roleParam, $selfTeam, $selfTable);
        $strike = $action['strike'];
        if (! empty($option['noshow'])) {
            $label = trim((string) ($option['label'] ?? ''));
            if ($label !== '' && ! in_array($label, $strike, true)) {
                $strike[] = $label;
            }
        }

        return [
            'start' => self::clock($activity['start_time'] ?? null),
            'end' => self::clock($activity['end_time'] ?? null),
            'room' => RoleSheetCells::room($activity, $roleParam, $selfTeam, $selfTable, $programId),
            'action' => $action['text'],
            'strike' => $strike,
        ];
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private static function isPunctual(array $activity): bool
    {
        $presence = $activity['presence'] ?? 'punctual';
        if ($presence === null || $presence === '') {
            return true;
        }

        return $presence === 'punctual';
    }

    private static function clock(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }
        if (preg_match('/(\d{1,2}:\d{2})/', $value, $match)) {
            $parts = explode(':', $match[1]);

            return sprintf('%02d:%02d', (int) $parts[0], (int) $parts[1]);
        }

        return '';
    }
}
