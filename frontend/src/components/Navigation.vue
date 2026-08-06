<script setup lang="ts">
import {Menu, MenuButton, MenuItems, MenuItem} from '@headlessui/vue'
import {onMounted, ref, computed, watch} from 'vue'
import {useRoute, useRouter} from 'vue-router'
import {useEventStore} from '@/stores/event'
import {usePlanCacheStore} from '@/stores/planCache'
import {useAuth} from '@/composables/useAuth'
import axios from 'axios'
import dayjs from 'dayjs'
import {imageUrl, programLogoSrc, programLogoAlt} from '@/utils/images'
import {getAbbreviatedCompetitionType} from '@/utils/eventTitle'
import keycloak from '@/keycloak.js'
import HelpModal from '@/components/atoms/HelpModal.vue'
import {theme, setTheme} from '@hands-on/glass/theme'
import AppShell from '@hands-on/glass/app-shell'
import SidebarFooter from '@hands-on/glass/sidebar-footer'
import SidebarNavItem from '@hands-on/glass/sidebar-nav-item'

const eventStore = useEventStore()
const planCache = usePlanCacheStore()
const {isAdmin, initializeUserRoles} = useAuth()
const router = useRouter()
const route = useRoute()

const userLabel = computed(() => {
  const parsed = keycloak?.tokenParsed as Record<string, unknown> | undefined
  const name = typeof parsed?.name === 'string' ? parsed.name.trim() : ''
  if (name) return name
  const preferred = typeof parsed?.preferred_username === 'string' ? parsed.preferred_username.trim() : ''
  return preferred || 'FLOW'
})

const selectableEvents = ref<any[]>([])
const loadingEvents = ref(false)
const userRegionalPartners = ref<number[]>([])

const showEventDropdown = computed(
    () => (dropdownEventsFlat.value.length > 1 || isAdmin.value) && eventStore.selectedEvent
)
const eventSearchQuery = ref('')
const eventSearchInput = ref<HTMLInputElement | null>(null)

const dropdownEventsFlat = computed(() => {
  if (!selectableEvents.value.length) return []
  return selectableEvents.value.flatMap((rp: any) =>
      (rp.events || []).map((e: any) => ({
        ...e,
        regional_partner_id: rp.regional_partner?.id,
        regional_partner_name: rp.regional_partner?.name
      }))
  )
})

async function fetchSelectableEvents() {
  loadingEvents.value = true
  try {
    const response = await axios.get('/events/selectable')
    selectableEvents.value = response.data || []
    if (isAdmin.value) {
      try {
        const rpResponse = await axios.get('/user/regional-partners')
        if (rpResponse.data?.regional_partners) {
          userRegionalPartners.value = rpResponse.data.regional_partners.map((rp: any) => rp.id)
        }
      } catch {
        if (import.meta.env.DEV) console.debug('Failed to fetch regional partners')
      }
    }
  } catch (error) {
    console.error('Failed to fetch selectable events:', error)
  } finally {
    loadingEvents.value = false
  }
}

const dropdownEvents = computed(() => {
  if (!dropdownEventsFlat.value.length) return dropdownEventsFlat.value
  if (isAdmin.value && userRegionalPartners.value.length > 0) {
    return dropdownEventsFlat.value.filter(
        (e: any) => userRegionalPartners.value.includes(e.regional_partner_id)
    )
  }
  return dropdownEventsFlat.value
})

const filteredDropdownEvents = computed(() => {
  const query = eventSearchQuery.value.trim().toLowerCase()
  if (!query) return dropdownEvents.value

  return dropdownEvents.value.filter((ev: any) => {
    const name = ev.name?.toLowerCase() || ''
    const regionalPartner = ev.regional_partner_name?.toLowerCase() || ''
    const date = dayjs(ev.date).format('DD.MM.YY').toLowerCase()
    return name.includes(query) || regionalPartner.includes(query) || date.includes(query)
  })
})

