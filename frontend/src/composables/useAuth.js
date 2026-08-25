import { ref, computed } from 'vue'
import keycloak from '@/keycloak.js'

const userRoles = ref([])

function collectRolesFromToken(tokenParsed) {
  const roles = []
  const clientRoles = tokenParsed?.resource_access?.flow?.roles
  if (Array.isArray(clientRoles)) {
    roles.push(...clientRoles)
  }
  const realmRoles = tokenParsed?.realm_access?.roles
  if (Array.isArray(realmRoles)) {
    roles.push(...realmRoles)
  }
  return [...new Set(roles)]
}

// Initialize user roles from Keycloak token
function initializeUserRoles() {
  if (keycloak.authenticated && keycloak.tokenParsed) {
    userRoles.value = collectRolesFromToken(keycloak.tokenParsed)
  }
}

// NOTE: UI convenience only — actual authorization happens on the server
const isAdmin = computed(() => {
  return userRoles.value.includes('flow-admin') || userRoles.value.includes('flow_admin')
})

// Intended production gate role (Keycloak groups like Regionalpartner grant this)
const isFlowUser = computed(() => {
  return (
    userRoles.value.includes('flow_user') ||
    userRoles.value.includes('flow-user') ||
    userRoles.value.includes('regionalpartner') || // legacy
    userRoles.value.includes('Geschäftsstelle MA') || // legacy
    isAdmin.value
  )
})

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
    isFlowUser,
    hasRole,
    initializeUserRoles
  }
}
