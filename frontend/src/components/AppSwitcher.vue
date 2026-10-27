<script setup lang="ts">
import {computed, onBeforeUnmount, ref, watch} from 'vue'
import {useRouter} from 'vue-router'
import keycloak from '@/keycloak.js'
import {imageUrl} from '@/utils/images'
import ScorLogo from '@/components/scor/ScorLogo.vue'

const props = defineProps<{
  canFlow: boolean
  canScor: boolean
  scorMode: boolean
}>()

const router = useRouter()
const open = ref(false)
const rootEl = ref<HTMLElement | null>(null)
const menuStyle = ref<Record<string, string>>({})

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
    items.push({id: 'scor', label: 'SCOR', href: '/scor', internal: true, current: props.scorMode})
  }
  if (coach) {
    items.push({id: 'join', label: 'JOIN', href: externalUrl('join'), internal: false, current: false})
  }
  if (hero) {
    items.push({id: 'hero', label: 'HERO', href: externalUrl('hero'), internal: false, current: false})
  }
  return items
})

const canSwitch = computed(() => choices.value.length > 1)

function placeMenu() {
  const button = rootEl.value?.querySelector('.app-brand__switch')
  if (!(button instanceof HTMLElement)) return
  const rect = button.getBoundingClientRect()
  const width = 220
  const left = Math.min(rect.left, window.innerWidth - width - 12)
  menuStyle.value = {
    top: `${rect.bottom + 8}px`,
    left: `${Math.max(8, left)}px`,
    width: `${width}px`,
  }
}

function toggle() {
  open.value = !open.value
  if (open.value) placeMenu()
}

function close() {
  open.value = false
}

function onDocumentPointer(event: PointerEvent) {
  if (!open.value) return
  const target = event.target
  if (target instanceof Node && rootEl.value?.contains(target)) return
  close()
}

function onKey(event: KeyboardEvent) {
  if (event.key === 'Escape') close()
}

watch(open, (isOpen) => {
  if (isOpen) {
    document.addEventListener('pointerdown', onDocumentPointer)
    document.addEventListener('keydown', onKey)
    window.addEventListener('resize', placeMenu)
  } else {
    document.removeEventListener('pointerdown', onDocumentPointer)
    document.removeEventListener('keydown', onKey)
    window.removeEventListener('resize', placeMenu)
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocumentPointer)
  document.removeEventListener('keydown', onKey)
  window.removeEventListener('resize', placeMenu)
})

function openInternal(href: string) {
  close()
  void router.push(href)
}
</script>

<template>
  <div ref="rootEl" class="app-brand">
    <RouterLink
        :to="scorMode ? '/scor' : '/plan/overview'"
        class="app-brand__logo"
        @click="close"
    >
      <ScorLogo v-if="scorMode"/>
      <img v-else :src="imageUrl('/flow/flow.png')" alt="FLOW" class="glass-sidebar__brand-logo"/>
    </RouterLink>

    <button
        v-if="canSwitch"
        type="button"
        class="app-brand__switch glass-btn-icon"
        aria-label="App wechseln"
        :aria-expanded="open"
        @click.stop="toggle"
    >
      <i class="bi bi-arrow-left-right" aria-hidden="true"/>
    </button>

    <div
        v-if="open"
        class="app-brand__menu glass-card liquid-surface"
        :style="menuStyle"
        role="menu"
        aria-label="Apps"
    >
      <template v-for="app in choices" :key="app.id">
        <button
            v-if="app.internal"
            type="button"
            class="app-brand__choice"
            :class="{'is-current': app.current}"
            role="menuitem"
            @click="openInternal(app.href)"
        >
          <span class="app-brand__choice-logo">
            <img :src="imageUrl(`/flow/${app.id}.png`)" :alt="app.label">
          </span>
          <i v-if="app.current" class="bi bi-check-lg" aria-hidden="true"/>
        </button>
        <a
            v-else
            class="app-brand__choice"
            role="menuitem"
            :href="app.href"
        >
          <span class="app-brand__choice-logo">
            <img :src="imageUrl(`/flow/${app.id}.png`)" :alt="app.label">
          </span>
        </a>
      </template>
    </div>
  </div>
</template>

<style scoped>
.app-brand {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.35rem;
  width: 100%;
  padding-bottom: 0.35rem;
}

.app-brand__logo {
  display: block;
  width: 100%;
  min-width: 0;
  text-decoration: none;
}

.app-brand__logo .glass-sidebar__brand-logo,
.app-brand__logo :deep(.scor-logo) {
  max-height: 2.5rem;
}

.app-brand__switch {
  width: 2.25rem;
  height: 2.25rem;
}

.app-brand__menu {
  position: fixed;
  z-index: 80;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  padding: 0.45rem;
}

.app-brand__choice {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  min-height: 2.75rem;
  padding: 0.35rem 0.55rem;
  border: 0;
  border-radius: var(--radius);
  background: transparent;
  color: inherit;
  text-decoration: none;
  text-align: left;
  cursor: pointer;
}

.app-brand__choice:hover,
.app-brand__choice:focus-visible {
  background: var(--color-bg-hover);
}

.app-brand__choice.is-current {
  background: color-mix(in srgb, var(--color-accent) 16%, transparent);
}

.app-brand__choice-logo {
  display: flex;
  align-items: center;
  height: 1.65rem;
  width: 7.5rem;
}

.app-brand__choice-logo img {
  height: 100%;
  width: auto;
  max-height: none;
  max-width: 100%;
  object-fit: contain;
  object-position: left center;
}

.app-brand__choice .bi {
  color: var(--color-accent);
  font-size: 1.1rem;
}
</style>

<style>
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
</style>