async function selectEventFromDropdown(event: any, regionalPartnerId: number) {
  try {
    await axios.post('/user/select-event', {
      event: event.id,
      regional_partner: regionalPartnerId
    })
    await eventStore.fetchSelectedEvent()
    mobileMenuOpen.value = false
    if (route.path.includes('/overview')) {
      await router.replace('/plan/overview')
    } else {
      void router.push('/plan/overview')
    }
  } catch (error) {
    console.error('Failed to select event:', error)
  }
}

function eventDropdownLabel() {
  const ev = eventStore.selectedEvent
  if (!ev) return 'Veranstaltung auswählen...'
  const type = getAbbreviatedCompetitionType(ev)
  const date = dayjs(ev.date).format('DD.MM.YY')
  return `${type} ${date}`.trim()
}

function focusSearchAfterDropdownOpen(event: MouseEvent) {
  if (!isAdmin.value) return
  const trigger = event.currentTarget as HTMLElement | null
  if (!trigger) return

  const tryFocus = () => {
    if (trigger.getAttribute('aria-expanded') !== 'true') return
    const input = eventSearchInput.value
    if (!input) return
    input.focus()
    input.setSelectionRange(0, input.value.length)
  }

  ;[0, 40, 120, 220].forEach((ms) => setTimeout(tryFocus, ms))
}

const readiness = ref({
  explore_teams_ok: true,
  challenge_teams_ok: true,
  room_mapping_ok: true
})

async function checkDataReadiness() {
  if (!eventStore.selectedEvent?.id) return
  const data = await eventStore.refreshReadiness(eventStore.selectedEvent.id)
  if (data) {
    readiness.value = {
      explore_teams_ok: !!data.explore_teams_ok,
      challenge_teams_ok: !!data.challenge_teams_ok,
      room_mapping_ok: !!data.room_mapping_ok,
    }
  } else {
    readiness.value = {
      explore_teams_ok: false,
      challenge_teams_ok: false,
      room_mapping_ok: false,
    }
  }
}

type NavChild = {
  name: string
  path: string
  icon?: string
}

type NavEntry = {
  name: string
  path?: string
  icon: string
  children?: NavChild[]
}

const navEntries = computed<NavEntry[]>(() => [
  {name: 'Übersicht', path: '/plan/overview', icon: 'bi-house-door'},
  {
    name: 'Ablauf',
    path: '/plan/schedule',
    icon: 'bi-list-check',
    children: [
      {name: 'Allgemein', path: '/plan/schedule', icon: 'bi-sliders2-vertical'},
      {name: 'Expertenparameter', path: '/plan/schedule/expert', icon: 'bi-gear-wide-connected'},
    ],
  },
  {
    name: 'Zusätzliche Aktivitäten',
    path: '/plan/schedule/blocks',
    icon: 'bi-calendar-plus',
    children: [
      {name: 'Feste Blöcke', path: '/plan/schedule/blocks', icon: 'bi-puzzle'},
      {name: 'Freie Blöcke', path: '/plan/schedule/free', icon: 'bi-calendar2-plus'},
      {name: 'Slots', path: '/plan/schedule/slots', icon: 'bi-grid-3x3-gap'},
    ],
  },
  {name: 'Teams', path: '/plan/teams', icon: 'bi-people'},
  {name: 'Räume', path: '/plan/rooms', icon: 'bi-door-open'},
  {name: 'Logos', path: '/plan/logos', icon: 'bi-images'},
  {
    name: 'Ausgabe',
    path: '/plan/publish',
    icon: 'bi-broadcast',
    children: [
      {name: 'Verteilung', path: '/plan/publish', icon: 'bi-link-45deg'},
      {name: 'Digital', path: '/plan/publish/digital', icon: 'bi-display'},
      {name: 'Analog', path: '/plan/publish/analog', icon: 'bi-printer'},
    ],
  },
  {name: 'am Tag', path: '/plan/live', icon: 'bi-play-circle'},
])

const liveTabPath = '/plan/live'
const isLiveTabActive = computed(() => isActive(liveTabPath))

