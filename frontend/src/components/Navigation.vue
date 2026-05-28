<script setup lang="ts">
import {Menu, MenuButton, MenuItems, MenuItem} from '@headlessui/vue'
import {onMounted, ref, computed, watch} from 'vue'
import {useRoute, useRouter} from 'vue-router'
import {useEventStore} from '@/stores/event'
import {useAuth} from '@/composables/useAuth'
import axios from 'axios'
import dayjs from 'dayjs'
import {imageUrl, programLogoSrc, programLogoAlt} from '@/utils/images'
import {getAbbreviatedCompetitionType} from '@/utils/eventTitle'
import keycloak from '@/keycloak.js'
import HelpModal from '@/components/atoms/HelpModal.vue'
import {theme, toggleTheme} from '@handson/glass/theme'

const eventStore = useEventStore()
const {isAdmin, initializeUserRoles} = useAuth()
const router = useRouter()
const route = useRoute()

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
    if (route.path.includes('/event')) {
      await router.replace('/event')
    } else {
      router.push('/event')
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

const tabs = computed(() => [
  {name: 'Veranstaltung', path: '/event'},
  {name: 'Ablauf', path: '/schedule'},
  {name: 'Slots', path: '/slots'},
  {name: 'Teams', path: '/teams'},
  {name: 'Räume', path: '/rooms'},
  {name: 'Logos', path: '/logos'},
  {name: 'Ausgabe', path: '/publish'},
  {name: 'am Tag', path: '/live'},
])

const liveTabPath = '/live'
const isLiveTabActive = computed(() => isActive(liveTabPath))

onMounted(async () => {
  initializeUserRoles()
  if (!eventStore.selectedEvent) {
    await eventStore.fetchSelectedEvent()
  }
  await checkDataReadiness()
  await fetchSelectableEvents()
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
    () => route.path,
    async () => {
      if (eventStore.selectedEvent?.id) {
        await checkDataReadiness()
      }
    }
)

watch(
    () => eventStore.selectedEvent?.id,
    () => fetchSelectableEvents()
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

  switch (tabPath) {
    case '/teams':
      return eventStore.selectedEvent?.hasTeamDiscrepancy
    case '/schedule':
      return !readiness.value.explore_teams_ok || !readiness.value.challenge_teams_ok
    case '/rooms':
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

function isActive(path: string) {
  const cleanPath = path.replace(/^\//, '')
  return route.path.endsWith('/' + cleanPath) || route.path === '/plan/' + cleanPath
}

function goTo(tab: { name: string; path: string }) {
  router.push(tab.path)
  mobileMenuOpen.value = false
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
  <button
      type="button"
      class="glass-layout__mobile-trigger"
      aria-label="Menü öffnen"
      @click="toggleMobileMenu"
  >
    <svg v-if="!mobileMenuOpen" class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
    </svg>
    <svg v-else class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
    </svg>
  </button>

  <div
      v-if="mobileMenuOpen"
      class="glass-layout__mobile-backdrop lg:hidden"
      @click="mobileMenuOpen = false"
  />

  <aside
      class="glass-layout__sidebar liquid-surface flex-col"
      :class="mobileMenuOpen ? 'flex glass-layout__sidebar--drawer' : 'hidden lg:flex'"
  >
    <div class="glass-layout__sidebar-header">
      <img :src="imageUrl('/flow/flow.png')" alt="FLOW Logo"/>
      <img :src="imageUrl('/flow/hot+fll.png')" alt="HANDS on TECHNOLOGY + FLL Logo"/>
    </div>

    <nav class="glass-layout__sidebar-nav">
      <button
          v-if="isLiveTabActive"
          type="button"
          class="glass-nav-link glass-nav-link--back nav-link"
          @click="goTo({ name: 'Veranstaltung', path: '/event' })"
      >
        <span class="inline-flex items-center gap-2">
          <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
          </svg>
          Zurück zu Planung
        </span>
      </button>

      <button
          v-for="tab in tabs"
          :key="tab.path"
          type="button"
          class="glass-nav-link nav-link"
          :class="{'glass-nav-link--active': isActive(tab.path)}"
          @click="goTo(tab)"
      >
        <span>{{ tab.name }}</span>
        <span
            v-if="hasWarning(tab.path)"
            class="glass-nav-warning"
            title="Achtung: Es gibt offene Punkte in diesem Bereich"
        />
      </button>
    </nav>

    <div class="glass-layout__sidebar-footer">
      <Menu v-if="showEventDropdown" as="div" class="relative w-full">
        <MenuButton
            @click="focusSearchAfterDropdownOpen($event)"
            class="glass-nav-link w-full"
        >
          <span class="truncate">{{ eventDropdownLabel() }}</span>
          <svg class="w-4 h-4 flex-shrink-0 opacity-60" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd"
                  d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                  clip-rule="evenodd"/>
          </svg>
        </MenuButton>
        <MenuItems
            class="absolute left-0 bottom-full z-50 mb-2 origin-bottom-left rounded-xl liquid-surface liquid-surface--radius-lg focus:outline-none w-[min(100%,20rem)] max-h-[50vh] overflow-y-auto"
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

      <button
          v-if="isAdmin"
          type="button"
          class="glass-nav-link nav-link"
          :class="{'glass-nav-link--active': isActive('/admin')}"
          @click="goTo({ name: 'Admin', path: '/admin' })"
      >
        Admin
      </button>

      <button type="button" class="glass-nav-link nav-link" @click="openHelpModal">
        Hilfe
      </button>

      <button type="button" class="glass-nav-link nav-link" @click="toggleTheme">
        <span>{{ theme === 'dark' ? 'Hell' : 'Dunkel' }}</span>
        <i :class="theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon'"/>
      </button>

      <button type="button" class="glass-nav-link nav-link" @click="logout">
        Logout
      </button>
    </div>
  </aside>

  <HelpModal :show="showHelpModal" @close="closeHelpModal"/>
</template>
