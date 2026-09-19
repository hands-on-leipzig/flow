<?php

declare(strict_types=1);

namespace App\Print;

use App\Services\PublicPlanService;
use Illuminate\Support\Facades\DB;

final class RoleSheetCatalog
{
    /** @var list<int> */
    public const OMIT_IDS = [6, 10, 14, 24];

    public function __construct(private PublicPlanService $publicPlan) {}

    /**
     * @return array{plan_id:int,event_id:int,title_short:string,programs:list<array<string,mixed>>,roles:list<array<string,mixed>>}|null
     */
    public function forEvent(int $eventId): ?array
    {
        $planId = DB::table('plan')->where('event', $eventId)->value('id');
        if ($planId === null) {
            return null;
        }
        $planId = (int) $planId;

        $payload = $this->publicPlan->getRoles($planId);

        return [
            'plan_id' => $planId,
            'event_id' => $eventId,
            'title_short' => (string) ($payload['title_short'] ?? ''),
            'programs' => $payload['programs'] ?? [],
            'roles' => self::filterRoles($payload['roles'] ?? []),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $roles
     * @return list<array<string, mixed>>
     */
    public static function filterRoles(array $roles): array
    {
        $kept = [];
        foreach ($roles as $role) {
            if (! self::omitRole($role)) {
                $kept[] = $role;
            }
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $role
     */
    public static function omitRole(array $role): bool
    {
        $id = (int) ($role['id'] ?? 0);
        $name = (string) ($role['name'] ?? '');
        if (in_array($id, self::OMIT_IDS, true) || $name === 'Publikum') {
            return true;
        }

        return false;
    }
}