function entryWarning(entry: NavEntry): boolean {
  if (entry.children?.length) {
    return entry.children.some((child) => hasWarning(child.path))
  }
  return !!entry.path && hasWarning(entry.path)
}

/** Parents with children: only highlight via active child (not own default path). */
function entryActive(entry: NavEntry): boolean {
  if (entry.children?.length) {
    return entry.children.some((child) => isActive(child.path))
  }
  return !!entry.path && isActive(entry.path)
}

function childNavProps(child: NavChild) {
  return {
    id: child.path,
    label: child.name,
    icon: child.icon,
    path: child.path,
    active: isActive(child.path),
    warning: hasWarning(child.path),
  }
}

onMounted(async () => {
  initializeUserRoles()
  if (!eventStore.selectedEvent) {
    await eventStore.fetchSelectedEvent()
  }
  // Sidebar warnings only — keep this light so Übersicht/SharePoint stay first
  await checkDataReadiness()
  // Event switcher list can wait; don't compete with homepage APIs
  if (typeof window !== 'undefined' && 'requestIdleCallback' in window) {
    window.requestIdleCallback(() => {
      void fetchSelectableEvents()
    }, {timeout: 8000})
  } else {
    setTimeout(() => {
      void fetchSelectableEvents()
    }, 2000)
  }
})

watch(
    () => eventStore.readiness,
    (newVal) => {
      if (newVal) {
        readiness.value = {
          explore_teams_ok: !!newVal.explore_teams_ok,
          challenge_teams_ok: !!newVal.challenge_teams_ok,
          room_mapping_ok: !!newVal.room_mapping_ok,
        }
      }
    },
    {deep: true, immediate: true}
)

watch(
    () => eventStore.selectedEvent?.id,
    async (newId, oldId) => {
      if (oldId && newId !== oldId) {
        planCache.clear()
      }
      if (newId) {
        await checkDataReadiness()
      }
      // Prefetch is owned by HomeOverview (after homepage data). Event list can wait.
      if (typeof window !== 'undefined' && 'requestIdleCallback' in window) {
        window.requestIdleCallback(() => {
          void fetchSelectableEvents()
        }, {timeout: 8000})
      } else {
        void fetchSelectableEvents()
      }
    }
)

watch(
    () => showEventDropdown.value,
    (isVisible) => {
      if (!isVisible) {
        eventSearchQuery.value = ''
      }
    }
)

function hasWarning(tabPath: string): boolean {
  if (!readiness.value) return false
  const path = normalizePlanPath(tabPath)

  switch (path) {
    case '/plan/teams':
      return eventStore.selectedEvent?.hasTeamDiscrepancy
    case '/plan/schedule':
    case '/plan/schedule/expert':
      return !readiness.value.explore_teams_ok || !readiness.value.challenge_teams_ok
    case '/plan/rooms':
      return !readiness.value.room_mapping_ok
    default:
      return false
  }
}

const showHelpModal = ref(false)
const mobileMenuOpen = ref(false)

function openHelpModal() {
  showHelpModal.value = true
  mobileMenuOpen.value = false
}

function closeHelpModal() {
  showHelpModal.value = false
}

function normalizePlanPath(path: string): string {
  const raw = (path || '').trim()
  if (!raw) return '/plan/overview'
  if (raw.startsWith('/plan/') || raw === '/plan') return raw.replace(/\/$/, '') || '/plan'
  if (raw.startsWith('/')) return (`/plan${raw}`).replace(/\/$/, '')
  return (`/plan/${raw}`).replace(/\/$/, '')
}

function isActive(path: string) {
  const target = normalizePlanPath(path)
  const current = route.path.replace(/\/$/, '') || '/'
  return current === target
}

function goToPath(path: string) {
  const target = normalizePlanPath(path)
  mobileMenuOpen.value = false
  if (isActive(target)) return
  void router.push(target)
}

function onNavSelect(entry: NavEntry) {
  if (entry.path) goToPath(entry.path)
}

function onNavChildSelect(child: { path?: string; label?: string }) {
  if (child?.path) goToPath(child.path)
}

