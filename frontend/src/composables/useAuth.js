import { ref, computed } from 'vue'
import keycloak from '@/keycloak.js'

const userRoles = ref([])

// Initialize user roles from Keycloak token
function initializeUserRoles() {
  if (keycloak.authenticated && keycloak.tokenParsed) {
    // Handle both plain objects and stdClass objects
    let roles = []
    if (keycloak.tokenParsed.resource_access?.flow?.roles) {
      roles = Array.isArray(keycloak.tokenParsed.resource_access.flow.roles) 
        ? keycloak.tokenParsed.resource_access.flow.roles 
        : []
    }
    
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

// Re-initialize roles when keycloak state changes
if (typeof window !== 'undefined') {
  // Check periodically if keycloak becomes available
  const checkInterval = setInterval(() => {
    if (keycloak.authenticated && keycloak.tokenParsed && userRoles.value.length === 0) {
      initializeUserRoles()
    }
  }, 1000)
  
  // Clear interval after 30 seconds
  setTimeout(() => clearInterval(checkInterval), 30000)
}

export function useAuth() {
  // Re-initialize roles when composable is used (in case keycloak wasn't ready before)
  if (keycloak.authenticated && keycloak.tokenParsed && userRoles.value.length === 0) {
    initializeUserRoles()
  }
  
  return {
    userRoles: computed(() => userRoles.value),
    isAdmin,
    hasRole,
    initializeUserRoles
  }
}
