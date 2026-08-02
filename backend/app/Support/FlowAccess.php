<?php

namespace App\Support;

/**
 * Keycloak role helpers for FLOW.
 *
 * Gate roles (client "flow" or realm):
 * - flow_user   → may use FLOW (intended via Keycloak groups like Regionalpartner / Geschäftsstelle MA)
 * - flow_admin  → full admin
 * - flow-tester → local/staging only
 *
 * Legacy role names are still accepted during the Keycloak migration.
 */
class FlowAccess
{
    public const ROLE_USER = 'flow_user';
    public const ROLE_ADMIN = 'flow_admin';
    public const ROLE_TESTER = 'flow-tester';

    public const SOURCE_DRAHT = 'draht';
    public const SOURCE_MANUAL = 'manual';

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

    /**
     * Environment gate: who may call authenticated FLOW APIs at all.
     */
    public static function canAccessApp(array $roles, string $env): bool
    {
        if (in_array($env, ['local', 'staging'], true)) {
            return self::isTester($roles);
        }

        return self::isFlowUser($roles);
    }
}
