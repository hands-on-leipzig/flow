import {onMounted, onUnmounted, ref} from 'vue'
import {
  emailFromAccessToken,
  publicEventSsoRequestMessage,
  tokenFromPublicEventSsoMessage,
} from '@hands-on/glass/venues/sso'

function localPlannerToken() {
  try {
    return localStorage.getItem('kc_token') || ''
  } catch {
    return ''
  }
}

function isEmbedded() {
  return window.parent !== window
}

/**
 * SSO identity for the public event page: JOIN/HERO post a Keycloak token
 * into the embed iframe; same-origin FLOW planner sessions use kc_token.
 */
export function usePublicEventSso() {
  const ssoToken = ref('')
  const ssoEmail = ref('')

  function applyToken(token) {
    const email = emailFromAccessToken(token)
    if (!token || !email) return false
    ssoToken.value = token
    ssoEmail.value = email
    return true
  }

  function requestParentSso() {
    if (!isEmbedded()) return
    window.parent.postMessage(publicEventSsoRequestMessage(), '*')
  }

  function hydrateFromLocal() {
    if (isEmbedded() || ssoEmail.value) return
    applyToken(localPlannerToken())
  }

  function onMessage(event) {
    if (event.source !== window.parent) return
    const token = tokenFromPublicEventSsoMessage(event.data)
    if (!token) return
    applyToken(token)
  }

  onMounted(() => {
    window.addEventListener('message', onMessage)
    hydrateFromLocal()
    requestParentSso()
  })

  onUnmounted(() => {
    window.removeEventListener('message', onMessage)
  })

  function refreshSso() {
    requestParentSso()
    hydrateFromLocal()
  }

  function awaitSso(timeoutMs = 400) {
    refreshSso()
    if (ssoEmail.value || !isEmbedded()) return Promise.resolve()
    return new Promise((resolve) => {
      const started = Date.now()
      const timer = window.setInterval(() => {
        if (ssoEmail.value || Date.now() - started >= timeoutMs) {
          window.clearInterval(timer)
          resolve()
        }
      }, 50)
    })
  }

  return {ssoToken, ssoEmail, refreshSso, awaitSso}
}
