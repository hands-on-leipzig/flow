import { ref, computed } from 'vue'
import keycloak from '@/keycloak.js'

const userRoles = ref([])

// Initialize user roles from Keycloak token
function initializeUserRoles() {
  if (keycloak.authenticated && keycloak.tokenParsed) {
    console.log('🔍 Keycloak token parsed:', keycloak.tokenParsed)
    console.log('🔍 Resource access:', keycloak.tokenParsed.resource_access)
    
    // Handle both plain objects and stdClass objects
    let roles = []
    if (keycloak.tokenParsed.resource_access?.flow?.roles) {
      roles = Array.isArray(keycloak.tokenParsed.resource_access.flow.roles) 
        ? keycloak.tokenParsed.resource_access.flow.roles 
        : []
    }
    
    console.log('🔍 Extracted roles:', roles)
    userRoles.value = roles
  } else {
    console.log('🔍 Keycloak not authenticated or no token parsed')
    console.log('🔍 Authenticated:', keycloak.authenticated)
    console.log('🔍 Token parsed:', keycloak.tokenParsed)
  }
}

// Check if user has admin role
// NOTE: This is for UI convenience only - actual authorization happens on the server
const isAdmin = computed(() => {
  const adminCheck = userRoles.value.includes('flow-admin') || userRoles.value.includes('flow_admin')
  console.log('🔍 isAdmin check:', { userRoles: userRoles.value, adminCheck })
  return adminCheck
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
      console.log('🔍 Re-initializing roles from keycloak')
      initializeUserRoles()
    }
  }, 1000)
  
  // Clear interval after 30 seconds
  setTimeout(() => clearInterval(checkInterval), 30000)
}

export function useAuth() {
  // Re-initialize roles when composable is used (in case keycloak wasn't ready before)
  if (keycloak.authenticated && keycloak.tokenParsed && userRoles.value.length === 0) {
    console.log('🔍 Re-initializing roles in useAuth()')
    initializeUserRoles()
  }
  
  return {
    userRoles: computed(() => userRoles.value),
    isAdmin,
    hasRole,
    initializeUserRoles
  }
}
