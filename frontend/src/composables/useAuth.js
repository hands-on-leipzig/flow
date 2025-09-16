import { ref, computed } from 'vue'
import keycloak from '@/keycloak.js'

const userRoles = ref([])

// Initialize user roles from Keycloak token
function initializeUserRoles() {
  if (keycloak.authenticated && keycloak.tokenParsed) {
    const roles = keycloak.tokenParsed.resource_access?.flow?.roles || []
    userRoles.value = roles
  }
}

// Check if user has admin role
// NOTE: This is for UI convenience only - actual authorization happens on the server
const isAdmin = computed(() => {
  return userRoles.value.includes('flow-admin') || userRoles.value.includes('flow_admin')
})

// Check if user has specific role
// NOTE: This is for UI convenience only - actual authorization happens on the server
function hasRole(role) {
  return userRoles.value.includes(role)
}

// Initialize roles when composable is first used
initializeUserRoles()

export function useAuth() {
  return {
    userRoles: computed(() => userRoles.value),
    isAdmin,
    hasRole,
    initializeUserRoles
  }
}
