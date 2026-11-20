import {ref} from 'vue'

export type WiseRoles = {
  referee: boolean
  jury: boolean
  results: boolean
}

const roles = ref<WiseRoles | null>(null)

export function useWiseRoles() {
  function setRoles(next: WiseRoles | null) {
    roles.value = next
  }

  return {roles, setRoles}
}
