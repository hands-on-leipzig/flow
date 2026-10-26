<?php

namespace App\Support;

use App\Models\User;

/**
 * Keycloak role helpers for FLOW.
 *
 * Gate roles (client "flow" or realm):
 * - flow_user   → may use FLOW (intended via Keycloak groups like Regionalpartner / Geschäftsstelle MA)
 * - flow_admin  → full admin
 * - flow-tester → local/staging only
 *
 * Legacy role names are still accepted during the Keycloak migration.
 *
 * App permissions live in FLOW, not in Keycloak roles.
 * Draht contacts get FLOW and SCOR for every event of their regional partner (`user_regional_partner`).
 * Everyone else is invited per event (`event_invitation` → `event_user_access`).
 * Keycloak only authenticates. `flow_user` still opens FLOW for staff without an event grant.
 */
class FlowAccess
{
    public const ROLE_USER = 'flow_user';
    public const ROLE_ADMIN = 'flow_admin';
    public const ROLE_TESTER = 'flow-tester';

    public const SOURCE_DRAHT = 'draht';
    public const SOURCE_MANUAL = 'manual';

    public const PERMISSION_FLOW = 'flow';
    public const PERMISSION_SCOR = 'scor';

    public const PERMISSIONS = [self::PERMISSION_FLOW, self::PERMISSION_SCOR];

    public static function permissionColumn(string $permission): string
    {
        if (!in_array($permission, self::PERMISSIONS, true)) {
            throw new \InvalidArgumentException("Unknown permission: {$permission}");
        }

        return 'can_'.$permission;
    }

    public static function permissionForPath(string $path): string
    {
        return str_starts_with($path, 'api/scor') ? self::PERMISSION_SCOR : self::PERMISSION_FLOW;
    }

    /**
     * @param  object|array|null  $jwt
     * @return list<string>
     */
    public static function rolesFromJwt(object|array|null $jwt): array
    {
        if (!$jwt) {
            return [];
        }

        $jwt = is_array($jwt) ? (object) $jwt : $jwt;
        $roles = [];

        $clientRoles = data_get($jwt, 'resource_access.flow.roles', []);
        if (is_object($clientRoles)) {
            $clientRoles = (array) $clientRoles;
        }
        if (is_array($clientRoles)) {
            $roles = array_merge($roles, $clientRoles);
        }

        $realmRoles = data_get($jwt, 'realm_access.roles', []);
        if (is_object($realmRoles)) {
            $realmRoles = (array) $realmRoles;
        }
        if (is_array($realmRoles)) {
            $roles = array_merge($roles, $realmRoles);
        }

        return array_values(array_unique(array_map('strval', $roles)));
    }

    public static function hasAny(array $roles, array $candidates): bool
    {
        foreach ($candidates as $role) {
            if (in_array($role, $roles, true)) {
                return true;
            }
        }

        return false;
    }

    public static function isAdmin(array $roles): bool
    {
        return self::hasAny($roles, [self::ROLE_ADMIN, 'flow-admin']);
    }

    public static function isTester(array $roles): bool
    {
        return self::hasAny($roles, [self::ROLE_TESTER, 'flow_tester']);
    }

    public static function isFlowUser(array $roles): bool
    {
        return self::hasAny($roles, [
            self::ROLE_USER,
            'flow-user',
            // Legacy: these used to be the production gate before Keycloak groups → flow_user
            'regionalpartner',
            'Geschäftsstelle MA',
        ]) || self::isAdmin($roles);
    }

    public static function isTesterEnvironment(string $env): bool
    {
        return in_array($env, ['local', 'staging'], true);
    }

    /**
     * App gate: who may call authenticated FLOW (or SCOR) APIs at all.
     * Event / regional partner checks still apply on top.
     */
    public static function canUse(array $roles, User $user, string $permission, string $env): bool
    {
        if (self::isTesterEnvironment($env)) {
            return self::isTester($roles);
        }

        if (self::isAdmin($roles)) {
            return true;
        }

        if ($permission === self::PERMISSION_FLOW && self::isFlowUser($roles)) {
            return true;
        }

        return $user->hasPermission($permission);
    }
}
