<?php

namespace App\Support;

final class DrahtContactId
{
    /**
     * Dolibarr/DRAHT contact id from a Keycloak access token.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function fromClaims(array $claims): ?int
    {
        foreach (['dolibarr_contact_id', 'dolibarrContactId', 'dolibarr_id', 'dolibarrId'] as $claim) {
            $value = $claims[$claim] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $id = (int) $value;
            if ($id > 0) {
                return $id;
            }
        }

        return null;
    }
}
