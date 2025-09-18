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
        // Allow admins to update any logo
        $roles = $user->getRoles();
        if (in_array('flow-admin', $roles) || in_array('flow_admin', $roles)) {
            return true;
        }
        
        // Allow users to update logos from their regional partner
        return $user->selection_regional_partner == $logo->regional_partner;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Logo $logo): bool
    {
        // Allow admins to delete any logo
        $roles = $user->getRoles();
        if (in_array('flow-admin', $roles) || in_array('flow_admin', $roles)) {
            return true;
        }
        
        // Allow users to delete logos from their regional partner
        return $user->selection_regional_partner == $logo->regional_partner;
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
