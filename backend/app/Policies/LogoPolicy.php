<?php

namespace App\Policies;

use App\Models\Logo;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class LogoPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true; // Allow all authenticated users to view logos
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Logo $logo): bool
    {
        return true; // Allow all authenticated users to view logos
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true; // Allow all authenticated users to create logos
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Logo $logo): bool
    {
        return $user->hasRegionalPartnerAccess((int) $logo->regional_partner);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Logo $logo): bool
    {
        return $user->hasRegionalPartnerAccess((int) $logo->regional_partner);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Logo $logo): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Logo $logo): bool
    {
        return false;
    }
}