function toggleMobileMenu() {
  mobileMenuOpen.value = !mobileMenuOpen.value
}

function logout() {
  localStorage.removeItem('kc_token')

  if (keycloak.authenticated) {
    keycloak.logout({
      redirectUri: window.location.origin
    })
  } else {
    window.location.reload()
  }
}
</script>

<template>
  <AppShell
      :open="mobileMenuOpen"
      menu-aria-label="Menü öffnen"
      @toggle="toggleMobileMenu"
      @update:open="mobileMenuOpen = $event"
  >
    <template #brand>
      <RouterLink to="/plan/overview" class="glass-sidebar__brand" @click="mobileMenuOpen = false">
        <img :src="imageUrl('/flow/flow.png')" alt="FLOW" class="glass-sidebar__brand-logo"/>
      </RouterLink>
    </template>

    <template #nav>
      <SidebarNavItem
          v-if="isLiveTabActive"
          label="Zurück zur Übersicht"
          icon="bi-arrow-left"
          @select="goToPath('/plan/overview')"
        />

      <SidebarNavItem
          v-for="entry in navEntries"
          :key="entry.path ?? entry.name"
          :label="entry.name"
          :icon="entry.icon"
          :active="entryActive(entry)"
          :warning="entryWarning(entry)"
          :children="entry.children?.map(childNavProps)"
          @select="onNavSelect(entry)"
          @select-child="onNavChildSelect"
      />
    </template>

    <template #lower="{ collapsed }">
      <SidebarFooter
          identity-aria-label="Account"
          settings-aria-label="Einstellungen"
      >
        <template v-if="showEventDropdown" #prepend>
          <Menu as="div" class="relative w-full">
            <MenuButton
                @click="focusSearchAfterDropdownOpen($event)"
                class="glass-sidebar__item w-full"
                :title="collapsed ? eventDropdownLabel() : undefined"
            >
              <span class="glass-sidebar__item-icon"><i class="bi bi-calendar2-event" aria-hidden="true"/></span>
              <span class="glass-sidebar__item-label truncate">{{ eventDropdownLabel() }}</span>
            </MenuButton>
            <MenuItems
                :class="[
                  'absolute z-50 origin-bottom-left rounded-xl liquid-surface liquid-surface--radius-lg focus:outline-none w-[min(100%,20rem)] max-h-[50vh] overflow-y-auto',
                  collapsed
                    ? 'left-full bottom-0 ml-2'
                    : 'left-0 bottom-full mb-2',
                ]"
            >
              <div class="py-2">
                <div v-if="isAdmin" class="px-3 pb-2">
                  <input
                      ref="eventSearchInput"
                      v-model="eventSearchQuery"
                      type="text"
                      placeholder="Veranstaltung suchen..."
                      class="w-full px-3 py-2 text-sm liquid-surface-control"
                  />
                </div>
                <div v-if="loadingEvents" class="px-4 py-4 text-center text-sm text-[var(--color-text-muted)]">
                  Lade...
                </div>
                <div v-else-if="filteredDropdownEvents.length === 0"
                     class="px-4 py-4 text-center text-sm text-[var(--color-text-muted)]">
                  Keine Veranstaltungen gefunden.
                </div>
                <template v-else>
                  <MenuItem
                      v-for="ev in filteredDropdownEvents"
                      :key="ev.id"
                      v-slot="{ active }"
                  >
                    <button
                        @click="selectEventFromDropdown(ev, ev.regional_partner_id)"
                        :class="[
                      'w-full text-left px-4 py-3 text-sm transition-colors',
                      active ? 'bg-[var(--color-bg-hover)]' : '',
                      eventStore.selectedEvent?.id === ev.id ? 'border-l-[3px] border-[var(--color-accent)]' : ''
                    ]"
                    >
                      <div class="flex justify-between items-start gap-2 min-w-0">
                        <div class="flex-1 min-w-0">
                          <div class="font-medium truncate">{{ ev.name }}</div>
                          <div class="text-xs text-[var(--color-text-muted)]">
                            {{ dayjs(ev.date).format('DD.MM.YY') }} · {{ ev.regional_partner_name }}
                          </div>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                          <img v-if="ev.event_explore" :src="programLogoSrc('E')" :alt="programLogoAlt('E')"
                               class="w-5 h-5"/>
                          <img v-if="ev.event_challenge" :src="programLogoSrc('C')" :alt="programLogoAlt('C')"
                               class="w-5 h-5"/>
                        </div>
                      </div>
                    </button>
                  </MenuItem>
                  <MenuItem v-if="isAdmin" v-slot="{ active }">
                    <button
                        @click="router.push({ path: '/events' }); mobileMenuOpen = false"
                        :class="['w-full text-left px-4 py-3 text-sm border-t border-[var(--color-border)]', active ? 'bg-[var(--color-bg-hover)]' : 'text-[var(--color-accent)]']"
                    >
                      Mehr Veranstaltungen...
                    </button>
                  </MenuItem>
                </template>
              </div>
            </MenuItems>
          </Menu>
        </template>

        <template #identity="{ close }">
          <div class="glass-sidebar-footer__menu-header">
            <span class="glass-sidebar-footer__menu-title">{{ userLabel }}</span>
            <span class="glass-sidebar-footer__menu-subtitle">FLOW</span>
          </div>
          <button
              type="button"
              class="glass-sidebar-footer__menu-item glass-sidebar-footer__menu-item--danger"
              role="menuitem"
              @click="logout(); close()"
          >
            <i class="bi bi-box-arrow-right" aria-hidden="true"/>
            <span>Logout</span>
          </button>
        </template>

        <template #settings="{ close }">
          <div class="glass-sidebar-footer__prefs">
            <div class="glass-sidebar-footer__prefs-block">
              <span class="glass-sidebar-footer__menu-label">Theme</span>
              <div class="glass-sidebar-footer__prefs-row" role="group" aria-label="Theme">
                <button
                    type="button"
                    class="glass-sidebar-footer__pref-btn"
                    :class="{ active: theme === 'light' }"
                    :aria-pressed="theme === 'light'"
                    @click="setTheme('light')"
                >
                  <i class="bi bi-sun-fill" aria-hidden="true"/>
                  <span>Hell</span>
                </button>
                <button
                    type="button"
                    class="glass-sidebar-footer__pref-btn"
                    :class="{ active: theme === 'dark' }"
                    :aria-pressed="theme === 'dark'"
                    @click="setTheme('dark')"
                >
                  <i class="bi bi-moon-fill" aria-hidden="true"/>
                  <span>Dunkel</span>
                </button>
              </div>
            </div>
          </div>
          <button
              v-if="isAdmin"
              type="button"
              class="glass-sidebar-footer__menu-item"
              role="menuitem"
              @click="goToPath('/plan/admin'); close()"
          >
            <i class="bi bi-shield-lock" aria-hidden="true"/>
            <span>Admin</span>
          </button>
          <button
              type="button"
              class="glass-sidebar-footer__menu-item"
              role="menuitem"
              @click="openHelpModal(); close()"
          >
            <i class="bi bi-question-circle" aria-hidden="true"/>
            <span>Hilfe</span>
          </button>
        </template>

        <template #partners>
          <img
              :src="imageUrl('/flow/first+fll_v.png')"
              alt="FIRST LEGO League"
              class="glass-sidebar__partner-logo glass-sidebar__partner-logo--primary"
              decoding="async"
          />
          <a
              href="https://www.hands-on-technology.org"
              target="_blank"
              rel="noopener noreferrer"
              class="glass-sidebar__partner-link"
          >
            <img
                :src="imageUrl('/flow/hot.png')"
                alt="HANDS on TECHNOLOGY"
                class="glass-sidebar__partner-logo glass-sidebar__partner-logo--secondary"
            />
          </a>
        </template>
      </SidebarFooter>
    </template>

    <slot />

    <HelpModal :show="showHelpModal" @close="closeHelpModal"/>
  </AppShell>
</template>

