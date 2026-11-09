<script setup lang="ts">
import {computed} from 'vue'
import {useRouter} from 'vue-router'
import keycloak from '@/keycloak.js'
import {imageUrl} from '@/utils/images'
import ScorLogo from '@/components/scor/ScorLogo.vue'
import AppSwitch from '@hands-on/glass/app-switch'

const props = defineProps<{
  canFlow: boolean
  canScor: boolean
  scorMode: boolean
  buttonOnly?: boolean
}>()

const router = useRouter()

type AppChoice = {
  id: string
  label: string
  href: string
  internal: boolean
  current: boolean
}

function tokenRoles(): string[] {
  const parsed = keycloak?.tokenParsed as {
    realm_access?: {roles?: string[]}
    resource_access?: Record<string, {roles?: string[]}>
  } | undefined
  const roles = [...(parsed?.realm_access?.roles || [])]
  const clients = parsed?.resource_access || {}
  for (const client of Object.values(clients)) {
    if (Array.isArray(client?.roles)) roles.push(...client.roles)
  }
  return roles.map((role) => role.toLowerCase())
}

function externalUrl(app: string): string {
  const host = window.location.hostname
  const tier = host.startsWith('dev.') ? 'dev.' : host.startsWith('test.') ? 'test.' : ''
  return `https://${tier}${app}.hands-on-technology.org`
}

const choices = computed<AppChoice[]>(() => {
  const roles = tokenRoles()
  const coach = roles.includes('coach')
  const hero = roles.some((role) => role === 'hero-user' || role === 'hero_user' || role === 'hero_admin' || role === 'hero-admin')
  const items: AppChoice[] = []
  if (props.canFlow) {
    items.push({id: 'flow', label: 'FLOW', href: '/plan/overview', internal: true, current: !props.scorMode})
  }
  if (props.canScor) {
    items.push({id: 'scor', label: 'WISE', href: '/scor', internal: true, current: props.scorMode})
  }
  if (coach) {
    items.push({id: 'join', label: 'JOIN', href: externalUrl('join'), internal: false, current: false})
  }
  if (hero) {
    items.push({id: 'hero', label: 'HERO', href: externalUrl('hero'), internal: false, current: false})
  }
  return items
})

const menuApps = computed(() => choices.value.map((app) => ({
  ...app,
  logo: imageUrl(app.id === 'scor' ? '/flow/wise.png' : `/flow/${app.id}.png`),
})))

function openInternal(app: {href: string}) {
  void router.push(app.href)
}
</script>

<template>
  <AppSwitch
      v-if="buttonOnly"
      :apps="menuApps"
      @select="openInternal"
  />
  <div v-else class="app-brand">
    <RouterLink
        :to="scorMode ? '/scor' : '/plan/overview'"
        class="app-brand__logo"
    >
      <ScorLogo v-if="scorMode"/>
      <img v-else :src="imageUrl('/flow/flow.png')" alt="FLOW" class="glass-sidebar__brand-logo"/>
    </RouterLink>
    <AppSwitch :apps="menuApps" @select="openInternal"/>
  </div>
</template>

<style scoped>
.app-brand {
  display: flex;
  flex-direction: row;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  width: 100%;
  padding-bottom: 0.35rem;
}

.app-brand__logo {
  display: flex;
  align-items: center;
  flex: 1 1 auto;
  width: auto;
  min-width: 0;
  text-decoration: none;
}

.app-brand__logo .glass-sidebar__brand-logo,
.app-brand__logo :deep(.scor-logo) {
  max-height: 2.5rem;
}

</style>

<style>
.glass-sidebar--collapsed .app-brand {
  flex-direction: column;
  align-items: center;
  width: 100%;
}

.glass-sidebar--collapsed .app-brand__logo {
  width: 2.5rem;
  height: 2.35rem;
  overflow: hidden;
}

.glass-sidebar--collapsed .app-brand__logo .glass-sidebar__brand-logo,
.glass-sidebar--collapsed .app-brand__logo .scor-logo {
  width: auto;
  max-width: none;
  height: 2.35rem;
  max-height: 2.35rem;
}

.scor-logo--mark {
  display: none;
}

.glass-sidebar--collapsed .app-brand__logo .scor-logo--word {
  display: none;
}

.glass-sidebar--collapsed .app-brand__logo .scor-logo--mark {
  display: block;
}
</style>
